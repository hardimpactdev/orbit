import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";

type Step = {
    name: string;
    task?: { workflowIdOrName?: string; inputs?: Record<string, unknown> };
    dependsOn?: { step?: string; condition?: { type?: string } }[];
};
type Job = { name: string; steps: Step[]; dependsOn?: { job?: string; condition?: { type?: string } }[] };
type Workflow = { name: string; jobs: Job[] };

const directory = new URL("../workflows/", import.meta.url);
const purposes = ["clone", "dependencies", "publish", "pull_request", "dependency_merge"];
const minted = /vault\.get\([^)]*(write_token|read_token|github_token|git_authorization|dependency_auth)/;

async function workflows() {
    const found: { file: string; text: string; workflow: Workflow }[] = [];
    for await (const file of Deno.readDir(directory)) {
        if (!file.name.endsWith(".yaml")) continue;
        const text = await Deno.readTextFile(new URL(file.name, directory));
        found.push({ file: file.name, text, workflow: parse(text) as Workflow });
    }
    return found;
}

/** The steps that must have run before `step`, through every `dependsOn` in its job. */
function before(steps: Step[], step: Step): Set<string> {
    const byName = new Map(steps.map((candidate) => [candidate.name, candidate]));
    const seen = new Set<string>();
    const queue = [step];
    while (queue.length > 0) {
        for (const parent of queue.shift()?.dependsOn ?? []) {
            if (parent.step === undefined || seen.has(parent.step)) continue;
            seen.add(parent.step);
            const next = byName.get(parent.step);
            if (next !== undefined) queue.push(next);
        }
    }
    return seen;
}

Deno.test("only the mint workflow and the check push post to the Gateway mint", async () => {
    const posting = (await workflows()).filter(({ text }) => text.includes("/github-token"))
        .map(({ file }) => file).sort();
    assertEquals(posting, ["workflow-task-check-push.yaml", "workflow-task-github-mint.yaml"]);
});

Deno.test("every mint step names a purpose the Gateway issues and the run it belongs to", async () => {
    let calls = 0;
    for (const { file, workflow } of await workflows()) {
        for (const job of workflow.jobs) {
            for (const step of job.steps) {
                if (step.task?.workflowIdOrName !== "task-github-mint") continue;
                calls += 1;
                const inputs = step.task.inputs ?? {};
                assert(purposes.includes(String(inputs.purpose)), `${file}/${step.name}: purpose ${inputs.purpose}`);
                assert(String(inputs.run_id).includes("run"), `${file}/${step.name}: run_id ${inputs.run_id}`);
                assert(String(inputs.operation_id).includes("operation_id"), `${file}/${step.name}: operation_id`);
            }
        }
    }
    assert(calls >= 6, `expected the six task steps to mint, found ${calls}`);
});

/** The jobs that must have run before `job`. */
function jobsBefore(jobs: Job[], job: Job): Set<string> {
    const seen = new Set<string>();
    const queue = [job];
    while (queue.length > 0) {
        for (const parent of queue.shift()?.dependsOn ?? []) {
            if (parent.job === undefined || seen.has(parent.job)) continue;
            seen.add(parent.job);
            const next = jobs.find((candidate) => candidate.name === parent.job);
            if (next !== undefined) queue.push(next);
        }
    }
    return seen;
}

/** The purpose of the mint that writes the key a step reads: the clone credential and the dependency files have their own. */
function purposeOf(text: string): string | null {
    if (text.includes("dependency_auth")) return "dependencies";
    if (/vault\.get\(inputs\.intent\.git_authorization/.test(text)) return "clone";
    return null;
}

Deno.test("a step reads a minted key only after the step that mints it", async () => {
    for (const { file, workflow } of await workflows()) {
        const mints = workflow.jobs.flatMap((job) =>
            job.steps.filter((step) => step.task?.workflowIdOrName === "task-github-mint").map((step) => ({ job, step }))
        );
        // A workflow with no mint step gets its tokens another way; the check pushes and the dependency evidence are not task mints.
        if (mints.length === 0) continue;
        for (const job of workflow.jobs) {
            for (const step of job.steps) {
                const text = JSON.stringify(step.task ?? {});
                if (step.task?.workflowIdOrName === "task-github-mint" || !minted.test(text)) continue;
                const purpose = purposeOf(text);
                const owner = mints.find(({ step: mint }) => purpose === null || mint.task?.inputs?.purpose === purpose);
                const where = `${file}: "${step.name}" in job "${job.name}"`;
                assert(owner !== undefined, `${where} reads a minted key and nothing in the workflow mints it`);
                if (owner.job === job) {
                    assert(before(job.steps, step).has(owner.step.name), `${where} reads a minted key before "${owner.step.name}"`);
                } else {
                    assert(jobsBefore(workflow.jobs, job).has(owner.job.name), `${where} runs without waiting for job "${owner.job.name}", which mints`);
                }
            }
        }
    }
});

const ends = ["always", "completed", "or"];

Deno.test("a workflow that writes the dependency auth removes it on every outcome, and installs only after the write", async () => {
    let writers = 0;
    for (const { file, workflow } of await workflows()) {
        const calls = (name: string) =>
            workflow.jobs.flatMap((job) =>
                job.steps.filter((step) => step.task?.workflowIdOrName === name).map((step) => ({ job, step }))
            );
        const [write] = calls("task-dependency-auth-write");
        if (write === undefined) continue;
        writers += 1;
        const [remove] = calls("task-dependency-auth-remove");
        assert(remove !== undefined, `${file}: writes the dependency auth and never removes it`);
        if (remove.job === write.job) {
            assert(before(remove.job.steps, remove.step).has(write.step.name), `${file}: the removal does not wait for the write`);
            for (const parent of remove.step.dependsOn ?? []) {
                assert(ends.includes(String(parent.condition?.type)), `${file}: the removal waits for "${parent.step}" only on success`);
            }
        } else {
            assert(jobsBefore(workflow.jobs, remove.job).has(write.job.name), `${file}: the removal job does not wait for the write job`);
            for (const parent of remove.job.dependsOn ?? []) {
                assert(ends.includes(String(parent.condition?.type)), `${file}: the removal job waits for "${parent.job}" only on success`);
            }
        }
        for (const job of workflow.jobs) {
            for (const step of job.steps.filter((candidate) => /^(Install the package|setup-)/.test(candidate.name))) {
                if (job === write.job) {
                    assert(before(job.steps, step).has(write.step.name), `${file}: "${step.name}" does not wait for the write`);
                }
            }
        }
    }
    assert(writers >= 1, `expected the package install to write the dependency auth through the shared child, found ${writers}`);
});
