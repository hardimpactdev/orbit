/**
 * Stateful Incus boundary for the nested fleet of an Instance container (swamp/ansible/tasks/instance-fleet-*.yml). The Node's `incus`
 * reaches only `exec` into the Instance container; there the inner `incus`, `ip`, `wg`, `resolvectl`, `ping`, `test` and `curl` act on one JSON state:
 * the nested containers with their devices, configuration keys, files and snapshots, the inner bridge's keys, the operator tunnel, and
 * the nested Gateway's API.
 * Every call is one JSON line in `calls`. No live host command runs.
 */
const folder = Deno.env.get("HOST_FIXTURE")!;
const file = `${folder}/state.json`;
const state = JSON.parse(Deno.readTextFileSync(file));
const args = [...Deno.args];
// A script sent with `python3 -c` (instance_node_services.py) or `python3 -I -c` (instance_shape_tools.py, and instance_fleet_shrink.py,
// whose first argument is its mode) is recorded by its name.
// instance_fleet_runtime.py's first argument is the inner pools folder.
const shrinkScript = args[5] === "python3" && args[6] === "-I" && args[7] === "-c" && ["check", "based"].includes(args[9]);
// The shrink that the refresh starts as a transient unit (`systemd-run --unit orbit-fleet-shrink ... python3 -I -c <script> run ...`).
const shrinkUnit = args[5] === "systemd-run" && args[7] === "orbit-fleet-shrink";
const runtimeScript = args[5] === "python3" && args[6] === "-I" && args[7] === "-c" && args[9] === "/var/lib/incus/storage-pools";
// instance_fleet_tunnel.py writes the tunnel's boot script and unit (`boot <script> <unit>`).
const tunnelBootScript = args[5] === "python3" && args[6] === "-I" && args[7] === "-c" && args[9] === "boot";
// The heal sent into a nested Instance host (`incus exec <name> -- python3 -I -c <script> ensure`) is recorded by its name too.
const healScript = args[5] === "incus" && args[6] === "exec" && args[8] === "--" && args[9] === "python3" && args[10] === "-I" && args[11] === "-c"
  && args[13] === "ensure";
// The Instance host's code read (instance-fleet-refresh.yml) is recorded with its script by name.
const hostCodeScript = args[5] === "sh" && args[6] === "-c" && args[7].includes("archive -o");
const recorded = hostCodeScript ? [...args.slice(0, 7), "<host-code-read>", ...args.slice(8)]
  : shrinkUnit ? args.map((arg, index) => args[index - 1] === "-c" ? "<instance_fleet_shrink.py>" : arg)
  : healScript ? [...args.slice(0, 12), "<incus-network-pid-heal.py>", ...args.slice(13)]
  : args[5] === "python3" && args[6] === "-c" ? [...args.slice(0, 7), "<instance_node_services.py>", ...args.slice(8)]
  : args[5] === "python3" && args[6] === "-I" && args[7] === "-c"
  ? [...args.slice(0, 8), shrinkScript ? "<instance_fleet_shrink.py>" : runtimeScript ? "<instance_fleet_runtime.py>"
    : tunnelBootScript ? "<instance_fleet_tunnel.py>" : "<instance_shape_tools.py>",
    ...args.slice(9)] : args;
Deno.writeTextFileSync(`${folder}/${args[0] === "--project" ? "calls" : "node-calls"}`, JSON.stringify(recorded) + "\n", { append: true });
const save = () => Deno.writeTextFileSync(file, JSON.stringify(state));
const fail = (message: string, code = 1): never => {
  save();
  console.error(`Error: ${message}`);
  Deno.exit(code);
};
const done = (output?: unknown): never => {
  if (output !== undefined) console.log(typeof output === "string" ? output : JSON.stringify(output));
  save();
  Deno.exit(0);
};
const pairs = (values: string[]) =>
  Object.fromEntries(values.map((value) => [value.slice(0, value.indexOf("=")), value.slice(value.indexOf("=") + 1)]));
const copy = <T>(value: T): T => structuredClone(value);
/** The checkout recipe's Swamp release and its sha256, as the fixture's recipe pins it. */
const swampPin = "20261114.210101.0-sha.537e03d1";
const swampSha = "0171346a3588019a73a0e9d28e64447688ad87261e6eceb49b3febf6bd603da3";
/** The nested Gateway's six Swamp tokens that the refresh keeps young (instance-fleet.yml `fleet_token_names`). */
const tokenNames = ["serve-driver", "orbit-gateway-run", "orbit-gateway-read", "orbit-worker-a-access", "orbit-worker-b-access", "orbit-worker-c-access"];
const iso = (milliseconds: number) => new Date(milliseconds).toISOString();

// The Node's own read of the Instance container (instance-fleet-proxy.yml): `lastUsedAt` is its last start.
if (args[0] === "query" && args[1] === "--" && args[2] === `/1.0/instances/${state.outer.name}?project=${state.outer.project}`) {
  done({ name: state.outer.name, last_used_at: state.outer.lastUsedAt ?? "2026-11-13T23:00:00.000000000Z" });
}

// The Node's call: `incus --project <project> exec <container> -- <command>`.
if (args[0] !== "--project" || args[1] !== state.outer.project || args[2] !== "exec" || args[3] !== state.outer.name || args[4] !== "--") {
  fail(`Unexpected Node call: ${args.join(" ")}`);
}
const command = args.slice(5);
const containers: Record<string, {
  status: string;
  config: Record<string, string>;
  devices: Record<string, Record<string, string>>;
  files?: Record<string, string>;
  links?: Record<string, string>;
  snapshots?: Record<string, { config: Record<string, string>; devices: Record<string, Record<string, string>>; files: Record<string, string>; links?: Record<string, string>; taken: string }>;
}> = state.containers;
const container = (name: string) => containers[name] ?? fail(`Instance not found: ${name}`);
const tunnel = state.tunnel as null | { addresses: string[]; up: boolean; key?: string; port?: string; peer?: string; endpoint?: string; allowed?: string;
  dns?: string[]; domains?: string[] };

// instance_node_services.py `proxy`: the drop-ins and the manager environment, changed when the value differs.
if (command[0] === "python3" && command[1] === "-c" && command[3] === "proxy") {
  const wanted = JSON.parse(await new Response(Deno.stdin.readable).text()).proxy_environment;
  // A foreign file of the same name: the writer fails with its public code and changes nothing.
  if (state.outer.proxyConflict) {
    save();
    console.error("instance.node_service_unit_conflict");
    Deno.exit(1);
  }
  const changed = JSON.stringify(state.outer.proxy ?? null) !== JSON.stringify(wanted);
  state.outer.proxy = wanted;
  done({ changed });
}
if (command[0] === "systemctl" && command[1] === "try-restart") done();
// The tunnel's boot files: `tunnelBoot` holds what the container has; changed when either differs.
if (tunnelBootScript) {
  if (command[5] !== "/etc/orbit/wg-nested-up" || command[6] !== "/etc/systemd/system/orbit-inner-fleet-tunnel.service") fail(`The tunnel boot got ${command.slice(5)}`);
  const wanted = JSON.parse(await new Response(Deno.stdin.readable).text());
  const changed = JSON.stringify(state.outer.tunnelBoot ?? null) !== JSON.stringify(wanted);
  state.outer.tunnelBoot = wanted;
  done({ changed });
}
// The refresh's best-effort resume of each target: a frozen one runs again, any other stays as it is.
if (command[0] === "sh" && command[1] === "-c" && command[2] === 'for n do incus resume "$n" || true; done' && command[3] === "sh") {
  for (const name of command.slice(4)) if (state.containers[name]?.status === "Frozen") state.containers[name].status = "Running";
  state.resumedLate = command.slice(4);
  done();
}
// The thaw timer of a running `base` (instance-fleet-refresh.yml): `thawArmed` holds the names it would resume, until a stop of its timer.
if (command[0] === "systemd-run" && command[1] === "--unit" && command[2] === "orbit-fleet-thaw") {
  if (state.thawArmed) fail("Unit orbit-fleet-thaw.timer was already loaded or has a fragment file.");
  state.thawArmed = command.slice(command.indexOf("sh", command.indexOf("-c")) + 1);
  state.thawArmedTimes = (state.thawArmedTimes ?? 0) + 1;
  done();
}
if (command[0] === "systemctl" && command[1] === "stop" && command[2] === "orbit-fleet-thaw.timer") {
  delete state.thawArmed;
  done();
}

// instance_fleet_shrink.py in the Instance container: `check` answers `shrink` (by default not due); `based` makes a `shrunk` check
// current; `shrinkExit` ends either with that code and no result line.
if (command[0] === "python3" && command[1] === "-I" && command[2] === "-c" && ["check", "based"].includes(command[4])) {
  if (command[5] !== "/var/lib/orbit/fleet-shrink.json" || command[6] !== "/var/lib/incus/storage-pools") fail(`The shrink script got ${command.slice(5, 7)}`);
  if (state.shrinkExit) {
    save();
    console.error("Traceback (most recent call last):");
    Deno.exit(state.shrinkExit);
  }
  if (command[4] === "check") done(JSON.stringify(state.shrink ?? { due: false, reason: "current" }));
  const based = state.shrink?.reason === "shrunk";
  if (based) state.shrink = { due: false, reason: "current" };
  done(JSON.stringify({ based }));
}
// Its `run` as the unit orbit-fleet-shrink: `shrinkStartFails` refuses the start; otherwise a run whose `shrinkResult` (by default
// both parts ran) is `ran` leaves the check `shrunk`. `shrinkRuns` keeps the unit running until a stop, which counts in `shrinkStopped`.
if (shrinkUnit) {
  if (state.shrinkStartFails) fail("Unit orbit-fleet-shrink.service was already loaded or has a fragment file.");
  const run = command.slice(command.indexOf("-c") + 2);
  if (run[0] !== "run" || run[1] !== "/var/lib/orbit/fleet-shrink.json" || run[2] !== "/var/lib/incus/storage-pools") fail(`The shrink unit got ${run}`);
  if (state.shrinkRuns) state.shrinkUnit = "running";
  else if ((state.shrinkResult ?? { shrink: "ran" }).shrink === "ran") state.shrink = { due: true, reason: "shrunk" };
  done();
}
if (command[0] === "systemctl" && command[1] === "stop" && command[2] === "orbit-fleet-shrink.service") {
  if (!state.shrinkUnit) fail("Failed to stop orbit-fleet-shrink.service: Unit orbit-fleet-shrink.service not loaded.", 5);
  delete state.shrinkUnit;
  state.shrinkStopped = (state.shrinkStopped ?? 0) + 1;
  done();
}

// incus-network-pid-heal.py `ensure` in a nested Instance host: it places itself (its standard input) and its drop-in when they differ,
// and removes each empty `dnsmasq.pid` of the inner Incus's networks, as a pid file no live dnsmasq owns.
if (healScript) {
  const found = container(args[7]);
  found.files ??= {};
  const source = await new Response(Deno.stdin.readable).text();
  const dropIn = "[Service]\nExecStartPre=/usr/local/sbin/incus-network-pid-heal\n";
  const placed = found.files["/usr/local/sbin/incus-network-pid-heal"] !== source
    || found.files["/etc/systemd/system/incus.service.d/60-network-pid-heal.conf"] !== dropIn;
  found.files["/usr/local/sbin/incus-network-pid-heal"] = source;
  found.files["/etc/systemd/system/incus.service.d/60-network-pid-heal.conf"] = dropIn;
  const removed: string[] = [];
  for (const [path, content] of Object.entries(found.files)) {
    const network = path.match(/^\/var\/lib\/incus\/networks\/([^/]+)\/dnsmasq\.pid$/)?.[1];
    if (network === undefined || content !== "") continue;
    delete found.files[path];
    removed.push(network);
  }
  done(JSON.stringify({ placed, removed, restarted: removed.length > 0 }));
}

// instance_fleet_runtime.py in the Instance container: it removes each `dnsmasq.pid` of the inner Incus's networks from the files of the
// named nested containers that are stopped, and names the others as skipped. `runtimeUnsafe` names containers whose disk holds a link
// where the walk needs a folder: they are named unsafe and keep their files.
if (runtimeScript) {
  const removed: string[] = [];
  const skipped: string[] = [];
  const unsafe: string[] = [];
  for (const name of command.slice(5)) {
    if (container(name).status !== "Stopped") {
      skipped.push(name);
      continue;
    }
    if ((state.runtimeUnsafe ?? []).includes(name)) {
      unsafe.push(name);
      continue;
    }
    for (const path of Object.keys(container(name).files ?? {})) {
      const network = path.match(/^\/var\/lib\/incus\/networks\/([^/]+)\/dnsmasq\.pid$/)?.[1];
      if (network === undefined) continue;
      delete container(name).files![path];
      removed.push(`${name}/${network}`);
    }
  }
  done(JSON.stringify({ removed, skipped, unsafe }));
}

// instance_shape_tools.py in the Instance container: its last line is the result, and its programs write to standard error.
// `tools` is what the container holds: "present" (the default) or "missing", which a build makes "present". `toolsFail` names the step
// that fails; `toolsExit` makes the script end with that code and no result line (124: the timeout ended it).
if (command[0] === "python3" && command[1] === "-I" && command[2] === "-c") {
  if (command[4] !== "/srv/workspace") fail(`The tools script got ${command[4]}, not the workspace`);
  if (state.toolsExit) {
    save();
    console.error("Traceback (most recent call last):");
    Deno.exit(state.toolsExit);
  }
  const names = { venv: "venv-0123456789abcdef", collections: "collections-0123456789abcdef" };
  if ((state.tools ?? "present") === "present") done(JSON.stringify({ tools: "present", reason: "current", ...names }));
  console.error("Installing the pinned shape tools into /opt/orbit-shape-gate/venv-0123456789abcdef");
  if (state.toolsFail) done(JSON.stringify({ tools: "failed", reason: state.toolsFail, ...names }));
  state.tools = "present";
  done(JSON.stringify({ tools: "built", reason: "missing", ...names }));
}

// `df` of the container's `/`, in bytes: `room` is [size, free]; by default 40 GiB with 20 GiB free.
if (command[0] === "df") {
  const [size, free] = state.room ?? [42949672960, 21474836480];
  done(`1B-blocks       Avail\n${size} ${free}`);
}
if (command[0] === "test" && command[1] === "-s") {
  save();
  Deno.exit(state.outer.files[command[2]] ? 0 : 1);
}

// The refresh record on the Instance container's own disk: read with `cat`, written root-owned 0644 from stdin.
if (command[0] === "cat") {
  const content = state.outer.files[command[1]];
  if (content === undefined) fail(`cat: ${command[1]}: No such file or directory`);
  if (state.outer.unreadable?.includes(command[1])) fail(`cat: ${command[1]}: Permission denied`);
  Deno.stdout.writeSync(new TextEncoder().encode(content));
  done();
}
if (command[0] === "rm" && command[1] === "-f") {
  delete state.outer.files[command[2]];
  done();
}
// The Instance container's id map is isolated: the Node's stdin pipe shows there as owned by nobody, mode 0600, so an open of
// /dev/stdin or /proc/self/fd/0 by path is refused, as on a real container. A command reads the open stream instead.
if (command.some((part) => /\/dev\/stdin|\/dev\/fd\/0|\/proc\/self\/fd\/0/.test(part))) fail(`${command[0]}: Permission denied`);
// The record write: `cat` the stream into a file beside the record, set owner and mode, rename it over the record.
if (command[0] === "sh" && command[1] === "-c" && command[2].includes('cat > "$1.tmp"') && command[3] === "sh") {
  const path = command[4];
  state.outer.files[path] = await new Response(Deno.stdin.readable).text();
  state.outer.modes ??= {};
  state.outer.modes[path] = [...command[2].matchAll(/(?:chown|chmod) (\S+) /g)].map((match) => match[1]).join(" ");
  done();
}
// The Instance host's code digest (host_code.ts over the checkout's HEAD): with `hostCheckout` the real read runs over that Git
// checkout with this Deno; otherwise it prints `hostCode` (six by default), and `hostCodeFail` or `digestsFail` makes it fail.
if (hostCodeScript) {
  if (state.hostCheckout) {
    const result = new Deno.Command("sh", { args: ["-c", command[2], "sh", state.hostCheckout, Deno.execPath(), command[6]] }).outputSync();
    if (!result.success) fail(`cannot read the Instance host code: ${new TextDecoder().decode(result.stderr).trim()}`);
    done(new TextDecoder().decode(result.stdout).trimEnd());
  }
  if (state.hostCodeFail || state.digestsFail) fail("fatal: not a git repository");
  done(state.hostCode ?? "6".repeat(64));
}
// The Git object IDs of the workspace's checkout: `digests` maps each path to its ID; `digestsFail` makes the read fail. `swamp`, or a
// path under `swamp/`, that `digests` does not name has the ID `c3`.
if (command[0] === "git" && command.includes("rev-parse")) {
  if (state.digestsFail) fail("fatal: not a git repository");
  const digests: Record<string, string> = { "apps/agent/contract": "d4", ...(state.digests ?? { "apps/gateway": "a1" }) };
  done(command.slice(command.indexOf("rev-parse") + 1).map((object) => object.slice("HEAD:".length))
    .map((path) => digests[path] ?? (path === "swamp" || path.startsWith("swamp/") ? "c3" : path === "apps/gateway/resources/machine-software" ? "e5" : "")).join("\n"));
}

if (command[0] === "incus") {
  const inner = command.slice(1);
  if (inner[0] === "admin" && inner[1] === "waitready") done();
  if (inner[0] === "list") {
    done(Object.entries(containers).map(([name, value]) => `${name},${value.status.toUpperCase()}`).join("\n"));
  }
  // The inner bridge: `network` is its name and keys; `networkUnreadable` makes its read fail.
  if (inner[0] === "query" && inner[1].startsWith("/1.0/networks/")) {
    const name = inner[1].slice("/1.0/networks/".length);
    if (state.networkUnreadable || name !== state.network.name) fail("Network not found");
    done({ name, type: "bridge", config: state.network.config });
  }
  if (inner[0] === "network" && inner[1] === "set") {
    if (inner[2] !== state.network.name) fail("Network not found");
    // Incus restarts the bridge's dnsmasq, and a nested container on it loses DNS and DHCP while it runs: the fake refuses that.
    const running = Object.entries(containers).filter(([, value]) => value.status === "Running").map(([name]) => name);
    if (running.length > 0) fail(`The bridge changed under running nested containers: ${running.join(", ")}`);
    Object.assign(state.network.config, pairs(inner.slice(3)));
    done();
  }
  // The drivers of the inner storage pools, one per line: `poolDriver` is the one pool's (a directory pool unless a test says
  // otherwise); `poolsUnreadable` makes the read fail, and `poolsOutput` is printed as it is with exit 0.
  if (inner.join(" ") === "storage list --format csv --columns D") {
    if (state.poolsUnreadable) fail("Failed to list storage pools");
    done(state.poolsOutput ?? state.poolDriver ?? "dir");
  }
  if (inner[0] === "query") {
    const [route, query] = inner[1].slice("/1.0/instances/".length).split("?");
    const [name, part] = route.split("/");
    const found = container(name);
    if (part === "snapshots") {
      if (query !== "recursion=1") fail("Snapshots are read with recursion");
      done(Object.keys(found.snapshots ?? {}).map((snapshot) => ({ name: snapshot })));
    }
    done({ name, status: found.status, config: found.config, devices: found.devices });
  }
  // `stop --force` takes several names in one call (the reset); stopping a stopped container fails, as in Incus.
  if (inner[0] === "stop" && inner[1] === "--force") {
    const found = inner.slice(2).map(container);
    if (found.length === 0) fail("No instance named");
    if (found.some((value) => value.status !== "Running")) fail("The instance is already stopped");
    for (const value of found) value.status = "Stopped";
    done();
  }
  // `start` takes several names in one call (the reset): Incus starts each it can and fails naming the others.
  if (inner[0] === "start" && inner.length > 2) {
    const errors: string[] = [];
    for (const name of inner.slice(1)) {
      const found = container(name);
      if (state.failStart?.[name]) errors.push(`${name}: ${state.failStart[name]}`);
      else if (found.status === "Running") errors.push(`${name}: The instance is already running`);
      else found.status = "Running";
    }
    if (errors.length > 0) fail(errors.join("; "));
    done();
  }
  // `pause` freezes running containers and `resume` thaws frozen ones, several in one call, as in Incus.
  if (inner[0] === "pause" || inner[0] === "resume") {
    const found = inner.slice(1).map(container);
    if (found.length === 0) fail("No instance named");
    const from = inner[0] === "pause" ? "Running" : "Frozen";
    // `pauseDelay`: seconds a freeze stalls before it takes effect; a caller's `timeout` that ends it first leaves nothing frozen.
    if (inner[0] === "pause" && state.pauseDelay) await new Promise((resolve) => setTimeout(resolve, state.pauseDelay * 1000));
    if (found.some((value) => value.status !== from)) fail(inner[0] === "pause" ? "The instance isn't running" : "The instance isn't frozen");
    for (const value of found) value.status = inner[0] === "pause" ? "Frozen" : "Running";
    done();
  }
  if (inner[0] === "start" || inner[0] === "stop") {
    const found = container(inner[1]);
    if (inner[0] === "start") {
      if (state.failStart?.[inner[1]]) fail(state.failStart[inner[1]]);
      if (found.status === "Running") fail("The instance is already running");
      // A start two levels below the host fails on an ID-shifted disk device, as the experiment showed.
      const shifted = Object.entries(found.devices).find(([, device]) => device.type === "disk" && device.shift === "true");
      if (shifted) fail(`Failed to setup device mount "${shifted[0]}": idmapping abilities are required but aren't supported on system`);
    }
    // `driftOnStop`: another writer removes a device of a running container while one stops (a rule that differs after the read).
    if (inner[0] === "stop" && state.driftOnStop) {
      delete containers[state.driftOnStop.container].devices[state.driftOnStop.device];
      delete state.driftOnStop;
    }
    found.status = inner[0] === "start" ? "Running" : "Stopped";
    done();
  }
  if (inner[0] === "config" && inner[1] === "device") {
    const [, , verb, name, device, ...rest] = inner;
    const found = container(name);
    if (verb === "add") {
      if (found.devices[device]) fail(`The device already exists`);
      found.devices[device] = { type: rest[0], ...pairs(rest.slice(1)) };
    } else if (verb === "set") {
      if (!found.devices[device]) fail(`Device doesn't exist`);
      Object.assign(found.devices[device], pairs(rest));
    } else fail(`Unexpected device verb ${verb}`);
    done();
  }
  if (inner[0] === "config" && inner[1] === "set") {
    Object.assign(container(inner[2]).config, pairs(inner.slice(3)));
    done();
  }
  if (inner[0] === "snapshot") {
    const [, verb, name, snapshot] = inner;
    const found = container(name);
    found.snapshots ??= {};
    found.files ??= {};
    if (verb === "create") {
      if (found.snapshots[snapshot]) fail("Snapshot already exists");
      // `failSnapshot`: the names whose snapshot fails.
      if (state.failSnapshot?.includes(name)) fail("Failed to create snapshot");
      found.snapshots[snapshot] = { config: copy(found.config), devices: copy(found.devices), files: copy(found.files), links: copy(found.links ?? {}), taken: found.status };
    } else if (verb === "delete") {
      if (!found.snapshots[snapshot]) fail("Snapshot not found");
      delete found.snapshots[snapshot];
    } else if (verb === "restore") {
      const taken = found.snapshots[snapshot] ?? fail("Snapshot not found");
      // A restore of a running container stops and boots it again; the fake counts those, so a reset that restores a stopped one shows.
      if (found.status === "Running") state.runningRestores = (state.runningRestores ?? 0) + 1;
      found.config = copy(taken.config);
      found.devices = copy(taken.devices);
      found.files = copy(taken.files);
      found.links = copy(taken.links ?? {});
    } else fail(`Unexpected snapshot verb ${verb}`);
    done();
  }
  if (inner[0] === "file" && inner[1] === "pull") {
    const target = inner[2];
    const name = target.slice(0, target.indexOf("/"));
    const content = (container(name).files ?? {})[target.slice(name.length)];
    if (content === undefined) fail("Not Found");
    Deno.stdout.writeSync(new TextEncoder().encode(content));
    done();
  }
  if (inner[0] === "file" && inner[1] === "push") {
    if (!inner.includes("--create-dirs")) fail("The folder of a drop-in does not exist");
    const target = inner.at(-1)!;
    const name = target.slice(0, target.indexOf("/"));
    const found = container(name);
    found.files ??= {};
    found.files[target.slice(name.length)] = await new Response(Deno.stdin.readable).text();
    done();
  }
  if (inner[0] === "exec") {
    let rest = inner.slice(1);
    const name = rest[0];
    if (container(name).status !== "Running") fail("Instance is not running");
    rest = rest.slice(rest.indexOf("--") + 1);
    state.execs ??= [];
    state.execs.push([name, ...rest]);
    // The checkout is `agent:work` 2770; `orbit` writes into it only with its group `work`, which `runuser` gives and
    // `incus exec --user 1000` does not (uid 1000 with group 0).
    if (inner.includes("--user")) fail("install: cannot create directory '/home/orbit/orbit/apps/agent/delivery': Permission denied");
    const asOrbit = rest[0] === "runuser" && rest[1] === "-u" && rest[2] === "orbit" && rest[3] === "--";
    if ((rest[0] === "install" || rest[0] === "php") && !asOrbit) fail(`${rest[0]} as root: the app user runs it`);
    if (asOrbit) rest = rest.slice(4);
    // The nested Gateway's Valkey: `CLIENT PAUSE <ms> WRITE` holds the writes (`queuePaused`) unless `queuePauseFails`, `CLIENT UNPAUSE`
    // lets them go; `SAVE` answers OK unless `queueSaveFails`, and counts in `queueSaved` with the writes held or not (`queueSavedHeld`).
    if (name === "nested-gateway" && rest[0] === "valkey-cli") {
      const verb = rest.slice(1).join(" ");
      if (/^CLIENT PAUSE [0-9]+ WRITE$/.test(verb)) {
        if (state.queuePauseFails) fail("ERR timeout is not an integer or out of range");
        state.queuePaused = Number(rest[3]);
        done("OK");
      }
      if (verb === "CLIENT UNPAUSE") {
        delete state.queuePaused;
        done("OK");
      }
      if (verb === "SAVE") {
        if (state.queueSaveFails) fail("ERR Background save already in progress");
        state.queueSaved = (state.queueSaved ?? 0) + 1;
        if (state.queuePaused) state.queueSavedHeld = (state.queueSavedHeld ?? 0) + 1;
        done("OK");
      }
    }
    if (rest.join(" ") === "php artisan list --raw --no-interaction") {
      if (!asOrbit || name !== "nested-gateway" || !inner.includes("/home/orbit/orbit/apps/gateway")) fail("Wrong command-list context");
      if (state.failCommandList) fail("Gateway command list unavailable");
      done(state.oldGatewayCommands ? "list  List commands" : "gateway:known-hosts:sync  Restore recorded pins");
    }
    if (rest[0] === "php" && rest.includes("gateway:known-hosts:sync")) {
      if (state.oldGatewayCommands) fail("Command gateway:known-hosts:sync is not defined");
      if (!asOrbit || name !== "nested-gateway" || !inner.includes("/home/orbit/orbit/apps/gateway")) fail("Wrong known-hosts command context");
      if (state.failKnownHostsSync) fail("Gateway pin store unavailable");
      const gateway = container(name);
      gateway.files ??= {};
      const path = "/home/orbit/.orbit/ssh/known_hosts";
      const changed = gateway.files[path] !== "recorded-node-pins";
      gateway.files[path] = "recorded-node-pins";
      done(JSON.stringify({ changed }));
    }
    if (rest.join(" ") === "test -f /etc/orbit/instance-fleet-node") {
      if (container(name).files?.["/etc/orbit/instance-fleet-node"] === undefined) { save(); Deno.exit(1); }
      done();
    }
    if (rest.join(" ") === "id -nG orbit") {
      if (state.failHostGroups) fail("Instance host groups unavailable");
      const groups = container(name).files?.["/etc/group"] ?? fail("Instance host group file missing");
      done(groups.trim().split("\n").map((line) => line.split(":"))
        .filter((parts) => parts[0] === "orbit" || parts[3].split(",").includes("orbit")).map((parts) => parts[0]).join(" "));
    }
    if (rest[0] === "usermod") {
      if (asOrbit || rest.join(" ") !== "usermod --append --groups incus-admin orbit") fail("Wrong Instance host membership command");
      const found = container(name);
      const groups = found.files?.["/etc/group"] ?? fail("Instance host group file missing");
      if (!groups.split("\n").some((line) => line.startsWith("incus-admin:"))) fail("Incus admin group missing");
      found.files!["/etc/group"] = groups.split("\n").map((line) => {
        if (!line.startsWith("incus-admin:")) return line;
        const parts = line.split(":"), members = parts[3].split(",").filter(Boolean);
        if (!members.includes("orbit")) members.push("orbit");
        parts[3] = members.join(",");
        return parts.join(":");
      }).join("\n");
      done();
    }
    // wg-quick makes the interface before it sets its private key; a read in between prints `(none)` with exit 0.
    if (rest[0] === "wg" && rest[1] === "show") {
      if ((state.hubKeyNone ?? 0) > 0) {
        state.hubKeyNone -= 1;
        done("(none)");
      }
      done(state.hubKey);
    }
    // Links in a nested container's own disk, by path.
    if (rest[0] === "readlink") {
      const target = container(name).links?.[rest[1]];
      if (target === undefined) fail("not a link");
      done(target);
    }
    if (rest[0] === "ln") {
      const linked = container(name);
      linked.links = { ...(linked.links ?? {}), [rest[3]]: rest[2] };
      done();
    }
    if (rest[0] === "systemctl" && rest[1] === "is-active") {
      const units = rest.slice(rest[2] === "--quiet" ? 3 : 2);
      // Right after a start a mount can still be `activating` for `activating` more reads.
      const activating = (state.activating ?? 0) > 0;
      if (activating) state.activating -= 1;
      const inactive = activating ? units : units.filter((unit) => (state.inactive ?? []).includes(unit));
      if (rest[2] !== "--quiet") {
        console.log(units.map((unit) => !inactive.includes(unit) ? "active" : activating ? "activating" : "inactive").join("\n"));
      }
      save();
      Deno.exit(inactive.length > 0 ? 3 : 0);
    }
    if (rest[0] === "systemctl" && rest[1] === "show") done("inactive\ninactive");
    // The nested Gateway's Swamp tokens, names and dates only, as `swamp access token list --json` prints them. `tokens` maps a name to its
    // creation time; by default the six exist and are one day old. `tokensUnreadable` makes the list fail.
    if (rest[0] === "env" && rest.includes("swamp") && rest.includes("list")) {
      if (state.tokensUnreadable) fail("Error: the token store is locked");
      const tokens: Record<string, string> = state.tokens ?? Object.fromEntries(tokenNames.map((token) => [token, iso(Date.now() - 86400_000)]));
      done(JSON.stringify(Object.entries(tokens).map(([token, created]) => ({ name: token, createdAt: created, expiresAt: created })), null, 2));
    }
    // The refresh's two reads of the nested Gateway's Swamp: the checkout recipe's release and sha256 (`swampPin` and `swampSha`, the
    // current pin by default) and the binary that serve runs (`swampRunning`, the fleet build's folder of that sha256 by default).
    // `swampUnreadable` makes the first fail.
    if (rest[0] === "python3" && rest[1] === "-c" && rest[2].includes('["tools"]["swamp"]')) {
      if (state.swampUnreadable) fail("FileNotFoundError: recipe.json");
      done(`${state.swampPin ?? swampPin}\n${state.swampSha ?? swampSha}`);
    }
    if (rest[0] === "sh" && rest[1] === "-c" && rest[2].includes("orbit-swamp-serve.service)/exe")) {
      done(state.swampRunning ?? `/usr/local/lib/orbit-swamp/${state.swampSha ?? swampSha}/swamp`);
    }
    // The swamp-pin play in the nested Gateway: it installs the recipe's release, restarts serve and the workers on it and checks them.
    // `swampPinFail` makes it fail as a play whose serve did not come back.
    if (rest[0] === "env" && rest.some((value) => value.endsWith("ansible-playbook")) && rest.includes("swamp-pin")) {
      if (state.swampPinFail) {
        console.log("TASK [Wait for serve to be ready] ***\nfatal: [localhost]: FAILED! => {\"changed\": false, \"rc\": 7}");
        fail("PLAY RECAP *********\nlocalhost                  : ok=8    changed=6    unreachable=0    failed=1    skipped=0");
      }
      state.swampRunning = `/usr/local/lib/orbit-swamp/${state.swampSha ?? swampSha}/swamp`;
      done("PLAY RECAP *********\nlocalhost                  : ok=12   changed=7    unreachable=0    failed=0    skipped=0");
    }
    // The remint play in the nested Gateway: it makes the worker set whole, renews the six tokens and starts serve and the three
    // workers. `remintFail` makes it fail with a play error; its output holds no token, as the real play's.
    if (rest[0] === "env" && rest.some((value) => value.endsWith("ansible-playbook")) && rest.includes("remint")) {
      state.reminted = (state.reminted ?? 0) + 1;
      if (state.remintFail) {
        console.log("TASK [Rotate the serve driver token] ***\nfatal: [localhost]: FAILED! => {\"changed\": true, \"cmd\": \"umask 077 && swamp access token rotate serve-driver --duration 30d --json >/dev/null\", \"rc\": 1}");
        fail("PLAY RECAP *********\nlocalhost                  : ok=12   changed=3    unreachable=0    failed=1    skipped=0");
      }
      state.tokens = Object.fromEntries(tokenNames.map((token) => [token, iso(Date.now())]));
      const gateway = container(name);
      gateway.files ??= {};
      gateway.files["/etc/orbit/swamp-role-worker/c/server.token"] = "made";
      done("PLAY RECAP *********\nlocalhost                  : ok=40   changed=21   unreachable=0    failed=0    skipped=0");
    }
    // The grants play in the nested Gateway: its recap names how many files it changed (`grantsChanged`, 0 by default).
    if (rest[0] === "env" && rest.some((value) => value.endsWith("ansible-playbook"))) {
      const changed = state.grantsChanged ?? 0;
      delete state.grantsChanged;
      done(`PLAY RECAP *********\nlocalhost                  : ok=4    changed=${changed}    unreachable=0    failed=0    skipped=0`);
    }
    // A checkout that moved under the running nested Gateway (`schemaBehind`): Doctor is unhealthy until the migrate and a restart after it.
    // `pendingMigration`: the migrate applies one (Laravel prints each migration); otherwise it prints that nothing was due.
    // The nested Gateway's digest of each named Node's default converge intent (`orbit:node-converge-digest`): `intentDigests` names a
    // Node's digest, `intentDigestErrors` the error of a Node whose intent cannot be built, `intentDigestFail` makes the command fail.
    if (rest[0] === "php" && rest.includes("orbit:node-converge-digest")) {
      if (rest.includes("--base")) fail("A refresh reads the default intent digest");
      if (state.intentDigestFail) fail("The Node app-dev is unknown.");
      const own: Record<string, string> = { gateway: "1", "app-dev": "2", "app-prod": "3" };
      const nodes = rest.slice(rest.indexOf("--host") + 2);
      // `--host app-dev`: nested-app-dev's Instance host intent digest (`hostIntentDigest`), or empty before it has its bridge.
      const host = rest.includes("--host") ? { host: state.hostIntentDigest ?? "4".repeat(64) } : {};
      done(JSON.stringify({ ...Object.fromEntries(nodes.map((node) => [node, state.intentDigestErrors?.[node] !== undefined
        ? { error: state.intentDigestErrors[node] } : state.intentDigests?.[node] ?? own[node].repeat(64)])), ...host }));
    }
    if (rest[0] === "php" && rest.includes("migrate")) {
      state.migrated = true;
      // `checkoutSchema`: the schema mark the checkout's migrations reach; a disk mark below it has migrations due.
      const mark = Number(container(name).files?.["/home/orbit/.orbit/schema"] ?? "0");
      const applied = state.pendingMigration || state.schemaBehind || (name === "nested-gateway" && (state.checkoutSchema ?? 0) > mark);
      delete state.pendingMigration;
      // The nested Gateway's database lives on its own disk: an applied migration moves its schema mark there.
      if (applied && name === "nested-gateway") {
        const gateway = container(name);
        gateway.files ??= {};
        gateway.files["/home/orbit/.orbit/schema"] = String(Math.max(Number(gateway.files["/home/orbit/.orbit/schema"] ?? "0") + 1, state.checkoutSchema ?? 0));
      }
      done(applied ? "INFO  Running migrations.\n  2026_11_13_000000_add_a_column ........ 12.40ms DONE" : "INFO  Nothing to migrate.");
    }
    if (rest[0] === "systemctl" && rest[1] === "restart" && state.migrated) state.restartedAfterMigrate = true;
    done();
  }
  fail(`Unexpected inner Incus call: ${inner.join(" ")}`);
}

if (command[0] === "wg" && command[1] === "show") {
  if (!tunnel) fail("Unable to access interface: No such device");
  const lines = ["interface: wg-nested"];
  if (tunnel!.key) lines.push("  public key: OPERATORPUB=", "  private key: (hidden)");
  if (tunnel!.port) lines.push(`  listening port: ${tunnel!.port}`);
  if (tunnel!.peer) lines.push("", `peer: ${tunnel!.peer}`, `  endpoint: ${tunnel!.endpoint}`, `  allowed ips: ${tunnel!.allowed}`);
  done(lines.join("\n"));
}
if (command[0] === "wg" && command[1] === "set") {
  if (!tunnel) fail("Unable to modify interface: No such device");
  const option = (name: string) => command[command.indexOf(name) + 1];
  if (!state.outer.files[option("private-key")]) fail("No such file or directory");
  // wg refuses a peer that is not a WireGuard key, `(none)` among them (Operation 19987).
  if (!/^[A-Za-z0-9+/]{42}[AEIMQUYcgkosw048]=$/.test(option("peer"))) fail(`Key is not the correct length or format: \`${option("peer")}'`);
  Object.assign(tunnel!, { key: option("private-key"), port: option("listen-port"), peer: option("peer"), endpoint: option("endpoint"), allowed: option("allowed-ips") });
  done();
}
if (command[0] === "ip") {
  const verb = command.slice(command[1] === "-j" ? 2 : 1);
  if (verb[0] === "link" && verb[1] === "add") {
    if (tunnel) fail("RTNETLINK answers: File exists");
    state.tunnel = { addresses: [], up: false };
    done();
  }
  if (!tunnel) fail('Device "wg-nested" does not exist.');
  if (verb[0] === "addr" && verb[1] === "show") {
    done([{ ifname: "wg-nested", flags: tunnel!.up ? ["POINTOPOINT", "NOARP", "UP", "LOWER_UP"] : ["POINTOPOINT", "NOARP"],
      addr_info: tunnel!.addresses.map((address) => ({ family: "inet", local: address.split("/")[0], prefixlen: Number(address.split("/")[1]) })) }]);
  }
  if (verb[0] === "addr" && verb[1] === "add") {
    tunnel!.addresses.push(verb[2]);
    done();
  }
  if (verb[0] === "link" && verb[1] === "set" && verb[3] === "up") {
    tunnel!.up = true;
    done();
  }
  fail(`Unexpected ip call: ${command.join(" ")}`);
}
// systemd-resolved's per-link settings, which go with the link: `resolvectl dns|domain wg-nested [values]` sets them, without values reads them.
if (command[0] === "resolvectl" && ["dns", "domain"].includes(command[1]) && command[2] === "wg-nested") {
  if (!tunnel) fail("Failed to resolve interface \"wg-nested\": No such device");
  const key = command[1] === "dns" ? "dns" : "domains";
  if (command.length === 3) done(`Link 9 (wg-nested): ${(tunnel![key] ?? []).join(" ")}`.trimEnd());
  tunnel![key] = command.slice(3);
  done();
}
const tunnelUp = () =>
  tunnel?.up && tunnel.peer === state.hubKey && tunnel.addresses.length > 0 && containers["nested-gateway"].status === "Running";
if (command[0] === "ping") {
  save();
  Deno.exit(tunnelUp() ? 0 : 1);
}
if (command[0] === "curl") {
  // The Instance container's environment sends every address but the egress proxy's through it, and the proxy cannot reach the tunnel.
  if (!(command.includes("--noproxy") && command[command.indexOf("--noproxy") + 1] === "*")) {
    if (command.some((value) => value.startsWith("-") && !value.startsWith("--") && value.includes("S"))) console.error("curl: (7) CONNECT tunnel failed, response 503");
    save();
    Deno.exit(7);
  }
  // `curlError`: a Doctor call that gets no response, such as a timeout.
  if (state.curlError && command.some((value) => value.endsWith("/doctor"))) {
    console.error(state.curlError);
    save();
    Deno.exit(28);
  }
  if (!tunnelUp()) {
    console.error("curl: (7) Failed to connect to 10.45.0.1 port 443 after 3 ms: Could not connect to server");
    save();
    Deno.exit(7);
  }
  // `-w '\nHTTP %{http_code}'`: the status on a last line of its own.
  const status = command.includes("-w") ? (code: number) => `\nHTTP ${code}` : () => "";
  const respond = (code: number, body: string): never => {
    Deno.stdout.writeSync(new TextEncoder().encode(body + status(code)));
    save();
    Deno.exit(0);
  };
  const url = command.find((value) => value.startsWith("https://"))!;
  const method = command.includes("-X") ? command[command.indexOf("-X") + 1] : "GET";
  const path = url.slice(url.indexOf("/api/v1/") + "/api/v1".length);
  if (method === "POST" && path === "/doctor") {
    state.doctorCalls = (state.doctorCalls ?? 0) + 1;
    // `doctorResponse`: a raw status and body, such as an empty 502 from Caddy while php-fpm restarts.
    if (state.doctorResponse) respond(state.doctorResponse.status, state.doctorResponse.body);
    if (state.schemaBehind && !state.restartedAfterMigrate) {
      respond(200, JSON.stringify({ data: { healthy: false, checks: [{ code: "gateway.schema_behind", node: "gateway" }] } }));
    }
    respond(200, JSON.stringify(state.doctorCalls > (state.doctorUnhealthy ?? 0)
      ? { data: { healthy: true, checks: [] } }
      : { data: { healthy: false, checks: [{ code: "node.agent_view_stale", node: "app-dev" }] } }));
  }
  // `agentsOffline`: the reads left in which the agent of app-prod is still offline (after a restart); -1 keeps it offline.
  if (method === "GET" && path === "/nodes") {
    const offline = (state.agentsOffline ?? 0) !== 0;
    if (state.agentsOffline > 0) state.agentsOffline -= 1;
    done({ data: offline ? state.nodes.map((node: { name: string }) => node.name === "app-prod" ? { ...node, agent: { state: "offline" } } : node) : state.nodes });
  }
  const converge = /^\/nodes\/(\d+)\/converge$/.exec(path);
  if (method === "POST" && converge) {
    // A refresh converges each Node with the default scope; nested-app-dev keeps the base scope below the 2,000,000 ID map, and its
    // default converge runs its `app-dev` role, the Instance host (`state.roleConverges`).
    const roles = command.at(-1) === '{"base":false}';
    if (!roles && !(command.at(-1) === '{"base":true}' && converge[1] === "2")) fail("A refresh converges the default scope, or app-dev's base");
    state.operations ??= [];
    const node = Number(converge[1]);
    // NodeNetworkReservation: a converge of the same Node is refused; beside a converge of the hub (the nested Gateway, Node 1), only
    // two base converges pass; two peers of the hub pass each other. An Operation is open until a read has returned its end.
    const open = state.operations.filter((op: { ended?: boolean }) => !op.ended);
    if (open.some((op: { node: number; roles?: boolean }) => op.node === node || ((node === 1 || op.node === 1) && (roles || op.roles)))) {
      fail("curl: (22) The requested URL returned error: 409", 22);
    }
    if (roles && node === 2) (state.roleConverges ??= []).push(2);
    // `convergeRefused`: the Nodes whose admission the nested Gateway refuses, as curl --fail reports a 409.
    if (state.convergeRefused?.includes(node)) fail("curl: (22) The requested URL returned error: 409", 22);
    // `egressNeeded`: a base converge runs apt and downloads the agent, so it fails on a Node without the egress files.
    const guest = containers[["nested-gateway", "nested-app-dev", "nested-app-prod"][node - 1]];
    const egress = !state.egressNeeded || state.egressNeeded.every((path: string) => (guest.files ?? {})[path]?.includes("10.253.0.1:3128"));
    state.operations.push({ node, roles, status: state.convergeStatus?.[node] ?? (egress ? "succeeded" : "failed") });
    done({ data: { id: 900 + state.operations.length, status: "pending" } });
  }
  // The refresh reads every admitted Operation in one call; with `-w '\n%{exitcode}\n'` curl prints each body, then that
  // transfer's exit code on a line of its own.
  const operationUrls = command.filter((value) => value.startsWith("https://") && /\/api\/v1\/operations\/\d+$/.test(value));
  const writeOut = command[command.indexOf("-w") + 1];
  if (method === "GET" && operationUrls.length > 0 && command.includes("-w") && writeOut.includes("%{exitcode}")) {
    Deno.stdout.writeSync(new TextEncoder().encode(operationUrls.map((value) => JSON.stringify(readOperation(Number(value.slice(value.lastIndexOf("/") + 1)))) + "\n0\n").join("")));
    save();
    Deno.exit(0);
  }
  const operation = /^\/operations\/(\d+)$/.exec(path);
  if (method === "GET" && operation) {
    Deno.stdout.writeSync(new TextEncoder().encode(JSON.stringify(readOperation(Number(operation[1]))) + (command.includes("-w") ? "\n" : "")));
    save();
    Deno.exit(0);
  }
  fail(`Unexpected API call: ${method} ${path}`);
}

/** One Operation as the nested Gateway returns it, from the fake's converge records. */
function readOperation(id: number): unknown {
  const found = state.operations[id - 901];
  // `convergeRunning`: the reads in which a Node's converge still runs.
  if ((state.convergeRunning?.[found.node] ?? 0) > 0) {
    state.convergeRunning[found.node] -= 1;
    return { data: { id, status: "running", error_code: null, result: null } };
  }
  found.ended = true;
  // `convergeChanged`: the tasks each Node's base converge changed, as the Operation's `result.changed`; 0 by default.
  // `convergeNoCount`: a result without a count. `convergePhases`: each Node's phases and their status, as the Operation's `result.phases`.
  const phases = state.convergePhases?.[found.node] ? { phases: state.convergePhases[found.node] } : {};
  return { data: { id, status: found.status, error_code: found.status === "failed" ? "node.converge_failed" : null,
    result: found.status !== "succeeded" ? null : state.convergeNoCount ? {} : { changed: state.convergeChanged?.[found.node] ?? 0, ...phases } } };
}
fail(`Unexpected command in the Instance container: ${command.join(" ")}`);
