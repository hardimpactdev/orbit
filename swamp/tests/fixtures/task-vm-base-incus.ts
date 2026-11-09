/** Stateful Incus boundary for base creation; never reaches a host. */
const file = Deno.env.get("BASE_FIXTURE")!;
const state = JSON.parse(Deno.readTextFileSync(file));
const args = [...Deno.args];
state.calls.push([...args]);
if (args[0] === "--project") args.splice(0, 2);
const [family, action] = args;
let missing = false;
if (family === "delete") {
  missing = !state.vm;
  state.vm = null;
} else if (family === "storage" && action === "volume") {
  if (args[2] === "delete") {
    missing = !state.seed;
    state.seed = false;
  } else if (args[2] === "create") {
    state.seed = true;
  } else throw new Error(`Unexpected volume call: ${args}`);
} else if (family === "list") {
  console.log(JSON.stringify(state.vm ? [state.vm] : []));
} else if (family === "init") {
  if (state.vm || !state.seed) throw new Error("Invalid base creation order");
  state.vm = { name: args.at(-1), generation: "stock", status: "Stopped" };
} else throw new Error(`Unexpected Incus call: ${args}`);
Deno.writeTextFileSync(file, JSON.stringify(state));
if (missing) {
  console.error("not found");
  Deno.exit(1);
}
