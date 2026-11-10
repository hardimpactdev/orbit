/**
 * The Incus boundary of the move of a checkout Instance (instance-migrate.yml). It adds the two calls that only a failed move makes,
 * the deletion of the move's container and of its ACL, and hands every other call to the boundary of the container phases.
 */
const folder = Deno.env.get("HOST_FIXTURE")!;
const file = `${folder}/state.json`;
const args = [...Deno.args];
const without = (flag: string) => {
  const index = args.indexOf(flag);
  if (index >= 0) args.splice(index, 2);
};
if (args[0] === "network" && args[1] === "acl" && args[2] === "delete") {
  const state = JSON.parse(Deno.readTextFileSync(file));
  state["network-acls"] = (state["network-acls"] ?? []).filter((acl: { name: string }) => acl.name !== args[3]);
  Deno.writeTextFileSync(file, JSON.stringify(state));
  Deno.exit(0);
}
if (args.includes("delete") && args[0] === "--project") {
  without("--project");
  const state = JSON.parse(Deno.readTextFileSync(file));
  const name = args.at(-1)!;
  if (!state.containers?.[name]) {
    console.error("Error: Instance not found");
    Deno.exit(1);
  }
  if (!args.includes("--force") && state.containers[name].status === "Running") {
    console.error("Error: The instance is currently running");
    Deno.exit(1);
  }
  delete state.containers[name];
  Deno.writeTextFileSync(file, JSON.stringify(state));
  Deno.removeSync(`${folder}/containers/${name}`, { recursive: true });
  Deno.exit(0);
}
const done = await new Deno.Command(Deno.execPath(), {
  args: ["run", "-A", new URL("instance-container-incus.ts", import.meta.url).pathname, ...Deno.args],
  stdin: "inherit",
  stdout: "inherit",
  stderr: "inherit",
}).output();
Deno.exit(done.code);
