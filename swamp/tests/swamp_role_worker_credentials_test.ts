import { assert, assertEquals } from "jsr:@std/assert@1.0.14";
import { parse } from "jsr:@std/yaml@1.0.12";

type Step = {
    name: string;
    dependsOn?: Array<{ step?: string }>;
    forEach?: { item: string };
    task: { inputs: { argv: string[] } };
};

const workflow = parse(Deno.readTextFileSync(
    new URL("../workflows/workflow-operation-node-role-add.yaml", import.meta.url),
)) as { jobs: Array<{ name: string; steps: Step[] }> };
const steps = workflow.jobs.find((job) => job.name === "swamp_runtime_job")!.steps;
const named = (name: string) => steps.find((step) => step.name === name)!;
const after = (step: Step) => (step.dependsOn ?? []).map((dependency) => dependency.step);

// The release converges the swamp role of Node 209 with this workflow (the fleet rollout admits `node:role:add --converge`). The worker unit reads its
// token files as the swamp account, so the folders and files must be readable before the unit is written.
Deno.test("the swamp role opens the worker credentials before it writes the worker unit", () => {
    const own = named("Own the Swamp worker credentials");
    const close = named("Close the Swamp worker credentials");
    const unit = named("Apply Swamp worker unit");

    assertEquals(own.forEach?.item, "worker");
    assertEquals(close.forEach?.item, "worker");
    assertEquals(after(close), ["Own the Swamp worker credentials"]);
    assert(after(unit).includes("Close the Swamp worker credentials"));
    assert(steps.indexOf(close) < steps.indexOf(unit));
});

// The modes are what the argv does on a folder: run its chmod on a scratch tree like the one the tokens are placed in.
Deno.test("the credentials end as folders 0750 and token files 0640", () => {
    const argv = named("Close the Swamp worker credentials").task.inputs.argv;
    const root = Deno.makeTempDirSync();
    Deno.mkdirSync(`${root}/a`);
    Deno.writeTextFileSync(`${root}/a/enrollment.token`, "x");
    Deno.writeTextFileSync(`${root}/a/server.token`, "x");
    Deno.chmodSync(`${root}/a`, 0o700);
    Deno.chmodSync(`${root}/a/enrollment.token`, 0o600);
    Deno.chmodSync(`${root}/a/server.token`, 0o600);

    // Without sudo and with the scratch folder in place of the worker folder.
    const chmod = new Deno.Command("/bin/chmod", { args: [...argv.slice(4, 6), `${root}/a`] }).outputSync();
    assert(chmod.success);

    assertEquals(Deno.statSync(`${root}/a`).mode! & 0o777, 0o750);
    assertEquals(Deno.statSync(`${root}/a/enrollment.token`).mode! & 0o777, 0o640);
    assertEquals(Deno.statSync(`${root}/a/server.token`).mode! & 0o777, 0o640);
    assert(argv[3].endsWith("/chmod") && argv[0].endsWith("/sudo"));
});
