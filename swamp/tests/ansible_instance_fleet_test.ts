/**
 * Run the fleet step of a nested-fleet Instance container (swamp/ansible/tasks/instance-fleet-*.yml) against a stateful Incus boundary:
 * the Node reaches the Instance container with `incus exec`, and the inner Incus holds the three nested containers in the device and
 * configuration shape exported from a task base. The connection is local and nothing becomes root.
 */
import { assert, assertEquals, assertStringIncludes } from "jsr:@std/assert@1";

const hasAnsible = new Deno.Command("sh", { args: ["-c", "command -v ansible-playbook"] }).outputSync().success;
const root = new URL("../ansible/", import.meta.url);
const shapes = JSON.parse(Deno.readTextFileSync(new URL("fixtures/instance-fleet-containers.json", import.meta.url)));
const phases = ["rules", "rules-read", "start", "ready", "refresh", "converge-node", "reset", ""];
const guests = { gateway: "nested-gateway", "app-dev": "nested-app-dev", "app-prod": "nested-app-prod" };
const names = Object.values(guests);
const outer = ["--project", "orbit-instances-nested", "exec", "instance-9", "--"];
const clubKey = "/run/orbit-club/swamp-club-api-key";
const clubValue = "club-key-value-that-never-leaves-the-file";
const proxy = {
  HTTP_PROXY: "http://10.253.0.1:3128",
  HTTPS_PROXY: "http://10.253.0.1:3128",
  http_proxy: "http://10.253.0.1:3128",
  https_proxy: "http://10.253.0.1:3128",
  NO_PROXY: "localhost,127.0.0.1,.orbit,10.45.0.0/24,10.232.2.0/24",
  no_proxy: "localhost,127.0.0.1,.orbit,10.45.0.0/24,10.232.2.0/24",
};
const dropIn = '[Service]\n' + Object.entries(proxy).map(([key, value]) => `Environment="${key}=${value}"`).join("\n") + "\n";
const environment = Object.fromEntries(Object.entries(proxy).map(([key, value]) => [`environment.${key}`, value]));
const units = ["/etc/systemd/system/orbit-swamp-serve.service.d/orbit-proxy.conf", "/etc/systemd/system/orbit-swamp-worker@.service.d/orbit-proxy.conf"];

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
  for (const name of ["nested-gateway", "nested-app-dev"]) containers[name].config["raw.idmap"] = "both 1000 1000";
  for (const name of names) Object.assign(containers[name].config, environment);
  containers["nested-gateway"].files = Object.fromEntries(units.map((unit) => [unit, dropIn]));
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
    containers, tunnel: null, hubKey: "HUBPUB=", nodes,
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
  ...units.map((unit) => `incus file pull nested-gateway${unit} -`),
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
  "wg set wg-nested private-key /etc/wireguard/wg-nested.key listen-port 51821 peer HUBPUB= endpoint 10.232.2.11:51820 allowed-ips 10.45.0.0/24 persistent-keepalive 25",
  "ip -j addr show dev wg-nested",
  "ip addr add 10.45.0.10/24 dev wg-nested",
  "ip link set wg-nested up",
];
const readyCalls = [
  "incus list --format csv --columns ns",
  "ping -c 1 -W 2 10.45.0.1",
  "curl -sk -m 80 -H Host: gateway.orbit -H Accept: application/json -X POST https://10.45.0.1/api/v1/doctor -H Content-Type: application/json -d {}",
  "curl -sk -m 80 -H Host: gateway.orbit -H Accept: application/json --fail https://10.45.0.1/api/v1/nodes",
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
          [...(name === "nested-app-prod" ? [] : ["raw.idmap=both 1000 1000"]), ...proxyKeys].map((key) => `incus config set ${name} ${key}`)),
        ...units.map((unit) => `incus file push --create-dirs --mode 0644 - nested-gateway${unit}`),
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
  name: "a start without the Club key starts the three containers, brings up wg-nested with ip and wg, and waits for readiness; a rerun only reads",
  ignore: !hasAnsible,
  fn() {
    const f = fixture(ruled());
    f.change((state) => state.doctorUnhealthy = 2);
    try {
      const first = f.run("start");
      assert(first.ok, first.output);
      const calls = f.calls();
      assertEquals(calls, [...reads, ...startCalls, ...readyCalls.slice(0, 3), readyCalls[2], readyCalls[2], readyCalls[3]]);
      assertKeyUntouched(calls, first.output);
      assert(!calls.some((call) => call.includes("wg-quick")));
      assertEquals(f.state().containers["nested-gateway"].links, { "/home/orbit/.config/swamp/api-key": "club/swamp-club-api-key" });
      assertEquals(f.state().tunnel, { addresses: ["10.45.0.10/24"], up: true, key: "/etc/wireguard/wg-nested.key", port: "51821", peer: "HUBPUB=", endpoint: "10.232.2.11:51820", allowed: "10.45.0.0/24" });
      const again = f.run("start");
      assert(again.ok, again.output);
      assertEquals(again.changed, 0);
      assertEquals(f.calls(), [...reads, "incus list --format csv --columns ns", "incus exec nested-gateway -- readlink /home/orbit/.config/swamp/api-key", "test -s /etc/wireguard/wg-nested.key",
        "incus exec nested-gateway -- wg show orbit public-key", "wg show wg-nested", "ip -j addr show dev wg-nested", ...readyCalls]);
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a start that fails names the nested container, and a readiness timeout names the check",
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
      const late = f.run("start", ["-e", "fleet_ready_seconds=3", "-e", "fleet_ready_delay=1"]);
      assert(!late.ok);
      assertStringIncludes(late.output, "Readiness check 'doctor' failed after 3 s:");
      assertStringIncludes(late.output, "node.agent_view_stale");
      f.change((state) => {
        state.doctorUnhealthy = 0;
        state.nodes[2].agent.state = "offline";
      });
      const offline = f.run("start");
      assert(!offline.ok);
      assertStringIncludes(offline.output, "Readiness check 'agents' failed: the agent of app-prod is not online");
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a fleet without its rules is refused with the container and the rule, before any start",
  ignore: !hasAnsible,
  fn() {
    const f = fixture(structuredClone(shapes));
    try {
      const refused = f.run("start");
      assert(!refused.ok);
      assertStringIncludes(refused.output, "The nested fleet lacks its rules: nested-gateway device checkout, nested-gateway device club-key, nested-gateway config raw.idmap,");
      assertStringIncludes(refused.output, "nested-app-prod device apparmor-off");
      assertStringIncludes(refused.output, "nested-gateway unit /etc/systemd/system/orbit-swamp-worker@.service.d/orbit-proxy.conf");
      assertEquals(f.calls(), reads);
      const one = ruled();
      delete one["nested-app-dev"].devices["apparmor-off"];
      const g = fixture(one);
      try {
        const missing = g.run("start");
        assert(!missing.ok);
        assertStringIncludes(missing.output, "The nested fleet lacks its rules: nested-app-dev device apparmor-off\"");
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
      // A device the rules own went missing while the fleet ran: the refresh puts it back before it takes base.
      f.change((state) => delete state.containers["nested-app-prod"].devices["apparmor-off"]);
      const refreshed = f.run("refresh");
      assert(refreshed.ok, refreshed.output);
      const calls = f.calls();
      const exec = (name: string, command: string) => `incus exec ${name} -- ${command}`;
      const api = "curl -sk -m 80 -H Host: gateway.orbit -H Accept: application/json";
      const services = ["orbit-gateway-horizon.service", "orbit-gateway-schedule.service", "orbit-agent-view.service"];
      const mounts = "home-orbit-orbit-apps-gateway-.env.mount home-orbit-orbit-apps-gateway-storage.mount home-orbit-orbit-apps-gateway-bootstrap-cache.mount";
      assertEquals(calls, [
        `test -s ${clubKey}`,
        exec("nested-gateway", `systemctl is-active ${mounts}`),
        exec("nested-app-dev", `systemctl is-active ${mounts}`),
        "incus exec nested-gateway --user 1000 -- install -D -m 0444 /home/orbit/orbit/apps/gateway/resources/agent/frozen-manifest.json /home/orbit/orbit/apps/agent/delivery/manifest.json",
        exec("nested-gateway", "env ANSIBLE_CONFIG=/home/orbit/orbit/swamp/ansible/ansible.cfg ANSIBLE_DISPLAY_FAILED_STDERR=true /opt/orbit/ansible/venv/bin/ansible-playbook -i localhost, -e @/opt/orbit/bootstrap/control.yml --tags grants /home/orbit/orbit/swamp/ansible/gateway-bootstrap.yml"),
        "incus exec nested-gateway --user 1000 --cwd /home/orbit/orbit/apps/gateway -- php artisan migrate --force",
        exec("nested-gateway", `systemctl restart ${services.join(" ")}`),
        ...[...services, "orbit-swamp-serve.service", "orbit-swamp-worker@a.service", "orbit-swamp-worker@b.service"]
          .map((unit) => exec("nested-gateway", `systemctl is-active --quiet ${unit}`)),
        `${api} --fail https://10.45.0.1/api/v1/nodes`,
        ...names.map((name) => exec(name, "systemctl stop apt-daily.timer apt-daily-upgrade.timer")),
        ...names.map((name) => exec(name, "systemctl show --property=ActiveState --value apt-daily.service apt-daily-upgrade.service")),
        ...[1, 2, 3].flatMap((node) => [
          `${api} --fail -X POST https://10.45.0.1/api/v1/nodes/${node}/converge -H Content-Type: application/json -d {"base":true}`,
          `${api} --fail https://10.45.0.1/api/v1/operations/${900 + node}`,
        ]),
        ...readyCalls,
        ...names.map((name) => `incus stop ${name}`),
        "incus list --format csv --columns ns",
        ...reads,
        "incus config device add nested-app-prod apparmor-off disk source=/etc/orbit/apparmor-off path=/sys/module/apparmor/parameters/enabled readonly=true",
        ...reads,
        ...names.map((name) => `incus query /1.0/instances/${name}/snapshots?recursion=1`),
        ...names.map((name) => `incus snapshot delete ${name} base`),
        ...names.map((name) => `incus snapshot create ${name} base`),
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
      assert(!calls.some((call) => call.includes("ansible-playbook") || call.includes("orbit-swamp")), calls.join("\n"));
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
        ...readyCalls,
      ]);
      assertKeyUntouched(calls, reset.output);
      assertEquals(f.state().containers["nested-app-dev"].devices.checkout.shift, "false");
      const g = fixture(structuredClone(shapes), { base: true });
      try {
        const refused = g.run("reset");
        assert(!refused.ok);
        assertStringIncludes(refused.output, "The nested fleet lacks its rules: nested-gateway device checkout");
        assert(!g.calls().some((call) => call.startsWith("incus start")));
      } finally {
        g.cleanup();
      }
    } finally {
      f.cleanup();
    }
  },
});
