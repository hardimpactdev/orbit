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
Deno.writeTextFileSync(`${folder}/calls`, JSON.stringify(args) + "\n", { append: true });
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

if (command[0] === "test" && command[1] === "-s") {
  save();
  Deno.exit(state.outer.files[command[2]] ? 0 : 1);
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
    if (rest[0] === "wg" && rest[1] === "show") done(state.hubKey);
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
      const inactive = units.filter((unit) => (state.inactive ?? []).includes(unit));
      if (rest[2] !== "--quiet") console.log(units.map((unit) => inactive.includes(unit) ? "inactive" : "active").join("\n"));
      save();
      Deno.exit(inactive.length > 0 ? 3 : 0);
    }
    if (rest[0] === "systemctl" && rest[1] === "show") done("inactive\ninactive");
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
  if (!tunnelUp()) {
    save();
    Deno.exit(7);
  }
  const url = command.find((value) => value.startsWith("https://"))!;
  const method = command.includes("-X") ? command[command.indexOf("-X") + 1] : "GET";
  const path = url.slice(url.indexOf("/api/v1/") + "/api/v1".length);
  if (method === "POST" && path === "/doctor") {
    state.doctorCalls = (state.doctorCalls ?? 0) + 1;
    done(state.doctorCalls > (state.doctorUnhealthy ?? 0)
      ? { data: { healthy: true, checks: [] } }
      : { data: { healthy: false, checks: [{ code: "node.agent_view_stale", node: "app-dev" }] } });
  }
  if (method === "GET" && path === "/nodes") done({ data: state.nodes });
  const converge = /^\/nodes\/(\d+)\/converge$/.exec(path);
  if (method === "POST" && converge) {
    if (command.at(-1) !== '{"base":true}') fail("A refresh converges the base");
    state.operations ??= [];
    const node = Number(converge[1]);
    state.operations.push({ node, status: state.convergeStatus?.[node] ?? "succeeded" });
    done({ data: { id: 900 + state.operations.length, status: "pending" } });
  }
  const operation = /^\/operations\/(\d+)$/.exec(path);
  if (method === "GET" && operation) {
    const found = state.operations[Number(operation[1]) - 901];
    done({ data: { id: Number(operation[1]), status: found.status, error_code: found.status === "failed" ? "node.converge_failed" : null } });
  }
  fail(`Unexpected API call: ${method} ${path}`);
}
fail(`Unexpected command in the Instance container: ${command.join(" ")}`);
