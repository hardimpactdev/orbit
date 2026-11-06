import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";

type Step = {
    name: string;
    task?: { workflowIdOrName?: string; inputs?: Record<string, unknown> };
    dependsOn?: { step?: string }[];
};
type Workflow = { name: string; jobs: { name: string; steps: Step[] }[] };

const directory = new URL("../workflows/", import.meta.url);
const purposes = ["clone", "publish", "pull_request"];
const minted = /vault\.get\([^)]*(write_token|github_token|git_authorization)/;

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

Deno.test("a step reads a minted key only after the step that mints it", async () => {
    for (const { file, workflow } of await workflows()) {
        for (const job of workflow.jobs) {
            const mint = job.steps.find((step) => step.task?.workflowIdOrName === "task-github-mint");
            if (mint === undefined) continue;
            for (const step of job.steps) {
                if (step === mint || !minted.test(JSON.stringify(step.task ?? {}))) continue;
                assert(
                    before(job.steps, step).has(mint.name),
                    `${file}: "${step.name}" reads a minted key before "${mint.name}"`,
                );
            }
        }
    }
});
