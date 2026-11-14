/**
 * Stateful Incus boundary for the nested fleet of an Instance container (swamp/ansible/tasks/instance-fleet-*.yml). The Node's `incus`
 * reaches only `exec` into the Instance container; there the inner `incus`, `ip`, `wg`, `ping`, `test` and `curl` act on one JSON state:
 * the nested containers with their devices, configuration keys, files and snapshots, the operator tunnel, and the nested Gateway's API.
 * Every call is one JSON line in `calls`. No live host command runs.
 */
const folder = Deno.env.get("HOST_FIXTURE")!;
const file = `${folder}/state.json`;
const state = JSON.parse(Deno.readTextFileSync(file));
const args = [...Deno.args];
// A script sent with `python3 -c` is recorded by its name.
const recorded = args[5] === "python3" && args[6] === "-c" ? [...args.slice(0, 7), "<instance_node_services.py>", ...args.slice(8)] : args;
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
const tunnel = state.tunnel as null | { addresses: string[]; up: boolean; key?: string; port?: string; peer?: string; endpoint?: string; allowed?: string };

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
// The Git object IDs of the workspace's checkout: `digests` maps each path to its ID; `digestsFail` makes the read fail. A path under
// `swamp/` that `digests` does not name has the ID `c3`.
if (command[0] === "git" && command.includes("rev-parse")) {
  if (state.digestsFail) fail("fatal: not a git repository");
  const digests: Record<string, string> = { "apps/agent/contract": "d4", ...(state.digests ?? { "apps/gateway": "a1" }) };
  done(command.slice(command.indexOf("rev-parse") + 1).map((object) => object.slice("HEAD:".length))
    .map((path) => digests[path] ?? (path.startsWith("swamp/") ? "c3" : "")).join("\n"));
}

if (command[0] === "incus") {
  const inner = command.slice(1);
  if (inner[0] === "admin" && inner[1] === "waitready") done();
  if (inner[0] === "list") {
    done(Object.entries(containers).map(([name, value]) => `${name},${value.status.toUpperCase()}`).join("\n"));
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
      found.snapshots[snapshot] = { config: copy(found.config), devices: copy(found.devices), files: copy(found.files), links: copy(found.links ?? {}), taken: found.status };
    } else if (verb === "delete") {
      if (!found.snapshots[snapshot]) fail("Snapshot not found");
      delete found.snapshots[snapshot];
    } else if (verb === "restore") {
      const taken = found.snapshots[snapshot] ?? fail("Snapshot not found");
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
    // The grants play in the nested Gateway: its recap names how many files it changed (`grantsChanged`, 0 by default).
    if (rest[0] === "env" && rest.some((value) => value.endsWith("ansible-playbook"))) {
      const changed = state.grantsChanged ?? 0;
      delete state.grantsChanged;
      done(`PLAY RECAP *********\nlocalhost                  : ok=4    changed=${changed}    unreachable=0    failed=0    skipped=0`);
    }
    // A checkout that moved under the running nested Gateway (`schemaBehind`): Doctor is unhealthy until the migrate and a restart after it.
    // `pendingMigration`: the migrate applies one (Laravel prints each migration); otherwise it prints that nothing was due.
    // The nested Gateway's digest of each named Node's base converge intent (`orbit:node-converge-digest`): `intentDigests` names a
    // Node's digest, `intentDigestFail` makes the command fail.
    if (rest[0] === "php" && rest.includes("orbit:node-converge-digest")) {
      if (state.intentDigestFail) fail("The base intent of app-dev cannot be built: RuntimeException");
      const own: Record<string, string> = { gateway: "1", "app-dev": "2", "app-prod": "3" };
      const nodes = rest.slice(rest.indexOf("--base") + 1);
      done(JSON.stringify(Object.fromEntries(nodes.map((node) => [node, state.intentDigests?.[node] ?? own[node].repeat(64)]))));
    }
    if (rest[0] === "php" && rest.includes("migrate")) {
      state.migrated = true;
      const applied = state.pendingMigration || state.schemaBehind;
      delete state.pendingMigration;
      // The nested Gateway's database lives on its own disk: an applied migration moves its schema mark there.
      if (applied && name === "nested-gateway") {
        const gateway = container(name);
        gateway.files ??= {};
        gateway.files["/home/orbit/.orbit/schema"] = String(Number(gateway.files["/home/orbit/.orbit/schema"] ?? "0") + 1);
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
    if (command.at(-1) !== '{"base":true}') fail("A refresh converges the base");
    state.operations ??= [];
    const node = Number(converge[1]);
    // `egressNeeded`: a base converge runs apt and downloads the agent, so it fails on a Node without the egress files.
    const guest = containers[["nested-gateway", "nested-app-dev", "nested-app-prod"][node - 1]];
    const egress = !state.egressNeeded || state.egressNeeded.every((path: string) => (guest.files ?? {})[path]?.includes("10.253.0.1:3128"));
    state.operations.push({ node, status: state.convergeStatus?.[node] ?? (egress ? "succeeded" : "failed") });
    done({ data: { id: 900 + state.operations.length, status: "pending" } });
  }
  const operation = /^\/operations\/(\d+)$/.exec(path);
  if (method === "GET" && operation) {
    const found = state.operations[Number(operation[1]) - 901];
    // `convergeChanged`: the tasks each Node's base converge changed, as the Operation's `result.changed`; 0 by default.
    // `convergeNoCount`: a result without a count.
    done({ data: { id: Number(operation[1]), status: found.status, error_code: found.status === "failed" ? "node.converge_failed" : null,
      result: found.status !== "succeeded" ? null : state.convergeNoCount ? {} : { changed: state.convergeChanged?.[found.node] ?? 0 } } });
  }
  fail(`Unexpected API call: ${method} ${path}`);
}
fail(`Unexpected command in the Instance container: ${command.join(" ")}`);
