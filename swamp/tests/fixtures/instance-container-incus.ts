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
/** Incus's own id of a container: a UUID that differs per container. */
const uuid = (name: string) => {
  const hex = [...new TextEncoder().encode(name.padEnd(16, "-"))].slice(0, 16).map((byte) => byte.toString(16).padStart(2, "0")).join("");
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20, 32)}`;
};
const rootfs = (name: string) => `${folder}/containers/${name}`;
/** A container path inside its folder; other arguments stay as they are. */
const inside = (name: string, value: string) =>
  /^\/(srv|tmp|etc|home|usr\/local)\//.test(value) ? rootfs(name) + value : value;
/** A container's /run: a tmpfs, so it lives beside its root file system, and a stop, snapshot or copy never holds it. */
const tmpfs = (name: string) => `${folder}/tmpfs/${name}`;

if (args[0] === "query") {
  const [route, query = ""] = args.at(-1)!.slice(5).split("?");
  const parts = route.split("/");
  if (parts[0] === "network-acls") {
    console.log(JSON.stringify(parts[1] ? state["network-acls"].find((acl: {name: string}) => acl.name === parts[1]) : state["network-acls"]));
  } else if (parts[0] === "storage-pools") {
    console.log(JSON.stringify(Object.keys(state.containers).map((name) => ({ name, type: "container" }))));
  } else if (parts[0] === "instances") {
    const container = state.containers[parts[1]];
    if (!container) fail("Instance not found");
    if (parts[2] === "files") {
      // A directory's entries, as Incus lists them; it works on a stopped container too.
      const path = new URLSearchParams(query).get("path")!;
      try {
        console.log(JSON.stringify([...Deno.readDirSync(rootfs(parts[1]) + path)].map((entry) => entry.name).sort()));
      } catch {
        fail("Not Found");
      }
    } else if (parts[2] === "snapshots") {
      console.log(
        JSON.stringify(container.snapshots.map((name: string) => ({ name, created_at: container.created_at?.[name] ?? "2026-01-01T00:00:00Z" }))),
      );
    } else {
      const devices: Record<string, Record<string, string>> = { eth0: {type: "nic", network: "orbitinst0", "security.port_isolation": "true"} };
      for (const device of container.devices ?? []) {
        const comma = device.indexOf(",");
        const equal = device.indexOf("=", comma);
        const name = device.slice(0, comma);
        devices[name] ??= {};
        devices[name][device.slice(comma + 1, equal)] = device.slice(equal + 1);
      }
      Object.assign(devices, container.added ?? {});
      console.log(JSON.stringify({ name: parts[1], status: container.status, query, devices: container.added ?? {},
        expanded_devices: devices, expanded_config: {...Object.fromEntries((container.config ?? []).map((value: string) => { const split = value.indexOf("="); return [value.slice(0, split), value.slice(split + 1)]; })), ...(container.inherited_config ?? {})}, config: { "volatile.uuid": container.uuid } }));
    }
  } else fail(`Unexpected query ${route}`);
  Deno.exit(0);
}
if (args[0] === "network" && args[1] === "acl") {
  const project = take("--project");
  if (args[2] === "create") {
    state["network-acls"].push({ name: args[3], description: "", egress: [], ingress: [] });
  } else if (args[2] === "delete") {
    if (project !== "default") fail("ACLs must be removed from default");
    state["network-acls"] = state["network-acls"].filter((acl: {name: string}) => acl.name !== args[3]);
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
if (family === "list") {
  console.log(JSON.stringify(Object.entries(state.containers).map(([name, value]) => ({ name, ...(value as object) }))));
} else if (family === "delete") {
  const name = args.at(-1)!;
  delete state.containers[name];
  Deno.removeSync(rootfs(name), { recursive: true });
} else if (family === "init") {
  const name = args[2];
  const devices: string[] = [];
  for (let device = take("--device"); device !== null; device = take("--device")) devices.push(device);
  const config: string[] = [];
  for (let setting = take("--config"); setting !== null; setting = take("--config")) config.push(setting);
  state.containers[name] = { project, image: args[1], status: "Stopped", devices, config, snapshots: [], uuid: uuid(name) };
  Deno.mkdirSync(`${rootfs(name)}/srv/workspace`, { recursive: true });
  Deno.mkdirSync(`${rootfs(name)}/etc/systemd/system`, { recursive: true });
  Deno.mkdirSync(`${rootfs(name)}/etc/tmpfiles.d`, { recursive: true });
  for (const home of ["agent/.codex", "agent/.config/orbit", "orbit"]) {
    Deno.mkdirSync(`${rootfs(name)}/home/${home}`, { recursive: true });
  }
  Deno.mkdirSync(`${rootfs(name)}/tmp`, { recursive: true });
  Deno.mkdirSync(`${rootfs(name)}/usr/local/bin`, { recursive: true });
  Deno.mkdirSync(`${rootfs(name)}/usr/local/libexec`, { recursive: true });
  Deno.mkdirSync(`${rootfs(name)}/etc/systemd/system/multi-user.target.wants`, { recursive: true });
  const runtimeHome = rootfs(name) + "/var/lib/orbit/vite-plus-home";
  Deno.mkdirSync(runtimeHome, {recursive: true});
  Deno.symlinkSync("/opt/orbit-runtime/current/vite-plus-home/js_runtime", runtimeHome + "/js_runtime");
  // The image was published from a booted container, so every container made from it starts with the image's machine-id.
  Deno.writeTextFileSync(`${rootfs(name)}/etc/machine-id`, "0123456789abcdef0123456789abcdef\n");
} else if (family === "copy") {
  // A copy of a snapshot: the source's root file system as it is, a new container with the overridden devices.
  const [, from, name] = args;
  const [source, snapshot] = from.split("/");
  if (!state.containers[source]?.snapshots.includes(snapshot)) fail("Snapshot not found");
  if (state.containers[name]) fail("Instance already exists");
  const devices: string[] = [];
  for (let device = take("--device"); device !== null; device = take("--device")) devices.push(device);
  // Incus copy merges overrides into LOCAL snapshot devices, not expanded profile devices.
  const local: Record<string, Record<string, string>> = {};
  for (const device of [...state.containers[source].devices, ...devices]) {
    const comma = device.indexOf(",");
    const equal = device.indexOf("=", comma);
    const name = device.slice(0, comma);
    local[name] ??= {};
    local[name][device.slice(comma + 1, equal)] = device.slice(equal + 1);
  }
  if (local.root && !local.root.type) fail('Failed creating instance record: Invalid device type ""');
  new Deno.Command("cp", { args: ["-R", rootfs(source), rootfs(name)] }).outputSync();
  for (const path of ["home/agent/.codex", "home/agent/.config/orbit", "etc/orbit"]) {
    Deno.mkdirSync(`${rootfs(name)}/${path}`, { recursive: true });
  }
  const config: string[] = [];
  for (let setting = take("--config"); setting !== null; setting = take("--config")) config.push(setting);
  state.containers[name] = { project, source: from, status: "Stopped",
    devices: Object.entries(local).flatMap(([device, settings]) => Object.entries(settings).map(([key, value]) => `${device},${key}=${value}`)),
    config, snapshots: [], uuid: uuid(name) };
} else if (family === "config" && args[1] === "device" && args[2] === "add") {
  const [, , , name, device, type, ...settings] = args;
  state.containers[name].added ??= {};
  state.containers[name].added[device] = { type, settings };
} else if (family === "config" && args[1] === "set") {
  // A local key replaces a key of the same name.
  const [, , name, setting] = args;
  const key = setting.slice(0, setting.indexOf("="));
  state.containers[name].config = [...(state.containers[name].config ?? []).filter((value: string) => !value.startsWith(key + "=")), setting];
} else if (family === "pause" || family === "resume") {
  // A freeze keeps the processes and their memory; Incus refuses a pause of a container that does not run and a resume of one that
  // is not frozen. `pause_fails` holds a pause that Incus refuses.
  const name = args.at(-1)!;
  if (!state.containers[name]) fail("Instance not found");
  if (family === "pause" && (state.pause_fails || state.containers[name].status !== "Running")) fail("The instance isn't running");
  if (family === "resume" && (state.resume_fails || state.containers[name].status !== "Frozen")) fail("The instance is not frozen");
  state.containers[name].status = family === "pause" ? "Frozen" : "Running";
} else if (family === "start" || family === "stop") {
  const name = args.at(-1)!;
  if (!state.containers[name]) fail("Instance not found");
  // Incus 7: a start of a Frozen container unfreezes it, with no boot; a clean stop of one times out and leaves it running, so only a
  // forced stop ends it.
  if (state.containers[name].status === "Frozen" && family === "start") {
    state.containers[name].status = "Running";
    save();
    Deno.exit(0);
  }
  if (family === "stop" && state.stop_fails) fail("Failed shutting down instance");
  if (state.containers[name].status === "Frozen" && !args.includes("--force")) fail("Failed shutting down instance, status is \"Running\"");
  // A boot brings a web unit back that stayed silent after a resume.
  if (family === "start") delete state.containers[name].silent_until_boot;
  state.containers[name].status = family === "start" ? "Running" : "Stopped";
  if (family === "stop") {
    try {
      Deno.removeSync(tmpfs(name), { recursive: true });
    } catch { /* nothing in /run */ }
  }
  // A boot starts every unit that is enabled: a link in multi-user.target.wants.
  if (family === "start") {
    const wants = `${rootfs(name)}/etc/systemd/system/multi-user.target.wants`;
    let booted: string[] = [];
    try {
      booted = [...Deno.readDirSync(wants)].map((entry) => entry.name).sort();
    } catch { /* no unit is enabled */ }
    state.containers[name].booted = [...(state.containers[name].booted ?? []), booted];
    state.containers[name].bootMachineIds = [...(state.containers[name].bootMachineIds ?? []),
      Deno.readTextFileSync(`${rootfs(name)}/etc/machine-id`).trim()];
    state.inactive = (state.inactive ?? []).filter((unit: string) => !booted.includes(unit) || (state.failing ?? []).includes(unit));
  }
} else if (family === "snapshot") {
  const [, verb, name, snapshot] = args;
  const container = state.containers[name];
  if (verb === "create") {
    if (container.snapshots.includes(snapshot) && !args.includes("--reuse")) fail("Snapshot already exists");
    container.snapshots = container.snapshots.filter((s: string) => s !== snapshot);
    container.snapshots.push(snapshot);
    // The snapshot's content: the root file system as it is now, never the tmpfs.
    Deno.mkdirSync(`${folder}/snapshots/${name}`, { recursive: true });
    new Deno.Command("cp", { args: ["-R", rootfs(name), `${folder}/snapshots/${name}/${snapshot}`] }).outputSync();
    // Incus orders nothing for a reader; each snapshot carries its creation time.
    state.clock = (state.clock ?? 0) + 1;
    container.created_at = { ...(container.created_at ?? {}), [snapshot]: new Date(Date.UTC(2026, 10, 13, 0, 0, state.clock)).toISOString() };
  } else if (verb === "restore") {
    if (!container.snapshots.includes(snapshot)) fail("Snapshot not found");
    container.restored = [...(container.restored ?? []), snapshot];
  } else {
    container.snapshots = container.snapshots.filter((s: string) => s !== snapshot);
  }
} else if (family === "file" && args[1] === "delete") {
  for (const target of args.slice(2).filter((arg) => !arg.startsWith("-"))) {
    const name = target.slice(0, target.indexOf("/"));
    Deno.removeSync(rootfs(name) + target.slice(name.length), { recursive: args.includes("--force") });
  }
} else if (family === "file" && args[1] === "push") {
  const createDirs = args.indexOf("--create-dirs");
  if (createDirs >= 0) args.splice(createDirs, 1);
  const uid = take("--uid");
  const gid = take("--gid");
  const mode = take("--mode")!;
  const [, , source, target] = args;
  const name = target.slice(0, target.indexOf("/"));
  const path = rootfs(name) + target.slice(name.length);
  if (createDirs >= 0) Deno.mkdirSync(path.slice(0, path.lastIndexOf("/")), { recursive: true });
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
  const user = command[0] === "runuser" ? command[command.indexOf("-u") + 1] : "root";
  if (command[0] === "runuser") command = command.slice(command.indexOf("--") + 1);
  const commandTimeout = command[0] === "timeout" ? Number(command[1]) : null;
  if (command[0] === "timeout") command = command.slice(2);
  if (command[0] === "systemctl") {
    state.systemctl ??= [];
    state.systemctl.push(command.slice(1));
    if (command[1] === "start" && command.includes("t3.service")) state.containers[name].t3 = true;
    // A started unit runs and a stopped one does not, unless the test holds it in `failing`.
    const units = command.slice(command.indexOf("--") + 1);
    const wants = `${rootfs(name)}/etc/systemd/system/multi-user.target.wants`;
    if (command[1] === "enable" || command[1] === "disable") {
      Deno.mkdirSync(wants, { recursive: true });
      for (const unit of units) {
        try {
          Deno.removeSync(`${wants}/${unit}`);
        } catch { /* not enabled */ }
        if (command[1] === "enable") Deno.symlinkSync(`/etc/systemd/system/${unit}`, `${wants}/${unit}`);
      }
    }
    state.inactive ??= [];
    if (["start", "restart"].includes(command[1]) || (command[1] === "enable" && command[2] === "--now")) {
      state.inactive = state.inactive.filter((unit: string) => !units.includes(unit) || (state.failing ?? []).includes(unit));
    } else if (command[1] === "stop" || (command[1] === "disable" && command[2] === "--now")) {
      state.inactive = [...new Set([...state.inactive, ...units])];
    }
    if (command[1] === "disable" && command.includes("--now")) {
      const seconds = state.slowStopSeconds ?? 0;
      if (seconds > 0) {
        if (command.includes("--no-block")) {
          state.stopDue = Date.now() + seconds * 1000;
        } else {
          await new Promise((resolve) => setTimeout(resolve, Math.min(seconds, commandTimeout ?? seconds) * 1000));
          if (commandTimeout !== null && seconds > commandTimeout) Deno.exit(124);
          state.slowStopCompleted = true;
        }
      }
    }
    save();
    if (command[1] === "show") {
      const unit = command.at(-1)!;
      const queued = state.stopDue && Date.now() < state.stopDue ? 1 : (state.queued?.[unit] ?? 0);
      if (queued > 0) {
        if (state.queued?.[unit]) state.queued[unit] = queued - 1;
        save();
      }
      // A Process unit whose file is not installed does not exist for systemd: it never becomes active.
      let missing = false;
      try {
        Deno.statSync(`${rootfs(name)}/etc/systemd/system/${unit}`);
      } catch {
        missing = unit.startsWith("orbit-process-");
      }
      console.log(`ActiveState=${missing || (state.inactive ?? []).includes(unit) ? "inactive" : "active"}`);
      console.log(queued > 0 ? "Job=123" : "Job=");
      Deno.exit(0);
    }
    if (command[1] === "is-active") {
      const seconds = state.inspectionSeconds ?? 0;
      if (seconds > 0) {
        await new Promise((resolve) => setTimeout(resolve, Math.min(seconds, commandTimeout ?? seconds) * 1000));
        if (commandTimeout !== null && seconds > commandTimeout) Deno.exit(124);
      }
      const inactive = command.slice(3).filter((unit) => (state.inactive ?? []).includes(unit));
      for (const unit of command.slice(3)) console.log(inactive.includes(unit) ? "inactive" : "active");
      Deno.exit(inactive.length > 0 ? 3 : 0);
    }
    Deno.exit(0);
  }
  if (command[0] === "python3" && command[1] === "-c" && command.at(-1) === "proxy" && command[2].includes("def write_proxy_environment")) {
    // instance_node_services.py `proxy`, the one writer of the container's proxy bypass: keep the value it was given.
    const given = JSON.parse(new TextDecoder().decode(await new Response(Deno.stdin.readable).arrayBuffer())).proxy_environment;
    const changed = JSON.stringify(state.containers[name].proxy ?? null) !== JSON.stringify(given);
    state.containers[name].proxy = given;
    state.proxyWrites = (state.proxyWrites ?? 0) + 1;
    save();
    console.log(JSON.stringify({ changed }));
    Deno.exit(0);
  }
  if (command[0] === "python3" && (command[1] === "/usr/local/libexec/orbit-instance-node-runtime.py" || command.includes("seed-prepare") || command.includes("seed-complete"))) {
    // Runtime conversion and Vite+ behavior have their own real helper/fake-vp fixture.
    const converted = command.includes("seed-prepare") && state.node_seed_convert === true;
    state.node_seed_calls = [...(state.node_seed_calls ?? []), command];
    state.node_seed_convert = false;
    if (converted) state.node_seed_pending = true;
    if (command.includes("seed-complete")) state.node_seed_pending = false;
    save();
    console.log(JSON.stringify({status: converted ? "converted" : "unchanged", versions: ["24.22.1"], pins: [], seed_refresh_required: state.node_seed_pending === true}));
    Deno.exit(0);
  }
  if (command[0] === "findmnt" && command.at(-1) === "/run") {
    console.log(state.run_fstype ?? "tmpfs");
    Deno.exit(0);
  }
  // The shipped Club key script runs for real: its /run folder in the tmpfs, its tmpfiles line in the root file system, and
  // findmnt, chown and the inner Incus of the nested fleet as fakes that record their arguments.
  if (command[0] === "sh" && command[1] === "-c" && command[2].startsWith("# orbit-club-key:")) {
    const [directory, file, owner, group, tmpfiles, gateway, unit] = command.slice(4);
    if (!directory.startsWith("/run/")) fail("The Club key folder is not under /run");
    const bin = `${folder}/club-bin`;
    Deno.mkdirSync(bin, { recursive: true });
    Deno.writeTextFileSync(`${bin}/findmnt`, `#!/bin/sh\necho "$FAKE_FSTYPE"\n`);
    Deno.writeTextFileSync(`${bin}/chown`, `#!/bin/sh\necho "$*" >> '${folder}/club-chown'\n`);
    // A just-booted container's inner Incus waits behind network-online.target: its service is not active and its client hangs.
    Deno.writeTextFileSync(`${bin}/systemctl`, `#!/bin/sh\necho "systemctl $*" >> '${folder}/inner-calls'\n[ "$FAKE_INNER" != inactive ]\n`);
    Deno.writeTextFileSync(`${bin}/incus`, `#!/bin/sh\necho "$*" >> '${folder}/inner-calls'\n[ "$FAKE_INNER" = hang ] && exec sleep 600\n[ "$FAKE_INNER" = restart_fails ] && [ "$1" = exec ] && case "$*" in *restart*) exit 1;; esac\n[ "$1" = list ] && echo "$FAKE_GATEWAY"\nexit 0\n`);
    for (const tool of ["findmnt", "chown", "incus", "systemctl"]) Deno.chmodSync(`${bin}/${tool}`, 0o755);
    state.club_key_runs = [...(state.club_key_runs ?? []), command.slice(4)];
    save();
    const done = new Deno.Command("sh", {
      args: ["-c", command[2], "sh", tmpfs(name) + directory, file, owner, group, rootfs(name) + tmpfiles, gateway, unit],
      stdin: "inherit",
      env: { PATH: `${bin}:${Deno.env.get("PATH")}`, FAKE_FSTYPE: state.run_fstype ?? "tmpfs", FAKE_GATEWAY: state.gateway_status ?? "RUNNING",
        FAKE_INNER: state.inner_incus ?? "ready" },
    }).outputSync();
    // The script saw the tmpfs folder at its fixture path; inside the container that folder is the /run path itself.
    try {
      const line = rootfs(name) + tmpfiles;
      Deno.writeTextFileSync(line, Deno.readTextFileSync(line).replaceAll(tmpfs(name), ""));
    } catch { /* the script stopped before it wrote the line */ }
    Deno.stdout.writeSync(done.stdout);
    Deno.stderr.writeSync(done.stderr);
    Deno.exit(done.code);
  }
  if (command[0] === "journalctl") {
    const unit = command[command.indexOf("--unit") + 1];
    for (const line of (state.journal?.[unit] ?? []).slice().reverse()) console.log(line);
    Deno.exit(0);
  }
  // T3 in the container: its well-known document once started, sessions it issues and knows, and the project it adds.
  if (command[0] === "curl") {
    const url = command.at(-1)!;
    // FrankenPHP on the web port: `web` lists the status of each answer in turn and keeps its last; none means it does not listen.
    if (url.startsWith("http://127.0.0.1:8080/")) {
      const web: number[] = state.containers[name].web ?? [];
      if (web.length === 0 || state.containers[name].silent_until_boot) Deno.exit(7);
      const status = web.length > 1 ? web.shift()! : web[0];
      save();
      Deno.stdout.writeSync(new TextEncoder().encode(String(status)));
      Deno.exit(0);
    }
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
  // Synthetic fleet targets have no listener on this machine. Model the NIC's published tuple and
  // the bridge's post-DNAT tuple from the ACLs actually written, not just from the admitted intent.
  // Localhost reach/refusal cases below still use real sockets.
  if (command.some((arg) => arg.includes("/dev/tcp/$1/$2")) && state.bridgeProbe) {
    const target = state.bridgeProbe;
    if (command.at(-2) !== target.host || command.at(-1) !== String(target.port)) fail("Unexpected bridge probe");
    const allows = (rule: Record<string, string>, host: string, port: number) =>
      rule.action === "allow" && rule.state === "enabled" && rule.protocol === "tcp" &&
      rule.destination === `${host}/32` && rule.destination_port === String(port);
    const attached = state.containers[name].devices.find((device: string) => device.startsWith("eth0,security.acls="))
      ?.slice("eth0,security.acls=".length).split(",") ?? [];
    const nic = state["network-acls"].filter((acl: {name: string}) => attached.includes(acl.name));
    const bridge = state["network-acls"].find((acl: {name: string}) => acl.name === "orbitinst0-egress");
    const admitted = nic.some((acl: {egress: Record<string, string>[]}) => acl.egress.some((rule) => allows(rule, target.host, target.port))) &&
      bridge?.egress.some((rule: Record<string, string>) => rule.source === "10.253.0.0/24" && allows(rule, target.translatedHost, target.translatedPort));
    Deno.exit(admitted ? 0 : 1);
  }
  if (command[0] === "python3" && command[1] === "-c" && command[2].includes("/var/lib/orbit/vite-plus-home/js_runtime")) {
    command[2] = command[2].replaceAll("/var/lib/orbit/vite-plus-home", rootfs(name) + "/var/lib/orbit/vite-plus-home")
      .replaceAll("/usr/local/libexec", rootfs(name) + "/usr/local/libexec");
  }
  const done = new Deno.Command(command[0], {
    args: command.slice(1).map((value) => command[0] === "git" && command[1] === "config" ? value : inside(name, value)),
    cwd: cwd === null ? undefined : inside(name, cwd),
    stdin: "inherit",
    env: { GIT_CONFIG_NOSYSTEM: "1", HOME: user === "root" ? `${folder}/home` : `${rootfs(name)}/home/${user}` },
  }).outputSync();
  Deno.stdout.writeSync(done.stdout);
  Deno.stderr.writeSync(done.stderr);
  save();
  Deno.exit(done.code);
} else fail(`Unexpected Incus call: ${Deno.args.join(" ")}`);
save();
