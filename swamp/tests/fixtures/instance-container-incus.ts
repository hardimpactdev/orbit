/**
 * Stateful Incus boundary for the container phases of a default Instance and its clones. A container is a folder under the fixture, its root file
 * system; `incus exec` runs the command on this machine with the container's paths mapped into that folder, as `runuser` would run
 * it, and records `systemctl` calls instead of running them. No live host commands.
 */
const folder = Deno.env.get("HOST_FIXTURE")!;
const file = `${folder}/state.json`;
const state = JSON.parse(Deno.readTextFileSync(file));
state.containers ??= {};
state["network-acls"] ??= [];
const args = [...Deno.args];
Deno.writeTextFileSync(`${folder}/calls`, JSON.stringify(args) + "\n", {
  append: true,
});
const take = (flag: string) => {
  const index = args.indexOf(flag);
  if (index < 0) return null;
  const [, value] = args.splice(index, 2);
  return value;
};
const fail = (message: string) => {
  console.error(`Error: ${message}`);
  Deno.exit(1);
};
const save = () => Deno.writeTextFileSync(file, JSON.stringify(state));
const rootfs = (name: string) => `${folder}/containers/${name}`;
/** A container path inside its folder; other arguments stay as they are. */
const inside = (name: string, value: string) =>
  /^\/(srv|tmp|etc|home|usr\/local)\//.test(value) ? rootfs(name) + value : value;

if (args[0] === "query") {
  const [route, query = ""] = args.at(-1)!.slice(5).split("?");
  const parts = route.split("/");
  if (parts[0] === "network-acls") {
    console.log(JSON.stringify(state["network-acls"]));
  } else if (parts[0] === "instances") {
    const container = state.containers[parts[1]];
    if (!container) fail("Instance not found");
    if (parts[2] === "snapshots") {
      console.log(
        JSON.stringify(container.snapshots.map((name: string) => ({ name }))),
      );
    } else {
      console.log(JSON.stringify({ name: parts[1], status: container.status, query, devices: container.added ?? {} }));
    }
  } else fail(`Unexpected query ${route}`);
  Deno.exit(0);
}
if (args[0] === "network" && args[1] === "acl") {
  take("--project");
  if (args[2] === "create") {
    state["network-acls"].push({ name: args[3], description: "", egress: [], ingress: [] });
  } else {
    Object.assign(
      state["network-acls"].find((acl: { name: string }) => acl.name === args[3]),
      JSON.parse(await new Response(Deno.stdin.readable).text()),
    );
  }
  save();
  Deno.exit(0);
}
const project = take("--project");
const [family] = args;
if (family === "init") {
  const name = args[2];
  const devices: string[] = [];
  for (let device = take("--device"); device !== null; device = take("--device")) devices.push(device);
  state.containers[name] = { project, image: args[1], status: "Stopped", devices, config: take("--config"), snapshots: [] };
  Deno.mkdirSync(`${rootfs(name)}/srv/workspace`, { recursive: true });
  Deno.mkdirSync(`${rootfs(name)}/etc/systemd/system`, { recursive: true });
  Deno.mkdirSync(`${rootfs(name)}/tmp`, { recursive: true });
  Deno.mkdirSync(`${rootfs(name)}/usr/local/bin`, { recursive: true });
} else if (family === "copy") {
  // A copy of a snapshot: the source's root file system as it is, a new container with the overridden devices.
  const [, from, name] = args;
  const [source, snapshot] = from.split("/");
  if (!state.containers[source]?.snapshots.includes(snapshot)) fail("Snapshot not found");
  if (state.containers[name]) fail("Instance already exists");
  const devices: string[] = [];
  for (let device = take("--device"); device !== null; device = take("--device")) devices.push(device);
  new Deno.Command("cp", { args: ["-R", rootfs(source), rootfs(name)] }).outputSync();
  for (const path of ["home/agent/.codex", "home/agent/.config/orbit", "etc/orbit"]) {
    Deno.mkdirSync(`${rootfs(name)}/${path}`, { recursive: true });
  }
  state.containers[name] = { project, source: from, status: "Stopped", devices, snapshots: [] };
} else if (family === "config" && args[1] === "device" && args[2] === "add") {
  const [, , , name, device, type, ...settings] = args;
  state.containers[name].added ??= {};
  state.containers[name].added[device] = { type, settings };
} else if (family === "start" || family === "stop") {
  const name = args.at(-1)!;
  if (!state.containers[name]) fail("Instance not found");
  state.containers[name].status = family === "start" ? "Running" : "Stopped";
} else if (family === "snapshot") {
  const [, verb, name, snapshot] = args;
  const container = state.containers[name];
  if (verb === "create") {
    if (container.snapshots.includes(snapshot)) fail("Snapshot already exists");
    container.snapshots.push(snapshot);
  } else {
    container.snapshots = container.snapshots.filter((s: string) => s !== snapshot);
  }
} else if (family === "file" && args[1] === "push") {
  const uid = take("--uid");
  const gid = take("--gid");
  const mode = take("--mode")!;
  const [, , source, target] = args;
  const name = target.slice(0, target.indexOf("/"));
  const path = rootfs(name) + target.slice(name.length);
  const content = source === "-"
    ? new Uint8Array(await new Response(Deno.stdin.readable).arrayBuffer())
    : Deno.readFileSync(source);
  Deno.writeFileSync(path, content);
  Deno.chmodSync(path, parseInt(mode, 8));
  state.files ??= {};
  state.files[target.slice(name.length)] = { uid, gid, mode };
} else if (family === "exec") {
  const cwd = take("--cwd");
  while (take("--env") !== null) { /* the environment of `orbit` */ }
  const name = args[1];
  if (state.containers[name]?.status !== "Running") fail("Instance is not running");
  let command = args.slice(args.indexOf("--") + 1);
  if (command[0] === "runuser") command = command.slice(command.indexOf("--") + 1);
  if (command[0] === "systemctl") {
    state.systemctl ??= [];
    state.systemctl.push(command.slice(1));
    if (command[1] === "start" && command.includes("t3.service")) state.containers[name].t3 = true;
    save();
    if (command[1] === "is-active") {
      const inactive = command.slice(3).filter((unit) => (state.inactive ?? []).includes(unit));
      for (const unit of command.slice(3)) console.log(inactive.includes(unit) ? "inactive" : "active");
      Deno.exit(inactive.length > 0 ? 3 : 0);
    }
    Deno.exit(0);
  }
  // T3 in the container: its well-known document once started, sessions it issues and knows, and the project it adds.
  if (command[0] === "curl") {
    const url = command.at(-1)!;
    if (!state.containers[name].t3) Deno.exit(7);
    if (url.endsWith("/.well-known/t3/environment")) {
      console.log(JSON.stringify({ environmentId: `env-${name}`, label: "t3", serverVersion: "0.1.7" }));
    } else {
      const token = (await new Response(Deno.stdin.readable).text()).replace("Authorization: Bearer ", "").trim();
      const known = (state.sessions ?? []).find((session: { token: string }) => session.token === token);
      Deno.stdout.writeSync(new TextEncoder().encode(known
        ? JSON.stringify({ authenticated: true, scopes: known.scopes, expiresAt: "2026-12-10T10:00:00.000Z" }) + "\n200"
        : "\n401"));
    }
    Deno.exit(0);
  }
  if (command[0].endsWith("/t3")) {
    if (command[1] === "project") {
      state.projects = [...(state.projects ?? []), command[3]];
      console.log(`project-${state.projects.length}`);
    } else {
      const label = command[command.indexOf("--label") + 1];
      state.sessions ??= [];
      const token = `token-${state.sessions.length + 1}`;
      state.sessions.push({ token, label, scopes: label === "Orbit Gateway" ? ["access:read", "access:write"] : ["orchestration:read"] });
      console.log(token);
    }
    save();
    Deno.exit(0);
  }
  const done = new Deno.Command(command[0], {
    args: command.slice(1).map((value) => inside(name, value)),
    cwd: cwd === null ? undefined : inside(name, cwd),
    env: { GIT_CONFIG_NOSYSTEM: "1", HOME: `${folder}/home` },
  }).outputSync();
  Deno.stdout.writeSync(done.stdout);
  Deno.stderr.writeSync(done.stderr);
  save();
  Deno.exit(done.code);
} else fail(`Unexpected Incus call: ${Deno.args.join(" ")}`);
save();
