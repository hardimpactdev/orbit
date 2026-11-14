/**
 * Run the fleet step of a nested-fleet Instance container (swamp/ansible/tasks/instance-fleet-*.yml) against a stateful Incus boundary:
 * the Node reaches the Instance container with `incus exec`, and the inner Incus holds the three nested containers in the device and
 * configuration shape exported from a task base. The connection is local and nothing becomes root.
 */
import { assert, assertEquals, assertStringIncludes } from "jsr:@std/assert@1";

const hasAnsible = new Deno.Command("sh", { args: ["-c", "command -v ansible-playbook"] }).outputSync().success;
const root = new URL("../ansible/", import.meta.url);
const shapes = JSON.parse(Deno.readTextFileSync(new URL("fixtures/instance-fleet-containers.json", import.meta.url)));
const phases = ["check", "rules", "rules-read", "files", "start", "reach", "ready", "refresh", "converge-node", "reset", "resume", ""];
const guests = { gateway: "nested-gateway", "app-dev": "nested-app-dev", "app-prod": "nested-app-prod" };
const names = Object.values(guests);
const outer = ["--project", "orbit-instances-nested", "exec", "instance-9", "--"];
const clubKey = "/run/orbit-club/swamp-club-api-key";
const clubValue = "club-key-value-that-never-leaves-the-file";
/** The nested Gateway's hub key: a WireGuard public key, 32 bytes in base64. */
const hubKey = "HUBKEY" + "A".repeat(37) + "=";
const proxy = {
  HTTP_PROXY: "http://10.253.0.1:3128",
  HTTPS_PROXY: "http://10.253.0.1:3128",
  http_proxy: "http://10.253.0.1:3128",
  https_proxy: "http://10.253.0.1:3128",
  NO_PROXY: "localhost,127.0.0.1,::1,.orbit,10.45.0.1,10.45.0.0/24,10.232.2.0/24",
  no_proxy: "localhost,127.0.0.1,::1,.orbit,10.45.0.1,10.45.0.0/24,10.232.2.0/24",
};
const dropIn = "[Service]\nEnvironment=" + Object.entries(proxy).map(([key, value]) => `"${key}=${value}"`).join(" ") + "\n";
const environment = Object.fromEntries(Object.entries(proxy).map(([key, value]) => [`environment.${key}`, value]));
const units = ["/etc/systemd/system/orbit-swamp-serve.service.d/50-inner-fleet-proxy.conf", "/etc/systemd/system/orbit-swamp-worker@.service.d/50-inner-fleet-proxy.conf"];
const idmap = "both 1000 1000\nuid 2750 2750\ngid 2760 2760";
/** The egress files of every nested container: apt and sudo and SSH sessions reach the internet only through the egress proxy. */
const aptFile = `Acquire::http::Proxy "${proxy.HTTP_PROXY}";\nAcquire::https::Proxy "${proxy.HTTPS_PROXY}";\n`;
const environmentFile = 'PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:/usr/games:/usr/local/games:/snap/bin"\n' +
  Object.entries(proxy).map(([key, value]) => `${key}="${value}"`).join("\n") + "\n";
const egress = { "/etc/apt/apt.conf.d/90orbit-package-cache": aptFile, "/etc/environment": environmentFile };
/** Each file rule in the order the rules read it: the nested Gateway's drop-ins, then the egress files of each container. */
const fileRules: [string, string][] = [
  ...units.map((unit): [string, string] => ["nested-gateway", unit]),
  ...names.flatMap((name) => Object.keys(egress).map((path): [string, string] => [name, path])),
];
const push = (name: string, path: string) => `incus file push --create-dirs --uid 0 --gid 0 --mode 0644 - ${name}${path}`;

/** The shape a fleet has after the fleet rules: the exported shape with the devices and keys the rules give each container. */
function ruled() {
  const containers = structuredClone(shapes);
  const checkout = { path: "/home/orbit/orbit", shift: "false", source: "/srv/workspace", type: "disk" };
  const apparmor = { type: "disk", source: "/etc/orbit/apparmor-off", path: "/sys/module/apparmor/parameters/enabled", readonly: "true" };
  containers["nested-gateway"].devices.checkout = checkout;
  containers["nested-gateway"].devices["club-key"] = { path: "/home/orbit/.config/swamp/club", required: "false", shift: "false", source: "/run/orbit-club", type: "disk" };
  containers["nested-app-dev"].devices.checkout = checkout;
  containers["nested-app-dev"].devices["apparmor-off"] = apparmor;
  containers["nested-app-prod"].devices["apparmor-off"] = apparmor;
  for (const name of ["nested-gateway", "nested-app-dev"]) containers[name].config["raw.idmap"] = idmap;
  for (const name of names) Object.assign(containers[name].config, environment);
  containers["nested-gateway"].files = Object.fromEntries(units.map((unit) => [unit, dropIn]));
  for (const name of names) containers[name].files = { ...(containers[name].files ?? {}), ...egress };
  return containers;
}

function fixture(containers: Record<string, Record<string, unknown>>, options: { club?: boolean; base?: boolean } = {}) {
  const folder = Deno.makeTempDirSync();
  Deno.mkdirSync(`${folder}/bin`);
  Deno.mkdirSync(`${folder}/tasks`);
  Deno.mkdirSync(`${folder}/run`);
  for (const phase of phases) {
    const name = phase === "" ? "instance-fleet.yml" : `instance-fleet-${phase}.yml`;
    Deno.copyFileSync(new URL(`tasks/${name}`, root), `${folder}/tasks/${name}`);
  }
  Deno.writeTextFileSync(`${folder}/play.yml`, [
    "- name: Run one phase of the fleet step",
    "  hosts: localhost",
    "  gather_facts: false",
    "  vars:",
    "    intent: \"{{ lookup('ansible.builtin.file', lookup('ansible.builtin.env', 'ORBIT_RUN') ~ '/intent.json') | from_json }}\"",
    "  tasks:",
    "    - ansible.builtin.include_tasks: \"tasks/instance-fleet-{{ phase }}.yml\"",
    "    - ansible.builtin.copy: { content: \"{{ fleet_refresh_result | to_json }}\", dest: \"{{ lookup('ansible.builtin.env', 'ORBIT_RUN') }}/refresh.json\" }",
    "      when: fleet_refresh_result is defined",
    "",
  ].join("\n"));
  Deno.writeTextFileSync(`${folder}/bin/incus`, `#!/bin/sh\nexec '${Deno.execPath()}' run -A '${new URL("fixtures/instance-fleet-incus.ts", import.meta.url).pathname}' "$@"\n`);
  Deno.chmodSync(`${folder}/bin/incus`, 0o755);
  for (const container of Object.values(containers)) {
    container.status ??= "Stopped";
    if (options.base) container.snapshots = { base: { config: structuredClone(container.config), devices: structuredClone(container.devices), files: structuredClone(container.files ?? {}), taken: "Stopped" } };
  }
  const nodes = [
    { id: 1, name: "gateway", agent: { state: "online" }, wireguard_public_key: "GATEWAYPUB=" },
    { id: 2, name: "app-dev", agent: { state: "online" }, wireguard_public_key: "APPDEVPUB=" },
    { id: 3, name: "app-prod", agent: { state: "online" }, wireguard_public_key: "APPPRODPUB=" },
    { id: 4, name: "operator", agent: null, wireguard_public_key: "OPERATORPUB=" },
  ];
  const files: Record<string, string> = { "/etc/wireguard/wg-nested.key": "operator-private-key" };
  if (options.club) files[clubKey] = clubValue;
  Deno.writeTextFileSync(`${folder}/state.json`, JSON.stringify({
    outer: { project: "orbit-instances-nested", name: "instance-9", files },
    containers, tunnel: null, hubKey, nodes,
  }));
  Deno.writeTextFileSync(`${folder}/run/intent.json`, JSON.stringify({
    container: { project: "orbit-instances-nested", name: "instance-9", workspace: "/srv/workspace" },
    fleet: {
      guests,
      layout: {
        gateway: { address: "10.232.2.11", wireguard: "10.45.0.1" },
        "app-dev": { address: "10.232.2.12", wireguard: "10.45.0.2" },
        "app-prod": { address: "10.232.2.13", wireguard: "10.45.0.3" },
      },
      operator: { address: "10.45.0.10", hub: "10.45.0.1", network: "10.45.0.0/24" },
      checkout: { path: "/home/orbit/orbit" },
      proxy,
    },
  }));
  const state = () => JSON.parse(Deno.readTextFileSync(`${folder}/state.json`));
  return {
    state,
    // The state is the fixture's JSON; a test edits any part of it.
    // deno-lint-ignore no-explicit-any
    change(edit: (value: Record<string, any>) => void) {
      const value = state();
      edit(value);
      Deno.writeTextFileSync(`${folder}/state.json`, JSON.stringify(value));
    },
    /** The calls in the Instance container since the last read, without the Node's `incus exec` prefix. */
    calls() {
      let text = "";
      try {
        text = Deno.readTextFileSync(`${folder}/calls`);
        Deno.removeSync(`${folder}/calls`);
      } catch { /* no call */ }
      return text.split("\n").filter(Boolean).map((line) => {
        const call = JSON.parse(line) as string[];
        assertEquals(call.slice(0, 5), outer);
        return call.slice(5).join(" ");
      });
    },
    run(phase: string, extra: string[] = []) {
      const result = new Deno.Command("ansible-playbook", {
        args: ["-i", "localhost,", "-e", "ansible_connection=local", "-e", "ansible_python_interpreter={{ ansible_playbook_python }}",
          "-e", `phase=${phase}`, "-e", "fleet_ready_delay=0", ...extra, `${folder}/play.yml`],
        env: {
          HOST_FIXTURE: folder,
          PATH: `${folder}/bin:${Deno.env.get("PATH")}`,
          ANSIBLE_NOCOLOR: "1",
          ANSIBLE_HOME: `${folder}/ansible`,
          ANSIBLE_LOCAL_TEMP: `${folder}/tmp`,
          ANSIBLE_COLLECTIONS_PATH: "",
          ORBIT_RUN: `${folder}/run`,
        },
      }).outputSync();
      const output = new TextDecoder().decode(result.stdout) + new TextDecoder().decode(result.stderr);
      return { ok: result.success, output, changed: Number(/^localhost\s*:.*changed=(\d+)/m.exec(output)?.[1] ?? -1) };
    },
    /** Edit the run's intent. */
    // deno-lint-ignore no-explicit-any
    intent(edit: (value: Record<string, any>) => void) {
      const value = JSON.parse(Deno.readTextFileSync(`${folder}/run/intent.json`));
      edit(value);
      Deno.writeTextFileSync(`${folder}/run/intent.json`, JSON.stringify(value));
    },
    /** What the last refresh did (`fleet_refresh_result`), or null when no refresh passed. */
    refreshed(): Record<string, unknown> | null {
      try {
        const value = JSON.parse(Deno.readTextFileSync(`${folder}/run/refresh.json`));
        Deno.removeSync(`${folder}/run/refresh.json`);
        return value;
      } catch {
        return null;
      }
    },
    cleanup: () => Deno.removeSync(folder, { recursive: true }),
  };
}

/** The Club key is only a device source and a presence test: never read, printed or copied. */
function assertKeyUntouched(calls: string[], output: string) {
  assert(!output.includes(clubValue), "the key's value is in the output");
  for (const call of calls.filter((line) => line.includes(clubKey))) {
    assert(call === `test -s ${clubKey}` || / config device (add|set) nested-gateway club-key /.test(call), `the key file is touched: ${call}`);
  }
}

const reads = [
  ...names.map((name) => `incus query /1.0/instances/${name}`),
  ...fileRules.map(([name, path]) => `incus file pull ${name}${path} -`),
];
const startCalls = [
  "incus list --format csv --columns ns",
  ...names.map((name) => `incus start ${name}`),
  // The nested Swamp's key file is a link into the folder of the club-key device.
  "incus exec nested-gateway -- readlink /home/orbit/.config/swamp/api-key",
  "incus exec nested-gateway -- ln -sfnT club/swamp-club-api-key /home/orbit/.config/swamp/api-key",
  "test -s /etc/wireguard/wg-nested.key",
  "incus exec nested-gateway -- wg show orbit public-key",
  "wg show wg-nested",
  "ip link add wg-nested type wireguard",
  `wg set wg-nested private-key /etc/wireguard/wg-nested.key listen-port 51821 peer ${hubKey} endpoint 10.232.2.11:51820 allowed-ips 10.45.0.0/24 persistent-keepalive 25`,
  "ip -j addr show dev wg-nested",
  "ip addr add 10.45.0.10/24 dev wg-nested",
  "ip link set wg-nested up",
];
/** What a start promises: the containers run and the tunnel reaches the hub; no API call. */
const reachCalls = ["incus list --format csv --columns ns", "ping -c 1 -W 2 10.45.0.1"];
/** The operator's API calls never use the container's proxy, and keep curl's error. */
const api = "curl -sSk --noproxy * -m 80 -H Host: gateway.orbit -H Accept: application/json";
/** The refresh reads its record and the checkout's digests first, and writes the record last (instance-fleet-refresh.yml). */
const record = "/var/lib/orbit/fleet-refresh.json";
const recordReads = [`cat ${record}`, "git -c safe.directory=* -C /srv/workspace rev-parse HEAD:apps/gateway HEAD:apps/agent/contract HEAD:swamp"];
const recordWrite = `sh -c mkdir -p "\${1%/*}" && cat > "$1.tmp" && chown root:root "$1.tmp" && chmod 0644 "$1.tmp" && mv -f "$1.tmp" "$1" sh ${record}`;
const recordRemove = `rm -f ${record}`;
const readyCalls = [
  "incus list --format csv --columns ns",
  "ping -c 1 -W 2 10.45.0.1",
  `${api} -w \\nHTTP %{http_code} -X POST https://10.45.0.1/api/v1/doctor -H Content-Type: application/json -d {}`,
  `${api} --fail https://10.45.0.1/api/v1/nodes`,
];

Deno.test({
  name: "the rules give the exported fleet its devices, ID mapping and proxy, check them, and a rerun changes nothing",
  ignore: !hasAnsible,
  fn() {
    const f = fixture(structuredClone(shapes), { club: true });
    try {
      const first = f.run("rules");
      assert(first.ok, first.output);
      const calls = f.calls();
      const proxyKeys = Object.entries(environment).map(([key, value]) => `${key}=${value}`);
      assertEquals(calls, [
        ...reads,
        "incus config device add nested-app-dev apparmor-off disk source=/etc/orbit/apparmor-off path=/sys/module/apparmor/parameters/enabled readonly=true",
        "incus config device add nested-app-prod apparmor-off disk source=/etc/orbit/apparmor-off path=/sys/module/apparmor/parameters/enabled readonly=true",
        "incus config device set nested-gateway checkout source=/srv/workspace path=/home/orbit/orbit shift=false",
        "incus config device set nested-gateway club-key source=/run/orbit-club path=/home/orbit/.config/swamp/club required=false shift=false",
        "incus config device set nested-app-dev checkout source=/srv/workspace path=/home/orbit/orbit shift=false",
        ...["nested-gateway", "nested-app-dev", "nested-app-prod"].flatMap((name) =>
          [...(name === "nested-app-prod" ? [] : [`raw.idmap=${idmap}`]), ...proxyKeys].map((key) => `incus config set ${name} ${key}`)),
        ...fileRules.map(([name, path]) => push(name, path)),
        ...reads,
      ]);
      assertKeyUntouched(calls, first.output);
      assertEquals(f.state().containers, Object.fromEntries(Object.entries(ruled()).map(([name, value]) => [name, { status: "Stopped", ...value as object }])));
      const again = f.run("rules");
      assert(again.ok, again.output);
      assertEquals(again.changed, 0);
      assertEquals(f.calls(), reads);
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a start without the Club key starts the three containers, brings up wg-nested with ip and wg, and checks only that they run and the tunnel reaches the hub; a rerun only reads",
  ignore: !hasAnsible,
  fn() {
    const f = fixture(ruled());
    // An unhealthy Doctor does not stop a start: it never asks.
    f.change((state) => state.doctorUnhealthy = 1000);
    try {
      const first = f.run("start");
      assert(first.ok, first.output);
      const calls = f.calls();
      assertEquals(calls, [...reads, ...startCalls, ...reachCalls]);
      assertKeyUntouched(calls, first.output);
      assert(!calls.some((call) => call.includes("wg-quick")));
      assertEquals(f.state().containers["nested-gateway"].links, { "/home/orbit/.config/swamp/api-key": "club/swamp-club-api-key" });
      assertEquals(f.state().tunnel, { addresses: ["10.45.0.10/24"], up: true, key: "/etc/wireguard/wg-nested.key", port: "51821", peer: hubKey, endpoint: "10.232.2.11:51820", allowed: "10.45.0.0/24" });
      const again = f.run("start");
      assert(again.ok, again.output);
      assertEquals(again.changed, 0);
      assertEquals(f.calls(), [...reads, "incus list --format csv --columns ns", "incus exec nested-gateway -- readlink /home/orbit/.config/swamp/api-key", "test -s /etc/wireguard/wg-nested.key",
        "incus exec nested-gateway -- wg show orbit public-key", "wg show wg-nested", "ip -j addr show dev wg-nested", ...reachCalls]);
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a start that fails names the nested container, and a readiness timeout of a resumed fleet names the check",
  ignore: !hasAnsible,
  fn() {
    const f = fixture(ruled());
    try {
      f.change((state) => state.failStart = { "nested-app-dev": 'Failed to start device "eth0": Device or resource busy' });
      const failed = f.run("start");
      assert(!failed.ok);
      assertStringIncludes(failed.output, 'nested-app-dev did not start: Error: Failed to start device \\"eth0\\": Device or resource busy');
      f.change((state) => {
        delete state.failStart;
        state.doctorUnhealthy = 1000;
      });
      const late = f.run("resume", ["-e", "fleet_ready_seconds=3", "-e", "fleet_ready_delay=1"]);
      assert(!late.ok);
      assertStringIncludes(late.output, "Readiness check 'doctor' failed after 3 s:");
      assertStringIncludes(late.output, "node.agent_view_stale");
      f.change((state) => {
        state.doctorUnhealthy = 0;
        state.nodes[2].agent.state = "offline";
      });
      const offline = f.run("resume");
      assert(!offline.ok);
      assertStringIncludes(offline.output, "Readiness check 'agents' failed: the agent of app-prod is not online");
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a start waits while the nested Gateway's hub key reads (none) with exit 0 and sets the peer with the real key; a key that never comes fails in plain words",
  ignore: !hasAnsible,
  fn() {
    const f = fixture(ruled());
    try {
      // Operation 19987: wg-quick had made the hub interface but not yet set its private key.
      f.change((state) => state.hubKeyNone = 1);
      const started = f.run("start");
      assert(started.ok, started.output);
      const calls = f.calls();
      const read = "incus exec nested-gateway -- wg show orbit public-key";
      assertEquals(calls.filter((call) => call === read).length, 2);
      assertEquals(calls, [...reads, ...startCalls.flatMap((call) => call === read ? [read, read] : [call]), ...reachCalls]);
      assertEquals(f.state().tunnel.peer, hubKey);
      assert(!calls.some((call) => call.includes("(none)")));

      const late = fixture(ruled());
      try {
        late.change((state) => state.hubKeyNone = 1000);
        const failed = late.run("start", ["-e", "fleet_boot_seconds=3", "-e", "fleet_ready_delay=1"]);
        assert(!failed.ok);
        assertStringIncludes(failed.output, "The nested Gateway's WireGuard key was not ready after 3 s; the last read got (none)");
        assert(!late.calls().some((call) => call.startsWith("wg set")), "a peer was set without a key");
        assertEquals(late.state().tunnel, null);
      } finally {
        late.cleanup();
      }
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a fleet that lacks a build rule is refused with instance.container_fleet_drift before any start; a missing owned rule passes and is not written",
  ignore: !hasAnsible,
  fn() {
    const f = fixture(structuredClone(shapes));
    try {
      const refused = f.run("start");
      assert(!refused.ok);
      assertStringIncludes(refused.output, "The nested fleet differs from its rules where Orbit does not write: nested-gateway config raw.idmap, nested-app-dev config raw.idmap\"");
      assertStringIncludes(refused.output, '"msg": "instance.container_fleet_drift"');
      assertEquals(f.calls(), reads);
      const one = ruled();
      delete one["nested-app-dev"].devices["apparmor-off"];
      const g = fixture(one);
      try {
        const started = g.run("start");
        assert(started.ok, started.output);
        assert(!g.calls().some((call) => / config (set|device) | file push /.test(call)));
        assertEquals(g.state().containers["nested-app-dev"].devices["apparmor-off"], undefined);
      } finally {
        g.cleanup();
      }
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a refresh applies the checkout, converges the three Node bases, waits for readiness, stops the fleet, then takes base with the rules",
  ignore: !hasAnsible,
  fn() {
    const f = fixture(ruled(), { club: true, base: true });
    try {
      assert(f.run("start").ok);
      f.calls();
      // A device Orbit owns went missing while the fleet ran: the refresh puts it back before it takes base.
      f.change((state) => delete state.containers["nested-app-prod"].devices["apparmor-off"]);
      const refreshed = f.run("refresh");
      assert(refreshed.ok, refreshed.output);
      const calls = f.calls();
      const exec = (name: string, command: string) => `incus exec ${name} -- ${command}`;
      const services = ["orbit-gateway-horizon.service", "orbit-gateway-schedule.service", "orbit-agent-view.service"];
      const mounts = "home-orbit-orbit-apps-gateway-.env.mount home-orbit-orbit-apps-gateway-storage.mount home-orbit-orbit-apps-gateway-bootstrap-cache.mount";
      const swampMounts = "home-orbit-orbit-swamp-.swamp.mount home-orbit-orbit-swamp-vaults.mount home-orbit-orbit-swamp-grants.mount";
      assertEquals(calls, [
        `test -s ${clubKey}`,
        ...recordReads,
        recordRemove,
        // With the Club key the nested Gateway's Swamp state mounts must be up too: the grants are written there.
        exec("nested-gateway", `systemctl is-active ${mounts} ${swampMounts}`),
        exec("nested-app-dev", `systemctl is-active ${mounts}`),
        // The app user with its groups: `work` opens the checkout.
        exec("nested-gateway", "runuser -u orbit -- install -D -m 0444 /home/orbit/orbit/apps/gateway/resources/agent/frozen-manifest.json /home/orbit/orbit/apps/agent/delivery/manifest.json"),
        exec("nested-gateway", "env ANSIBLE_CONFIG=/home/orbit/orbit/swamp/ansible/ansible.cfg ANSIBLE_DISPLAY_FAILED_STDERR=true /opt/orbit/ansible/venv/bin/ansible-playbook -i localhost, -e @/opt/orbit/bootstrap/control.yml --tags grants /home/orbit/orbit/swamp/ansible/gateway-bootstrap.yml"),
        "incus exec nested-gateway --cwd /home/orbit/orbit/apps/gateway -- runuser -u orbit -- php artisan migrate --force",
        exec("nested-gateway", `systemctl restart ${services.join(" ")}`),
        ...[...services, "orbit-swamp-serve.service", "orbit-swamp-worker@a.service", "orbit-swamp-worker@b.service"]
          .map((unit) => exec("nested-gateway", `systemctl is-active --quiet ${unit}`)),
        // The rules and files are read (and files written where they differ) on the running fleet, before the converge.
        ...reads,
        `${api} --fail https://10.45.0.1/api/v1/nodes`,
        ...names.map((name) => exec(name, "systemctl stop apt-daily.timer apt-daily-upgrade.timer")),
        ...names.map((name) => exec(name, "systemctl show --property=ActiveState --value apt-daily.service apt-daily-upgrade.service")),
        ...[1, 2, 3].flatMap((node) => [
          `${api} --fail -X POST https://10.45.0.1/api/v1/nodes/${node}/converge -H Content-Type: application/json -d {"base":true}`,
          `${api} --fail https://10.45.0.1/api/v1/operations/${900 + node}`,
        ]),
        ...readyCalls,
        ...names.map((name) => `incus query /1.0/instances/${name}/snapshots?recursion=1`),
        ...names.map((name) => `incus stop ${name} --timeout 90`),
        "incus list --format csv --columns ns",
        ...reads,
        "incus config device add nested-app-prod apparmor-off disk source=/etc/orbit/apparmor-off path=/sys/module/apparmor/parameters/enabled readonly=true",
        ...reads,
        ...names.map((name) => `incus snapshot delete ${name} base`),
        ...names.map((name) => `incus snapshot create ${name} base`),
        recordWrite,
      ]);
      assertKeyUntouched(calls, refreshed.output);
      const state = f.state();
      assertEquals(state.operations.map((operation: { node: number }) => operation.node), [1, 2, 3]);
      for (const name of names) {
        assertEquals(state.containers[name].status, "Stopped");
        assertEquals(state.containers[name].snapshots.base.taken, "Stopped");
        assertEquals(state.containers[name].snapshots.base.devices, ruled()[name].devices);
        assertEquals(state.containers[name].snapshots.base.config, ruled()[name].config);
      }
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a refresh waits while a state mount is still activating after the start; mounts that never come up fail with the bound and the containers",
  ignore: !hasAnsible,
  fn() {
    const f = fixture(ruled(), { club: true, base: true });
    try {
      assert(f.run("start").ok);
      f.calls();
      f.change((state) => state.activating = 1);
      const refreshed = f.run("refresh");
      assert(refreshed.ok, refreshed.output);
      const mounts = f.calls().filter((call) => call.includes("systemctl is-active home-orbit"));
      assertEquals(mounts.map((call) => call.split(" ")[2]), ["nested-gateway", "nested-gateway", "nested-app-dev"]);

      const g = fixture(ruled(), { club: true, base: true });
      try {
        assert(g.run("start").ok);
        g.change((state) => state.activating = 1000);
        const failed = g.run("refresh", ["-e", "fleet_boot_seconds=2", "-e", "fleet_ready_delay=1"]);
        assert(!failed.ok);
        assertStringIncludes(failed.output, "The state mounts are not active after 2 s in nested-gateway, nested-app-dev");
      } finally {
        g.cleanup();
      }
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a refresh stops at a failed Node converge and takes no base; without the Club key it skips the grants and the Swamp units",
  ignore: !hasAnsible,
  fn() {
    const f = fixture(ruled(), { base: true });
    try {
      assert(f.run("start").ok);
      f.calls();
      f.change((state) => state.convergeStatus = { 2: "failed" });
      const failed = f.run("refresh");
      assert(!failed.ok);
      assertStringIncludes(failed.output, "The base converge of the nested Node app-dev (Operation 902) ended failed with node.converge_failed");
      const calls = f.calls();
      assert(!calls.some((call) => !call.includes(" file pull ") && (call.includes("ansible-playbook") || call.includes("orbit-swamp"))), calls.join("\n"));
      assert(!calls.some((call) => call.includes("/nodes/3/converge") || call.includes("snapshot") || call.includes("incus stop")));
      assertKeyUntouched(calls, failed.output);
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a reset restores base on all three containers, keeps the rules of base, and starts the fleet; a base without the rules is refused",
  ignore: !hasAnsible,
  fn() {
    const f = fixture(ruled(), { base: true });
    try {
      assert(f.run("start").ok);
      f.calls();
      // Work in the fleet changed a device of a nested container; the restore takes base's back.
      f.change((state) => state.containers["nested-app-dev"].devices.checkout.shift = "true");
      const reset = f.run("reset");
      assert(reset.ok, reset.output);
      const calls = f.calls();
      assertEquals(calls, [
        ...names.map((name) => `incus snapshot restore ${name} base`),
        ...reads,
        "incus list --format csv --columns ns",
        "incus exec nested-gateway -- readlink /home/orbit/.config/swamp/api-key",
        "incus exec nested-gateway -- ln -sfnT club/swamp-club-api-key /home/orbit/.config/swamp/api-key",
        "test -s /etc/wireguard/wg-nested.key",
        "incus exec nested-gateway -- wg show orbit public-key",
        "wg show wg-nested",
        "ip -j addr show dev wg-nested",
        ...reachCalls,
        ...readyCalls,
      ]);
      assertKeyUntouched(calls, reset.output);
      assertEquals(f.state().containers["nested-app-dev"].devices.checkout.shift, "false");
      const g = fixture(structuredClone(shapes), { base: true });
      try {
        const refused = g.run("reset");
        assert(!refused.ok);
        assertStringIncludes(refused.output, '"msg": "instance.container_fleet_drift"');
        assert(!g.calls().some((call) => call.startsWith("incus start")));
      } finally {
        g.cleanup();
      }
    } finally {
      f.cleanup();
    }
  },
});

// The three nested containers of the first inner fleet as they run (read from Instance 2454 on 2026-11-13, without volatile keys and
// cloud-init data), with the `club-key` device as the key move to /run sets it. step8/runbook.sh built them by hand.
const live = JSON.parse(Deno.readTextFileSync(new URL("fixtures/instance-fleet-live.json", import.meta.url)));
const tunnelUp = { addresses: ["10.45.0.10/24"], up: true, key: "/etc/wireguard/wg-nested.key", port: "51821", peer: hubKey, endpoint: "10.232.2.11:51820", allowed: "10.45.0.0/24" };
const writes = (calls: string[]) => calls.filter((call) => / config (set|device) | file push | snapshot | stop /.test(call));
// deno-lint-ignore no-explicit-any
const withStatus = (status: string): Record<string, any> =>
  Object.fromEntries(Object.entries(structuredClone(live)).map(([name, value]) => [name, { ...value as object, status }]));

Deno.test({
  name: "the live fleet passes the check and its start changes no config: running it is a no-op, stopped it only starts",
  ignore: !hasAnsible,
  fn() {
    const f = fixture(withStatus("Running"), { club: true });
    f.change((state) => state.tunnel = structuredClone(tunnelUp));
    try {
      const check = f.run("check");
      assert(check.ok, check.output);
      assertEquals(check.changed, 0);
      assertEquals(f.calls(), ["incus admin waitready --timeout 80", ...reads]);
      const start = f.run("start");
      assert(start.ok, start.output);
      assertEquals(start.changed, 0);
      assertEquals(writes(f.calls()), []);
      assertEquals(f.state().containers, withStatus("Running"));
    } finally {
      f.cleanup();
    }
    const g = fixture(withStatus("Stopped"), { club: true });
    try {
      const start = g.run("start");
      assert(start.ok, start.output);
      const calls = g.calls();
      assertEquals(calls.filter((call) => call.startsWith("incus start")), names.map((name) => `incus start ${name}`));
      assertEquals(writes(calls), []);
      assertEquals(g.state().containers, withStatus("Running"));
    } finally {
      g.cleanup();
    }
  },
});

Deno.test({
  name: "a live fleet with a foreign difference is refused by the check and by the rules before any change",
  ignore: !hasAnsible,
  fn() {
    const containers = withStatus("Running");
    // The ID map of the rules before the live shape: without 2750 and 2760 the nested Gateway cannot read the workspace.
    containers["nested-gateway"].config["raw.idmap"] = "both 1000 1000";
    const f = fixture(containers, { club: true });
    try {
      for (const phase of ["check", "rules"]) {
        const refused = f.run(phase);
        assert(!refused.ok, phase);
        assertStringIncludes(refused.output, "where Orbit does not write: nested-gateway config raw.idmap\"");
        assertStringIncludes(refused.output, '"msg": "instance.container_fleet_drift"');
        assertEquals(writes(f.calls()), [], phase);
      }
      assertEquals(f.state().containers, containers);
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a fleet built from the rules has the live shape, with boot.autostart true",
  ignore: !hasAnsible,
  fn() {
    const f = fixture(structuredClone(shapes));
    try {
      const built = f.run("rules");
      assert(built.ok, built.output);
      const state = f.state().containers;
      for (const name of names) {
        const want = structuredClone(live[name]);
        want.config["boot.autostart"] = "true";
        // The live fleet was built before the egress files; they are owned rules, which the refresh writes.
        want.files = { ...(want.files ?? {}), ...egress };
        assertEquals(state[name].devices, want.devices, name);
        assertEquals(state[name].files ?? {}, want.files ?? {}, name);
        const keys = ["raw.idmap", "boot.autostart", ...Object.keys(environment)];
        const pick = (config: Record<string, string>) => Object.fromEntries(keys.filter((key) => key in config).map((key) => [key, config[key]]));
        assertEquals(pick(state[name].config), pick(want.config), name);
      }
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "on the live fleet a refresh writes the egress files before the converge, then boot.autostart, then takes base",
  ignore: !hasAnsible,
  fn() {
    const f = fixture(withStatus("Running"), { club: true, base: true });
    // A base converge on the live fleet runs apt and downloads the agent: it fails on a Node without the egress files.
    f.change((state) => {
      state.tunnel = structuredClone(tunnelUp);
      state.egressNeeded = Object.keys(egress);
    });
    try {
      const refreshed = f.run("refresh");
      assert(refreshed.ok, refreshed.output);
      const calls = f.calls();
      const egressWrites = names.flatMap((name) => Object.keys(egress).map((path) => push(name, path)));
      assertEquals(writes(calls).filter((call) => !/ (stop|snapshot) /.test(call)),
        [...egressWrites, ...names.map((name) => `incus config set ${name} boot.autostart=true`)]);
      assert(calls.indexOf(egressWrites.at(-1)!) < calls.findIndex((call) => call.includes("/converge")), calls.join("\n"));
      for (const name of names) {
        assertEquals(f.state().containers[name].snapshots.base.config["boot.autostart"], "true");
        assertEquals(f.state().containers[name].snapshots.base.files["/etc/environment"], environmentFile);
      }
      // The egress files keep the fleet's names and networks off the proxy: a nested Node's own calls to the nested Gateway.
      for (const line of environmentFile.split("\n").filter((value) => /^no_proxy=/i.test(value))) {
        for (const local of [".orbit", "10.45.0.1", "10.45.0.0/24", "10.232.2.0/24"]) assertStringIncludes(line, local);
      }
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a refresh whose egress files are missing at the converge fails cleanly with the Node and the task, and takes no base",
  ignore: !hasAnsible,
  fn() {
    const f = fixture(withStatus("Running"), { club: true, base: true });
    f.change((state) => {
      state.tunnel = structuredClone(tunnelUp);
      state.egressNeeded = ["/etc/apt/apt.conf.d/missing-in-this-case"];
    });
    try {
      const failed = f.run("refresh");
      assert(!failed.ok);
      assertStringIncludes(failed.output, "Check the base converge of gateway");
      assertStringIncludes(failed.output, "The base converge of the nested Node gateway (Operation 901) ended failed with node.converge_failed");
      assert(!f.calls().some((call) => /^incus (snapshot|stop) /.test(call)));
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "readiness reads a healthy Doctor on the first poll; an empty, an error or no response fails with what the last poll got",
  ignore: !hasAnsible,
  fn() {
    const f = fixture(withStatus("Running"), { club: true, base: true });
    f.change((state) => state.tunnel = structuredClone(tunnelUp));
    try {
      const ready = f.run("resume");
      assert(ready.ok, ready.output);
      assertEquals(f.state().doctorCalls, 1);
      // A running fleet: the start only reads, and readiness asks Doctor once.
      // Every API call bypasses the container's proxy: without it the fixture answers as the proxy did live (503, exit 7).
      assert(f.calls().filter((call) => call.startsWith("curl ")).every((call) => call.startsWith(api)));
      const late = ["-e", "fleet_ready_seconds=2", "-e", "fleet_ready_delay=1"];
      f.change((state) => state.doctorResponse = { status: 502, body: "" });
      const empty = f.run("resume", late);
      assert(!empty.ok);
      assertStringIncludes(empty.output, "Readiness check 'doctor' failed after 2 s: HTTP 502: (empty body)");
      const body = '{"message":"Server Error","trace":"' + "x".repeat(400) + '"}';
      f.change((state) => state.doctorResponse = { status: 500, body });
      const error = f.run("resume", late);
      assert(!error.ok);
      assertStringIncludes(error.output, `Readiness check 'doctor' failed after 2 s: HTTP 500: ${body.slice(0, 120)}`);
      assert(!error.output.includes(body.slice(0, 301)), "the detail holds at most 300 characters of the body");
      f.change((state) => {
        delete state.doctorResponse;
        state.curlError = "curl: (28) Operation timed out after 80001 milliseconds with 0 bytes received";
      });
      const silent = f.run("resume", late);
      assert(!silent.ok);
      assertStringIncludes(silent.output, "Readiness check 'doctor' failed after 2 s: no response: curl: (28) Operation timed out");
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a refresh after the checkout moved migrates and restarts the nested Gateway before its only Doctor wait, and passes",
  ignore: !hasAnsible,
  fn() {
    const f = fixture(ruled(), { club: true, base: true });
    try {
      // Operation 19765: the checkout moved 47 commits under the running nested Gateway; its Doctor stays unhealthy until the
      // migrate and a restart after it. A readiness wait before them never ends.
      f.change((state) => state.schemaBehind = true);
      const started = f.run("start");
      assert(started.ok, started.output);
      const startCalls = f.calls();
      assert(!startCalls.some((call) => call.includes("/doctor") || call.includes("/nodes")), startCalls.join("\n"));
      const early = f.run("resume", ["-e", "fleet_ready_seconds=2", "-e", "fleet_ready_delay=1"]);
      assert(!early.ok, "a Doctor wait before the migrate must fail");
      assertStringIncludes(early.output, "Readiness check 'doctor' failed after 2 s:");
      assertStringIncludes(early.output, "gateway.schema_behind");
      f.calls();
      f.change((state) => state.doctorCalls = 0);
      const refreshed = f.run("refresh");
      assert(refreshed.ok, refreshed.output);
      const calls = f.calls();
      const migrate = calls.findIndex((call) => call.endsWith("php artisan migrate --force"));
      const restart = calls.findIndex((call) => call.includes("systemctl restart orbit-gateway-horizon.service"));
      const doctor = calls.findIndex((call) => call.includes("/doctor"));
      assert(migrate >= 0 && migrate < restart && restart < doctor, calls.join("\n"));
      assertEquals(calls.filter((call) => call.includes("/doctor")).length, 1);
      assert(calls.findIndex((call) => call.includes("/converge")) < doctor);
      for (const name of names) assertEquals(f.state().containers[name].snapshots.base.taken, "Stopped");
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a refresh waits for the three agents after the restart before it converges, and names the agent that stays offline",
  ignore: !hasAnsible,
  fn() {
    const f = fixture(ruled(), { base: true });
    try {
      assert(f.run("start").ok);
      f.calls();
      f.change((state) => state.agentsOffline = 2);
      const refreshed = f.run("refresh");
      assert(refreshed.ok, refreshed.output);
      const calls = f.calls();
      const firstConverge = calls.findIndex((call) => call.includes("/converge"));
      assertEquals(calls.slice(0, firstConverge).filter((call) => call.endsWith("--fail https://10.45.0.1/api/v1/nodes")).length, 3);
      const g = fixture(ruled(), { base: true });
      try {
        assert(g.run("start").ok);
        g.calls();
        g.change((state) => state.agentsOffline = -1);
        const failed = g.run("refresh", ["-e", "fleet_ready_seconds=2", "-e", "fleet_ready_delay=1"]);
        assert(!failed.ok);
        assertStringIncludes(failed.output, "The agent of app-prod was not online within 2 s after the restart of the nested Gateway");
        assert(!g.calls().some((call) => call.includes("/converge") || call.includes("snapshot")));
      } finally {
        g.cleanup();
      }
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a resume starts a stopped fleet and waits for readiness: the start after a passed refresh and after a restore",
  ignore: !hasAnsible,
  fn() {
    const f = fixture(ruled(), { club: true, base: true });
    try {
      const resumed = f.run("resume");
      assert(resumed.ok, resumed.output);
      const calls = f.calls();
      assertEquals(calls, [...reads, ...startCalls, ...reachCalls, ...readyCalls]);
      assertKeyUntouched(calls, resumed.output);
      for (const name of names) assertEquals(f.state().containers[name].status, "Running");
      assertEquals(writes(calls), []);
    } finally {
      f.cleanup();
    }
  },
});

/** A fleet refreshed once, running again: the state after a passed refresh and the next release's start. */
function refreshFromScratch() {
  const f = fixture(ruled(), { club: true, base: true });
  assert(f.run("start").ok);
  const first = f.run("refresh");
  assert(first.ok, first.output);
  assertEquals(f.refreshed()?.base, "retaken");
  assert(f.run("start").ok);
  f.calls();
  f.change((state) => state.doctorCalls = 0);
  return f;
}
/** Saved Incus boundary after one real refresh and start. The first test checks its full persistent state.
 * Regenerate `state` from persistentState(f.state()) in that test after reviewing the playbook change;
 * take each `records` value from its corresponding producer's written record, never from the digest algorithm.
 */
const refreshedBoundary = JSON.parse(Deno.readTextFileSync(new URL("fixtures/instance-fleet-refreshed.json", import.meta.url)));
// deno-lint-ignore no-explicit-any
function persistentState(state: Record<string, any>) {
  const value = structuredClone(state);
  for (const key of ["execs", "operations", "doctorCalls"]) delete value[key];
  return value;
}
function refreshedOnce() {
  const f = fixture(ruled(), { club: true, base: true });
  f.change((state) => {
    for (const key of Object.keys(state)) delete state[key];
    Object.assign(state, structuredClone(refreshedBoundary.state));
  });
  return f;
}
Deno.test({
  name: "the saved refreshed fleet boundary matches a real first refresh and start",
  ignore: !hasAnsible,
  fn() {
    const f = refreshFromScratch();
    try {
      assertEquals(persistentState(f.state()), refreshedBoundary.state);
    } finally {
      f.cleanup();
    }
  },
});
const ran = (calls: string[], part: string) => calls.some((call) => call.includes(part));
const parts = {
  migrate: "php artisan migrate --force",
  restart: "systemctl restart orbit-gateway-horizon.service",
  converge: "/converge",
  stop: "incus stop ",
  base: "incus snapshot create ",
};

Deno.test({
  name: "a refresh whose inputs did not change converges nothing, keeps the fleet running and base as it is, and still waits for readiness",
  ignore: !hasAnsible,
  fn() {
    const f = refreshedOnce();
    try {
      const before = structuredClone(f.state().containers);
      const again = f.run("refresh");
      assert(again.ok, again.output);
      const calls = f.calls();
      for (const part of Object.values(parts)) assert(!ran(calls, part), `${part} ran: ${calls.join("\n")}`);
      // The agent delivery, the grants, the rules read and one Doctor wait run every time.
      assert(ran(calls, "frozen-manifest.json") && ran(calls, "--tags grants") && ran(calls, "/doctor"), calls.join("\n"));
      assertEquals(calls.at(-1), recordWrite);
      for (const name of names) {
        assertEquals(f.state().containers[name].status, "Running");
        assertEquals(f.state().containers[name].snapshots.base, before[name].snapshots.base);
      }
      const result = f.refreshed()!;
      assertEquals({ ...result, seconds: undefined }, { migrate: "skipped", restart: "no", converge: "skipped", changed: 0, base: "kept",
        base_containers: [], record: "present", seconds: undefined });
      assertEquals(Object.keys(result.seconds as object), ["gateway", "converge", "ready", "base"]);
      assertEquals(f.state().outer.modes[record], "root:root 0644");
      assertEquals(JSON.parse(f.state().outer.files[record]), {
        code: JSON.parse(f.state().outer.files[record]).code, converge: JSON.parse(f.state().outer.files[record]).converge });
      assertEquals(Object.keys(JSON.parse(f.state().outer.files[record])), ["code", "converge"]);
    } finally {
      f.cleanup();
    }
  },
});

// deno-lint-ignore no-explicit-any
const inputCases: [string, (state: Record<string, any>) => void, string[], string, string][] = [
  // A package's migration (the Composer lock in `apps/gateway`) moves the code digest only; the migrate applies it.
  ["the code with a pending migration migrates, restarts, converges and retakes base of the nested Gateway", (state) => {
    state.digests = { "apps/gateway": "a9", swamp: "c3" };
    state.pendingMigration = true;
  }, ["migrate", "restart", "converge", "stop", "base"], "retaken", "code"],
  ["the code without a pending migration migrates, restarts and converges, and keeps base",
    (state) => state.digests = { "apps/gateway": "a9", swamp: "c3" }, ["migrate", "restart", "converge"], "kept", "code"],
  ["swamp converges", (state) => state.digests = { "apps/gateway": "a1", swamp: "c9" }, ["converge"], "kept", "swamp"],
  // The nested Gateway reads the agent contract from outside `apps/gateway`: it is part of the code.
  ["the agent contract migrates, restarts and converges",
    (state) => state.digests = { "apps/gateway": "a1", "apps/agent/contract": "d9", swamp: "c3" }, ["migrate", "restart", "converge"], "kept", "agent"],
];
for (const [input, change, due, base, recorded] of inputCases) {
  Deno.test({
    name: `a changed input runs only its due parts: ${input}`,
    ignore: !hasAnsible,
    fn() {
      const f = refreshedOnce();
      try {
        f.change(change);
        const refreshed = f.run("refresh");
        assert(refreshed.ok, refreshed.output);
        const calls = f.calls();
        for (const [part, call] of Object.entries(parts)) assertEquals(ran(calls, call), due.includes(part), `${part}\n${calls.join("\n")}`);
        assertEquals(f.refreshed()?.base, base);
        // This exact record is the next case's boundary, not a digest calculated by the test.
        assertEquals(JSON.parse(f.state().outer.files[record]), refreshedBoundary.records[recorded]);
      } finally {
        f.cleanup();
      }
    },
  });
  Deno.test({
    name: `the next refresh repeats no part: ${input}`,
    ignore: !hasAnsible,
    fn() {
      const f = refreshedOnce();
      try {
        f.change((state) => {
          change(state);
          delete state.pendingMigration;
          state.outer.files[record] = JSON.stringify(refreshedBoundary.records[recorded]);
        });
        assert(f.run("start").ok);
        f.calls();
        assert(f.run("refresh").ok);
        const next = f.calls();
        for (const call of Object.values(parts)) assert(!ran(next, call), `${call} ran again`);
      } finally {
        f.cleanup();
      }
    },
  });
}

Deno.test({
  name: "a changed input alone runs its own part, once: the fleet intent converges, and its files retake base",
  ignore: !hasAnsible,
  fn() {
    const f = refreshedOnce();
    try {
      f.intent((intent) => intent.fleet.proxy.HTTP_PROXY = "http://10.253.0.1:3129");
      const refreshed = f.run("refresh");
      assert(refreshed.ok, refreshed.output);
      const calls = f.calls();
      assert(ran(calls, parts.converge) && !ran(calls, parts.migrate) && !ran(calls, parts.restart), calls.join("\n"));
      assertEquals(f.refreshed()?.base, "retaken");
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a converge with changed tasks retakes base only for the changed Node and Gateway",
  ignore: !hasAnsible,
  fn() {
    const f = refreshedOnce();
    try {
      f.change((state) => {
        state.digests = { "apps/gateway": "a1", swamp: "c9" };
        state.convergeChanged = { 2: 4 };
      });
      const refreshed = f.run("refresh");
      assert(refreshed.ok, refreshed.output);
      const takes = (calls: string[]) => calls.filter((call) => call.startsWith("incus snapshot create "));
      // The changed Node and the nested Gateway, whose database records the converge; app-prod keeps running and its base.
      assertEquals(takes(f.calls()), ["nested-gateway", "nested-app-dev"].map((name) => `incus snapshot create ${name} base`));
      assertEquals(f.state().containers["nested-app-prod"].status, "Running");
      const result = f.refreshed()!;
      assertEquals([result.converge, result.changed, result.base, result.base_containers], ["ran", 4, "retaken", ["nested-gateway", "nested-app-dev"]]);
    } finally {
      f.cleanup();
    }
  },
});
Deno.test({
  name: "a converge without change counts retakes all three bases",
  ignore: !hasAnsible,
  fn() {
    const f = refreshedOnce();
    try {
      const takes = (calls: string[]) => calls.filter((call) => call.startsWith("incus snapshot create "));
      assert(f.run("start").ok);
      f.change((state) => {
        state.digests = { "apps/gateway": "a1", swamp: "c8" };
        delete state.convergeChanged;
        state.convergeNoCount = true;
      });
      f.calls();
      assert(f.run("refresh").ok);
      assertEquals(takes(f.calls()), names.map((name) => `incus snapshot create ${name} base`));
      const counted = f.refreshed()!;
      assertEquals([counted.changed, counted.base], [3, "retaken"]);
    } finally {
      f.cleanup();
    }
  },
});
Deno.test({
  name: "a missing base is retaken without another converge",
  ignore: !hasAnsible,
  fn() {
    const f = refreshedOnce();
    try {
      const takes = (calls: string[]) => calls.filter((call) => call.startsWith("incus snapshot create "));
      assert(f.run("start").ok);
      f.change((state) => delete state.containers["nested-app-prod"].snapshots.base);
      f.calls();
      assert(f.run("refresh").ok);
      const calls = f.calls();
      assert(!ran(calls, parts.converge), calls.join("\n"));
      assertEquals(takes(calls), ["incus snapshot create nested-app-prod base"]);
    } finally {
      f.cleanup();
    }
  },
});

function failedRefreshBoundary() {
  const state = structuredClone(refreshedBoundary.state);
  state.digests = { "apps/gateway": "a1", swamp: "c9" };
  delete state.outer.files[record];
  return state;
}

Deno.test({
  name: "a failed refresh that changed a Node removes its old record and writes none",
  ignore: !hasAnsible,
  fn() {
    const f = refreshedOnce();
    try {
      // Run 1 changes app-dev, then the app-prod converge fails: nothing is recorded and the old record is gone.
      f.change((state) => {
        state.digests = { "apps/gateway": "a1", swamp: "c9" };
        state.convergeChanged = { 2: 3 };
        state.convergeStatus = { 3: "failed" };
      });
      assert(!f.run("refresh").ok);
      const failed = f.calls();
      assert(failed.includes(recordRemove) && !failed.includes(recordWrite), failed.join("\n"));
      assertEquals(f.state().outer.files[record], undefined);
      const recovery = persistentState(f.state());
      delete recovery.convergeStatus;
      delete recovery.convergeChanged;
      assertEquals(recovery, failedRefreshBoundary());
    } finally {
      f.cleanup();
    }
  },
});
Deno.test({
  name: "a refresh after a failed run converges every part and retakes all three bases",
  ignore: !hasAnsible,
  fn() {
    const f = refreshedOnce();
    try {
      // The failed-refresh case proves this boundary: no record survives a failed converge.
      f.change((state) => Object.assign(state, failedRefreshBoundary()));
      assert(f.run("start").ok);
      f.calls();
      assert(f.run("refresh").ok);
      const calls = f.calls();
      assert(ran(calls, parts.converge) && calls.at(-1) === recordWrite, calls.join("\n"));
      const result = f.refreshed()!;
      assertEquals([result.record, result.changed, result.base, result.base_containers], ["missing", 0, "retaken", names]);
    } finally {
      f.cleanup();
    }
  },
});

// deno-lint-ignore no-explicit-any
const recordCases: [string, (state: Record<string, any>) => void, string, boolean, string][] = [
  // A record that cannot be trusted also retakes base of all three.
  ["a deleted record", (state) => delete state.outer.files[record], "missing", true, "retaken"],
  ["a malformed record", (state) => state.outer.files[record] = '{"code": "a1", "converge": ', "unreadable", true, "retaken"],
  ["an unreadable record", (state) => state.outer.unreadable = [record], "unreadable", true, "retaken"],
  // Without the checkout's digests every part runs and nothing is recorded, so the next refresh runs every part again. The record
  // that is there is still the fleet's, so base follows what the parts changed: nothing here.
  ["a failed digest read", (state) => state.digestsFail = true, "present", false, "kept"],
];
for (const [name, change, recordState, writes, base] of recordCases) {
  Deno.test({
    name: `${name} runs every part and never fails the refresh`,
    ignore: !hasAnsible,
    fn() {
      const f = refreshedOnce();
      try {
        f.change(change);
        const refreshed = f.run("refresh");
        assert(refreshed.ok, refreshed.output);
        const calls = f.calls();
        for (const part of ["migrate", "restart", "converge"] as const) assert(ran(calls, parts[part]), `${part} did not run`);
        for (const part of ["stop", "base"] as const) assertEquals(ran(calls, parts[part]), base === "retaken", part);
        const result = f.refreshed()!;
        assertEquals([result.record, result.base, result.migrate, result.converge, result.restart], [recordState, base, "nothing", "ran", "yes"]);
        assertEquals(result.base_containers, base === "retaken" ? names : []);
        assertEquals(calls.at(-1) === recordWrite, writes);
      } finally {
        f.cleanup();
      }
    },
  });
}

Deno.test({
  name: "a refresh whose migrate applied one retakes base of the nested Gateway alone, and a reset after it comes up on the new schema",
  ignore: !hasAnsible,
  fn() {
    const f = refreshedOnce();
    try {
      const before = structuredClone(f.state().containers);
      f.change((state) => {
        state.digests = { "apps/gateway": "a9", swamp: "c3" };
        state.pendingMigration = true;
      });
      const refreshed = f.run("refresh");
      assert(refreshed.ok, refreshed.output);
      const calls = f.calls();
      assertEquals(calls.filter((call) => /^incus (stop|snapshot create) /.test(call)),
        ["incus stop nested-gateway --timeout 90", "incus snapshot create nested-gateway base"]);
      const state = f.state();
      assertEquals(state.containers["nested-gateway"].snapshots.base.files["/home/orbit/.orbit/schema"], "1");
      for (const name of ["nested-app-dev", "nested-app-prod"]) {
        assertEquals(state.containers[name].status, "Running");
        assertEquals(state.containers[name].snapshots.base, before[name].snapshots.base);
      }
      assertEquals(f.refreshed()?.base_containers, ["nested-gateway"]);
      // A proof's reset restores base of all three and comes up on the new schema.
      f.change((value) => value.containers["nested-gateway"].files["/home/orbit/.orbit/schema"] = "0");
      const reset = f.run("reset");
      assert(reset.ok, reset.output);
      assertEquals(f.state().containers["nested-gateway"].files["/home/orbit/.orbit/schema"], "1");
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a grants reload that changed a file retakes base of the nested Gateway alone, whose disk holds the grants",
  ignore: !hasAnsible,
  fn() {
    const f = refreshedOnce();
    try {
      f.change((state) => state.grantsChanged = 2);
      const refreshed = f.run("refresh");
      assert(refreshed.ok, refreshed.output);
      const calls = f.calls();
      assert(!ran(calls, parts.converge) && !ran(calls, parts.migrate), calls.join("\n"));
      assertEquals(calls.filter((call) => /^incus (stop|snapshot create) /.test(call)),
        ["incus stop nested-gateway --timeout 90", "incus snapshot create nested-gateway base"]);
      assertEquals(f.refreshed()?.base_containers, ["nested-gateway"]);
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "on a partly stopped fleet the rules only compare: a rule that differs on a running container fails the refresh and writes nothing",
  ignore: !hasAnsible,
  fn() {
    const f = refreshedOnce();
    try {
      f.change((state) => {
        state.grantsChanged = 1;
        state.driftOnStop = { container: "nested-app-prod", device: "apparmor-off" };
      });
      const failed = f.run("refresh");
      assert(!failed.ok);
      assertStringIncludes(failed.output, "The nested fleet differs from its rules while only nested-gateway stopped: nested-app-prod device apparmor-off");
      const calls = f.calls();
      assert(!calls.some((call) => call.startsWith("incus config device") || call.startsWith("incus snapshot create")), calls.join("\n"));
      assert(!calls.includes(recordWrite));
    } finally {
      f.cleanup();
    }
  },
});
