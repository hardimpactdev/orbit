/**
 * A `ufw` stand-in for the container destroy test. The rules live in `$HOST_FIXTURE/ufw.json` as `{spec, comment}` in order, and
 * numbers come from their position, as in ufw. When `$HOST_FIXTURE/ufw-concurrent` names a comment, the first delete call first
 * deletes that rule, as another removal on the Node does at the same time, so every later number shifts.
 */
const folder = Deno.env.get("HOST_FIXTURE")!;
type Rule = { spec: string; comment: string };
const file = `${folder}/ufw.json`;
let rules = JSON.parse(Deno.readTextFileSync(file)) as Rule[];
const save = () => Deno.writeTextFileSync(file, JSON.stringify(rules));
const args = Deno.args.filter((arg) => arg !== "--force");
const concurrent = () => {
    const marker = `${folder}/ufw-concurrent`;
    try {
        const comment = Deno.readTextFileSync(marker).trim();
        Deno.removeSync(marker);
        rules = rules.filter((rule) => rule.comment !== comment);
    } catch (error) {
        if (!(error instanceof Deno.errors.NotFound)) throw error;
    }
};
if (args.join(" ") === "status numbered") {
    rules.forEach((rule, index) => console.log(`[${String(index + 1).padStart(2)}] ${rule.spec} # ${rule.comment}`));
} else if (args.join(" ") === "show added") {
    console.log("Added user rules (see 'ufw status' for running firewall):");
    for (const rule of rules) console.log(`ufw ${rule.spec} comment '${rule.comment}'`);
} else if (args[0] === "delete" && /^\d+$/.test(args[1] ?? "") && args.length === 2) {
    concurrent();
    rules.splice(Number(args[1]) - 1, 1);
    save();
} else if (args.includes("delete")) {
    concurrent();
    const at = args.indexOf("delete");
    const spec = [...args.slice(0, at), ...args.slice(at + 1)].join(" ");
    rules = rules.filter((rule) => rule.spec !== spec);
    save();
} else {
    console.error(`ufw fixture: unsupported ${Deno.args.join(" ")}`);
    Deno.exit(2);
}
