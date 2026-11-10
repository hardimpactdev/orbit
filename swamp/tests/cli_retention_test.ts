import { assert, assertEquals, assertMatch } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { cliPlan } from "../extensions/behaviors/operations/fleet/cli_install.ts";

type Task = Record<string, unknown>;
const release = { version: "0.40.0", url: "https://example.test/orbit", sha256: "a".repeat(64) };
const decoder = new TextDecoder();
const root = "/usr/local/bin";
const lock = "/run/lock/orbit-self-update.lock";
const workflow = parse(await Deno.readTextFile(new URL("../workflows/workflow-node-cli-install.yaml", import.meta.url))) as {
    jobs: Array<{ steps: Array<{ name: string; task: { inputs?: Record<string, unknown> }; dependsOn?: unknown[]; guard?: string; writes?: boolean }> }>;
};
const steps = workflow.jobs[0].steps;

async function fixture(run: (folder: string) => Promise<void>) {
    const folder = await Deno.makeTempDir({ prefix: "orbit-cli-retention-" });
    try {
        // The previous selection is deliberately older than other leftovers.
        // Keeping the two highest release numbers would destroy this rollback.
        for (const name of ["orbit-0.1.0", "orbit-0.10.0", "orbit-0.20.0", "orbit-0.30.0", "orbit-0.40.0", "orbit-agent", "orbit-0.01.0", "orbit-not-a-release"]) {
            await Deno.writeTextFile(`${folder}/${name}`, name);
        }
        await Deno.mkdir(`${folder}/orbit-0.2.0`);
        await Deno.symlink("orbit-agent", `${folder}/orbit-0.3.0`);
        await Deno.symlink("orbit-0.10.0", `${folder}/orbit`);
        await run(folder);
    } finally { await Deno.remove(folder, { recursive: true }); }
}
async function remaining(folder: string) {
    const names: string[] = [];
    for await (const entry of Deno.readDir(folder)) if (entry.isFile && /^orbit-0\.[1-9][0-9]*\.0$/.test(entry.name)) names.push(entry.name);
    return names.sort();
}
async function preserved(folder: string) {
    assertEquals(await remaining(folder), ["orbit-0.10.0", "orbit-0.40.0"]);
    assertEquals(await Deno.readLink(`${folder}/orbit`), "orbit-0.40.0");
    assertEquals(await Deno.readTextFile(`${folder}/orbit-agent`), "orbit-agent");
    assertEquals(await Deno.readTextFile(`${folder}/orbit-0.01.0`), "orbit-0.01.0");
    assertEquals(await Deno.readTextFile(`${folder}/orbit-not-a-release`), "orbit-not-a-release");
    assert((await Deno.stat(`${folder}/orbit-0.2.0`)).isDirectory);
    assertEquals(await Deno.readLink(`${folder}/orbit-0.3.0`), "orbit-agent");
}

Deno.test("native CLI installation keeps the actual previous selection and removes all older regular releases", async () => {
    const prune = steps.find((step) => step.name === "cli_prune")!;
    assert(prune, "the production installer must run cleanup");
    assertEquals(prune.writes, true);
    assertEquals(prune.dependsOn, [
        { step: "cli_profile", condition: { type: "succeeded" } },
        { step: "cli_link", condition: { type: "succeeded" } },
    ]);
    assertEquals(prune.guard, "${{ !steps.cli_plan.outputs.prune }}");
    assertEquals(prune.task.inputs?.argv, '${{ ["timeout", "130"] + steps.cli_plan.outputs.prune_argv }}');
    await fixture(async (folder) => {
        const plan = cliPlan({ release, observed: "Orbit 0.10.0", self_update: true, previous_path: `${root}/orbit-0.10.0\n` });
        assertEquals(plan.install, true);
        assertEquals(plan.prune, true);
        const argv = plan.prune_argv.map((arg) => arg.replaceAll(root, folder).replace(lock, `${folder}/update.lock`));
        await Deno.remove(`${folder}/orbit`);
        await Deno.symlink("orbit-0.40.0", `${folder}/orbit`);
        const applied = await new Deno.Command(argv[0], { args: argv.slice(1), stdout: "piped", stderr: "piped" }).output();
        assertEquals(applied.code, 0, decoder.decode(applied.stderr));
        assertMatch(decoder.decode(applied.stdout), /orbit-0\.30\.0/);
        await preserved(folder);
        const repeated = await new Deno.Command(argv[0], { args: argv.slice(1), stdout: "piped", stderr: "piped" }).output();
        assertEquals(repeated.code, 0, decoder.decode(repeated.stderr));
        assertEquals(decoder.decode(repeated.stdout), "");
        await preserved(folder);
    });
});

Deno.test("native cleanup requires a changed selection and a known previous path", () => {
    // A tolerated failure of the self-update help probe can require reinstalling
    // the same release. It must not erase the rollback kept by the last switch.
    const repeated = cliPlan({ release, observed: "Orbit 0.40.0", self_update: false, previous_path: `${root}/orbit-0.40.0\n` });
    assertEquals(repeated.install, true);
    assertEquals(repeated.prune, false);
    const unknown = cliPlan({ release, observed: "Orbit 0.10.0", self_update: true, previous_path: null });
    assertEquals(unknown.install, true);
    assertEquals(unknown.prune, false);
    // readlink -m succeeds for an absent first selection, without hiding a
    // transport failure as a successful observation of an empty directory.
    const first = cliPlan({ release, observed: null, self_update: false, previous_path: `${root}/orbit\n` });
    assertEquals(first.prune, true);
    const unchanged = cliPlan({ release, observed: "Orbit 0.40.0", self_update: true, previous_path: `${root}/orbit-0.40.0\n` });
    assertEquals(unchanged.install, false);
    assertEquals(unchanged.prune, false);
    assertEquals(steps.find((step) => step.name === "cli_previous")!.task.inputs?.argv, ["readlink", "-m", `${root}/orbit`]);
    assertMatch(String(steps.find((step) => step.name === "cli_plan")!.task.inputs?.previous_path), /steps\.cli_previous\.status == "succeeded"/);
});

Deno.test("Ansible CLI installation prunes after publication and leaves the rollback on a repeat", async () => {
    const base = parse(await Deno.readTextFile(new URL("../ansible/tasks/node-converge-base.yml", import.meta.url))) as Task[];
    const cli = base.find((task) => task.name === "Reconcile the recorded CLI release")!.block as Task[];
    const remember = cli.find((task) => task.name === "Remember the CLI selected before installation")!;
    const prune = cli.find((task) => task.name === "Keep the selected and previous CLI binaries")!;
    assert(remember && prune, "the production Ansible installer must run cleanup");
    assert(cli.indexOf(prune) > cli.findIndex((task) => task.name === "Reconcile the recorded CLI profile"));
    await fixture(async (folder) => {
        const tasks = JSON.parse(JSON.stringify([remember, {
            name: "Fixture publishes the checked CLI candidate",
            "ansible.builtin.file": { src: "orbit-0.40.0", dest: `${root}/orbit`, state: "link", force: true },
        }, prune]).replaceAll(root, folder).replaceAll(lock, `${folder}/update.lock`));
        const play = `${folder}/play.json`;
        await Deno.writeTextFile(play, JSON.stringify([{
            hosts: "localhost", connection: "local", gather_facts: false,
            vars: { intent: { cli: { release } } }, tasks,
        }]));
        const run = () => new Deno.Command("ansible-playbook", {
            args: ["-i", "localhost,", play], stdout: "piped", stderr: "piped",
            env: { ANSIBLE_NOCOLOR: "1", ANSIBLE_STDOUT_CALLBACK: "default", ANSIBLE_COLLECTIONS_PATH: `${folder}/empty-collections` },
        }).output();
        const applied = await run();
        assertEquals(applied.code, 0, decoder.decode(applied.stdout) + decoder.decode(applied.stderr));
        await preserved(folder);
        const repeated = await run();
        assertEquals(repeated.code, 0, decoder.decode(repeated.stdout) + decoder.decode(repeated.stderr));
        assertMatch(decoder.decode(repeated.stdout), /changed=0\s/);
        await preserved(folder);
    });
});
