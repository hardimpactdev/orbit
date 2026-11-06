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

Deno.test("the credentials go to root and the swamp account, folders 0750 and files 0640", () => {
    const own = named("Own the Swamp worker credentials").task.inputs.argv;
    const close = named("Close the Swamp worker credentials").task.inputs.argv;

    assertEquals(own.slice(0, 6), ["/usr/bin/sudo", "-n", "--", "/usr/bin/chown", "-R", own[5]]);
    assert(own[5].startsWith('${{ "root:" + '));
    assert(own[6].includes("/etc/orbit/swamp-role-worker/") && own[6].includes("self.worker"));
    // u=rwX,g=rX,o= makes a folder 0750 and a token file (not executable) 0640.
    assertEquals(close.slice(0, 6), ["/usr/bin/sudo", "-n", "--", "/usr/bin/chmod", "-R", "u=rwX,g=rX,o="]);
    assert(close[6].includes("/etc/orbit/swamp-role-worker/") && close[6].includes("self.worker"));
});

Deno.test("no step starts a worker before its unit and credentials", () => {
    const names = workflow.jobs.map((job) => job.name);
    assert(names.indexOf("swamp_runtime_job") < names.indexOf("swamp_workers_job"));
    const starts = workflow.jobs.find((job) => job.name === "swamp_workers_job")!;
    assertEquals(starts.steps.length, 1);
});
