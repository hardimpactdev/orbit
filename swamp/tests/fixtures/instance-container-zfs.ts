/**
 * The ZFS datasets behind the Incus fixture: `state.datasets` lists every dataset and snapshot by name. `list` prints those that exist
 * and fails for the others, as `zfs list` does; `create` and `snapshot` add one. Each call goes to `zfs-calls`. No live host commands.
 */
const folder = Deno.env.get("HOST_FIXTURE")!;
const file = `${folder}/state.json`;
const state = JSON.parse(Deno.readTextFileSync(file));
state.datasets ??= [];
const args = [...Deno.args];
Deno.writeTextFileSync(`${folder}/zfs-calls`, JSON.stringify(args) + "\n", { append: true });
const name = args.at(-1)!;
if (args[0] === "list") {
  const names = args.slice(args.indexOf("all") + 1);
  for (const known of names.filter((each) => state.datasets.includes(each))) console.log(known);
  const missing = names.filter((each) => !state.datasets.includes(each));
  for (const each of missing) console.error(`cannot open '${each}': dataset does not exist`);
  Deno.exit(missing.length === 0 ? 0 : 1);
} else if (args[0] === "create" || args[0] === "snapshot") {
  if (state.datasets.includes(name)) {
    console.error(`cannot create '${name}': dataset already exists`);
    Deno.exit(1);
  }
  if (args[0] === "snapshot" && !state.datasets.includes(name.split("@")[0])) {
    console.error(`cannot open '${name.split("@")[0]}': dataset does not exist`);
    Deno.exit(1);
  }
  state.datasets.push(name);
} else {
  console.error(`Unexpected zfs ${args.join(" ")}`);
  Deno.exit(2);
}
Deno.writeTextFileSync(file, JSON.stringify(state));
