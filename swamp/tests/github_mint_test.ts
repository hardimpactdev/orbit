import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { Environment } from "npm:@marcbachmann/cel-js@7.6.1";
import { parse } from "jsr:@std/yaml@1.0.12";

type Step = {
    name: string;
    guard?: string;
    task?: { workflowIdOrName?: string; inputs?: Record<string, unknown> };
    dependsOn?: { step?: string; condition?: { type?: string } }[];
};
type Job = { name: string; steps: Step[]; dependsOn?: { job?: string; condition?: { type?: string } }[] };
type Workflow = { name: string; jobs: Job[] };

const directory = new URL("../workflows/", import.meta.url);
const purposes = ["clone", "dependencies", "publish", "pull_request", "dependency_merge", "ci_rerun"];
const minted = /vault\.get\([^)]*(write_token|read_token|github_token|git_authorization|dependency_auth)/;

/** A copy-only step can use the mint's vault but read a pre-existing environment/Club key. */
function readsMintedKey(step: Step): boolean {
    const text = JSON.stringify(step.task ?? {});
    if (!minted.test(text)) return false;
    // Only this guard proves that the VM alternative of a conditional secret is never consumed.
    if (step.guard?.replace(/\s/g, "") !== '${{!("workspace"ininputs.intent)}}') return true;
    const expressions: string[] = [];
    const visit = (value: unknown): void => {
        if (typeof value === "string") {
            for (const match of value.matchAll(/\$\{\{([\s\S]*?)\}\}/g)) {
                if (minted.test(match[1])) expressions.push(match[1].trim());
            }
        } else if (value !== null && typeof value === "object") {
            Object.values(value).forEach(visit);
        }
    };
    visit(step.task);
    assert(expressions.length > 0, `${step.name}: minted reference is not a CEL expression`);
    const keys = ["write_token", "read_token", "github_token", "git_authorization", "dependency_auth"];
    const execution_secrets = Object.fromEntries([...keys, "environment", "club_key"].map(key => [key, { vault: "shared", key }]));
    for (const workspace of [{}, { club_key: false }, { club_key: true }]) {
        const reads: string[] = [];
        const cel = new Environment({ unlistedVariablesAreDyn: true })
            .registerFunction("vaultget(dyn, dyn): string", (_vault: unknown, key: unknown) => {
                reads.push(String(key));
                return "secret";
            });
        for (const expression of expressions) {
            cel.evaluate(expression.replaceAll("vault.get(", "vaultget("), { inputs: { intent: { workspace }, execution_secrets } });
        }
        if (reads.some(key => keys.includes(key))) return true;
    }
    return false;
}

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
                if (step.task?.workflowIdOrName === "task-github-mint" || !readsMintedKey(step)) continue;
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

Deno.test("copy-only secret checks do not read the VM mint key, and losing that guard or reading the key is detected", async () => {
    const workflow = (await workflows()).find(row => row.file === "workflow-operation-task-publish.yaml")!.workflow;
    const steps = workflow.jobs.flatMap(job => job.steps);
    const check = steps.find(step => step.name === "Check the .env content")!;
    assertEquals(readsMintedKey(check), false);
    assertEquals(readsMintedKey({ ...check, guard: undefined }), true);
    const changed = structuredClone(check);
    changed.task!.inputs!.env = { TOKEN: "${{ vault.get(inputs.execution_secrets.git_authorization.vault, inputs.execution_secrets.git_authorization.key) }}" };
    assertEquals(readsMintedKey(changed), true);
    assertEquals(readsMintedKey(steps.find(step => step.name === "Read the base")!), true);
});
