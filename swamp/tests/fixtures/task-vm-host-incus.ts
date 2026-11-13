/** Stateful Incus boundary for the real host-preparation Ansible tasks. No live host commands. */
const folder = Deno.env.get("HOST_FIXTURE")!;
if (Deno.args[0] === "--daemon") {
  Deno.serve({ path: `${folder}/unix.socket`, onListen: () => console.log(`ready ${Deno.pid}`) }, () => {
    const current = JSON.parse(Deno.readTextFileSync(`${folder}/state.json`));
    return Response.json({ metadata: { environment: { server_pid: current.socket_server_pid ?? Deno.pid } } });
  });
  await new Promise(() => {});
}
const settings = JSON.parse(Deno.readTextFileSync(`${folder}/state.json`));
const configRemote = Deno.env.get("INCUS_REMOTE") ?? "";
const args = [...Deno.args];
// Incus takes both spellings of the global flag: "--project P" and "--project=P".
const joinedProject = args.findIndex((arg) => arg.startsWith("--project="));
if (joinedProject >= 0) args.splice(joinedProject, 1, "--project", args[joinedProject].slice(10));
const projectIndex = args.indexOf("--project");
const project = projectIndex >= 0 ? args[projectIndex + 1]
  : (args[0] === "query" ? new URLSearchParams(args.at(-1)?.split("?")[1]).get("project") : null) ?? "default";
if (projectIndex >= 0) args.splice(projectIndex, 2);
// Incus 7.0.2 refuses the global project flag on its raw API command; the path carries the project.
if (projectIndex >= 0 && args[0] === "query") {
  console.error("Error: --project cannot be used with the query command");
  Deno.exit(1);
}
// Incus parses explicit image targets before applying the environment's default remote/socket.
const targetIndex = args[0] === "image"
  ? (args[1] === "alias" ? 3
    : ["set-property", "unset-property", "delete", "show", "info", "list"].includes(args[1]) ? 2 : -1) : -1;
const target = targetIndex >= 0 ? args[targetIndex] : undefined;
const explicitRemote = target?.includes(":") ? target.split(":", 1)[0] : undefined;
const remote = explicitRemote !== undefined ? explicitRemote !== "local"
  : settings.default_remote && configRemote !== "local";
const file = `${folder}/${remote ? "remote-state" : "state"}.json`;
const state = JSON.parse(Deno.readTextFileSync(file));
state.profiles ??= {};
state.instances ??= [];
Deno.writeTextFileSync(`${folder}/calls`, JSON.stringify(Deno.args) + "\n", {
  append: true,
});
Deno.writeTextFileSync(`${folder}/targets`, JSON.stringify({
  args: Deno.args, remote: Boolean(remote), configRemote, socket: Deno.env.get("INCUS_SOCKET") ?? "",
}) + "\n", { append: true });
if (explicitRemote !== undefined) args[targetIndex] = target!.slice(explicitRemote.length + 1);
const [family, action, name] = args;
const owned = (image: {project?: string; fingerprint: string}) => image.project === undefined || image.project === project;
const archive = (fingerprint: string) => `${folder}/images/${fingerprint}`;
const digest = async (value: string) => Array.from(new Uint8Array(await crypto.subtle.digest("SHA-256", new TextEncoder().encode(value))))
  .map((byte) => byte.toString(16).padStart(2, "0")).join("");
const input = async () =>
  JSON.parse(await new Response(Deno.stdin.readable).text());
const fail = (message: string) => {
  console.error(`Error: ${message}`);
  Deno.exit(1);
};
const instance = (named: string) =>
  state.instances.find((r: { name: string }) => r.name === named);
/** Incus splits `key=value` at the first `=`: a value such as `port=0` holds one itself. */
const pair = (argument: string) => {
  const at = argument.indexOf("=");
  return [argument.slice(0, at), argument.slice(at + 1)];
};
type Accounts = {
  users: Record<string, number>;
  groups: Record<string, number>;
};
/**
 * The account commands of a setup script, as the shadow tools answer them in a container: `groupadd` and `useradd` refuse an id
 * that is taken, and `groupdel` a group that is missing. Other lines change nothing here.
 */
function accounts(row: Accounts, script: string) {
  const refuse = (message: string, code: number) => {
    console.error(message);
    Deno.exit(code);
  };
  const run = (line: string) => {
    const words = line.split(/\s+/);
    const name = words.at(-1)!;
    const option = (flag: string) => words[words.indexOf(flag) + 1];
    if (words[0] === "userdel") {
      // The user's own group goes with it; a group it only was a member of stays.
      delete row.users[name];
      delete row.groups[name];
    } else if (words[0] === "groupdel") {
      if (!(name in row.groups)) {
        refuse(`groupdel: group '${name}' does not exist`, 6);
      }
      delete row.groups[name];
    } else if (words[0] === "groupadd") {
      const gid = Number(option("--gid"));
      if (Object.values(row.groups).includes(gid)) {
        refuse(`groupadd: GID '${gid}' already exists`, 4);
      }
      row.groups[name] = gid;
    } else if (words[0] === "useradd") {
      const uid = Number(option("--uid"));
      if (Object.values(row.users).includes(uid)) {
        refuse(`useradd: UID ${uid} is not unique`, 4);
      }
      if (!(option("--gid") in row.groups)) {
        refuse(`useradd: group '${option("--gid")}' does not exist`, 6);
      }
      row.users[name] = uid;
    }
  };
  for (const line of script.split("\n").map((l) => l.trim())) {
    const guarded = line.match(
      /^if (id|getent group) (\S+) >\/dev\/null 2>&1; then (.+); fi$/,
    );
    if (!guarded) run(line);
    else if (guarded[2] in (guarded[1] === "id" ? row.users : row.groups)) {
      run(guarded[3]);
    }
  }
}
if (family === "query") {
  const path = args.at(-1)!;
  const [route, query = ""] = path.slice(5).split("?");
  const [resource, item] = route.split("/");
  const rows = state[resource];
  if (
    resource !== "images" && item && Array.isArray(rows) &&
    !rows.find((r: { name: string }) => r.name === item)
  ) {
    fail(`${resource} not found`);
  }
  if (path === "/1.0") {
    console.log(
      JSON.stringify({
        environment: { server_version: state.server_version ?? "7.0.1", server_name: state.server_name ?? "fixture-node", server_pid: state.cli_server_pid ?? Number(Deno.readTextFileSync(`${folder}/daemon.pid`)) },
      }),
    );
  } else if (resource === "images") {
    const alias = decodeURIComponent(path.split("?")[0].split("/").at(-1)!);
    const bodyIndex = args.indexOf("--data");
    const body = bodyIndex < 0 ? {} : JSON.parse(args[bodyIndex + 1]);
    const method = args[args.indexOf("--request") + 1];
    if (method === "PUT") {
      for (const image of state.images.filter(owned)) image.aliases = image.aliases.filter((a: {name: string}) => a.name !== alias);
      state.images.find((image: {fingerprint: string}) => owned(image) && image.fingerprint === body.target).aliases.push({name: alias});
    } else if (method === "POST") {
      state.images.find((image: {fingerprint: string}) => owned(image) && image.fingerprint === body.target).aliases.push({name: body.name});
    } else throw new Error("Unexpected image query");
  } else if (resource === "instances") {
    const expanded = state.instances.filter((row: {project: string}) => new URLSearchParams(query).get("all-projects") === "true" || row.project === project).map((row: { project: string; config?: Record<string, string>; devices?: Record<string, Record<string, string>> }) => {
      const devices = structuredClone(state.profiles[row.project]?.devices ?? {});
      // Incus replaces a whole device of the same name; it does not inherit missing local properties.
      for (const [name, device] of Object.entries(row.devices ?? {})) devices[name] = device;
      return { ...row, expanded_config: {...(state.profiles[row.project]?.config ?? {}), ...row.config}, expanded_devices: devices };
    });
    console.log(JSON.stringify(item ? expanded.find((row: {name: string}) => row.name === item) : expanded));
  } else if (resource === "profiles") {
    const owner = new URLSearchParams(query).get("project") ?? "default";
    console.log(JSON.stringify(state.profiles[owner]));
  } else {
    console.log(JSON.stringify(
      item ? rows.find((r: { name: string }) => r.name === item) : rows,
    ));
  }
} else if (family === "storage" && action === "create") {
  const source = args.find((v) => v.startsWith("source="))?.slice(7) ??
    `${folder}/pool.img`;
  if (source.startsWith("/")) Deno.writeTextFileSync(source, "");
  state["storage-pools"].push({ name, driver: "zfs", config: { source } });
} else if (family === "storage" && action === "get") {
  console.log(state.zfs_pool_name ?? name);
} else if (family === "project" && action === "create") {
  state.projects.push({
    name,
    config: {
      "features.images": "true",
      "features.profiles": "true",
      "features.networks": "false",
    },
  });
  state.profiles[name] = { config: {}, description: "", devices: {} };
} else if (family === "project" && action === "set") {
  const row = state.projects.find((r: { name: string }) => r.name === name);
  for (const pair of args.slice(3)) {
    const [key, value] = pair.split("=");
    row.config[key] = value;
  }
} else if (family === "project" && action === "unset") {
  const row = state.projects.find((r: { name: string }) => r.name === name);
  for (const key of args.slice(3)) delete row.config[key];
} else if (family === "config" && action === "device" && ["set", "override"].includes(name)) {
  const row = instance(args[3]);
  const device = args[4];
  row.devices ??= {};
  const before = row.devices[device] ?? state.profiles[project].devices[device];
  row.devices[device] = {...before, ...Object.fromEntries(args.slice(5).map(pair))};
  if (!row.devices[device]["security.acls"] || row.devices[device]["security.acls.default.egress.action"] !== "reject") fail("NIC move opened its gate");
} else if (family === "network" && action === "acl") {
  if (name === "create") {
    state["network-acls"].push({ name: args[3], config: {} });
  } else {
    Object.assign(
      state["network-acls"].find((r: { name: string }) => r.name === args[3]),
      await input(),
    );
  }
} else if (family === "network") {
  if (action === "create") {
    state.networks.push({ name, type: "bridge", managed: true, config: {} });
  }
  const row = state.networks.find((r: { name: string }) => r.name === name);
  for (
    const argument of args.slice(3).filter((s) =>
      s.includes("=") && !s.startsWith("--")
    )
  ) {
    const [key, value] = pair(argument);
    row.config[key] = value;
  }
} else if (family === "profile" && action === "edit") {
  state.profiles[project] = await input();
} else if (family === "image" && action === "list") {
  console.log(JSON.stringify(args.includes("--all-projects") ? state.images : state.images.filter(owned)));
} else if (family === "image" && action === "copy") {
  state.images.push({
    fingerprint: `fp-${args[args.indexOf("--alias") + 1]}`,
    aliases: [{ name: args[args.indexOf("--alias") + 1] }],
    source: args[2],
    vm: args.includes("--vm"),
    project: args.includes("--target-project") ? args[args.indexOf("--target-project") + 1] : project,
    update_source: {server: "https://images.example"},
    auto_update: false,
  });
} else if (family === "image" && action === "alias") {
  const [, , verb, alias, fingerprint] = args;
  if (verb === "create" && state.images.filter(owned).some((image: {aliases: {name: string}[]}) => image.aliases.some((a) => a.name === alias))) fail("Image alias already exists");
  for (const image of state.images.filter(owned)) {
    image.aliases = image.aliases.filter((a: { name: string }) =>
      a.name !== alias
    );
  }
  if (verb === "create") {
    state.images.find((i: { fingerprint: string }) =>
      owned(i) && i.fingerprint === fingerprint
    ).aliases.push({ name: alias });
  }
} else if (family === "image" && ["set-property", "unset-property"].includes(action)) {
  const image = state.images.find((row: {fingerprint: string}) => owned(row) && row.fingerprint === name);
  if (!image) fail("Image not found");
  image.properties ??= {};
  for (const arg of args.slice(3)) {
    if (action === "set-property") {
      const [key, value] = pair(arg);
      image.properties[key] = value;
    } else delete image.properties[arg];
  }
} else if (family === "image" && action === "delete") {
  if (state.delete_failure === name) fail("Injected image deletion failure");
  state.images = state.images.filter((i: { fingerprint: string }) =>
    !owned(i) || i.fingerprint !== name
  );
  if (!state.images.some((i: {fingerprint: string}) => i.fingerprint === name)) {
    try { Deno.removeSync(archive(name)); } catch { /* no archive */ }
  }
} else if (family === "publish") {
  const alias = args[args.indexOf("--alias") + 1];
  const fingerprint = state.publish_fingerprint ?? await digest(`${alias}-${state.publications ?? 0}`);
  if (state.images.some((i: {fingerprint: string}) => owned(i) && i.fingerprint === fingerprint)) fail("The image already exists");
  state.publications = (state.publications ?? 0) + 1;
  const source = state.images.find((i: {fingerprint: string}) => owned(i) && i.fingerprint === instance(action)?.config?.["volatile.base_image"]);
  const properties = {...source?.properties, ...Object.fromEntries(args.slice(2).filter((arg) => arg.includes("=")).map(pair))};
  // An image is the container's files as they are, its machine-id too.
  state.images.push({
    fingerprint,
    project,
    aliases: [],
    properties,
    auto_update: false,
    update_source: null,
    machine_id: instance(action)?.machine_id,
  });
  if (!state.publish_missing_archive) Deno.writeTextFileSync(archive(fingerprint), "published archive");
  // Incus commits image creation before the separate alias request.
  if (state.publish_builder_owner) instance(action).config["user.orbit.stock-build"] = state.publish_builder_owner;
  Deno.writeTextFileSync(file, JSON.stringify(state));
  if (state.publish_alias_failure) fail("Injected publication alias assignment failure");
  state.images.at(-1).aliases.push({name: alias});
} else if (family === "exec") {
  if (!instance(action)) fail("Instance not found");
  const command = args.slice(args.indexOf("--") + 1);
  if (command[0] === "systemctl" && command[1] === "try-restart") {
    const kind = command[2] === "t3.service" ? "agent" : "runtime";
    state.folder_restarts ??= [];
    state.folder_restarts.push({project, name: action, unit: command[2], marked: Deno.statSync(`${folder}/opt/orbit-${kind}/.restart`).isFile});
  }
  if (command[0] === "sh" && command[1] === "-c" && instance(action).groups) {
    accounts(instance(action), command[2]);
  }
  if (command.join(" ") === "truncate --size 0 /etc/machine-id") {
    instance(action).machine_id = "";
  }
  // The audit of the image's sudo paths reads what the fixture says the build left; a clean image prints nothing.
  if (command[0] === "sh" && command[2]?.includes("sudoers.d")) {
    if (state.sudo_paths) console.log(state.sudo_paths);
  }
} else if (family === "file" && action === "push") {
  state.files ??= {};
  state.files[args.at(-1)!] = {
    content: await new Response(Deno.stdin.readable).text(),
    mode: args[args.indexOf("--mode") + 1],
    uid: args[args.indexOf("--uid") + 1],
    gid: args[args.indexOf("--gid") + 1],
  };
} else if (family === "list") {
  console.log(JSON.stringify(
    state.instances.filter((r: { project: string; status: string }) =>
      (args.includes("--all-projects") || r.project === project) && r.status === "Running"
    ),
  ));
} else if (family === "init") {
  let source = state.images.find((i: {fingerprint: string; aliases: {name: string}[]}) => owned(i) && i.aliases.some((a) => a.name === action));
  if (action === "images:ubuntu/26.04/cloud") {
    const fingerprint = state.upstream_fingerprint ?? "a".repeat(64);
    source = state.images.find((i: {fingerprint: string}) => owned(i) && i.fingerprint === fingerprint);
    if (!source) {
      source = {fingerprint, project, aliases: [], cached: true, update_source: {server: "https://images.example"}, auto_update: false};
      state.images.push(source);
      Deno.writeTextFileSync(archive(fingerprint), "upstream archive");
    }
    try {
      if (!Deno.statSync(archive(fingerprint)).isFile) fail("Missing upstream archive");
    } catch {
      fail("Missing upstream archive");
    }
  }
  const config = Object.fromEntries(args.flatMap((arg, index) => arg === "--config" ? [pair(args[index + 1])] : []));
  state.instances.push({ name: args[2], project, status: "Stopped", config: {...config, "volatile.base_image": source?.fingerprint} });
} else if (family === "copy") {
  if (!instance(action)) fail("Instance not found");
  state.instances.push({ name, project, status: "Stopped" });
} else if (family === "start" || family === "stop") {
  const named = args.at(-1)!;
  if (!instance(named)) fail("Instance not found");
  // As Incus checks a host-path disk of the profile at start: a restricted project's path list admits it, and refuses `shift`.
  const config = state.projects.find((r: { name: string }) =>
    r.name === project
  )?.config ?? {};
  const paths = config["restricted.devices.disk.paths"];
  for (
    const device of Object.values(state.profiles[project]?.devices ?? {}) as {
      type: string;
      source?: string;
      shift?: string;
    }[]
  ) {
    if (
      family === "start" && device.type === "disk" &&
      device.source?.startsWith("/") && config.restricted === "true" && paths
    ) {
      if (
        !paths.split(",").some((p: string) =>
          `${device.source}/`.startsWith(`${p}/`)
        )
      ) {
        fail(`Disk source path "${device.source}" not allowed by project`);
      }
      if (device.shift === "true") {
        fail(
          'The "shift" property cannot be used with a restricted source path',
        );
      }
    }
  }
  instance(named).status = family === "start" ? "Running" : "Stopped";
  if (family === "start" && instance(named).machine_id === undefined) {
    // The first boot of the stock image: systemd writes the machine-id, and cloud-init makes its default user. The user's group
    // `lxd` comes first and takes gid 1000, so `ubuntu` gets uid 1000 with gid 1001.
    instance(named).machine_id = crypto.randomUUID().replaceAll("-", "");
    instance(named).users = { ubuntu: 1000 };
    instance(named).groups = { lxd: 1000, ubuntu: 1001 };
  }
} else if (family === "delete") {
  const named = args.at(-1)!;
  if (!instance(named)) {
    fail("Failed checking instance exists: Instance not found");
  }
  state.instances = state.instances.filter((r: { name: string }) =>
    r.name !== named
  );
} else throw new Error(`Unexpected Incus call: ${Deno.args.join(" ")}`);
Deno.writeTextFileSync(file, JSON.stringify(state));
