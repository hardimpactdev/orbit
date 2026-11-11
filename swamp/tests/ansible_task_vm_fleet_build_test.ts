/**
 * The fleet phase of the common converge builds the nested fleet from stock Ubuntu when a container of it is missing, and changes nothing
 * on a complete fleet. The real task files run on localhost in the order of the role (the Incus part of the tools phase, then the fleet
 * phase) against a fake `incus`, `swamp` and `timeout` that log every call. The fake `wg` stops the run at the operator join, which needs
 * root and systemd: what the build does before it is the subject here.
 */
import { assert, assertEquals, assertStringIncludes } from "jsr:@std/assert@1";
import {
  cleanupStockRoles,
  existingGatewayDns,
  freshGatewayDnsCheck,
  prepareStockRoles,
  productionGatewayDns,
  stockBaseTasks,
  withdrawGatewayDns,
} from "./_support/task_vm_stock_roles.ts";

const hasAnsible =
  new Deno.Command("sh", { args: ["-c", "command -v ansible-playbook"] })
    .outputSync().success;
const tasks = new URL("../ansible/tasks/", import.meta.url).pathname;
const user = new TextDecoder().decode(
  new Deno.Command("id", { args: ["-un"] }).outputSync().stdout,
).trim();

const guests = {
  gateway: "nested-gateway",
  "app-dev": "nested-app-dev",
  "app-prod": "nested-app-prod",
};
const layout = {
  gateway: {
    address: "10.232.2.11",
    hwaddr: "10:66:6a:5d:00:11",
    wireguard: "10.45.0.1",
    memory: "4GiB",
    tld: null,
  },
  "app-dev": {
    address: "10.232.2.12",
    hwaddr: "10:66:6a:5d:00:12",
    wireguard: "10.45.0.2",
    memory: "3GiB",
    tld: "nested-dev",
  },
  "app-prod": {
    address: "10.232.2.13",
    hwaddr: "10:66:6a:5d:00:13",
    wireguard: "10.45.0.3",
    memory: "3GiB",
    tld: "nested-prod",
  },
};
const unit = (guest: string, name: string) => ({
  guest,
  name,
  content: "[Mount]\nType=none\nOptions=bind\n",
});
// The shape of the converge intent's fleet (TaskVmConvergeInput, TaskVmNestedFleet).
const intent = {
  nested: { subnet: "10.232.2.0/24", interface: "oe-nested" },
  checkout: {
    path: "/home/orbit/apps/orbit/workspace",
    repository: "https://github.com/hardimpactdev/orbit.git",
    commit: "a".repeat(40),
  },
  fleet: {
    guests,
    network: "oe-nested",
    operator: {
      address: "10.45.0.10",
      hub: "10.45.0.1",
      network: "10.45.0.0/24",
    },
    layout,
    proof_network: "10.232.2.0/24",
    checkout: {
      path: "/home/orbit/orbit",
      state: "/var/lib/orbit-checkout-state",
      units: [
        unit("nested-gateway", "home-orbit-orbit-apps-gateway-.env.mount"),
        unit("nested-app-dev", "home-orbit-orbit-apps-gateway-.env.mount"),
      ],
      binds: {
        gateway: ["home-orbit-orbit-apps-gateway-.env.mount"],
        "app-dev": ["home-orbit-orbit-apps-gateway-.env.mount"],
      },
    },
    bootstrap: {
      apt_proxy: "http://10.232.2.1:3142",
      gateway: {
        url: "https://10.45.0.1",
        wireguard_ip: "10.45.0.1",
        wireguard_subnet: "10.45.0.0/24",
      },
      operator: {
        checkout: "/home/orbit/apps/orbit/workspace",
        wireguard_ip: "10.45.0.10",
      },
      agent_delivery: '{"commit":"c","version":"1","artifacts":{}}',
    },
  },
};

/**
 * Runs the Incus part of the tools phase and the fleet phase with `present` as the containers the VM holds, and `initialised` for an
 * Incus with its pools and network. `joined` records the operator stamp, which a complete fleet has. Returns the logged calls (incus,
 * swamp), the preseed, whether the operator stamp is still there, whether the play passed and its output.
 */
function run(
  present: string[],
  initialised: boolean,
  joined = false,
  stockRoles = false,
) {
  const folder = Deno.makeTempDirSync();
  const bin = `${folder}/bin`;
  Deno.mkdirSync(bin);
  Deno.mkdirSync(`${folder}/home/.ssh`, { recursive: true });
  Deno.mkdirSync(`${folder}/stamps`);
  if (joined) Deno.writeTextFileSync(`${folder}/stamps/operator`, "d");
  Deno.writeTextFileSync(`${folder}/containers`, present.join("\n"));
  const addresses = Object.fromEntries(
    Object.entries(guests).map((
      [role, name],
    ) => [name, layout[role as keyof typeof layout].address]),
  );
  Deno.writeTextFileSync(`${folder}/addresses.json`, JSON.stringify(addresses));
  // Each call is one log line; a created container shows in the next listing; an Orbit command admits the next Operation.
  Deno.writeTextFileSync(
    `${bin}/incus`,
    `#!/bin/sh
printf 'incus %s\\n' "$*" | tr '\\n' ' ' >> "${folder}/log"; echo >> "${folder}/log"
if [ -n "$STOCK_ROLES" ] && [ "$1" = --project ]; then
  exec "${Deno.execPath()}" run -A "${
      new URL("fixtures/task-vm-base-incus.ts", import.meta.url).pathname
    }" "$@"
fi
case "$*" in
  "storage list --format csv") [ -n "$INITIALISED" ] && printf 'default,dir\\ntopology-seed,btrfs\\n' ;;
  "network list --format csv") [ -n "$INITIALISED" ] && printf 'oe-nested,bridge\\n' ;;
  "admin init --preseed") cat > "${folder}/preseed" ;;
  "list --format json") jq -Rn --slurpfile a "${folder}/addresses.json" '[inputs | select(length > 0) | {name: ., status: "Running", config: {}, devices: {},
      expanded_devices: {eth0: {"ipv4.address": $a[0][.]}}}]' < "${folder}/containers" ;;
  "list --format csv --columns ns") sed 's/$/,RUNNING/' "${folder}/containers" ;;
  "init "*) echo "$3" >> "${folder}/containers" ;;
  *" -- systemctl restart wg-quick@orbit") if [ -n "$STOCK_ROLES" ]; then echo 'Fixture stops before real nested WireGuard/systemd activation' >&2; exit 42; fi ;;
  *" -- grep -m1 ^DB_DATABASE="*) echo 'DB_DATABASE=/home/orbit/.orbit/gateway.sqlite' ;;
  *"update nodes set wireguard_public_key ="*) echo '4' ;;
  *" -- cat /etc/ssh/ssh_host_ed25519_key.pub") echo "ssh-ed25519 AAAAgateway root@nested-gateway" ;;
  *" -- cat /home/orbit/.orbit/ssh/id_ed25519.pub") echo "ssh-ed25519 AAAAgatewaykey orbit@nested-gateway" ;;
  *" -- ssh-keygen -lf "*) echo "256 SHA256:fp-$2 root@$2 (ED25519)" ;;
  "file push "*) cat >> "${folder}/pushed-input" ;;
  *" php artisan orbit:"*) n=$(($(cat "${folder}/operations" 2>/dev/null || echo 0) + 1)); echo "$n" > "${folder}/operations"; if [ -n "$STOCK_ROLES" ]; then "${Deno.execPath()}" run -A "${
      new URL("_support/task_vm_stock_roles.ts", import.meta.url).pathname
    }" "${folder}" "$n"; fi; echo "Operation #$n queued." ;;
  *" select status from operations "*) if [ -n "$STOCK_ROLES" ]; then n=$(printf '%s' "$*" | sed 's/.*id = //'); cat "${folder}/role-$n.status"; else echo succeeded; fi ;;
  *"select coalesce("*) echo "at Wait for Reverb code node_role.apply_failed" ;;
  *"/api/v1/doctor "*) echo '{"data":{"healthy":true}}' ;;
esac
exit 0
`,
  );
  Deno.writeTextFileSync(
    `${bin}/swamp`,
    `#!/bin/sh
printf 'swamp %s key=%s\\n' "$*" "$SWAMP_API_KEY_FILE" >> "${folder}/log"
`,
  );
  Deno.writeTextFileSync(`${bin}/timeout`, `#!/bin/sh\nshift\nexec "$@"\n`);
  Deno.writeTextFileSync(
    `${bin}/wg`,
    stockRoles ? `#!/bin/sh\nexec /usr/bin/wg "$@"\n` : `#!/bin/sh\nexit 1\n`,
  );
  for (const name of ["incus", "swamp", "timeout", "wg"]) {
    Deno.chmodSync(`${bin}/${name}`, 0o755);
  }
  if (stockRoles) prepareStockRoles(folder);
  const play = [{
    hosts: "localhost",
    gather_facts: false,
    vars: {
      intent,
      recipe: {
        tools: {
          swamp: {
            artifacts: {
              amd64: { url: "https://swamp/x", sha256: "b".repeat(64) },
            },
          },
        },
      },
      orbit_user: user,
      orbit_home: `${folder}/home`,
      fleet_club_key: "/etc/orbit/swamp-club-api-key",
      converge_stamps_dir: `${folder}/stamps`,
      converge_stamps: joined ? { operator: "d" } : {},
      converge_due: { operator: false, tokens: false },
      checkout_now: { code: "c", agent: "a", migrations: "m" },
      checkout_current: ["code", "agent", "migrations"],
    },
    tasks: [
      ...(stockRoles
        ? [{
          name: "Create the unpromoted task VM base from stock",
          block: stockBaseTasks(),
          vars: {
            host: {
              project: "orbit-tasks",
              pool: "orbit-tasks",
              image: "ubuntu-26.04-vm",
              network: "orbittask0",
              current: null,
            },
            base: {
              vm: "host-base",
              seed: "host-base-seed",
              seed_size: "45GiB",
              cpus: 6,
              memory: "12GiB",
              disk: "60GiB",
              launch_config: "{}",
            },
            incus: ["incus", "--project", "orbit-tasks"],
          },
        }]
        : []),
      { "ansible.builtin.import_tasks": `${tasks}task-vm-incus.yml` },
      { "ansible.builtin.import_tasks": `${tasks}task-vm-fleet.yml` },
    ],
  }];
  if (stockRoles) {
    // Run sandboxed copies only where the fixture needs local files; all other fleet tasks stay the production source.
    for (const task of play[0].tasks) {
      if (task["ansible.builtin.import_tasks"]?.endsWith("task-vm-fleet.yml")) {
        task["ansible.builtin.import_tasks"] =
          `${folder}/tasks/task-vm-fleet.yml`;
      }
    }
  }
  Deno.writeTextFileSync(`${folder}/play.yml`, JSON.stringify(play));
  const done = new Deno.Command("ansible-playbook", {
    args: [
      "-i",
      "localhost,",
      "-e",
      "ansible_connection=local",
      "-e",
      "ansible_become=false",
      "-e",
      "ansible_python_interpreter={{ ansible_playbook_python }}",
      "-e",
      `incus_seed_disk=${folder}/seed-disk`,
      `${folder}/play.yml`,
    ],
    env: {
      PATH: `${bin}:${Deno.env.get("PATH")}`,
      INITIALISED: initialised ? "1" : "",
      STOCK_ROLES: stockRoles ? "1" : "",
      STOCK_FIXTURE: folder,
      BASE_FIXTURE: `${folder}/base-state.json`,
      ANSIBLE_HOME: `${folder}/ansible`,
      ANSIBLE_LOCAL_TEMP: `${folder}/tmp`,
      ANSIBLE_NOCOLOR: "1",
      ANSIBLE_FORKS: "4",
    },
  }).outputSync();
  const read = (name: string) => {
    try {
      return Deno.readTextFileSync(`${folder}/${name}`);
    } catch {
      return "";
    }
  };
  const log = read("log").trim().split("\n").filter(Boolean).map((line) =>
    line.trim()
  );
  const preseed = read("preseed");
  const stamped = read("stamps/operator") !== "";
  const pushedInput = read("pushed-input");
  const operatorKey = stockRoles ? read("wireguard/wg-nested.key") : "";
  const baseState = stockRoles ? JSON.parse(read("base-state.json")) : null;
  const roleOutputs = Object.fromEntries(
    [1, 2, 3, 4, 5].map((id) => [id, read(`role-${id}.output`)]),
  );
  const roleStatuses = [1, 2, 3, 4, 5].map((id) => read(`role-${id}.status`));
  if (stockRoles) cleanupStockRoles(folder);
  Deno.removeSync(folder, { recursive: true });
  return {
    baseState,
    operatorKey,
    roleOutputs,
    roleStatuses,
    log,
    preseed,
    stamped,
    pushedInput,
    passed: done.success,
    output: new TextDecoder().decode(done.stdout) +
      new TextDecoder().decode(done.stderr),
  };
}

/** The index of the first call at or after `from` that holds `text`; -1 when none does. */
const find = (log: string[], text: string, from = 0) =>
  log.findIndex((call, index) => index >= from && call.includes(text));
const reads =
  /^incus (storage list|network list|list )|^incus exec \S+ -- (cat|ssh-keygen -lf|cloud-init status|sqlite3|curl)/;

Deno.test({
  name:
    "a blank seed gets the network, then the containers, the Gateway bootstrap and the nested Orbit steps in order, then the operator join",
  ignore: !hasAnsible,
  fn() {
    const { log, preseed, passed, output, pushedInput } = run([], false); // The run stops at the operator join, which needs root and systemd (see the header).
    assertEquals(passed, false, output);
    // The run stops at the start of the operator join: at the fake `wg`, or one task earlier where `/etc/wireguard` is not readable,
    // as on a CI runner.
    const failed = output.indexOf("FAILED!");
    const stop = output.lastIndexOf("TASK [", failed);
    assert(
      failed >= 0 && stop >= 0 && [
        output.indexOf("TASK [Look for the operator key]"),
        output.indexOf("TASK [Make the operator key]"),
      ].includes(stop) &&
        output.includes("failed=1"),
      output,
    );
    assertStringIncludes(preseed, "name: oe-nested");
    assertStringIncludes(preseed, "ipv4.address: 10.232.2.1/24");
    assertStringIncludes(
      pushedInput,
      "ssh-ed25519 AAAAgatewaykey orbit@nested-gateway",
    );
    const steps = [
      "incus admin init --preseed",
      "incus list --format json",
      "incus init images:ubuntu/resolute/cloud nested-gateway --storage topology-seed --network oe-nested -c security.nesting=true -c limits.cpu=2 -c limits.memory=4GiB -c boot.autostart=true",
      "incus init images:ubuntu/resolute/cloud nested-app-dev",
      "incus init images:ubuntu/resolute/cloud nested-app-prod",
      "incus config device set nested-gateway eth0 ipv4.address=10.232.2.11 hwaddr=10:66:6a:5d:00:11",
      "incus start nested-gateway nested-app-dev nested-app-prod",
      "incus exec nested-gateway -- cloud-init status --wait",
      "incus exec nested-app-prod -- touch /etc/cloud/cloud-init.disabled",
      "incus config device add nested-gateway club-key disk source=/etc/orbit/swamp-club-api-key path=/home/orbit/.config/swamp/api-key",
      "swamp workflow run gateway-bootstrap --repo-dir /home/orbit/apps/orbit/workspace/swamp",
      "php artisan orbit:node-converge gateway",
      "select status from operations where id = 1",
      "php artisan orbit:role-add gateway gateway --converge",
      "select status from operations where id = 2",
      "php artisan orbit:role-add gateway websocket",
      "select status from operations where id = 3",
      "incus file push --uid 1000 --gid 1000 --mode 0600 - nested-app-dev/home/orbit/.ssh/authorized_keys",
      "php artisan orbit:node-add app-dev 10.232.2.12 --role=app-dev --wireguard-ip=10.45.0.2 --tld=nested-dev --host-key-fingerprint=SHA256:fp-nested-app-dev",
      "select status from operations where id = 4",
      "php artisan orbit:node-add app-prod 10.232.2.13 --role=app-prod --wireguard-ip=10.45.0.3 --tld=nested-prod --host-key-fingerprint=SHA256:fp-nested-app-prod",
      "select status from operations where id = 5",
      "ufw allow from 10.232.2.0/24 comment proof-network",
      "systemctl stop orbit-agent-view.service",
      "incus config device add nested-gateway checkout disk source=/home/orbit/apps/orbit/workspace path=/home/orbit/orbit shift=true",
      "incus file push --mode 0644 - nested-app-dev/etc/systemd/system/home-orbit-orbit-apps-gateway-.env.mount",
      "systemctl start --job-mode=ignore-requirements home-orbit-orbit-apps-gateway-.env.mount",
      "systemctl start php8.5-fpm.service",
      "https://10.45.0.1/api/v1/doctor",
      "incus list --format json",
    ];
    let at = 0;
    for (const step of steps) {
      const found = find(log, step, at);
      assert(found >= 0, `${step} after call ${at} in:\n${log.join("\n")}`);
      at = found + 1;
    }
    // The bootstrap input: what the Gateway sent, and what the run adds: the Swamp pin, the machine with its pinned key, the release.
    const bootstrap = log.find((call) => call.startsWith("swamp "))!;
    const input = JSON.parse(
      bootstrap.slice(
        bootstrap.indexOf("--input ") + 8,
        bootstrap.lastIndexOf(" key="),
      ),
    ).intent;
    const { identity_file: identity, ...ssh } = input.ssh;
    assertEquals(ssh, {
      host: "10.232.2.11",
      port: 22,
      user: "orbit",
      host_keys: ["ssh-ed25519 AAAAgateway"],
    });
    assert(identity.endsWith("/home/.ssh/id_ed25519"), identity);
    assertEquals(input.release, {
      repository: intent.checkout.repository,
      commit: intent.checkout.commit,
    });
    assertEquals(input.swamp, {
      url: "https://swamp/x",
      sha256: "b".repeat(64),
    });
    assertEquals(input.operator, intent.fleet.bootstrap.operator);
    assertEquals(input.apt_proxy, "http://10.232.2.1:3142");
    assert(bootstrap.endsWith("key=/etc/orbit/swamp-club-api-key"));
    // A blank seed has no fleet to remove.
    assertEquals(find(log, "delete --force"), -1);
  },
});

Deno.test({
  name: "a half-made fleet starts from nothing: its containers go first",
  ignore: !hasAnsible,
  fn() {
    const { log, output } = run(["nested-gateway"], true, true);
    const writes = log.filter((call) => !reads.test(call));
    assertEquals(writes[0], "incus delete --force nested-gateway", output);
    assert(find(log, "init images:ubuntu/resolute/cloud nested-gateway") > 0);
  },
});

Deno.test({
  name:
    "a fleet whose build stopped after its containers existed has no operator stamp: all three go and the build runs whole",
  ignore: !hasAnsible,
  fn() {
    const { log, stamped, output } = run(Object.values(guests), true);
    const writes = log.filter((call) => !reads.test(call));
    assertEquals(
      writes[0],
      "incus delete --force nested-gateway nested-app-dev nested-app-prod",
      output,
    );
    assert(find(log, "swamp workflow run gateway-bootstrap") > 0, output);
    assertEquals(stamped, false);
  },
});

Deno.test({
  name:
    "a build removes the operator stamp first, so a build that stops leaves the fleet incomplete",
  ignore: !hasAnsible,
  fn() {
    // A complete fleet that lost a container: the stamp of its earlier join goes before anything else changes.
    const { stamped, output } = run(
      ["nested-gateway", "nested-app-dev"],
      true,
      true,
    );
    assertEquals(stamped, false, output);
  },
});

Deno.test({
  name:
    "a complete fleet creates nothing, restarts nothing and changes nothing",
  ignore: !hasAnsible,
  fn() {
    const { log, preseed, stamped, passed, output } = run(
      Object.values(guests),
      true,
      true,
    );
    assertEquals(stamped, true);
    assert(passed, output);
    assertEquals(preseed, "");
    assertEquals(log, [
      "incus storage list --format csv",
      "incus network list --format csv",
      "incus list --format json",
    ]);
    assertStringIncludes(output, "changed=0");
  },
});

/**
 * The existing Incus fixture executes selected nested role contracts and generates real WireGuard keys in its own folder.
 * It stops at "Restart the nested hub": applying wg-quick requires an actual nested container, systemd and the kernel. Snapshot,
 * candidate task start and promotion remain unexecuted. app-dev/app-prod cover software selection, not their installed runtimes.
 * The websocket health endpoint is a DNS dependency probe, not the external Reverb application.
 */
Deno.test({
  name:
    "a from-stock refresh with no Gateway release metadata reaches all nested roles after private DNS lets the websocket dependency listen",
  ignore: !hasAnsible,
  fn() {
    const result = run([], false, false, true);
    assertEquals(result.baseState.vm.generation, "stock");
    assertEquals(result.baseState.seed, true);
    assertEquals(result.baseState.calls[0], [
      "--project",
      "orbit-tasks",
      "delete",
      "--force",
      "host-base",
    ]);
    assertStringIncludes(
      JSON.stringify(intent.fleet.bootstrap),
      '"wireguard_ip":"10.45.0.1"',
    );
    assertEquals("APP_VERSION" in intent.fleet.bootstrap, false);
    assertEquals("REVISION" in intent.fleet.bootstrap, false);
    assertEquals(
      result.roleStatuses,
      ["succeeded", "succeeded", "succeeded", "succeeded", "succeeded"],
      Object.entries(result.roleOutputs).map(([id, output]) =>
        `Nested Operation ${id}:\n${output}`
      ).join("\n"),
    );
    assertStringIncludes(
      result.roleOutputs[1],
      "Require the recorded CLI pin only for a CLI caller",
    );
    assertStringIncludes(result.roleOutputs[3], "Wait for Reverb");
    assertEquals(result.passed, false, result.output);
    assertStringIncludes(result.output, "TASK [Restart the nested hub]");
    assertStringIncludes(
      result.output,
      "Fixture stops before real nested WireGuard/systemd activation",
    );
    assertEquals(result.operatorKey.trim().length, 44);
    assertStringIncludes(
      result.log.join("\n"),
      " -- systemctl restart wg-quick@orbit",
    );
    assertEquals(find(result.log, "snapshot create"), -1);
    assertEquals(
      find(result.log, "php artisan orbit:role-add gateway websocket") <
        find(result.log, "php artisan orbit:node-add app-dev"),
      true,
    );
    assertEquals(
      find(result.log, "php artisan orbit:node-add app-dev") <
        find(result.log, "php artisan orbit:node-add app-prod"),
      true,
    );
  },
});

Deno.test({
  name:
    "an already-running local listener is reused with zero changes and peer-managed Gateway DNS is preserved",
  ignore: !hasAnsible,
  async fn() {
    const result = await existingGatewayDns();
    assertEquals(result.firstStatus, "succeeded", result.first);
    assertEquals(result.secondStatus, "succeeded", result.second);
    assertStringIncludes(result.second, "changed=0");
    assertEquals(result.samePid, true);
    assertEquals(
      result.calls.filter((call) =>
        call.name === "orbit-private-dns.service" && call.state === "restarted"
      ).length,
      1,
    );
    assertEquals(
      result.calls.some((call) =>
        call.name === "orbit-gateway-dns.service" && call.state
      ),
      false,
    );
    assertStringIncludes(result.second, "TASK [Read Gateway resolver policy]");
    assertStringIncludes(result.second, "skipping: [localhost]");
  },
});

Deno.test({
  name:
    "production Gateway vars from main create no listener or catalog and change no resolver",
  ignore: !hasAnsible,
  async fn() {
    const result = await productionGatewayDns();
    assertEquals(result.status, "succeeded", result.output);
    assertStringIncludes(result.output, "changed=0");
    assertEquals(result.dnsDirectory, false);
    assertEquals(result.listener, false);
    assertEquals(result.socket, false);
    assertEquals(result.calls.some((call) => call.state), false);
  },
});

Deno.test({
  name:
    "local Gateway DNS check mode skips activation and readiness without retries or mutation",
  ignore: !hasAnsible,
  async fn() {
    const result = await existingGatewayDns(true);
    assertEquals(result.firstStatus, "succeeded", result.first);
    assertEquals(result.secondStatus, "succeeded", result.second);
    assertStringIncludes(result.second, "changed=0");
    assertEquals(result.samePid, true);
    assertEquals(result.second.includes("FAILED - RETRYING"), false);
    assertStringIncludes(
      result.second,
      "TASK [Prove the local Gateway DNS answer before routing]",
    );
    assertStringIncludes(result.second, "skipping: [localhost]");
  },
});

Deno.test({
  name:
    "a recorded local listener is disabled and its stale catalog removed after routing to a separate VPN",
  ignore: !hasAnsible,
  async fn() {
    const result = await withdrawGatewayDns();
    assertEquals(result.status, "succeeded", result.output);
    assertEquals(result.retained, []);
    assertEquals(result.running, false);
    const route = result.calls.findIndex((call) =>
      call.name === "orbit-gateway-dns.service" && call.state === "started"
    );
    const stop = result.calls.findIndex((call) =>
      call.name === "orbit-private-dns.socket" && call.state === "stopped"
    );
    assertEquals(stop > route && route >= 0, true);
    assertEquals(
      result.calls.filter((call) => call.state === "stopped").map((
        call,
      ) => [call.name, call.enabled]),
      [
        ["orbit-private-dns.socket", false],
        ["orbit-private-dns.service", false],
      ],
    );
  },
});

Deno.test({
  name:
    "fresh local Gateway DNS check mode plans resources without requiring an installed release",
  ignore: !hasAnsible,
  async fn() {
    const result = await freshGatewayDnsCheck();
    assertEquals(result.status, "succeeded", result.output);
    assertEquals(result.output.includes("FAILED - RETRYING"), false);
  },
});

Deno.test({
  name:
    "withdrawal retains the listener when resolver policy still names its local address",
  ignore: !hasAnsible,
  async fn() {
    const result = await withdrawGatewayDns(true);
    assertEquals(result.status, "failed", result.output);
    assertEquals(result.running, true);
    assertEquals(result.retained.length, 4);
    assertEquals(result.calls.some((call) => call.state === "stopped"), false);
  },
});
