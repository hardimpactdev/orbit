/** Stateful Incus boundary for the real host-preparation Ansible tasks. No live host commands. */
const folder = Deno.env.get("HOST_FIXTURE")!;
const file = `${folder}/state.json`;
const state = JSON.parse(Deno.readTextFileSync(file));
const args = [...Deno.args];
Deno.writeTextFileSync(`${folder}/calls`, JSON.stringify(args) + "\n", {
  append: true,
});
const projectIndex = args.indexOf("--project");
if (projectIndex >= 0) args.splice(projectIndex, 2);
const [family, action, name] = args;
const input = async () =>
  JSON.parse(await new Response(Deno.stdin.readable).text());
if (family === "query") {
  const path = args.at(-1)!;
  const [resource, item] = path.slice(5).split("?")[0].split("/");
  const rows = state[resource];
  if (resource === "profiles") console.log(JSON.stringify(state.profile));
  else {console.log(JSON.stringify(
      item ? rows.find((r: { name: string }) => r.name === item) : rows,
    ));}
} else if (family === "storage" && action === "create") {
  const source = args.find((v) => v.startsWith("source="))?.slice(7) ??
    `${folder}/pool.img`;
  if (source.startsWith("/")) Deno.writeTextFileSync(source, "");
  state["storage-pools"].push({ name, driver: "zfs", config: { source } });
} else if (family === "project" && action === "create") {
  state.projects.push({
    name,
    config: {
      "features.images": "true",
      "features.profiles": "true",
      "features.networks": "false",
    },
  });
  state.profile = { config: {}, description: "", devices: {} };
} else if (family === "project" && action === "set") {
  const [key, value] = args[3].split("=");
  state.projects.find((r: { name: string }) => r.name === name).config[key] =
    value;
} else if (family === "network" && action === "acl") {
  if (name === "create") {
    state["network-acls"].push({ name: args[3], config: {} });
  } else {Object.assign(
      state["network-acls"].find((r: { name: string }) => r.name === args[3]),
      await input(),
    );}
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
  state.profile = await input();
} else if (family === "image" && action === "list") {
  console.log(JSON.stringify(state.images));
} else if (family === "image" && action === "copy") {
  state.images.push({ aliases: [{ name: args[args.indexOf("--alias") + 1] }] });
} else throw new Error(`Unexpected Incus call: ${Deno.args.join(" ")}`);
Deno.writeTextFileSync(file, JSON.stringify(state));
