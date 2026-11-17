/**
 * The ZFS datasets behind the Incus fixture: `state.datasets` lists every dataset and snapshot by name. `list` prints those that exist
 * and fails for the others, as `zfs list` does, with their children under `-r`; `create` and `snapshot` add one, under an existing
 * parent. Each call goes to `zfs-calls`. No live host commands.
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
  const recursive = args.includes("-r");
  // `state.appears`: a dataset, with its children, that another writer makes right after the first read of its name.
  const late = names.filter((each) => state.appears?.[each] && !state.revealed?.includes(each));
  const known = names.flatMap((each) => state.datasets.filter((dataset: string) =>
    dataset === each || (recursive && (dataset.startsWith(`${each}/`) || dataset.startsWith(`${each}@`)))));
  for (const each of known.filter((dataset) => !late.includes(dataset))) console.log(each);
  for (const each of late) {
    state.datasets.push(each, ...state.appears[each]);
    state.revealed = [...(state.revealed ?? []), each];
  }
  const missing = names.filter((each) => !state.datasets.includes(each) || late.includes(each));
  if (late.length > 0) Deno.writeTextFileSync(file, JSON.stringify(state));
  for (const each of missing) console.error(`cannot open '${each}': dataset does not exist`);
  Deno.exit(missing.length === 0 ? 0 : 1);
} else if (args[0] === "create" || args[0] === "snapshot") {
  if (state.datasets.includes(name)) {
    console.error(`cannot create '${name}': dataset already exists`);
    Deno.exit(1);
  }
  const parent = args[0] === "snapshot" ? name.split("@")[0] : name.slice(0, name.lastIndexOf("/"));
  if (!state.datasets.includes(parent)) {
    console.error(`cannot create '${name}': parent does not exist`);
    Deno.exit(1);
  }
  state.datasets.push(name);
} else {
  console.error(`Unexpected zfs ${args.join(" ")}`);
  Deno.exit(2);
}
Deno.writeTextFileSync(file, JSON.stringify(state));
