import { assert } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { Environment } from "npm:@marcbachmann/cel-js@7.6.1";

/**
 * The end check of a run is the step the Gateway reads its result from. Each workflow here waits in it for cleanup steps that a guard
 * can skip. A guard that holds makes the step `skipped`, and Swamp's `completed` accepts only `succeeded` and `failed`, so a plain
 * `completed` wait would skip the end check too (tasks.publish 20763, #3681). For every path of each workflow, these cases evaluate
 * each guard with the pinned CEL engine and assert that the end check's condition accepts the status that follows from it.
 */
type Condition = { type: string; conditions?: Condition[] };
type Step = { name: string; guard?: string; dependsOn?: { step: string; condition: Condition }[] };

const cel = new Environment({ enableOptionalTypes: true, unlistedVariablesAreDyn: true, homogeneousAggregateLiterals: false });
const accepts = (condition: Condition, status: string): boolean =>
    condition.type === "or" ? condition.conditions!.some((item) => accepts(item, status)) : condition.type === status ||
        (condition.type === "completed" && ["succeeded", "failed"].includes(status)) || condition.type === "always";

/** Asserts that `end` in `workflow` runs on each path: a map of the statuses the guards read, with the run's intent. */
function endCheckRuns(workflow: string, end: string, paths: Record<string, { intent?: Record<string, unknown>; steps: Record<string, { status: string }> }>) {
    const flow = parse(Deno.readTextFileSync(new URL(`../workflows/${workflow}`, import.meta.url))) as { jobs: { steps: Step[] }[] };
    const steps = new Map(flow.jobs.flatMap((job) => job.steps).map((step) => [step.name, step]));
    const guarded = steps.get(end)!.dependsOn!.filter((dependency) => steps.get(dependency.step)!.guard !== undefined);
    assert(guarded.length > 0, `${end} waits for no guarded step`);
    for (const [path, { intent = {}, steps: state }] of Object.entries(paths)) {
        for (const dependency of guarded) {
            const guard = steps.get(dependency.step)!.guard!.trim().replace(/^\$\{\{/, "").replace(/\}\}$/, "").trim();
            const skipped = cel.evaluate(guard, { inputs: { intent }, steps: state, run: { id: "run-1" } }) === true;
            for (const status of skipped ? ["skipped"] : ["succeeded", "failed"]) {
                assert(accepts(dependency.condition, status), `${path}: ${end} is skipped when ${dependency.step} is ${status}`);
            }
        }
    }
}

Deno.test("conn.environment_renew reports on every path, also when the Node step never ran", () => {
    endCheckRuns("workflow-operation-conn-environment-renew.yaml", "Report the renewal", {
        "a session issued, a refusal on the Node": { steps: { "Issue the Gateway session": { status: "succeeded" } } },
        "an unreachable Node": { steps: { "Issue the Gateway session": { status: "failed" } } },
        "a failed private folder": { steps: { "Create the private folder": { status: "failed" }, "Issue the Gateway session": { status: "skipped" } } },
    });
});

Deno.test("tasks.dependency_merge confirms on every path, also after a failed mint", () => {
    endCheckRuns("workflow-operation-dependency-merge.yaml", "Confirm the merge", {
        "a merge or an already merged PR": { steps: { "Mint the tokens": { status: "succeeded" } } },
        "a failed mint": { steps: { "Mint the tokens": { status: "failed" } } },
    });
});

Deno.test("tasks.publish confirms on every path of a copy and a VM, also after a failed mint", () => {
    for (const [name, intent] of Object.entries({ copy: { workspace: {} }, vm: {} })) {
        const preflight = { "Preflight the copy": { status: name === "copy" ? "succeeded" : "skipped" } };
        endCheckRuns("workflow-operation-task-publish.yaml", "Confirm publication", {
            [`${name}: a publication`]: { intent, steps: { ...preflight, "Mint the push token": { status: "succeeded" } } },
            [`${name}: a failed mint`]: { intent, steps: { ...preflight, "Mint the push token": { status: "failed" } } },
        });
    }
    endCheckRuns("workflow-operation-task-publish.yaml", "Confirm publication", {
        "copy: a failed preflight": { intent: { workspace: {} }, steps: { "Preflight the copy": { status: "failed" } } },
    });
});

Deno.test("vault.setup: a skipped cleanup step never skips the end check", () => {
    endCheckRuns("workflow-operation-vault-setup.yaml", "Confirm the setup", {
        "a verified account": { steps: { workspace: { status: "succeeded" }, "Read probe identity": { status: "succeeded" } } },
        "a failed probe": { steps: { workspace: { status: "succeeded" }, "Read probe identity": { status: "failed" } } },
        "a failed workspace": { steps: { workspace: { status: "failed" } } },
    });
});
