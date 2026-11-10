/** Stateful Incus boundary for the real host-preparation Ansible tasks. No live host commands. */
const folder = Deno.env.get("HOST_FIXTURE")!;
const file = `${folder}/state.json`;
const state = JSON.parse(Deno.readTextFileSync(file));
state.profiles ??= {};
state.instances ??= [];
const args = [...Deno.args];
Deno.writeTextFileSync(`${folder}/calls`, JSON.stringify(args) + "\n", {
  append: true,
});
const projectIndex = args.indexOf("--project");
const project = projectIndex >= 0 ? args[projectIndex + 1] : "default";
if (projectIndex >= 0) args.splice(projectIndex, 2);
const [family, action, name] = args;
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
    item && Array.isArray(rows) &&
    !rows.find((r: { name: string }) => r.name === item)
  ) {
    fail(`${resource} not found`);
  }
  if (path === "/1.0") {
    console.log(
      JSON.stringify({
        environment: { server_version: state.server_version ?? "7.0.1" },
      }),
    );
  } else if (resource === "instances" && !item) {
    console.log(JSON.stringify(state.instances.map((row: { project: string; devices?: Record<string, Record<string, string>> }) => {
      const devices = structuredClone(state.profiles[row.project]?.devices ?? {});
      // Incus replaces a whole device of the same name; it does not inherit missing local properties.
      for (const [name, device] of Object.entries(row.devices ?? {})) devices[name] = device;
      return { ...row, expanded_devices: devices };
    })));
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
  console.log(JSON.stringify(state.images));
} else if (family === "image" && action === "copy") {
  state.images.push({
    fingerprint: `fp-${args[args.indexOf("--alias") + 1]}`,
    aliases: [{ name: args[args.indexOf("--alias") + 1] }],
    source: args[2],
    vm: args.includes("--vm"),
  });
} else if (family === "image" && action === "alias") {
  const [, , verb, alias, fingerprint] = args;
  for (const image of state.images) {
    image.aliases = image.aliases.filter((a: { name: string }) =>
      a.name !== alias
    );
  }
  if (verb === "create") {
    state.images.find((i: { fingerprint: string }) =>
      i.fingerprint === fingerprint
    ).aliases.push({ name: alias });
  }
} else if (family === "image" && action === "delete") {
  state.images = state.images.filter((i: { fingerprint: string }) =>
    i.fingerprint !== name
  );
} else if (family === "publish") {
  const alias = args[args.indexOf("--alias") + 1];
  // An image is the container's files as they are, its machine-id too.
  state.images.push({
    fingerprint: `fp-${alias}`,
    aliases: [{ name: alias }],
    machine_id: instance(action)?.machine_id,
  });
} else if (family === "exec") {
  if (!instance(action)) fail("Instance not found");
  const command = args.slice(args.indexOf("--") + 1);
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
      r.project === project && r.status === "Running"
    ),
  ));
} else if (family === "init") {
  state.instances.push({ name: args[2], project, status: "Stopped" });
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
