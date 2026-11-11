/**
 * The fleet phase of the common converge builds the nested fleet from stock Ubuntu when a container of it is missing, and changes nothing
 * on a complete fleet. The real task files run on localhost in the order of the role (the Incus part of the tools phase, then the fleet
 * phase) against a fake `incus`, `swamp` and `timeout` that log every call. The fake `wg` stops the run at the operator join, which needs
 * root and systemd: what the build does before it is the subject here.
 */
import {
  cleanupStockRoles,
  prepareStockRoles,
  stockBaseTasks,
} from "./task_vm_stock_roles.ts";

export const hasAnsible =
  new Deno.Command("sh", { args: ["-c", "command -v ansible-playbook"] })
    .outputSync().success;
const tasks = new URL("../../ansible/tasks/", import.meta.url).pathname;
const user = new TextDecoder().decode(
  new Deno.Command("id", { args: ["-un"] }).outputSync().stdout,
).trim();

export const guests = {
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
export const intent = {
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
        unit(
          "nested-gateway",
          "home-orbit-orbit-apps-gateway-.env.mount",
        ),
        unit(
          "nested-app-dev",
          "home-orbit-orbit-apps-gateway-.env.mount",
        ),
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
export function run(
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
  Deno.writeTextFileSync(
    `${folder}/addresses.json`,
    JSON.stringify(addresses),
  );
  // Each call is one log line; a created container shows in the next listing; an Orbit command admits the next Operation.
  Deno.writeTextFileSync(
    `${bin}/incus`,
    `#!/bin/sh
printf 'incus %s\\n' "$*" | tr '\\n' ' ' >> "${folder}/log"; echo >> "${folder}/log"
if [ -n "$STOCK_ROLES" ] && [ "$1" = --project ]; then
  exec "${Deno.execPath()}" run -A "${
      new URL("../fixtures/task-vm-base-incus.ts", import.meta.url).pathname
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
      new URL("./task_vm_stock_roles.ts", import.meta.url).pathname
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
              amd64: {
                url: "https://swamp/x",
                sha256: "b".repeat(64),
              },
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
      if (
        task["ansible.builtin.import_tasks"]?.endsWith(
          "task-vm-fleet.yml",
        )
      ) {
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
export const find = (log: string[], text: string, from = 0) =>
  log.findIndex((call, index) => index >= from && call.includes(text));
export const reads =
  /^incus (storage list|network list|list )|^incus exec \S+ -- (cat|ssh-keygen -lf|cloud-init status|sqlite3|curl)/;
