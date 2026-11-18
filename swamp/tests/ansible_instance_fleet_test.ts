/**
 * Run the fleet step of a nested-fleet Instance container (swamp/ansible/tasks/instance-fleet-*.yml) against a stateful Incus boundary:
 * the Node reaches the Instance container with `incus exec`, and the inner Incus holds the three nested containers in the device and
 * configuration shape exported from a task base. The connection is local and nothing becomes root.
 */
import { assert, assertEquals, assertStringIncludes } from "jsr:@std/assert@1";
import { parse } from "jsr:@std/yaml@1";
import { createHash } from "node:crypto";

const hasAnsible = new Deno.Command("sh", { args: ["-c", "command -v ansible-playbook"] }).outputSync().success;
const root = new URL("../ansible/", import.meta.url);
const shapes = JSON.parse(Deno.readTextFileSync(new URL("fixtures/instance-fleet-containers.json", import.meta.url)));
const phases = ["check", "rules", "rules-read", "files", "start", "proxy", "reach", "ready", "refresh", "mounts", "room", "migrate", "known-hosts", "converge-node", "reset", "resume", ""];
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
/** The Instance container's own proxy bypass (InstanceNodeServiceTargets::proxyEnvironment): the image's base and the inner fleet. */
const outerBypass = "localhost,127.0.0.1,::1,10.253.0.1,10.45.0.1,10.45.0.0/24,10.232.2.0/24";
const dropIn = "[Service]\nEnvironment=" + Object.entries(proxy).map(([key, value]) => `"${key}=${value}"`).join(" ") + "\n";
const environment = Object.fromEntries(Object.entries(proxy).map(([key, value]) => [`environment.${key}`, value]));
const units = ["/etc/systemd/system/orbit-swamp-serve.service.d/50-inner-fleet-proxy.conf", "/etc/systemd/system/orbit-swamp-worker@.service.d/50-inner-fleet-proxy.conf",
  "/etc/systemd/system/orbit-gateway-horizon.service.d/50-inner-fleet-proxy.conf"];
/** The Gateway's PHP-FPM pool `orbit-gateway` gets the egress proxy from a file of its own: php-fpm merges a repeated pool section. */
const pool = "/etc/php/8.5/fpm/pool.d/zz-orbit-nested-egress.conf";
const poolFile = "; Managed by Orbit: the fleet's egress proxy for the Gateway's pool (docs/reference/development-containers.md#fleet-rules).\n[orbit-gateway]\n" +
  Object.entries(proxy).map(([key, value]) => `env[${key}] = "${value}"`).join("\n") + "\n";
const idmap = "both 1000 1000\nuid 2750 2750\ngid 2760 2760";
/** The egress files of every nested container: apt and sudo and SSH sessions reach the internet only through the egress proxy. */
const aptFile = `Acquire::http::Proxy "${proxy.HTTP_PROXY}";\nAcquire::https::Proxy "${proxy.HTTPS_PROXY}";\n`;
const environmentFile = 'PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:/usr/games:/usr/local/games:/snap/bin"\n' +
  Object.entries(proxy).map(([key, value]) => `${key}="${value}"`).join("\n") + "\n";
/** The size files of every nested container: apt keeps no package file, and journald keeps 64 MiB. */
const sizeFiles = {
  "/etc/apt/apt.conf.d/91orbit-nested-package-files": 'APT::Keep-Downloaded-Packages "false";\n',
  "/etc/systemd/journald.conf.d/zz-orbit-nested-journal.conf": "[Journal]\nSystemMaxUse=64M\n",
};
/** The fleet's own apt file: Orbit's Node plays own `90orbit-package-cache` and never touch it. */
const aptPath = "/etc/apt/apt.conf.d/95orbit-nested-egress";
/** dockerd reads no /etc/environment: a Database server's image pull on a nested Node needs the proxy in its own unit drop-in. */
const dockerDropIn = "/etc/systemd/system/docker.service.d/50-inner-fleet-proxy.conf";
const egress = { [aptPath]: aptFile, "/etc/environment": environmentFile, [dockerDropIn]: dropIn };
/** Git in every nested container trusts the checkout, which `agent` owns (ADR 0268): without it Git as `orbit` stops with "dubious ownership". */
const gitFiles = { "/etc/gitconfig": "# Managed by Orbit.\n[safe]\n\tdirectory = /home/orbit/orbit\n" };
/** Every file the fleet rules own in each nested container: the egress files, the size files, then the Git file. */
const owned = { ...egress, ...sizeFiles, ...gitFiles };
/** The marker of the nested Gateway of an Instance fleet (InstanceFleetGateway::Marker): its Nodes get no task VM package cache. */
const marker = "/etc/orbit/instance-fleet";
const markerFile = "# Managed by Orbit: this Gateway leads the nested fleet of an Instance container (docs/reference/development-containers.md#fleet-rules).\n";
/** nested-app-dev is an Instance host (development-containers.md#instance-host-in-the-fleet): its nesting keys. */
const instanceHostKeys = { "security.syscalls.intercept.mknod": "true", "security.syscalls.intercept.setxattr": "true" };
/** The files each container owns: the egress and size files and the Git file. The container branch writes nested-app-dev's Incus drop-in. */
const ownedBy = (_name: string) => owned;
/** The memory rule of each nested container (NestedFleetIntent::Memory): app-dev hosts Instances, app-prod serves none. */
const memory: Record<string, string> = { "nested-gateway": "4GiB", "nested-app-dev": "5GiB", "nested-app-prod": "2GiB" };
/** Each file rule in the order the rules read it: the nested Gateway's drop-ins and marker, then the owned files of each container. */
const fileRules: [string, string][] = [
  ...units.map((unit): [string, string] => ["nested-gateway", unit]),
  ["nested-gateway", pool],
  ["nested-gateway", marker],
  ...names.flatMap((name) => Object.keys(ownedBy(name)).map((path): [string, string] => [name, path])),
];
/** The inner bridge as the seed's first fleet has it, and with its rule: its dnsmasq has no upstream. */
const exportedBridge = { "ipv4.address": "10.232.2.1/24", "ipv4.nat": "true", "ipv6.address": "none" };
const ruledBridge = { ...exportedBridge, "raw.dnsmasq": "no-resolv" };
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
  containers["nested-app-prod"].devices.checkout = checkout;
  containers["nested-app-prod"].devices["apparmor-off"] = apparmor;
  for (const name of names) containers[name].config["raw.idmap"] = idmap;
  for (const name of names) Object.assign(containers[name].config, environment);
  containers["nested-gateway"].files = { ...Object.fromEntries(units.map((unit) => [unit, dropIn])), [pool]: poolFile, [marker]: markerFile };
  Object.assign(containers["nested-app-dev"].config, instanceHostKeys);
  for (const name of names) Object.assign(containers[name].config, { "limits.memory": memory[name], "limits.memory.swap": memory[name] });
  for (const name of names) containers[name].files = { ...(containers[name].files ?? {}), ...ownedBy(name) };
  return containers;
}

function fixture(containers: Record<string, Record<string, unknown>>, options: { club?: boolean; base?: boolean; bridge?: Record<string, string>; host?: boolean } = {}) {
  const folder = Deno.makeTempDirSync();
  Deno.mkdirSync(`${folder}/bin`);
  Deno.mkdirSync(`${folder}/tasks`);
  Deno.mkdirSync(`${folder}/run`);
  for (const phase of phases) {
    const name = phase === "" ? "instance-fleet.yml" : `instance-fleet-${phase}.yml`;
    Deno.copyFileSync(new URL(`tasks/${name}`, root), `${folder}/tasks/${name}`);
  }
  // The fleet's proxy bypass sends instance_node_services.py `proxy` into the container; the play's folder holds it, and the list
  // of converge inputs the refresh hashes.
  Deno.copyFileSync(new URL("instance_node_services.py", root), `${folder}/instance_node_services.py`);
  Deno.copyFileSync(new URL("fleet-converge-inputs.json", root), `${folder}/fleet-converge-inputs.json`);
  // The refresh sends instance_shape_tools.py into the container the same way.
  Deno.copyFileSync(new URL("instance_shape_tools.py", root), `${folder}/instance_shape_tools.py`);
  Deno.copyFileSync(new URL("instance_fleet_shrink.py", root), `${folder}/instance_fleet_shrink.py`);
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
    containers, network: { name: "oe-nested", config: { ...(options.bridge ?? ruledBridge) } }, tunnel: null, hubKey, nodes,
  }));
  Deno.writeTextFileSync(`${folder}/run/intent.json`, JSON.stringify({
    container: { project: "orbit-instances-nested", name: "instance-9", workspace: "/srv/workspace",
      config: { "environment.NO_PROXY": outerBypass, "environment.no_proxy": outerBypass }, clone: { t3: { unit: "t3.service" } },
      // Admitted at 22:59:00Z; the fake's container last started at 23:00:00Z, inside the Operation.
      bridge_admitted_at: 1794610740000000 },
    fleet: {
      guests,
      layout: {
        gateway: { address: "10.232.2.11", wireguard: "10.45.0.1", memory: "4GiB" },
        "app-dev": { address: "10.232.2.12", wireguard: "10.45.0.2", memory: "5GiB" },
        "app-prod": { address: "10.232.2.13", wireguard: "10.45.0.3", memory: "2GiB" },
      },
      host: options.host ?? true,
      operator: { address: "10.45.0.10", hub: "10.45.0.1", network: "10.45.0.0/24", domains: ["nested-dev", "nested-prod"] },
      checkout: { path: "/home/orbit/orbit" },
      proxy,
    },
  }));
  const state = () => JSON.parse(Deno.readTextFileSync(`${folder}/state.json`));
  return {
    state,
    /** Select shipped phase callers, preserving their order, vars, conditions and loops. */
    scopePhase(phase: "start" | "refresh", selection: string[] | { through: string; then?: string[] }, resultFields: string[] = []) {
      const path = `${folder}/tasks/instance-fleet-${phase}.yml`;
      // deno-lint-ignore no-explicit-any
      const tasks = parse(Deno.readTextFileSync(path)) as Record<string, any>[];
      let selected;
      if (Array.isArray(selection)) {
        selected = tasks.filter((task) => selection.includes(task.name));
        assertEquals(selected.map((task) => task.name), selection);
      } else {
        assertEquals(tasks.filter((task) => task.name === selection.through).length, 1);
        selected = tasks.slice(0, tasks.findIndex((task) => task.name === selection.through) + 1);
        const remaining = tasks.slice(selected.length).filter((task) => selection.then?.includes(task.name));
        assertEquals(remaining.map((task) => task.name), selection.then ?? []);
        selected.push(...remaining);
      }
      if (resultFields.length) {
        const reports = selected.filter((task) => task.name === "Name what the refresh did");
        assertEquals(reports.length, 1);
        const result = reports[0]["ansible.builtin.set_fact"].fleet_refresh_result;
        for (const field of resultFields) assert(Object.hasOwn(result, field), field);
        // Keep the shipped expressions for this subsystem, without inventing unrelated facts for the full report.
        reports[0]["ansible.builtin.set_fact"].fleet_refresh_result = Object.fromEntries(resultFields.map((field) => [field, result[field]]));
      }
      Deno.writeTextFileSync(path, JSON.stringify(selected));
    },
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
    /** Run a phase; its waits poll at once unless `ownDelay` keeps the delay that the phase sets. */
    run(phase: string, extra: string[] = [], options: { ownDelay?: boolean } = {}) {
      const result = new Deno.Command("ansible-playbook", {
        args: ["-i", "localhost,", "-e", "ansible_connection=local", "-e", "ansible_python_interpreter={{ ansible_playbook_python }}",
          "-e", `phase=${phase}`, ...(options.ownDelay ? [] : ["-e", "fleet_ready_delay=0"]), ...extra, `${folder}/play.yml`],
        env: {
          HOST_FIXTURE: folder,
          PATH: `${folder}/bin:${Deno.env.get("PATH")}`,
          ANSIBLE_NOCOLOR: "1",
          // Run the same modules over the local connection without staging each one in a temporary file.
          ANSIBLE_PIPELINING: "true",
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
  "incus query /1.0/networks/oe-nested",
  ...fileRules.map(([name, path]) => `incus file pull ${name}${path} -`),
];
/** A comparison that only refuses (the check, the start) reads no file: every file rule is owned and passes it. */
const compareReads = reads.slice(0, names.length + 1);
/** A start, which is also a deployment's one fleet check, waits for the inner Incus and compares the rules first. */
const startReads = ["incus admin waitready --timeout 80", ...compareReads];
const instanceHostReads = names.map((name) => `incus exec ${name} -- test -f /etc/orbit/instance-fleet-node`);
const knownHostsList = "incus exec nested-gateway --cwd /home/orbit/orbit/apps/gateway -- runuser -u orbit -- php artisan list --raw --no-interaction";
const knownHostsSync = "incus exec nested-gateway --cwd /home/orbit/orbit/apps/gateway -- runuser -u orbit -- php artisan gateway:known-hosts:sync --no-interaction";
const startCalls = [
  "incus list --format csv --columns ns",
  ...names.map((name) => `incus start ${name}`),
  ...instanceHostReads,
  knownHostsList, knownHostsSync,
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
  "resolvectl dns wg-nested",
  "resolvectl dns wg-nested 10.45.0.1",
  "resolvectl domain wg-nested",
  "resolvectl domain wg-nested ~nested-dev ~nested-prod",
];
/** A start reads the fleet's DNS on `wg-nested` and sets only what differs. */
const tunnelDnsReads = ["resolvectl dns wg-nested", "resolvectl domain wg-nested"];
/** What a start promises: the containers run and the tunnel reaches the hub; no API call. */
const reachCalls = ["incus list --format csv --columns ns", "ping -c 1 -W 2 10.45.0.1"];
/** The operator's API calls never use the container's proxy, and keep curl's error. */
const api = "curl -sSk --noproxy * -m 80 -H Host: gateway.orbit -H Accept: application/json";
/** The refresh reads its record and the checkout's digests first, and writes the record last (instance-fleet-refresh.yml). */
const record = "/var/lib/orbit/fleet-refresh.json";
/** What a base converge reads from `swamp/` (fleet-converge-inputs.json): their Git object IDs join the `converge` digest. */
const convergeInputs: string[] = JSON.parse(Deno.readTextFileSync(new URL("fleet-converge-inputs.json", root))).inputs;
const recordReads = [`cat ${record}`,
  `git -c safe.directory=* -C /srv/workspace rev-parse HEAD:apps/gateway HEAD:apps/agent/contract ${convergeInputs.map((path) => `HEAD:${path}`).join(" ")} HEAD:swamp`];
const hostTreeRead = "git -c safe.directory=* -c core.quotePath=true -C /srv/workspace ls-tree -r -t --full-tree HEAD -- swamp/ansible apps/gateway/resources/machine-software";
/** After the migrate, the nested Gateway prints the digest of each Node's base converge intent; it admits and writes nothing. */
const intentRead = "incus exec nested-gateway --cwd /home/orbit/orbit/apps/gateway -- runuser -u orbit -- php artisan orbit:node-converge-digest --host app-dev gateway app-dev app-prod";
/** With the Club key every refresh reads the nested Gateway's Swamp tokens: names and dates, no secret. */
const tokenRead = "incus exec nested-gateway --cwd /home/orbit/orbit/swamp -- runuser -u orbit -- env HOME=/home/orbit SWAMP_HOME=/home/orbit/.swamp " +
  "SWAMP_CONFIG_DIR=/home/orbit/.config/swamp SWAMP_API_KEY_FILE=/home/orbit/.config/swamp/api-key SWAMP_NO_UPDATE_CHECK=1 SWAMP_NO_TELEMETRY=1 " +
  "swamp access token list --json";
/** With the Club key every refresh reads the recipe's Swamp release and sha256, and the binary that the nested Gateway's serve runs. */
const swampReads = [
  'incus exec nested-gateway -- python3 -c import json, sys; s = json.load(open(sys.argv[1]))["tools"]["swamp"]; print(s["version"]); ' +
  'print(s["artifacts"]["amd64"]["sha256"]) ' +
  "/home/orbit/orbit/apps/gateway/resources/machine-software/recipe.json",
  'incus exec nested-gateway -- sh -c readlink -f "/proc/$(systemctl show -p MainPID --value orbit-swamp-serve.service)/exe"',
];
/** The swamp-pin play on the nested Gateway, with the egress proxy: it installs the recipe's release and restarts Swamp on it. */
const swampPinPlay = (proxyArgs: string) => "incus exec nested-gateway -- env ANSIBLE_CONFIG=/home/orbit/orbit/swamp/ansible/ansible.cfg " +
  `ANSIBLE_DISPLAY_FAILED_STDERR=true ${proxyArgs}/opt/orbit/ansible/venv/bin/ansible-playbook -i localhost, -e @/opt/orbit/bootstrap/control.yml ` +
  "--tags swamp-pin /home/orbit/orbit/swamp/ansible/gateway-bootstrap.yml";
/** The pinned shape tools in the Instance container: instance_shape_tools.py, named by the workspace's own gate, after base. */
const toolsCall = "python3 -I -c <instance_shape_tools.py> /srv/workspace";
/** The shrink of the nested root disks in the Instance container: instance_fleet_shrink.py, its mode, then these arguments. */
const shrinkCall = "python3 -I -c <instance_fleet_shrink.py> ";
const shrinkArgs = "/var/lib/orbit/fleet-shrink.json /var/lib/incus/storage-pools nested-gateway nested-app-dev nested-app-prod";
/** What a refresh did with `base` and the shrink, and why. */
const shrunk = (result: Record<string, unknown> | null) => [result?.base, result?.shrink, result?.shrink_reason];
/** The remint play on the nested Gateway: it makes the worker set whole and renews the six tokens. */
const remint = "incus exec nested-gateway -- env ANSIBLE_CONFIG=/home/orbit/orbit/swamp/ansible/ansible.cfg ANSIBLE_DISPLAY_FAILED_STDERR=true " +
  "/opt/orbit/ansible/venv/bin/ansible-playbook -i localhost, -e @/opt/orbit/bootstrap/control.yml --tags remint /home/orbit/orbit/swamp/ansible/gateway-bootstrap.yml";
/** A Swamp token as `name.secret`: no output may hold one. */
const tokenShaped = /[a-z0-9-]+\.[A-Za-z0-9_-]{20,}/;
/** A call to the nested Gateway's converge API: an admission or a read of its Operation. */
const convergeApi = (call: string) => call.startsWith(api) && (call.includes("/converge ") || call.includes("/operations/"));
/** The due Nodes converge one at a time, in the order gateway (1), app-prod (3), app-dev (2): each is admitted and then read. The
 * fake numbers each admitted Operation from 901 in admission order. */
const convergeAdmit = (node: number, host = true) =>
  `${api} --fail -X POST https://10.45.0.1/api/v1/nodes/${node}/converge -H Content-Type: application/json -d ${
    node === 2 && !host ? '{"base":true}' : '{"base":false}'}`;
const convergeRead = (operation: number) => `${api} --fail https://10.45.0.1/api/v1/operations/${operation}`;
/** Each converge has the default scope. With `host` off (an ID map below 2,000,000), nested-app-dev keeps the base scope. */
const convergeCallsFor = (nodes: number[], host = true) =>
  [1, 3, 2].filter((node) => nodes.includes(node)).flatMap((node, index) => [convergeAdmit(node, host), convergeRead(901 + index)]);
const convergeCalls = convergeCallsFor([1, 2, 3], true);
/** The record keys of the three nested Nodes' converge digests and last converge times. */
const convergeKeys = ["gateway", "app_dev", "app_prod"].map((role) => `converge_${role}`);
const convergedKeys = ["gateway", "app_dev", "app_prod"].map((role) => `converged_${role}`);
const swampServices = ["orbit-swamp-serve.service", "orbit-swamp-worker@a.service", "orbit-swamp-worker@b.service", "orbit-swamp-worker@c.service"];
const mountReads = [
  "incus exec nested-gateway -- systemctl is-active home-orbit-orbit-apps-gateway-.env.mount home-orbit-orbit-apps-gateway-storage.mount " +
  "home-orbit-orbit-apps-gateway-bootstrap-cache.mount home-orbit-orbit-swamp-.swamp.mount home-orbit-orbit-swamp-vaults.mount home-orbit-orbit-swamp-grants.mount",
  "incus exec nested-app-dev -- systemctl is-active home-orbit-orbit-apps-gateway-.env.mount home-orbit-orbit-apps-gateway-storage.mount " +
  "home-orbit-orbit-apps-gateway-bootstrap-cache.mount",
];
const serviceRead = "incus exec nested-gateway -- systemctl is-active orbit-gateway-horizon.service orbit-gateway-schedule.service orbit-agent-view.service " +
  swampServices.join(" ");
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
    const f = fixture(structuredClone(shapes), { club: true, bridge: exportedBridge });
    try {
      const first = f.run("rules");
      assert(first.ok, first.output);
      const calls = f.calls();
      const proxyKeys = Object.entries(environment).map(([key, value]) => `${key}=${value}`);
      assertEquals(calls, [
        ...reads,
        "incus config device add nested-app-dev apparmor-off disk source=/etc/orbit/apparmor-off path=/sys/module/apparmor/parameters/enabled readonly=true",
        "incus config device add nested-app-prod checkout disk source=/srv/workspace path=/home/orbit/orbit shift=false",
        "incus config device add nested-app-prod apparmor-off disk source=/etc/orbit/apparmor-off path=/sys/module/apparmor/parameters/enabled readonly=true",
        "incus config device set nested-gateway checkout source=/srv/workspace path=/home/orbit/orbit shift=false",
        "incus config device set nested-gateway club-key source=/run/orbit-club path=/home/orbit/.config/swamp/club required=false shift=false",
        "incus config device set nested-app-dev checkout source=/srv/workspace path=/home/orbit/orbit shift=false",
        ...["nested-gateway", "nested-app-dev", "nested-app-prod"].flatMap((name) =>
          [`raw.idmap=${idmap}`, ...proxyKeys, ...(name === "nested-app-dev" ? Object.entries(instanceHostKeys).map(([key, value]) => `${key}=${value}`) : []),
            ...(name === "nested-gateway" ? [] : [`limits.memory=${memory[name]}`]), `limits.memory.swap=${memory[name]}`]
            .map((key) => `incus config set ${name} ${key}`)),
        "incus network set oe-nested raw.dnsmasq=no-resolv",
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
  name: "a fleet start restores the nested Gateway pins as orbit, reports a no-op on rerun and fails if the store cannot be repaired",
  ignore: !hasAnsible,
  fn() {
    const f = fixture(ruled());
    f.change((state) => { for (const name of names) state.containers[name].status = "Running"; });
    f.scopePhase("start", ["Name the fleet commands", "Restore the nested Gateway SSH trust"]);
    try {
      const first = f.run("start"); assert(first.ok, first.output);
      assertEquals(f.state().containers["nested-gateway"].files["/home/orbit/.orbit/ssh/known_hosts"], "recorded-node-pins");
      assert(f.calls().includes(knownHostsSync));
      const again = f.run("start"); assert(again.ok, again.output);
      assertEquals(again.changed, 0);
      assert(f.calls().includes(knownHostsSync));
      f.change((state) => state.failKnownHostsSync = true);
      const failed = f.run("start"); assert(!failed.ok);
      assertStringIncludes(failed.output, "Gateway pin store unavailable");
    } finally { f.cleanup(); }
  },
});

Deno.test({
  name: "an old-checkout fleet start records unavailable pin sync, then refresh writes pins on the new checkout and repeats unchanged",
  ignore: !hasAnsible,
  fn() {
    const f = fixture(ruled());
    f.change((state) => { for (const name of names) state.containers[name].status = "Running"; });
    f.scopePhase("start", ["Name the fleet commands", "Restore the nested Gateway SSH trust"]);
    f.scopePhase("refresh", { through: "Restore SSH trust on the refreshed checkout" });
    try {
      f.change((state) => state.oldGatewayCommands = true);
      const old = f.run("start"); assert(old.ok, old.output);
      assertStringIncludes(old.output, "command_unavailable");
      assert(f.calls().includes(knownHostsList));
      assert(!f.calls().includes(knownHostsSync));
      assertEquals(f.state().containers["nested-gateway"].files["/home/orbit/.orbit/ssh/known_hosts"], undefined);
      // The checkout moves between Prepare and Finish; refresh must write pins without another start.
      f.change((state) => state.oldGatewayCommands = false);
      const refreshed = f.run("refresh"); assert(refreshed.ok, refreshed.output);
      const calls = f.calls();
      assert(calls.indexOf(knownHostsSync) > calls.findIndex((call) => call.includes("artisan migrate --force")));
      assertEquals(f.state().containers["nested-gateway"].files["/home/orbit/.orbit/ssh/known_hosts"], "recorded-node-pins");
      const again = f.run("start"); assert(again.ok, again.output);
      assertEquals(again.changed, 0);
      assert(f.calls().includes(knownHostsSync));
      f.change((state) => state.failCommandList = true);
      const failed = f.run("start"); assert(!failed.ok);
      assertStringIncludes(failed.output, "Gateway command list unavailable");
    } finally { f.cleanup(); }
  },
});

Deno.test({
  name: "a fleet start appends the managed user to Incus admin on every marked host, preserves other groups and repeats unchanged without a converge",
  ignore: !hasAnsible,
  fn() {
    // The marker identifies actual hosts even when this copy's map cannot build another Instance image.
    const f = fixture(ruled(), { host: false });
    const groupFile = "orbit:x:1000:\nwork:x:2760:orbit\noperators:x:3000:orbit,other\nincus-admin:x:999:existing\n";
    const readyGroups = groupFile.replace("incus-admin:x:999:existing\n", "incus-admin:x:999:existing,orbit\n");
    const hosts = ["nested-app-dev", "nested-app-prod"];
    f.scopePhase("start", [
      "Name the fleet commands", "Read which nested Nodes are Instance hosts", "Read the managed user's groups on nested Instance hosts",
      "Give nested Instance hosts' managed users Incus socket access",
    ]);
    f.change((state) => {
      for (const name of names) state.containers[name].status = "Running";
      for (const name of names) state.containers[name].files["/etc/group"] = groupFile;
      for (const name of hosts) state.containers[name].files["/etc/orbit/instance-fleet-node"] = "Instance host\n";
    });
    try {
      const first = f.run("start"); assert(first.ok, first.output);
      for (const name of hosts) assertEquals(f.state().containers[name].files["/etc/group"], readyGroups);
      assertEquals(f.state().containers["nested-gateway"].files["/etc/group"], groupFile);
      const calls = f.calls();
      assertEquals(calls.filter((call) => call.includes("usermod")), hosts.map((name) =>
        `incus exec ${name} -- usermod --append --groups incus-admin orbit`));
      assert(!calls.some((call) => call.includes("converge") || call.includes("snapshot")));
      const again = f.run("start"); assert(again.ok, again.output);
      assertEquals(again.changed, 0);
      assert(!f.calls().some((call) => call.includes("usermod")));
      f.change((state) => state.failHostGroups = true);
      const failed = f.run("start"); assert(!failed.ok);
      assertStringIncludes(failed.output, "Instance host groups unavailable");
      assert(!f.calls().some((call) => call.includes("usermod")));
    } finally { f.cleanup(); }
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
      assertEquals(calls, [...startReads, ...startCalls, ...reachCalls]);
      assertKeyUntouched(calls, first.output);
      assert(!calls.some((call) => call.includes("wg-quick")));
      assertEquals(f.state().containers["nested-gateway"].links, { "/home/orbit/.config/swamp/api-key": "club/swamp-club-api-key" });
      assertEquals(f.state().tunnel, { addresses: ["10.45.0.10/24"], up: true, key: "/etc/wireguard/wg-nested.key", port: "51821", peer: hubKey, endpoint: "10.232.2.11:51820", allowed: "10.45.0.0/24",
        dns: ["10.45.0.1"], domains: ["~nested-dev", "~nested-prod"] });
      const again = f.run("start");
      assert(again.ok, again.output);
      assertEquals(again.changed, 0);
      assertEquals(f.calls(), [...startReads, "incus list --format csv --columns ns", ...instanceHostReads, knownHostsList, knownHostsSync, "incus exec nested-gateway -- readlink /home/orbit/.config/swamp/api-key", "test -s /etc/wireguard/wg-nested.key",
        "incus exec nested-gateway -- wg show orbit public-key", "wg show wg-nested", "ip -j addr show dev wg-nested", ...tunnelDnsReads, ...reachCalls]);
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
  name: "the deployment's check refuses a refresh that cannot end above the inner Doctor's floor before any start; with room it reads df once and starts",
  ignore: !hasAnsible,
  fn() {
    const f = fixture(ruled());
    try {
      // Refresh 21617: 2.75 GiB free; the inner Doctor wants 10% of 25 GiB, and a refresh writes up to 2 GiB more.
      f.change((state) => state.room = [26843545600, 2952790016]);
      const refused = f.run("start", ["-e", "fleet_room_check=true", "-v"]);
      assert(!refused.ok);
      assertStringIncludes(refused.output, '"msg": "instance.container_fleet_no_room"');
      // The detail the result file carries names the numbers.
      assertStringIncludes(refused.output, "The disk of the nested Nodes has 2.75 GiB free of 25.00 GiB. A refresh writes up to 2.00 GiB more, and the inner " +
        "Doctor wants at least 2.50 GiB free (2 GiB and 10%): free 1.75 GiB in the default before its next refresh.");
      // Nothing else ran: no wait, no read of the fleet, no start.
      assertEquals(f.calls(), ["df -B1 --output=size,avail /"]);
      assertEquals(f.state().containers["nested-gateway"].status, "Stopped");
      // 4.5 GiB of 25 GiB fits: 2.5 GiB floor plus 2 GiB.
      f.change((state) => state.room = [26843545600, 4831838208]);
      const started = f.run("start", ["-e", "fleet_room_check=true"]);
      assert(started.ok, started.output);
      assertEquals(f.calls(), ["df -B1 --output=size,avail /", ...startReads, ...startCalls, ...reachCalls]);
      // A default with an inner volume keeps its nested Nodes on that volume: the room is read there.
      f.intent((intent) => intent.container.inner_volume = { inner_pool: { name: "inner", source: "/srv/inner-pool" } });
      f.change((state) => state.room = [32212254720, 1073741824]);
      const volume = f.run("start", ["-e", "fleet_room_check=true"]);
      assert(!volume.ok);
      assertEquals(f.calls(), ["df -B1 --output=size,avail /srv/inner-pool"]);
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
      assertEquals(calls, [...startReads, ...startCalls.flatMap((call) => call === read ? [read, read] : [call]), ...reachCalls]);
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
      assertStringIncludes(refused.output, "The nested fleet differs from its rules where Orbit does not write: nested-gateway config raw.idmap, nested-app-dev config raw.idmap, nested-app-prod config raw.idmap\"");
      assertStringIncludes(refused.output, '"msg": "instance.container_fleet_drift"');
      assertEquals(f.calls(), startReads);
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
  name: "a refresh applies the checkout, converges the three Nodes one at a time, stops the fleet, takes base with the rules, then starts it and waits for readiness once",
  ignore: !hasAnsible,
  fn() {
    // Below 2,000,000: nested-app-dev keeps the base scope. The host's own order is the window's first refresh case.
    const f = fixture(ruled(), { club: true, base: true, host: false });
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
        // The tokens are one day old: one read, no remint.
        tokenRead,
        // The nested Gateway's serve runs the recipe's Swamp release: two reads, no install.
        ...swampReads,
        "incus exec nested-gateway --cwd /home/orbit/orbit/apps/gateway -- runuser -u orbit -- php artisan migrate --force",
        exec("nested-gateway", `systemctl restart ${services.join(" ")}`),
        knownHostsList, knownHostsSync,
        // One read of every service; with the Club key Swamp serve and its three workers too.
        exec("nested-gateway", `systemctl is-active ${[...services, ...swampServices].join(" ")}`),
        intentRead,
        // The rules and files are read (and files written where they differ) on the running fleet, before the converge.
        ...reads,
        `${api} --fail https://10.45.0.1/api/v1/nodes`,
        ...names.map((name) => exec(name, "systemctl stop apt-daily.timer apt-daily-upgrade.timer")),
        ...names.map((name) => exec(name, "systemctl show --property=ActiveState --value apt-daily.service apt-daily-upgrade.service")),
        ...convergeCallsFor([1, 2, 3], false),
        ...names.map((name) => `incus query /1.0/instances/${name}/snapshots?recursion=1`),
        // The shrink check: no package changed since the last shrink, so it adds nothing to `base`.
        shrinkCall + "check " + shrinkArgs,
        // `base` is due for all three, so readiness waits until they run again.
        ...names.map((name) => `incus stop ${name} --timeout 90`),
        "incus list --format csv --columns ns",
        ...reads,
        "incus config device add nested-app-prod apparmor-off disk source=/etc/orbit/apparmor-off path=/sys/module/apparmor/parameters/enabled readonly=true",
        ...reads,
        ...names.map((name) => `incus snapshot delete ${name} base`),
        ...names.map((name) => `incus snapshot create ${name} base`),
        // The containers start again: the seed holds a running, ready fleet, and no step after the refresh starts it.
        ...startReads,
        "incus list --format csv --columns ns",
        ...names.map((name) => `incus start ${name}`),
        ...instanceHostReads,
        knownHostsList, knownHostsSync,
        "incus exec nested-gateway -- readlink /home/orbit/.config/swamp/api-key",
        "test -s /etc/wireguard/wg-nested.key",
        "incus exec nested-gateway -- wg show orbit public-key",
        "wg show wg-nested",
        "ip -j addr show dev wg-nested",
        ...tunnelDnsReads,
        ...reachCalls,
        ...readyCalls,
        // The pinned shape tools in the outer container, after base: the seed after this phase holds them.
        toolsCall,
        recordWrite,
      ]);
      assertKeyUntouched(calls, refreshed.output);
      const state = f.state();
      assertEquals(state.operations.map((operation: { node: number }) => operation.node), [1, 3, 2]);
      assertEquals(calls.filter((call) => call.includes("/doctor")).length, 1);
      for (const name of names) {
        assertEquals(state.containers[name].status, "Running");
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
    const f = refreshedOnce();
    try {
      f.scopePhase("refresh", ["Name the fleet commands", "Look for the Club key", "Wait for the state mounts"]);
      f.calls();
      f.change((state) => state.activating = 1);
      const refreshed = f.run("refresh");
      assert(refreshed.ok, refreshed.output);
      const mounts = f.calls().filter((call) => call.includes("systemctl is-active home-orbit"));
      assertEquals(mounts.map((call) => call.split(" ")[2]), ["nested-gateway", "nested-gateway", "nested-app-dev"]);

      const g = refreshedOnce();
      try {
        g.scopePhase("refresh", ["Name the fleet commands", "Look for the Club key", "Wait for the state mounts"]);
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
  name: "a refresh stops at a failed Node converge, takes no base and writes no record; without the Club key it skips the grants and the Swamp units",
  ignore: !hasAnsible,
  fn() {
    // Below 2,000,000: nested-app-dev keeps the base scope.
    const f = fixture(ruled(), { base: true, host: false });
    try {
      assert(f.run("start").ok);
      f.calls();
      f.change((state) => state.convergeStatus = { 2: "failed" });
      const failed = f.run("refresh");
      assert(!failed.ok);
      assertStringIncludes(failed.output, "The converge of the nested Node app-dev (Operation 903) ended failed with node.converge_failed");
      const calls = f.calls();
      // app-dev converges last, so the three are admitted one at a time and each is awaited before the failure fails the refresh.
      assertEquals(calls.filter(convergeApi), convergeCallsFor([1, 2, 3], false));
      assert(!calls.some((call) => !call.includes(" file pull ") && (call.includes("ansible-playbook") || call.includes("orbit-swamp"))), calls.join("\n"));
      assert(!calls.some((call) => call.includes("snapshot") || call.includes("incus stop") || call === recordWrite), calls.join("\n"));
      assertEquals(f.state().outer.files[record], undefined);
      assertKeyUntouched(calls, failed.output);
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "the due Nodes converge one at a time, gateway, app-prod, then app-dev; a refused or failed converge stops the Nodes after it and fails the refresh",
  ignore: !hasAnsible,
  fn() {
    // Below 2,000,000: nested-app-dev keeps the base scope.
    const f = fixture(ruled(), { base: true, host: false });
    try {
      assert(f.run("start").ok);
      f.calls();
      // gateway still runs for two reads, then app-prod is refused at its admission.
      f.change((state) => {
        state.convergeRunning = { 1: 2 };
        state.convergeRefused = [3];
      });
      const refused = f.run("refresh");
      assert(!refused.ok);
      assertStringIncludes(refused.output, "The nested Gateway did not admit the converge of app-prod");
      let calls = f.calls();
      // app-prod is admitted only after gateway has ended; app-dev, after the refusal, is never admitted.
      assertEquals(calls.filter(convergeApi), [convergeAdmit(1, false), convergeRead(901), convergeRead(901), convergeRead(901), convergeAdmit(3, false)]);
      assertEquals(f.state().convergeRunning, { 1: 0 });
      assert(!calls.some((call) => call.includes("snapshot") || call.includes("incus stop") || call === recordWrite), calls.join("\n"));
      assertEquals(f.state().outer.files[record], undefined);
      // app-prod is admitted now and fails: app-dev is again never admitted.
      f.change((state) => {
        state.convergeRefused = [];
        state.convergeStatus = { 3: "failed" };
      });
      const failed = f.run("refresh");
      assert(!failed.ok);
      assertStringIncludes(failed.output, "The converge of the nested Node app-prod (Operation 903) ended failed with node.converge_failed");
      calls = f.calls();
      assertEquals(calls.filter(convergeApi), [convergeAdmit(1, false), convergeRead(902), convergeAdmit(3, false), convergeRead(903)]);
      assert(!calls.some((call) => call.includes("snapshot") || call.includes("incus stop") || call === recordWrite), calls.join("\n"));
      assertEquals(f.state().outer.files[record], undefined);
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a reset stops the three containers in one call, restores base on each stopped one, keeps the rules of base, starts the fleet and polls every second; a base without the rules is refused",
  ignore: !hasAnsible,
  fn() {
    const f = fixture(ruled(), { base: true });
    try {
      assert(f.run("start").ok);
      f.calls();
      // Work in the fleet changed a device of a nested container; the restore takes base's back.
      f.change((state) => state.containers["nested-app-dev"].devices.checkout.shift = "true");
      // Doctor answers unhealthy once, so the readiness wait polls again after the reset's own delay.
      f.change((state) => {
        state.doctorCalls = 0;
        state.doctorUnhealthy = 1;
      });
      const reset = f.run("reset", [], { ownDelay: true });
      assert(reset.ok, reset.output);
      const calls = f.calls();
      assertEquals(calls, [
        // A restore of a running container stops and boots it again, one by one: the reset stops the three in one call first.
        "incus list --format csv --columns ns",
        `incus stop --force ${names.join(" ")}`,
        ...names.map((name) => `incus snapshot restore ${name} base`),
        ...startReads,
        "incus list --format csv --columns ns",
        // One call starts the three after the rules check: Incus boots them in parallel.
        `incus start ${names.join(" ")}`,
        ...instanceHostReads,
        knownHostsList, knownHostsSync,
        "incus exec nested-gateway -- readlink /home/orbit/.config/swamp/api-key",
        "incus exec nested-gateway -- ln -sfnT club/swamp-club-api-key /home/orbit/.config/swamp/api-key",
        "test -s /etc/wireguard/wg-nested.key",
        "incus exec nested-gateway -- wg show orbit public-key",
        "wg show wg-nested",
        "ip -j addr show dev wg-nested",
        ...tunnelDnsReads,
        ...reachCalls,
        // base may hold an older schema than the checkout: the reset waits for the state mounts (no Club key here, so no Swamp state)
        // and migrates forward; nothing was due here, so no restart.
        `test -s ${clubKey}`,
        "incus exec nested-gateway -- systemctl is-active home-orbit-orbit-apps-gateway-.env.mount home-orbit-orbit-apps-gateway-storage.mount home-orbit-orbit-apps-gateway-bootstrap-cache.mount",
        "incus exec nested-app-dev -- systemctl is-active home-orbit-orbit-apps-gateway-.env.mount home-orbit-orbit-apps-gateway-storage.mount home-orbit-orbit-apps-gateway-bootstrap-cache.mount",
        "incus exec nested-gateway --cwd /home/orbit/orbit/apps/gateway -- runuser -u orbit -- php artisan migrate --force",
        // The unhealthy Doctor answer is polled once more.
        ...readyCalls.slice(0, 3), readyCalls[2], readyCalls[3],
      ]);
      assertKeyUntouched(calls, reset.output);
      assertEquals(f.state().containers["nested-app-dev"].devices.checkout.shift, "false");
      assertEquals(f.state().runningRestores ?? 0, 0, "a restore of a running container boots it on its own");
      // The waits poll every second within the 300 s bound: 300 retries, where the refresh's 5 s give 60.
      assertStringIncludes(reset.output, "FAILED - RETRYING: [localhost]: Wait for Doctor of the nested Gateway (300 retries left)");
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

Deno.test({
  name: "a reset that fails after its stop starts the containers that ran again and fails with the first reason",
  ignore: !hasAnsible,
  fn() {
    const f = fixture(ruled(), { base: true });
    f.scopePhase("start", [
      "Name the fleet commands", "Read the state of the nested containers", "Start the nested containers",
      "Start the nested containers together", "Check that the nested containers started",
    ]);
    try {
      assert(f.run("start").ok);
      f.calls();
      // nested-app-prod lost its base: its restore fails after the stop of all three.
      f.change((state) => delete state.containers["nested-app-prod"].snapshots.base);
      const failed = f.run("reset");
      assert(!failed.ok, failed.output);
      assertStringIncludes(failed.output, '"msg": "nested-app-prod did not restore base: Error: Snapshot not found"');
      // The failure keeps the name of the task that failed, which the container play writes into its `detail`.
      assertEquals(failed.output.match(/TASK \[Check the restores\]/g)?.length, 2);
      const calls = f.calls();
      const stop = calls.indexOf(`incus stop --force ${names.join(" ")}`);
      assert(stop >= 0, calls.join("\n"));
      // Each start is its own call after the failed restore, so one that fails stops none of the others.
      assertEquals(calls.slice(stop + 1).filter((call) => call.startsWith("incus start")), names.map((name) => `incus start ${name}`));
      assertEquals(names.map((name) => f.state().containers[name].status), ["Running", "Running", "Running"]);
      // The one start of the three fails for one of them: the check names the call's containers and the reason.
      f.change((state) => {
        state.containers["nested-app-prod"].snapshots.base = structuredClone(state.containers["nested-app-dev"].snapshots.base);
        state.failStart = { "nested-app-dev": "Failed to start device" };
      });
      f.calls();
      const unstarted = f.run("reset");
      assert(!unstarted.ok);
      assertStringIncludes(unstarted.output, `"msg": "${names.join(", ")} did not start: Error: nested-app-dev: Failed to start device"`);
      f.change((state) => delete state.failStart);
      assert(f.run("start").ok);
      // A fleet that was stopped before the reset stays stopped.
      f.change((state) => {
        for (const name of names) state.containers[name].status = "Stopped";
        delete state.containers["nested-app-prod"].snapshots.base;
      });
      f.calls();
      assert(!f.run("reset").ok);
      assert(!f.calls().some((call) => call.startsWith("incus start")));
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a reset refuses an Instance that is not a clone with instance.fleet_reset_not_clone before any command",
  ignore: !hasAnsible,
  fn() {
    for (const clone of [null, undefined]) {
      const f = fixture(ruled(), { base: true });
      f.scopePhase("start", [
        "Name the fleet commands", "Read the state of the nested containers", "Start the nested containers",
        "Start the nested containers together", "Check that the nested containers started",
      ]);
      try {
        assert(f.run("start").ok);
        f.calls();
        // A default's intent has `clone` null (InstanceContainer::intent); a hand-made one may lack it.
        f.intent((intent) => {
          if (clone === null) intent.container.clone = null;
          else delete intent.container.clone;
        });
        const refused = f.run("reset");
        assert(!refused.ok, refused.output);
        assertStringIncludes(refused.output, '"msg": "instance.fleet_reset_not_clone"');
        assertEquals(f.calls(), []);
        assertEquals(names.map((name) => f.state().containers[name].status), ["Running", "Running", "Running"]);
      } finally {
        f.cleanup();
      }
    }
  },
});

// The three nested containers of the first inner fleet as they run (read from Instance 2454 on 2026-11-13, without volatile keys and
// cloud-init data), with the `club-key` device as the key move to /run sets it. step8/runbook.sh built them by hand.
const live = JSON.parse(Deno.readTextFileSync(new URL("fixtures/instance-fleet-live.json", import.meta.url)));
const tunnelUp = { addresses: ["10.45.0.10/24"], up: true, key: "/etc/wireguard/wg-nested.key", port: "51821", peer: hubKey, endpoint: "10.232.2.11:51820", allowed: "10.45.0.0/24",
  dns: ["10.45.0.1"], domains: ["~nested-dev", "~nested-prod"] };
const writes = (calls: string[]) => calls.filter((call) => / config (set|device) | file push | snapshot | stop /.test(call));
// deno-lint-ignore no-explicit-any
const withStatus = (status: string): Record<string, any> =>
  Object.fromEntries(Object.entries(structuredClone(live)).map(([name, value]) => [name, { ...value as object, status }]));

Deno.test({
  name: "the live fleet passes the check and its start changes no config: running it is a no-op, stopped it only starts",
  ignore: !hasAnsible,
  fn() {
    // A current fleet already holds its Node pins; the missing-store repair has its own case above.
    const currentFleet = (status: string) => {
      const containers = withStatus(status);
      containers["nested-gateway"].files["/home/orbit/.orbit/ssh/known_hosts"] = "recorded-node-pins";
      return containers;
    };
    const f = fixture(currentFleet("Running"), { club: true });
    f.change((state) => state.tunnel = structuredClone(tunnelUp));
    try {
      const check = f.run("check");
      assert(check.ok, check.output);
      assertEquals(check.changed, 0);
      assertEquals(f.calls(), ["incus admin waitready --timeout 80", ...compareReads]);
      const start = f.run("start");
      assert(start.ok, start.output);
      assertEquals(start.changed, 0);
      assertEquals(writes(f.calls()), []);
      assertEquals(f.state().containers, currentFleet("Running"));
    } finally {
      f.cleanup();
    }
    const g = fixture(currentFleet("Stopped"), { club: true });
    try {
      const start = g.run("start");
      assert(start.ok, start.output);
      const calls = g.calls();
      assertEquals(calls.filter((call) => call.startsWith("incus start")), names.map((name) => `incus start ${name}`));
      assertEquals(writes(calls), []);
      assertEquals(g.state().containers, currentFleet("Running"));
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
  name: "the nested Gateway's Horizon and PHP-FPM pool get the fleet's egress proxy from files of their own, so a Gateway role converge keeps them",
  ignore: !hasAnsible,
  fn() {
    const f = fixture(structuredClone(shapes), { club: true, bridge: exportedBridge });
    try {
      const ruledRun = f.run("rules");
      assert(ruledRun.ok, ruledRun.output);
      const files = f.state().containers["nested-gateway"].files;
      assertEquals([files[units[2]], files[pool]], [dropIn, poolFile]);
      for (const key of ["HTTPS_PROXY", "HTTP_PROXY", "NO_PROXY", "https_proxy", "http_proxy", "no_proxy"]) assertStringIncludes(files[pool], `env[${key}] = "`);
      // The pool file names the role's own pool; it never touches the role's file.
      assertStringIncludes(files[pool], "[orbit-gateway]\n");
      assertEquals(f.calls().filter((call) => call.includes("orbit-gateway.conf")), []);
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
        // The live fleet was built before the egress files and the marker; they are owned rules, which the refresh writes.
        want.files = { ...(want.files ?? {}), ...(name === "nested-gateway" ? { [units[2]]: dropIn, [pool]: poolFile, [marker]: markerFile } : {}), ...ownedBy(name) };
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
      const egressWrites = names.flatMap((name) => [...(name === "nested-gateway" ? [units[2], pool, marker] : []), ...Object.keys(ownedBy(name))].map((path) => push(name, path)));
      assertEquals(writes(calls).filter((call) => !/ (stop|snapshot) /.test(call)),
        [...egressWrites, ...names.flatMap((name) => ["boot.autostart=true", ...(name === "nested-app-dev" ? Object.entries(instanceHostKeys).map(([key, value]) => `${key}=${value}`) : []),
          ...(name === "nested-gateway" ? [] : [`limits.memory=${memory[name]}`]), `limits.memory.swap=${memory[name]}`]
          .map((key) => `incus config set ${name} ${key}`))]);
      assert(calls.indexOf(egressWrites.at(-1)!) < calls.findIndex((call) => call.includes("/converge ")), calls.join("\n"));
      for (const name of names) {
        assertEquals(f.state().containers[name].snapshots.base.config["boot.autostart"], "true");
        // A copy made from the new seed inherits the nested containers' swap.
        assertEquals(f.state().containers[name].snapshots.base.config["limits.memory.swap"], memory[name]);
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
    // Below 2,000,000: nested-app-dev keeps the base scope. The converges run one at a time, so the first failure stops them.
    const f = fixture(withStatus("Running"), { club: true, base: true, host: false });
    f.change((state) => {
      state.tunnel = structuredClone(tunnelUp);
      state.egressNeeded = ["/etc/apt/apt.conf.d/missing-in-this-case"];
    });
    try {
      const failed = f.run("refresh");
      assert(!failed.ok);
      assertStringIncludes(failed.output, "Check the converges of the nested Nodes");
      // The first converge, of the nested Gateway, fails and is named; no other converge is admitted.
      assertStringIncludes(failed.output, "The converge of the nested Node gateway (Operation 901) ended failed with node.converge_failed");
      assertEquals(f.calls().filter((call) => call.includes("/converge ")).length, 1);
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
      // A Doctor body names every issue: code, Node, summary and what it observed, each bounded, so a long first one hides none.
      const issue = (code: string, node: string, summary: string, observed: string) =>
        ({ code, kind: "drift", resource_type: "node", resource_id: 3, resource_name: node, summary, expected: "x", observed });
      const doctor = { data: { healthy: false, nodes: [{ node_id: 3, node_name: "app-dev", healthy: false, families: [{ family: "node", status: "drift",
        issues: [issue("node.disk_low", "app-dev", "The root filesystem has low free space. " + "y".repeat(400), "1 KiB free of 2 KiB"),
          issue("node.agent_view_stale", "app-dev", "The agent view is stale.", "old")] }] }] } };
      f.change((state) => state.doctorResponse = { status: 200, body: JSON.stringify(doctor) });
      const issues = f.run("resume", late);
      assert(!issues.ok);
      assertStringIncludes(issues.output, "failed after 2 s: HTTP 200: node.disk_low on app-dev: The root filesystem has low free space. yyy");
      assertStringIncludes(issues.output, "; node.agent_view_stale on app-dev: The agent view is stale. (old)");
      assert(!issues.output.includes("y".repeat(220)), "each issue is bounded");
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
      assert(calls.findIndex((call) => call.includes("/converge ")) < doctor);
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
    // Start from the verified running boundary; a missing record makes the shipped restart and converge due.
    const due = () => {
      const f = refreshedOnce();
      f.change((state) => {
        delete state.outer.files[record];
        delete state.outer.files[clubKey];
      });
      f.scopePhase("refresh", { through: "Converge the due nested Nodes one at a time" });
      return f;
    };
    const f = due();
    try {
      f.calls();
      f.change((state) => state.agentsOffline = 2);
      const refreshed = f.run("refresh");
      assert(refreshed.ok, refreshed.output);
      const calls = f.calls();
      const firstConverge = calls.findIndex((call) => call.includes("/converge "));
      assert(firstConverge >= 0, calls.join("\n"));
      const restart = calls.findIndex((call) => call.includes("systemctl restart orbit-gateway-horizon.service"));
      const agents = calls.findIndex((call) => call.endsWith("--fail https://10.45.0.1/api/v1/nodes"));
      assert(restart >= 0 && restart < agents && agents < firstConverge, calls.join("\n"));
      assertEquals(calls.slice(0, firstConverge).filter((call) => call.endsWith("--fail https://10.45.0.1/api/v1/nodes")).length, 3);
      const g = due();
      try {
        g.calls();
        g.change((state) => state.agentsOffline = -1);
        const failed = g.run("refresh", ["-e", "fleet_ready_seconds=2", "-e", "fleet_ready_delay=1"]);
        assert(!failed.ok);
        assertStringIncludes(failed.output, "The agent of app-prod was not online within 2 s after the restart of the nested Gateway");
        assert(!g.calls().some((call) => call.includes("/converge ") || call.includes("snapshot")));
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
      assertEquals(calls, [...startReads, ...startCalls, ...reachCalls, ...readyCalls]);
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
// Only the wall-clock times (the last converge and the oldest token) are rebound; all digests and other persistent state must match exactly.
function savedRecord(value: Record<string, string>) {
  for (const key of convergedKeys) assert(/^\d+$/.test(value[key]), `the real refresh must write a numeric ${key}`);
  assert(/^\d{14}$/.test(value.tokens), "the real refresh must write the oldest token's creation time");
  return { ...value, ...Object.fromEntries(convergedKeys.map((key) => [key, "@CONVERGED@"])), tokens: "@TOKENS@" };
}
function currentRecord(value: Record<string, string>, converged = String(Math.floor(Date.now() / 1000))) {
  assertEquals([...convergedKeys.map((key) => value[key]), value.tokens], [...convergedKeys.map(() => "@CONVERGED@"), "@TOKENS@"]);
  return { ...value, ...Object.fromEntries(convergedKeys.map((key) => [key, converged])),
    tokens: new Date(Date.now() - 86400_000).toISOString().slice(0, 19).replace(/[^0-9]/g, "") };
}
const refreshedBoundary = JSON.parse(Deno.readTextFileSync(new URL("fixtures/instance-fleet-refreshed.json", import.meta.url)));
// deno-lint-ignore no-explicit-any
function persistentState(state: Record<string, any>) {
  const value = structuredClone(state);
  for (const key of ["execs", "operations", "doctorCalls"]) delete value[key];
  if (value.outer.files[record]) value.outer.files[record] = JSON.stringify(savedRecord(JSON.parse(value.outer.files[record])));
  return value;
}
function refreshedOnce() {
  const f = fixture(ruled(), { club: true, base: true });
  f.change((state) => {
    for (const key of Object.keys(state)) delete state[key];
    Object.assign(state, structuredClone(refreshedBoundary.state));
    state.outer.files[record] = JSON.stringify(currentRecord(JSON.parse(state.outer.files[record])));
  });
  return f;
}
Deno.test({
  name: "the saved refreshed fleet boundary matches a real first refresh and start",
  ignore: !hasAnsible,
  fn() {
    const before = Math.floor(Date.now() / 1000);
    const f = refreshFromScratch();
    try {
      for (const key of convergedKeys) {
        const converged = Number(JSON.parse(f.state().outer.files[record])[key]);
        assert(converged >= before && converged <= Math.floor(Date.now() / 1000), key);
      }
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
  converge: "/converge ",
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
      const written = JSON.parse(f.state().outer.files[record]);
      // A test, another workflow, two modules of the converge's behavior that no converge runs, and the Route modules that no base converge
      // task calls (releases 2079, 2083, 2087) changed, so the `swamp` tree did: none of them is a converge input (the role plans only composeRole uses, the
      // redaction only the fleet reboot's result uses, `library/` and `module_utils/`), but the grants follow `swamp`.
      f.change((state) => state.digests = { "apps/gateway": "a1", swamp: "e1", "swamp/tests": "f9", "swamp/workflows/workflow-operation-instance-deploy-direct.yaml": "f8",
        "swamp/extensions/behaviors/operations/ansible-input/role_add_plans.ts": "f7", "swamp/extensions/behaviors/_lib/public_output.ts": "f6",
        "swamp/ansible/library/orbit_route_site.py": "f5", "swamp/ansible/library/orbit_route_withdraw.py": "f4", "swamp/ansible/module_utils/orbit_route_withdraw.py": "f3",
        "swamp/ansible/library/": "f2", "swamp/ansible/module_utils/": "f1" });
      const again = f.run("refresh");
      assert(again.ok, again.output);
      const calls = f.calls();
      for (const part of Object.values(parts)) assert(!ran(calls, part), `${part} ran: ${calls.join("\n")}`);
      // The grants reload, after the state mounts; the code did not move, so no agent delivery; one Doctor wait every time.
      assert(ran(calls, "--tags grants") && ran(calls, "systemctl is-active home-orbit") && !ran(calls, "frozen-manifest.json"), calls.join("\n"));
      assertEquals(calls.filter((call) => call.includes("/doctor")).length, 1);
      assertEquals(calls.at(-1), recordWrite);
      for (const name of names) {
        assertEquals(f.state().containers[name].status, "Running");
        assertEquals(f.state().containers[name].snapshots.base, before[name].snapshots.base);
      }
      const result = f.refreshed()!;
      assertEquals({ ...result, seconds: undefined }, { migrate: "skipped", restart: "no", converge: "skipped",
        converge_reasons: { gateway: { reason: "unchanged" }, "app-dev": { reason: "unchanged" }, "app-prod": { reason: "unchanged" } },
        converge_nodes: [], host: "kept", changed: 0, tokens: "young", tokens_reason: "recorded", base: "kept", base_containers: [], record: "present", tools: "present",
        tools_reason: "current", shrink: "skipped", shrink_reason: "current", swamp: "pinned", swamp_reason: "pinned",
        swamp_version: "20261114.210101.0-sha.537e03d1", seconds: undefined });
      // Nothing changed in the nested containers' packages: one shrink check, no shrink run.
      assertEquals(calls.filter((call) => call.startsWith(shrinkCall)), [shrinkCall + "check " + shrinkArgs]);
      // The record vouches for the oldest token: no read and no remint.
      assertEquals([calls.filter((call) => call === tokenRead).length, ran(calls, "--tags remint")], [0, false]);
      assertEquals(Object.keys(result.seconds as object), ["gateway", "converge", "ready", "base", "tools"]);
      // Present tools: one read in the container, just before the record write.
      assertEquals(calls.slice(-2), [toolsCall, recordWrite]);
      assertEquals(f.state().outer.modes[record], "root:root 0644");
      // The record keeps the time of the last converge that ran; the seeded record of the short cases is the one a refresh writes.
      assertEquals(JSON.parse(f.state().outer.files[record]), { ...written, grants: sha256("a1 e1") });
      assertEquals(Object.keys(written), ["code", ...convergeKeys.toSorted(), ...convergedKeys.toSorted(), "grants", "host", "rules", "tokens"].toSorted());
      assertEquals(seededRecord(Number(written.converged_gateway), fixtureFleet, written.tokens), written);
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a docs-only release reads no token list, reloads no grants, places no delivery and reads no fleet file",
  ignore: !hasAnsible,
  fn() {
    const f = refreshedOnce();
    try {
      const again = f.run("refresh");
      assert(again.ok, again.output);
      const calls = f.calls();
      // What still runs: the Club key test, the record and digests, the mount wait, the two Swamp reads, one read of the services, the converge decision, the
      // devices and keys, the snapshots, one readiness wait, the tools read and the record (instance-fleet-refresh.yml).
      assertEquals(calls, [
        `test -s ${clubKey}`,
        ...recordReads,
        hostTreeRead,
        recordRemove,
        ...mountReads,
        ...swampReads,
        knownHostsList, knownHostsSync,
        serviceRead,
        intentRead,
        ...compareReads,
        ...names.map((name) => `incus query /1.0/instances/${name}/snapshots?recursion=1`),
        shrinkCall + "check " + shrinkArgs,
        ...readyCalls,
        toolsCall,
        recordWrite,
      ]);
      assertEquals(f.refreshed()?.tokens_reason, "recorded");
    } finally {
      f.cleanup();
    }
  },
});

// deno-lint-ignore no-explicit-any
const inputCases: [string, (state: Record<string, any>) => void, string[], string, string][] = [
  // A package's migration (the Composer lock in `apps/gateway`) moves the code digest only; the migrate applies it.
  // The Gateway's code moved but the base intent of each Node did not: no converge.
  // A migration does not retake base: a reset migrates after its restore (instance-fleet-migrate.yml).
  ["the code with a pending migration migrates and restarts, converges nothing and keeps base, which a reset migrates", (state) => {
    state.digests = { "apps/gateway": "a9" };
    state.pendingMigration = true;
  }, ["migrate", "restart"], "kept", "code"],
  ["the code without a pending migration migrates and restarts, converges nothing and keeps base",
    (state) => state.digests = { "apps/gateway": "a9" }, ["migrate", "restart"], "kept", "code"],
  ["a swamp file the base converge reads converges",
    (state) => state.digests = { "apps/gateway": "a1", "swamp/ansible/tasks/node-converge-base.yml": "c9" }, ["converge"], "kept", "swamp"],
  // The nested Gateway reads the agent contract from outside `apps/gateway`: it is part of the code.
  ["the agent contract migrates and restarts",
    (state) => state.digests = { "apps/gateway": "a1", "apps/agent/contract": "d9" }, ["migrate", "restart"], "kept", "agent"],
];
for (const [input, change, due, base, recorded] of inputCases) {
  Deno.test({
    name: `a changed input runs only its due parts: ${input}`,
    ignore: !hasAnsible,
    fn() {
      const f = refreshedOnce();
      try {
        const previous = JSON.parse(f.state().outer.files[record]).converged_app_dev;
        const before = Math.floor(Date.now() / 1000);
        f.change(change);
        const refreshed = f.run("refresh");
        assert(refreshed.ok, refreshed.output);
        const calls = f.calls();
        for (const [part, call] of Object.entries(parts)) assertEquals(ran(calls, call), due.includes(part), `${part}\n${calls.join("\n")}`);
        assertEquals(f.refreshed()?.base, base);
        // This exact record is the next case's boundary, not a digest calculated by the test.
        const written = JSON.parse(f.state().outer.files[record]);
        if (due.includes("converge")) assert(Number(written.converged_app_dev) >= before && Number(written.converged_app_dev) <= Math.floor(Date.now() / 1000));
        else assertEquals(written.converged_app_dev, previous);
        assertEquals(savedRecord(written), refreshedBoundary.records[recorded]);
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
      f.scopePhase("start", [
        "Name the fleet commands", "Read the state of the nested containers", "Start the nested containers",
        "Start the nested containers together", "Check that the nested containers started",
      ]);
      try {
        f.change((state) => {
          change(state);
          delete state.pendingMigration;
          state.outer.files[record] = JSON.stringify(currentRecord(refreshedBoundary.records[recorded]));
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
  name: "an inner bridge without no-resolv passes a start unwritten; the refresh sets it with the fleet stopped and retakes base of all three",
  ignore: !hasAnsible,
  fn() {
    const f = refreshedOnce();
    try {
      // The seed of a copy made before the rule: its bridge's dnsmasq still forwards to a resolver that refuses.
      f.change((state) => delete state.network.config["raw.dnsmasq"]);
      const started = f.run("start");
      assert(started.ok, started.output);
      assert(!f.calls().some((call) => call.includes(" network set ")));
      assertEquals(f.state().network.config, exportedBridge);
      const refreshed = f.run("refresh");
      assert(refreshed.ok, refreshed.output);
      const calls = f.calls();
      // The fake refuses a bridge change while a nested container runs.
      const set = calls.indexOf("incus network set oe-nested raw.dnsmasq=no-resolv");
      assert(set > calls.findLastIndex((call) => call.startsWith(parts.stop)), calls.join("\n"));
      assert(set < calls.findIndex((call) => call.startsWith(parts.base)), calls.join("\n"));
      assertEquals(f.state().network.config, ruledBridge);
      const result = f.refreshed()!;
      assertEquals([result.base, result.base_containers], ["retaken", names]);
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a start refuses with instance.container_fleet_drift when the inner bridge cannot be read",
  ignore: !hasAnsible,
  fn() {
    const f = fixture(ruled());
    f.change((state) => state.networkUnreadable = true);
    try {
      const refused = f.run("start");
      assert(!refused.ok);
      assertStringIncludes(refused.output, "where Orbit does not write: oe-nested network oe-nested");
      assertStringIncludes(refused.output, '"msg": "instance.container_fleet_drift"');
      assertEquals(f.calls(), startReads);
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
        state.digests = { "apps/gateway": "a1", "swamp/ansible/tasks/node-converge-base.yml": "c9" };
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
    f.scopePhase("start", [
      "Name the fleet commands", "Read the state of the nested containers", "Start the nested containers",
      "Start the nested containers together", "Check that the nested containers started",
    ]);
    try {
      const takes = (calls: string[]) => calls.filter((call) => call.startsWith("incus snapshot create "));
      assert(f.run("start").ok);
      f.change((state) => {
        state.digests = { "apps/gateway": "a1", "swamp/ansible/tasks/node-converge-base.yml": "c8" };
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
    f.scopePhase("start", [
      "Name the fleet commands", "Read the state of the nested containers", "Start the nested containers",
      "Start the nested containers together", "Check that the nested containers started",
    ]);
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

Deno.test({
  name: "a due shrink retakes base of all three with the relink and the dedupe between the old base's delete and the new base; then it is current",
  ignore: !hasAnsible,
  fn() {
    const f = refreshedOnce();
    try {
      const takes = (calls: string[]) => calls.filter((call) => call.startsWith("incus snapshot create "));
      // A nested container's package changed since the last shrink, and duperemove is not there yet: only the relink runs.
      f.change((state) => {
        state.shrink = { due: true, reason: "packages" };
        state.shrinkResult = { shrink: "relinked", relinked: 345, dedupe: "missing" };
      });
      f.calls();
      const relinked = f.run("refresh");
      assert(relinked.ok, relinked.output);
      let calls = f.calls();
      assert(!ran(calls, parts.converge), calls.join("\n"));
      const run = calls.indexOf(shrinkCall + "run " + shrinkArgs);
      const deletes = calls.flatMap((call, index) => call.startsWith("incus snapshot delete ") ? [index] : []);
      assertEquals(deletes.length, 3, calls.join("\n"));
      assert(run > Math.max(...deletes) && run < calls.indexOf(takes(calls)[0]), calls.join("\n"));
      assertEquals(takes(calls).length, 3);
      for (const name of names) assertEquals(f.state().containers[name].status, "Running");
      assertEquals(shrunk(f.refreshed()), ["retaken", "relinked", "packages"]);

      // Without its dedupe the shrink is due again, and with duperemove both parts run: base of all three again.
      f.change((state) => delete state.shrinkResult);
      assert(f.run("refresh").ok);
      calls = f.calls();
      assertEquals(takes(calls).length, 3);
      assertEquals(shrunk(f.refreshed()), ["retaken", "ran", "packages"]);

      // The next refresh finds it current: one check, no run, and base stays.
      const before = structuredClone(f.state().containers);
      assert(f.run("refresh").ok);
      calls = f.calls();
      assertEquals(calls.filter((call) => call.startsWith(shrinkCall)), [shrinkCall + "check " + shrinkArgs]);
      assertEquals(takes(calls), []);
      for (const name of names) assertEquals(f.state().containers[name].snapshots.base, before[name].snapshots.base);
      assertEquals(shrunk(f.refreshed()), ["kept", "skipped", "current"]);
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a shrink check or run that fails or prints no result never fails the refresh: a failed check keeps base, a failed run is named",
  ignore: !hasAnsible,
  fn() {
    const f = refreshedOnce();
    try {
      f.change((state) => state.shrinkExit = 124);
      f.calls();
      assert(f.run("refresh").ok);
      assertEquals(f.calls().filter((call) => call.startsWith("incus snapshot create ")), []);
      assertEquals(shrunk(f.refreshed()), ["kept", "skipped", "unreadable"]);
      f.change((state) => {
        delete state.shrinkExit;
        state.shrink = { due: true, reason: "record" };
        state.shrinkResult = { shrink: "failed", relinked: 0, dedupe: "failed" };
      });
      const failed = f.run("refresh");
      assert(failed.ok, failed.output);
      assertEquals(shrunk(f.refreshed()), ["retaken", "failed", "record"]);
    } finally {
      f.cleanup();
    }
  },
});

function failedRefreshBoundary() {
  const state = structuredClone(refreshedBoundary.state);
  state.digests = { "apps/gateway": "a1", "swamp/ansible/tasks/node-converge-base.yml": "c9" };
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
        state.digests = { "apps/gateway": "a1", "swamp/ansible/tasks/node-converge-base.yml": "c9" };
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
    f.scopePhase("start", [
      "Name the fleet commands", "Read the state of the nested containers", "Start the nested containers",
      "Start the nested containers together", "Check that the nested containers started",
    ]);
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

/** JSON as Ansible's `to_json(sort_keys=true)` writes it (Python's `json.dumps`): sorted keys, `, ` and `: ` between items. */
function pythonJson(value: unknown): string {
  if (Array.isArray(value)) return `[${value.map(pythonJson).join(", ")}]`;
  if (value !== null && typeof value === "object") {
    const entries = Object.entries(value as Record<string, unknown>).sort(([a], [b]) => a < b ? -1 : a > b ? 1 : 0);
    return `{${entries.map(([key, item]) => `${JSON.stringify(key)}: ${pythonJson(item)}`).join(", ")}}`;
  }
  return JSON.stringify(value);
}
const sha256 = (text: string) => createHash("sha256").update(text).digest("hex");
const fixtureHostDigest = (intent = "4".repeat(64)) => sha256(`host ${sha256(pythonJson([`100644 blob ${"5".repeat(40)}\tapps/gateway/resources/machine-software/recipe.json`,
  `100644 blob ${"3".repeat(40)}\tswamp/ansible/shared.yml`]))} ${intent}`);
/** A time as the record keeps the oldest token's creation: `YYYYMMDDHHMMSS`, UTC. */
const tokenMark = (milliseconds: number) => new Date(milliseconds).toISOString().slice(0, 19).replace(/[^0-9]/g, "");
/** The fleet files in the order the rules list them, as the refresh's `rules` digest reads them. */
const fleetFiles = fileRules.map(([container, path]) => ({ container, path,
  content: units.includes(path) ? dropIn : path === pool ? poolFile : path === marker ? markerFile : (ownedBy(container) as Record<string, string>)[path] }));
/**
 * The record a passed refresh of the fixture's default inputs writes, with `converged` at the given time (seconds) and the oldest
 * token made at `tokens` (a day ago by default, as the fake's tokens are).
 */
function seededRecord(converged: number | number[], fleet = fixtureFleet, tokens = tokenMark(Date.now() - 86400_000)): Record<string, string> {
  const times = Array.isArray(converged) ? converged : [converged, converged, converged];
  return {
    code: sha256("a1d4"),
    // One digest per Node: the listed `swamp` files, that Node's base intent digest and the fleet intent.
    ...Object.fromEntries(convergeKeys.map((key, index) =>
      [key, sha256([...convergeInputs.map(() => "c3"), String(index + 1).repeat(64)].join(" ") + " " + pythonJson(fleet))])),
    ...Object.fromEntries(convergedKeys.map((key, index) => [key, String(times[index])])),
    grants: sha256("a1 c3"),
    rules: sha256(pythonJson(fleetFiles)),
    host: fixtureHostDigest(),
    tokens,
  };
}
let fixtureFleet: unknown;
{
  const f = fixture(ruled());
  f.intent((value) => fixtureFleet = value.fleet);
  f.cleanup();
}
const nowSeconds = () => Math.floor(Date.now() / 1000);
/** A started fleet with the record of a passed refresh, so a short case needs only its own refresh. */
function seeded(converged: number | number[]) {
  const f = refreshedOnce();
  f.change((state) => {
    state.outer.files[record] = JSON.stringify(seededRecord(converged));
    state.doctorCalls = 0;
  });
  f.calls();
  return f;
}

Deno.test({
  name: "a moved base intent of one Node converges that Node only, and the result names it and why",
  ignore: !hasAnsible,
  fn() {
    const before = nowSeconds();
    const f = seeded(before - 60);
    try {
      f.change((state) => {
        state.intentDigests = { "app-dev": "9".repeat(64) };
        // Its converge skips the phases whose phase digest did not move, and names the others.
        state.convergePhases = { 2: [{ name: "input", status: "succeeded" }, { name: "agent", status: "unchanged" },
          { name: "software", status: "succeeded" }, { name: "roles", status: "succeeded" }, { name: "tools", status: "unchanged" },
          { name: "network", status: "unchanged" }, { name: "check", status: "succeeded" }] };
      });
      const refreshed = f.run("refresh");
      assert(refreshed.ok, refreshed.output);
      const calls = f.calls();
      assertEquals(calls.filter(convergeApi), convergeCallsFor([2]));
      // Only the due Node's update timers stop.
      assertEquals(calls.filter((call) => call.includes("systemctl stop apt-daily.timer")).map((call) => call.split(" ")[2]), ["nested-app-dev"]);
      // The digest is read after the migrate decision and before the agents are awaited.
      assert(calls.indexOf(intentRead) < calls.findIndex((call) => call.endsWith("--fail https://10.45.0.1/api/v1/nodes")), calls.join("\n"));
      const result = f.refreshed()!;
      assertEquals([result.converge, result.converge_nodes, result.restart, result.base], ["ran", ["app-dev"], "no", "kept"]);
      assertEquals(result.converge_reasons, { gateway: { reason: "unchanged" }, "app-dev": { reason: "digest_changed", phases: ["software", "roles"] },
        "app-prod": { reason: "unchanged" } });
      const written = JSON.parse(f.state().outer.files[record]);
      const seededOne = seededRecord(before - 60);
      // app-dev's digest and time move; the other two keep theirs.
      assert(written.converge_app_dev !== seededOne.converge_app_dev && Number(written.converged_app_dev) >= before, JSON.stringify(written));
      assertEquals([written.converge_gateway, written.converge_app_prod, written.converged_gateway, written.converged_app_prod],
        [seededOne.converge_gateway, seededOne.converge_app_prod, seededOne.converged_gateway, seededOne.converged_app_prod]);
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a Node whose converge is older than seven days converges alone although no input changed, and the result names it stale",
  ignore: !hasAnsible,
  fn() {
    const before = nowSeconds();
    const f = seeded([before - 60, before - 60, before - 8 * 86400]);
    f.scopePhase("refresh", {
      through: "Time the converge", then: ["Name what the refresh did", "Write the refresh record"],
    }, ["converge", "converge_reasons", "converge_nodes"]);
    try {
      const refreshed = f.run("refresh");
      assert(refreshed.ok, refreshed.output);
      assertEquals(f.calls().filter(convergeApi), convergeCallsFor([3]));
      const result = f.refreshed()!;
      assertEquals([result.converge, (result.converge_reasons as Record<string, unknown>)["app-prod"], result.converge_nodes], ["ran", { reason: "stale" }, ["app-prod"]]);
      const written = JSON.parse(f.state().outer.files[record]);
      assertEquals(written.converge_app_prod, seededRecord(0).converge_app_prod);
      assert(Number(written.converged_app_prod) >= before, JSON.stringify(written));
      assertEquals(written.converged_gateway, String(before - 60));
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "an unreadable base intent digest converges, names why, and records no converge digest",
  ignore: !hasAnsible,
  fn() {
    const f = seeded(nowSeconds() - 60);
    f.scopePhase("refresh", {
      through: "Time the converge", then: ["Name what the refresh did", "Write the refresh record"],
    }, ["converge", "record", "converge_reasons"]);
    try {
      f.change((state) => state.intentDigestFail = true);
      const refreshed = f.run("refresh");
      assert(refreshed.ok, refreshed.output);
      // The same read carries the Instance host intent: unreadable, the host is due too, after the two bases.
      assertEquals(f.calls().filter(convergeApi), convergeCallsFor([1, 2, 3], true));
      const result = f.refreshed()!;
      assertEquals([result.converge, result.record], ["ran", "present"]);
      for (const role of ["gateway", "app-dev", "app-prod"]) {
        assertEquals((result.converge_reasons as Record<string, unknown>)[role], { reason: "digest_unreadable", error: "the digest read failed: Error: The Node app-dev is unknown." });
      }
      // Without its digest the next refresh converges again.
      assertEquals(Object.keys(JSON.parse(f.state().outer.files[record])), ["code", ...convergedKeys.toSorted(), "grants", "rules", "tokens"]);
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "one Node whose intent cannot be built converges alone and is named unreadable with its error; the others stay unchanged",
  ignore: !hasAnsible,
  fn() {
    const f = seeded(nowSeconds() - 60);
    f.scopePhase("refresh", {
      through: "Time the converge", then: ["Name what the refresh did", "Write the refresh record"],
    }, ["converge_reasons"]);
    try {
      f.change((state) => state.intentDigestErrors = { "app-dev": "RuntimeException: The Node has no Instance bridge yet." });
      const refreshed = f.run("refresh");
      assert(refreshed.ok, refreshed.output);
      assertEquals(f.calls().filter(convergeApi), convergeCallsFor([2]));
      const result = f.refreshed()!;
      assertEquals(result.converge_reasons, { gateway: { reason: "unchanged" },
        "app-dev": { reason: "digest_unreadable", error: "RuntimeException: The Node has no Instance bridge yet." }, "app-prod": { reason: "unchanged" } });
      // Without its digest app-dev is due again at the next refresh; the other two keep theirs.
      const written = JSON.parse(f.state().outer.files[record]);
      assert(!("converge_app_dev" in written) && "converge_gateway" in written && "converge_app_prod" in written, JSON.stringify(written));
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "tokens older than the bound are reminted before the services restart and the converge, and only the nested Gateway's base is retaken",
  ignore: !hasAnsible,
  fn() {
    const f = refreshedOnce();
    try {
      // One token is 21 days old, and the record says so; a Node's intent moved too, so the converge shows that it waits for the remint.
      f.change((state) => {
        const old = new Date(Date.now() - 21 * 86400_000).toISOString();
        const young = new Date(Date.now() - 86400_000).toISOString();
        state.outer.files[record] = JSON.stringify({ ...JSON.parse(state.outer.files[record]), tokens: tokenMark(Date.now() - 21 * 86400_000) });
        state.tokens = Object.fromEntries(["serve-driver", "orbit-gateway-run", "orbit-gateway-read", "orbit-worker-a-access",
          "orbit-worker-b-access", "orbit-worker-c-access"].map((token) => [token, token === "orbit-gateway-read" ? old : young]));
        state.intentDigests = { "app-dev": "9".repeat(64) };
      });
      const refreshStarted = tokenMark(Date.now());
      const refreshed = f.run("refresh");
      const refreshEnded = tokenMark(Date.now());
      assert(refreshed.ok, refreshed.output);
      const calls = f.calls();
      const at = (call: string) => calls.indexOf(call);
      const restart = calls.findIndex((call) => call.includes(parts.restart));
      const workerC = calls.indexOf(serviceRead);
      // Read, remint, restart of the services that read the new tokens, the check that serve and the three workers run, then the converge.
      assert(at(tokenRead) >= 0 && at(tokenRead) < at(remint) && at(remint) < restart && restart < workerC
        && workerC < calls.findIndex(convergeApi), calls.join("\n"));
      const result = f.refreshed()!;
      assertEquals([result.tokens, result.tokens_reason, result.restart, result.base_containers], ["reminted", "age", "yes", ["nested-gateway"]]);
      assertEquals(f.state().reminted, 1);
      assert(!tokenShaped.test(refreshed.output), "a token-shaped string is in the output");
      // The record now holds the remint's time, so the next refresh reads no list.
      const remintedAt = JSON.parse(f.state().outer.files[record]).tokens;
      assert(remintedAt >= refreshStarted && remintedAt <= refreshEnded, JSON.stringify({ remintedAt, refreshStarted, refreshEnded }));
      f.scopePhase("refresh", {
        through: "Renew the inner Swamp tokens", then: ["Name what the refresh did"],
      }, ["tokens_reason"]);
      assert(f.run("refresh").ok);
      assertEquals([f.calls().includes(tokenRead), f.refreshed()?.tokens_reason], [false, "recorded"]);
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a refresh keeps the recorded Instance host, and a changed host digest converges nested-app-dev's app-dev role with its base",
  ignore: !hasAnsible,
  fn() {
    const f = refreshedOnce();
    f.scopePhase("refresh", {
      through: "Time the converge", then: ["Name what the refresh did", "Write the refresh record"],
    }, ["host", "converge_nodes", "converge_reasons"]);
    try {
      const kept = f.run("refresh");
      assert(kept.ok, kept.output);
      assertEquals([f.refreshed()!.host, f.calls().filter((call) => call.includes("/converge ")).length], ["kept", 0]);
      f.change((state) => {
        state.outer.files[record] = JSON.stringify({ ...JSON.parse(state.outer.files[record]), host: "0".repeat(64) });
        delete state.roleConverges;
      });
      const changed = f.run("refresh");
      assert(changed.ok, changed.output);
      assertEquals(f.calls().filter((call) => call.includes("/converge ")), [convergeAdmit(2, true)]);
      const result = f.refreshed()!;
      assertEquals([result.host, result.converge_nodes, f.state().roleConverges], ["converged", ["app-dev"], [2]]);
      // app-dev's base digest did not move: it converges because the host part is due, and the result says so.
      assertEquals(result.converge_reasons, { gateway: { reason: "unchanged" }, "app-dev": { reason: "host_due" }, "app-prod": { reason: "unchanged" } });
      assert(JSON.parse(f.state().outer.files[record]).host !== "0".repeat(64));
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "the window's first refresh with all three Nodes and the host due admits nested-app-dev's role converge only after the base converges ended",
  ignore: !hasAnsible,
  fn() {
    const f = refreshedOnce();
    f.scopePhase("refresh", {
      through: "Time the converge", then: ["Name what the refresh did", "Write the refresh record"],
    }, ["host"]);
    try {
      // The window's deployment: the fleet intent changed, so every Node's converge digest differs, and the record holds no `host`.
      f.change((state) => {
        const { host: _, ...older } = JSON.parse(state.outer.files[record]);
        state.outer.files[record] = JSON.stringify({ ...older, ...Object.fromEntries(convergeKeys.map((key) => [key, "0".repeat(64)])) });
        delete state.roleConverges;
      });
      const refreshed = f.run("refresh");
      assert(refreshed.ok, refreshed.output);
      assertEquals(f.calls().filter(convergeApi), convergeCallsFor([1, 2, 3], true));
      assertEquals([f.refreshed()!.host, f.state().roleConverges], ["converged", [2]]);
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "the Instance host ignores exact housekeeping paths but converges for new paths or an unreadable checkout",
  ignore: !hasAnsible,
  fn() {
    const f = refreshedOnce();
    const checkout = Deno.makeTempDirSync();
    const git = (...args: string[]) => {
      const result = new Deno.Command("git", { args: ["-C", checkout, "-c", "user.name=Fixture", "-c", "user.email=fixture@example.invalid", ...args] }).outputSync();
      assert(result.success, new TextDecoder().decode(result.stderr));
    };
    const commit = (path: string, text: string) => {
      Deno.mkdirSync(`${checkout}/${path.slice(0, path.lastIndexOf("/"))}`, { recursive: true });
      Deno.writeTextFileSync(`${checkout}/${path}`, text);
      git("add", "--", path);
      git("commit", "-qm", "Change a fixture input");
    };
    const refresh = (due: boolean) => {
      const result = f.run("refresh");
      assert(result.ok, result.output);
      assertEquals(f.calls().filter((call) => call.includes("/converge ")), due ? [convergeAdmit(2, true)] : []);
      assertEquals(f.refreshed()!.host, due ? "converged" : "kept");
      return JSON.parse(f.state().outer.files[record]);
    };
    try {
      git("init", "-q");
      const housekeeping = "swamp/ansible/tasks/swamp-housekeeping.yml";
      commit(housekeeping, "stock housekeeping");
      commit("swamp/ansible/tasks/instance-fleet-node.yml", "stock roles");
      commit("apps/gateway/resources/machine-software/recipe.json", "stock recipe");
      f.change((state) => {
        state.hostCheckout = checkout;
        const previous = JSON.parse(state.outer.files[record]);
        delete previous.host;
        state.outer.files[record] = JSON.stringify(previous);
      });
      const calibrated = refresh(true);
      const bases = Object.fromEntries(names.map((name) => [name, f.state().containers[name].snapshots]));
      commit(housekeeping, "updated housekeeping");
      assertEquals(refresh(false).host, calibrated.host);
      assertEquals(Object.fromEntries(names.map((name) => [name, f.state().containers[name].snapshots])), bases);
      commit(`swamp/ansible/unknown/${housekeeping}`, "a new path with the excluded suffix");
      const changed = refresh(true);
      assert(changed.host && changed.host !== calibrated.host);
      f.change((state) => state.hostCheckout = `${checkout}/missing`);
      const unreadable = refresh(true);
      assertEquals(unreadable.host, undefined);
      assertEquals(unreadable.converge_gateway, calibrated.converge_gateway);
      assertEquals(unreadable.converge_app_prod, calibrated.converge_app_prod);
    } finally {
      f.cleanup();
      Deno.removeSync(checkout, { recursive: true });
    }
  },
});

Deno.test({
  name: "a moved Instance host intent, such as another image package, rebuilds the host, and that converge is awaited up to 1800 s",
  ignore: !hasAnsible,
  fn() {
    const f = refreshedOnce();
    f.scopePhase("refresh", {
      through: "Time the converge", then: ["Name what the refresh did", "Write the refresh record"],
    }, ["host"]);
    try {
      // Run a short pending sequence and assert the rendered retry budget below. Replaying 200 polling subprocesses
      // only to cross the base converge's 180 retries spends CPU without testing another state transition.
      f.change((state) => {
        state.hostIntentDigest = "5".repeat(64);
        state.convergeRunning = { 2: 2 };
        delete state.roleConverges;
      });
      const refreshed = f.run("refresh");
      assert(refreshed.ok, refreshed.output);
      assertStringIncludes(refreshed.output, "FAILED - RETRYING: [localhost]: Wait for the converge of each due nested Node (360 retries left)");
      assertEquals(f.calls().filter((call) => call.includes("/converge ")), [convergeAdmit(2, true)]);
      assertEquals([f.refreshed()!.host, f.state().roleConverges, f.state().convergeRunning], ["converged", [2], { 2: 0 }]);
      assertEquals(JSON.parse(f.state().outer.files[record]).host, fixtureHostDigest("5".repeat(64)));
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a default below 2,000,000 builds no Instance host: the refresh names skipped_idmap_small and converges nested-app-dev's base only",
  ignore: !hasAnsible,
  fn() {
    const f = fixture(ruled(), { club: true, base: true, host: false });
    f.scopePhase("refresh", {
      through: "Time the converge", then: ["Name what the refresh did", "Write the refresh record"],
    }, ["host"]);
    f.change((state) => {
      for (const key of Object.keys(state)) delete state[key];
      Object.assign(state, structuredClone(refreshedBoundary.state));
      const { host: _, ...older } = JSON.parse(state.outer.files[record]);
      state.outer.files[record] = JSON.stringify(currentRecord(older));
      delete state.roleConverges;
    });
    try {
      const refreshed = f.run("refresh");
      assert(refreshed.ok, refreshed.output);
      // nested-app-dev keeps the base scope, so its role converge does not run.
      assertEquals([f.refreshed()!.host, f.calls().filter((call) => call.includes('/nodes/2/converge') && call.endsWith('{"base":false}')).length,
        f.state().roleConverges], ["skipped_idmap_small", 0, undefined]);
      assertEquals(JSON.parse(f.state().outer.files[record]).host, undefined);
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a fleet without worker c remints at once and names it incomplete",
  ignore: !hasAnsible,
  fn() {
    const f = refreshedOnce();
    try {
      // The fleet of the first build: workers a and b only, all tokens one day old; the bound is the default. Its record, written
      // before the record kept the oldest token, has no time for it, so the list is read.
      f.change((state) => {
        const { tokens: _, ...older } = JSON.parse(state.outer.files[record]);
        state.outer.files[record] = JSON.stringify(older);
        const young = new Date(Date.now() - 86400_000).toISOString();
        state.tokens = Object.fromEntries(["serve-driver", "orbit-gateway-run", "orbit-gateway-read", "orbit-worker-a-access",
          "orbit-worker-b-access"].map((token) => [token, young]));
      });
      const first = f.run("refresh");
      assert(first.ok, first.output);
      assert(ran(f.calls(), "--tags remint"));
      const result = f.refreshed()!;
      assertEquals([result.tokens, result.tokens_reason, result.base_containers], ["reminted", "incomplete", ["nested-gateway"]]);
      // The remint made worker c and renewed all six: the next refresh finds them young, as the unchanged case shows.
      assertEquals(f.state().containers["nested-gateway"].files["/etc/orbit/swamp-role-worker/c/server.token"], "made");
      assertEquals(Object.keys(f.state().tokens).length, 6);
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a failed remint fails the refresh with its step, takes no base, writes no record and prints no token; an unreadable list remints",
  ignore: !hasAnsible,
  fn() {
    const f = refreshedOnce();
    try {
      f.change((state) => {
        const { tokens: _, ...older } = JSON.parse(state.outer.files[record]);
        state.outer.files[record] = JSON.stringify(older);
        state.tokensUnreadable = true;
        state.remintFail = true;
      });
      const failed = f.run("refresh");
      assert(!failed.ok);
      assertStringIncludes(failed.output, "Renew the inner Swamp tokens");
      const calls = f.calls();
      assert(ran(calls, "--tags remint"));
      assert(!calls.some((call) => call.includes(parts.converge) || call.includes("snapshot create") || call === recordWrite), calls.join("\n"));
      assertEquals(f.state().outer.files[record], undefined);
      assert(!tokenShaped.test(failed.output), "a token-shaped string is in the output");
      assertKeyUntouched(calls, failed.output);
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a moved Swamp pin installs the recipe's release on the nested Gateway, retakes its base and names the release; the next refresh only reads",
  ignore: !hasAnsible,
  fn() {
    const f = refreshedOnce();
    try {
      // The nested Gateway's serve still runs the release the fleet build installed, by its digest folder.
      f.change((state) => state.swampRunning = "/usr/local/lib/orbit-swamp/" + "6e0fe06f".repeat(8) + "/swamp");
      const first = f.run("refresh");
      assert(first.ok, first.output);
      const calls = f.calls();
      const play = calls.filter((call) => call.includes("--tags swamp-pin"));
      // Through the egress proxy, after the two reads and before the services are read.
      assertEquals(play, [swampPinPlay(Object.entries(proxy).map(([key, value]) => `${key}=${value} `).join(""))]);
      assert(calls.indexOf(swampReads[1]) < calls.indexOf(play[0]) && calls.indexOf(play[0]) < calls.indexOf(serviceRead), calls.join("\n"));
      const result = f.refreshed()!;
      assertEquals([result.swamp, result.swamp_reason, result.swamp_version], ["installed", "changed", "20261114.210101.0-sha.537e03d1"]);
      // The install wrote the nested Gateway's disk: its base is retaken.
      assert((result.base_containers as string[]).includes("nested-gateway"), JSON.stringify(result));
      f.scopePhase("refresh", [
        "Name the fleet commands", "Look for the Club key", "Read the recipe's Swamp release", "Read the binary that serve runs",
        "Work out whether the recipe's Swamp is due", "Install the recipe's Swamp on the nested Gateway", "Name what the refresh did",
      ], ["swamp", "swamp_reason", "swamp_version"]);
      const again = f.run("refresh");
      assert(again.ok, again.output);
      // The calls and the result are this run's: two reads, no install.
      const next = f.calls();
      assertEquals([next.filter((call) => swampReads.includes(call)).length, next.filter((call) => call.includes("--tags swamp-pin")).length], [2, 0]);
      const pinned = f.refreshed()!;
      assertEquals([pinned.swamp, pinned.swamp_reason], ["pinned", "pinned"]);
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a Swamp install whose serve does not come back fails the refresh with its step, takes no base and writes no record",
  ignore: !hasAnsible,
  fn() {
    const f = refreshedOnce();
    try {
      f.change((state) => {
        // Serve runs the release the fleet build installed; the recipe pins a newer one.
        state.swampRunning = "/usr/local/lib/orbit-swamp/0171346a3588019a73a0e9d28e64447688ad87261e6eceb49b3febf6bd603da3/swamp";
        state.swampPin = "20261201.000000.0-sha.0123abcd";
        state.swampSha = "0123abcd".repeat(8);
        state.swampPinFail = true;
      });
      const failed = f.run("refresh");
      assert(!failed.ok, failed.output);
      assertStringIncludes(failed.output, "Install the recipe's Swamp on the nested Gateway");
      const calls = f.calls();
      assert(ran(calls, "--tags swamp-pin"));
      assert(!calls.some((call) => call.includes("snapshot create") || call === recordWrite), calls.join("\n"));
      assertEquals(f.state().outer.files[record], undefined);
      assertKeyUntouched(calls, failed.output);
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a fleet built at the recipe's release only reads; a read that fails runs the install and names no release",
  ignore: !hasAnsible,
  fn() {
    const f = refreshedOnce();
    try {
      f.scopePhase("refresh", [
        "Name the fleet commands", "Look for the Club key", "Read the recipe's Swamp release", "Read the binary that serve runs",
        "Work out whether the recipe's Swamp is due", "Install the recipe's Swamp on the nested Gateway", "Name what the refresh did",
      ], ["swamp", "swamp_reason", "swamp_version"]);
      // The fleet build's own folder of the recipe's sha256: nothing to install.
      const built = f.run("refresh");
      assert(built.ok, built.output);
      assert(!f.calls().some((call) => call.includes("--tags swamp-pin")));
      const pinned = f.refreshed()!;
      assertEquals([pinned.swamp, pinned.swamp_reason, pinned.swamp_version], ["pinned", "pinned", "20261114.210101.0-sha.537e03d1"]);
      f.change((state) => state.swampUnreadable = true);
      const run = f.run("refresh");
      assert(run.ok, run.output);
      assertEquals(f.calls().filter((call) => call.includes("--tags swamp-pin")).length, 1);
      const result = f.refreshed()!;
      assertEquals([result.swamp, result.swamp_reason, result.swamp_version], ["installed", "unreadable", null]);
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a refresh without the Club key reads no Swamp release and installs none",
  ignore: !hasAnsible,
  fn() {
    const f = refreshedOnce();
    f.scopePhase("refresh", [
      "Name the fleet commands", "Look for the Club key", "Read the recipe's Swamp release", "Read the binary that serve runs",
      "Work out whether the recipe's Swamp is due", "Install the recipe's Swamp on the nested Gateway", "Name what the refresh did",
    ], ["swamp", "swamp_reason", "swamp_version"]);
    try {
      f.change((state) => {
        delete state.outer.files[clubKey];
        state.swampRunning = "/usr/local/lib/orbit-swamp/other/swamp";
      });
      const run = f.run("refresh");
      assert(run.ok, run.output);
      const calls = f.calls();
      assert(!calls.some((call) => swampReads.includes(call) || call.includes("--tags swamp-pin")), calls.join("\n"));
      const result = f.refreshed()!;
      assertEquals([result.swamp, result.swamp_reason, result.swamp_version], ["skipped", "no_key", null]);
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a container without the pinned shape tools gets them after base and before the record, and the next refresh only reads them",
  ignore: !hasAnsible,
  fn() {
    const f = refreshedOnce();
    try {
      f.change((state) => state.tools = "missing");
      const first = f.run("refresh");
      assert(first.ok, first.output);
      const calls = f.calls();
      // After the last write to a nested disk, and before the record: the seed after this phase holds the tools.
      assertEquals(calls.slice(-2), [toolsCall, recordWrite]);
      const built = f.refreshed()!;
      assertEquals([built.tools, built.tools_reason], ["built", "missing"]);
      assertEquals(Object.keys(built.seconds as object), ["gateway", "converge", "ready", "base", "tools"]);
      const again = f.run("refresh");
      assert(again.ok, again.output);
      assertEquals(f.calls().filter((call) => call === toolsCall).length, 1);
      const read = f.refreshed()!;
      assertEquals([read.tools, read.tools_reason], ["present", "current"]);
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a failed tools install never fails the refresh: it writes the record and names the step, a timeout or a lost result line",
  ignore: !hasAnsible,
  fn() {
    const f = refreshedOnce();
    f.scopePhase("refresh", {
      through: "Name the due converge", then: [
        "Keep the pinned shape tools in the container", "Read what the tools step did", "Report a failed tools step",
        "Name what the refresh did", "Write the refresh record",
      ],
    }, ["tools", "tools_reason"]);
    try {
      for (const [edit, reason] of [
        [(state: Record<string, unknown>) => Object.assign(state, { tools: "missing", toolsFail: "venv" }), "venv"],
        [(state: Record<string, unknown>) => Object.assign(state, { toolsFail: undefined, toolsExit: 124 }), "timeout"],
        [(state: Record<string, unknown>) => Object.assign(state, { toolsExit: 1 }), "unreadable"],
      ] as const) {
        f.change(edit);
        const refreshed = f.run("refresh");
        assert(refreshed.ok, refreshed.output);
        assertEquals(f.calls().at(-1), recordWrite);
        const result = f.refreshed()!;
        assertEquals([result.tools, result.tools_reason], ["failed", reason]);
        // An ok task prints no standard error: the report shows the end of the step's own output.
        assertStringIncludes(refreshed.output, `The pinned shape tools failed at ${reason}`);
      }
      assertEquals(f.state().tools, "missing");
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a refresh whose migrate applied one keeps base and the fleet running, and a reset after it migrates up to the new schema",
  ignore: !hasAnsible,
  fn() {
    const f = refreshedOnce();
    try {
      const before = structuredClone(f.state().containers);
      f.change((state) => {
        state.digests = { "apps/gateway": "a9" };
        state.checkoutSchema = 1;
      });
      const refreshed = f.run("refresh");
      assert(refreshed.ok, refreshed.output);
      const calls = f.calls();
      // No stop and no snapshot: base keeps the older schema, and every nested container keeps running.
      assertEquals(calls.filter((call) => /^incus (stop|snapshot create) /.test(call)), []);
      const state = f.state();
      assertEquals(state.containers["nested-gateway"].files["/home/orbit/.orbit/schema"], "1");
      for (const name of names) {
        assertEquals(state.containers[name].status, "Running");
        assertEquals(state.containers[name].snapshots.base, before[name].snapshots.base);
      }
      const result = f.refreshed()!;
      assertEquals([result.migrate, result.restart, result.base, result.base_containers], ["applied", "yes", "kept", []]);
      // A reset restores base, with the older schema, waits for the state mounts (still activating right after the restore), then
      // migrates forward and restarts before its readiness wait.
      f.change((value) => value.activating = 1);
      const reset = f.run("reset");
      assert(reset.ok, reset.output);
      const resetCalls = f.calls();
      const migrate = resetCalls.findIndex((call) => call.endsWith("php artisan migrate --force"));
      const mounts = resetCalls.map((call, index) => call.includes("systemctl is-active home-orbit") ? index : -1).filter((index) => index >= 0);
      // The first read finds the mounts activating and is read again; every read comes before the migrate.
      assert(mounts.length >= 3 && mounts.every((index) => index < migrate), resetCalls.join("\n"));
      const restart = resetCalls.findIndex((call) => call.includes("systemctl restart orbit-gateway-horizon.service"));
      const doctor = resetCalls.findIndex((call) => call.includes("/doctor"));
      assert(migrate >= 0 && migrate < restart && restart < doctor, resetCalls.join("\n"));
      assertEquals(f.state().containers["nested-gateway"].files["/home/orbit/.orbit/schema"], "1");
      // Every reset restores the same base, with its older schema, and migrates forward again; a refresh retakes base only for another
      // change of the nested Gateway's disk.
      const again = f.run("reset");
      assert(again.ok, again.output);
      assert(f.calls().some((call) => call.includes("systemctl restart orbit-gateway-horizon.service")));
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
      // The `swamp` tree moved, so the grants reload; the reload changed two files.
      f.change((state) => Object.assign(state, { grantsChanged: 2, digests: { "apps/gateway": "a1", swamp: "e2" } }));
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
  name: "a base retake whose run read no fleet file reads them first, and a file that differs retakes base of all three",
  ignore: !hasAnsible,
  fn() {
    const f = refreshedOnce();
    try {
      // A hand edit changed the fleet's apt file in app-prod; then a release reloads the grants, which changes the nested Gateway's
      // disk. The files' digest did not move and no converge is due.
      f.change((state) => {
        state.containers["nested-app-prod"].files[aptPath] = "Acquire::http::Proxy \"http://10.232.2.1:3142\";\n";
        state.grantsChanged = 1;
        state.digests = { "apps/gateway": "a1", swamp: "e2" };
      });
      const refreshed = f.run("refresh");
      assert(refreshed.ok, refreshed.output);
      const calls = f.calls();
      const write = push("nested-app-prod", aptPath);
      assert(calls.indexOf(write) >= 0 && calls.indexOf(write) < calls.findIndex((call) => call.startsWith(parts.stop)), calls.join("\n"));
      assertEquals(f.refreshed()?.base_containers, names);
      assertEquals(f.state().containers["nested-app-prod"].snapshots.base.files[aptPath], aptFile);
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a start after base that fails fails the refresh and writes no record",
  ignore: !hasAnsible,
  fn() {
    const f = refreshedOnce();
    try {
      f.change((state) => {
        state.grantsChanged = 1;
        state.digests = { "apps/gateway": "a1", swamp: "e2" };
        state.failStart = { "nested-gateway": "Failed to start device \"eth0\": Device or resource busy" };
      });
      const failed = f.run("refresh");
      assert(!failed.ok);
      assertStringIncludes(failed.output, "nested-gateway did not start");
      const calls = f.calls();
      assert(calls.includes("incus snapshot create nested-gateway base") && !calls.includes(recordWrite), calls.join("\n"));
      assertEquals(f.state().outer.files[record], undefined);
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "every refresh reads the services once and names only the one that is down, among active ones",
  ignore: !hasAnsible,
  fn() {
    const f = refreshedOnce();
    f.scopePhase("refresh", ["Name the fleet commands", "Look for the Club key", "Read the services", "Check the services"]);
    try {
      // `systemctl is-active` exits 0 when one unit is active: the refresh checks each line, so one stopped worker fails it.
      f.change((state) => state.inactive = ["orbit-swamp-worker@b.service"]);
      const failed = f.run("refresh");
      assert(!failed.ok);
      assertStringIncludes(failed.output, "orbit-swamp-worker@b.service not active in nested-gateway");
      assert(!failed.output.includes("orbit-swamp-worker@a.service not active"), failed.output);
      assertEquals(f.calls().filter((call) => call === serviceRead).length, 1);
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a refresh without the Club key records no grants digest, so the grants reload once the key is there",
  ignore: !hasAnsible,
  fn() {
    const f = refreshedOnce();
    try {
      f.change((state) => delete state.outer.files[clubKey]);
      assert(f.run("refresh").ok);
      assert(!ran(f.calls(), "--tags grants"));
      assertEquals(JSON.parse(f.state().outer.files[record]).grants, undefined);
      f.change((state) => state.outer.files[clubKey] = clubValue);
      assert(f.run("refresh").ok);
      assert(ran(f.calls(), "--tags grants"));
      assertEquals(JSON.parse(f.state().outer.files[record]).grants, sha256("a1 c3"));
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
        state.digests = { "apps/gateway": "a1", swamp: "e2" };
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

Deno.test({
  name: "only the start of a stopped clone writes the proxy bypass, and restarts T3 once when the write changed it",
  ignore: !hasAnsible,
  fn() {
    const f = fixture(withStatus("Running"), { club: true });
    f.scopePhase("start", ["Name the fleet commands", "Keep the inner fleet off the egress proxy in a started copy"]);
    f.change((state) => state.tunnel = structuredClone(tunnelUp));
    const proxyCalls = (calls: string[]) => calls.filter((call) => call.startsWith("python3 -c <instance_node_services.py> proxy") || call.includes("try-restart"));
    const restart = ["-e", "fleet_proxy_restart_t3=true"];
    try {
      // A deployment's or a create's fleet start: no write and no restart.
      const plain = f.run("start");
      assert(plain.ok, plain.output);
      assertEquals(proxyCalls(f.calls()), []);
      assertEquals(f.state().outer.proxy, undefined);
      // instance.start of a copy that this Operation started: the one writer runs, and T3 restarts once because the value changed.
      const first = f.run("start", restart);
      assert(first.ok, first.output);
      assertEquals(proxyCalls(f.calls()), ["python3 -c <instance_node_services.py> proxy", "systemctl try-restart -- t3.service"]);
      assertEquals(f.state().outer.proxy, { NO_PROXY: outerBypass, no_proxy: outerBypass });
      // A copy that already has the value: the writer runs, T3 stays.
      const again = f.run("start", restart);
      assert(again.ok, again.output);
      assertEquals(proxyCalls(f.calls()), ["python3 -c <instance_node_services.py> proxy"]);
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a start of a copy that was already running writes the proxy bypass but keeps its T3; a failed write keeps its code",
  ignore: !hasAnsible,
  fn() {
    const restart = ["-e", "fleet_proxy_restart_t3=true"];
    const f = fixture(withStatus("Running"), { club: true });
    f.scopePhase("start", ["Name the fleet commands", "Keep the inner fleet off the egress proxy in a started copy"]);
    // The container last started at 22:58:00Z, before the Operation's admission at 22:59:00Z: somebody may work in it.
    f.change((state) => {
      state.tunnel = structuredClone(tunnelUp);
      state.outer.lastUsedAt = "2026-11-13T22:58:00.000000000Z";
    });
    try {
      const running = f.run("start", restart);
      assert(running.ok, running.output);
      const calls = f.calls();
      assert(calls.includes("python3 -c <instance_node_services.py> proxy"), calls.join("\n"));
      assert(!calls.some((call) => call.includes("try-restart")), calls.join("\n"));
      assertEquals(f.state().outer.proxy, { NO_PROXY: outerBypass, no_proxy: outerBypass });
      f.change((state) => {
        delete state.outer.proxy;
        state.outer.proxyConflict = true;
      });
      const conflict = f.run("start", restart);
      assert(!conflict.ok);
      assertStringIncludes(conflict.output, "instance.node_service_unit_conflict");
      assert(!f.calls().some((call) => call.includes("try-restart")));
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a deployment starts an older fleet, refreshes its absent caller map with all Nodes stopped, and resets from the upgraded base",
  ignore: !hasAnsible,
  fn() {
    const old = ruled();
    delete old["nested-app-prod"].devices.checkout;
    delete old["nested-app-prod"].config["raw.idmap"];
    const f = fixture(old, { base: true });
    try {
      const started = f.run("start", ["-e", "fleet_deploy_allow_missing_build=true"]);
      assert(started.ok, started.output);
      assertEquals(writes(f.calls()), []);
      const refreshed = f.run("refresh");
      assert(refreshed.ok, refreshed.output);
      const calls = f.calls();
      const map = calls.indexOf(`incus config set nested-app-prod raw.idmap=${idmap}`);
      assert(map >= 0, calls.join("\n"));
      for (const name of names) {
        const stopped = calls.indexOf(`incus stop ${name} --timeout 90`);
        assert(stopped >= 0 && stopped < map);
        assert(calls.indexOf(`incus snapshot create ${name} base`) > map);
      }
      const base = f.state().containers["nested-app-prod"].snapshots.base;
      assertEquals(base.config["raw.idmap"], idmap);
      assertEquals(base.devices.checkout, ruled()["nested-app-prod"].devices.checkout);
      // The next proof uses a strict reset, with no deployment admission flag.
      const reset = f.run("reset");
      assert(reset.ok, reset.output);
      assertEquals(f.state().containers["nested-app-prod"].devices.checkout, base.devices.checkout);
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "standalone starts and proof resets refuse absent caller maps, and deployment starts still refuse conflicting maps",
  ignore: !hasAnsible,
  fn() {
    for (const phase of ["check", "start", "resume", "reset"]) {
      const old = ruled();
      delete old["nested-app-prod"].config["raw.idmap"];
      const f = fixture(old, { base: true });
      try {
        const refused = f.run(phase);
        assert(!refused.ok, phase);
        assertStringIncludes(refused.output, "instance.container_fleet_drift");
        assertStringIncludes(refused.output, "nested-app-prod config raw.idmap");
        assert(!f.calls().some((call) => call.startsWith("incus config set")));
      } finally {
        f.cleanup();
      }
    }
    const wrong = ruled();
    wrong["nested-app-prod"].config["raw.idmap"] = "both 1000 1000";
    const f = fixture(wrong, { base: true });
    try {
      for (const phase of ["start", "resume"]) {
        const refused = f.run(phase, ["-e", "fleet_deploy_allow_missing_build=true"]);
        assert(!refused.ok);
        assertStringIncludes(refused.output, "instance.container_fleet_drift");
        assertEquals(writes(f.calls()), []);
      }
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a failed upgrade resumes the older fleet restored from its admitted seed without writing missing maps",
  ignore: !hasAnsible,
  fn() {
    const old = ruled();
    delete old["nested-app-prod"].devices.checkout;
    delete old["nested-app-prod"].config["raw.idmap"];
    const f = fixture(old, { base: true });
    try {
      const admitted = f.run("start", ["-e", "fleet_deploy_allow_missing_build=true"]);
      assert(admitted.ok, admitted.output);
      f.calls();
      // The outer seed contains the admitted inner fleet. Restore returns those containers stopped, including their old base.
      // The outer seed capture/restore itself is covered by ansible_instance_container_test.ts.
      const seed = structuredClone(f.state().containers);
      f.change((state) => {
        state.containers = seed;
        for (const name of names) state.containers[name].status = "Stopped";
      });
      const resumed = f.run("resume", ["-e", "fleet_deploy_allow_missing_build=true"]);
      assert(resumed.ok, resumed.output);
      assertEquals(writes(f.calls()), []);
      for (const name of names) assertEquals(f.state().containers[name].status, "Running");
      assertEquals(f.state().containers["nested-app-prod"].config["raw.idmap"], undefined);
      assertEquals(f.state().containers["nested-app-prod"].devices.checkout, undefined);
    } finally {
      f.cleanup();
    }
  },
});
