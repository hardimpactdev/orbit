/**
 * Stateful Incus boundary for the container phases of a default Instance. A container is a folder under the fixture, its root file
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
  /^\/(srv|tmp|etc|home)\//.test(value) ? rootfs(name) + value : value;

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
    } else console.log(JSON.stringify({ name: parts[1], status: container.status, query }));
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
    save();
    if (command[1] === "is-active") {
      const inactive = command.slice(3).filter((unit) => (state.inactive ?? []).includes(unit));
      for (const unit of command.slice(3)) console.log(inactive.includes(unit) ? "inactive" : "active");
      Deno.exit(inactive.length > 0 ? 3 : 0);
    }
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
