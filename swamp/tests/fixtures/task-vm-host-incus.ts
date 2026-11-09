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
if (family === "query") {
  const path = args.at(-1)!;
  const [route, query = ""] = path.slice(5).split("?");
  const [resource, item] = route.split("/");
  const rows = state[resource];
  if (path === "/1.0") {
    console.log(
      JSON.stringify({
        environment: { server_version: state.server_version ?? "7.0.1" },
      }),
    );
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
  const [key, value] = args[3].split("=");
  state.projects.find((r: { name: string }) => r.name === name).config[key] =
    value;
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
    const [key, value] = argument.split("=");
    row.config[key] = value;
  }
} else if (family === "profile" && action === "edit") {
  state.profiles[project] = await input();
} else if (family === "image" && action === "list") {
  console.log(JSON.stringify(state.images));
} else if (family === "image" && action === "copy") {
  state.images.push({
    aliases: [{ name: args[args.indexOf("--alias") + 1] }],
    source: args[2],
    vm: args.includes("--vm"),
  });
} else if (family === "init") {
  state.instances.push({ name: args[2], project, status: "Stopped" });
} else if (family === "copy") {
  if (!instance(action)) fail("Instance not found");
  state.instances.push({ name, project, status: "Stopped" });
} else if (family === "start" || family === "stop") {
  const named = args.at(-1)!;
  if (!instance(named)) fail("Instance not found");
  instance(named).status = family === "start" ? "Running" : "Stopped";
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
