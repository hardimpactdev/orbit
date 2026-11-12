/** Real Git and the shipped removal phase; the optional pinned-native proof preserves the transfer DAG. */
import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { parse, stringify } from "jsr:@std/yaml@1.0.12";
const root = new URL("../../../", import.meta.url);
const workflow = parse(Deno.readTextFileSync(new URL("swamp/workflows/workflow-operation-instance-transfer.yaml", root))) as any;
const steps = workflow.jobs[0].steps;
const retirement = steps.find((step: any) => step.name === "Retire the source");
const preparation = steps.find((step: any) => step.name === "Prepare source retirement");
const archive = steps.find((step: any) => step.name === "Archive the checkout");
const text = (bytes: Uint8Array) => new TextDecoder().decode(bytes);
const quote = (value: string) => "'" + value.replaceAll("'", "'\\''") + "'";
const run = (program: string, args: string[], extra: Record<string, string> = {}) => new Deno.Command(program, {
    args, env: {GIT_CONFIG_NOSYSTEM: "1", GIT_CONFIG_GLOBAL: "/dev/null", ...extra},
}).outputSync();
const git = (path: string, ...args: string[]) => {
    const done = run("git", ["-C", path, ...args]);
    assert(done.success, text(done.stderr));
    return text(done.stdout);
};
function fixture() {
    const folder = Deno.makeTempDirSync({prefix: "orbit-transfer-retire-"});
    const source = folder + "/owner";
    const linked = folder + "/late";
    const moved = folder + "/moved";
    const inventory = [{id:23,path:moved}];
    git(folder, "init", "-q", source);
    Deno.writeTextFileSync(source + "/work", "preserve owner work");
    git(source, "add", ".");
    git(source, "-c", "user.name=Fixture", "-c", "user.email=f@example.test", "commit", "-qm", "fixture");
    const assets = folder + "/assets";
    const state = folder + "/run";
    Deno.mkdirSync(assets + "/instance", {recursive: true});
    Deno.mkdirSync(state);
    Deno.copyFileSync(new URL("swamp/ansible/instance/destroy-git.py", root), assets + "/instance/destroy-git.py");
    const phase = (parse(Deno.readTextFileSync(new URL("swamp/ansible/instance-checkout-destroy.yml", root))) as any[])[1];
    phase.hosts = "localhost";
    phase.connection = "local";
    phase.vars = {...phase.vars, run: state, ansible_python_interpreter: "/usr/bin/python3"};
    Deno.writeTextFileSync(state + "/intent.json", JSON.stringify({source_paths:[source],checkout_instances:inventory}));
    Deno.writeTextFileSync(assets + "/instance-checkout-destroy.yml", stringify([phase]));
    const env = {ORBIT_CHECKOUT_INVENTORY: "1", ORBIT_RUN: state, ORBIT_ANSIBLE: assets, ANSIBLE_DISPLAY_FAILED_STDERR: "1", ANSIBLE_STDOUT_CALLBACK: "default", ANSIBLE_COLLECTIONS_PATH: folder + "/collections"};
    const command = retirement.task.inputs.run.replace("/opt/orbit/ansible/venv/bin/ansible-playbook", "ansible-playbook");
    const archiveArgs = [...archive.task.inputs.argv];
    archiveArgs.splice(-3, 3, source, folder + "/archive.tar", ".env");
    return {folder, source, linked, moved, inventory, assets, state, env, command, archiveArgs};
}

export {workflow, retirement, preparation, archive, text, quote, run, git, fixture};
