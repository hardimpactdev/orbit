import { assert } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { Environment } from "npm:@marcbachmann/cel-js@7.6.1";

type Step = { name: string; guard?: string };
type Workflow = { inputs: { properties: Record<string, unknown> }; jobs: { steps: Step[] }[] };

const read = async (name: string) => parse(await Deno.readTextFile(new URL(`../workflows/${name}`, import.meta.url))) as Workflow;
const step = (workflow: Workflow, name: string) => workflow.jobs.flatMap((job) => job.steps).find((candidate) => candidate.name === name)!;

// The guard as Swamp evaluates it: the expression inside `${{ }}`, with optional chaining on.
const environment = new Environment({ enableOptionalTypes: true, unlistedVariablesAreDyn: true });
const expression = (guard: string) => guard.trim().replace(/^\$\{\{\s*/, "").replace(/\s*\}\}$/, "");

/**
 * Whether the step is SKIPPED. Swamp skips a step when its guard is truthy ("a truthy result skips the step with reason guarded";
 * a falsy one runs it). The first version of the destroy guard read the other way round and destroyed the VM it meant to keep (M5 try 8).
 */
function skipped(guard: string, context: Record<string, unknown>): boolean {
    return environment.evaluate(expression(guard), context) === true;
}

const proof = await read("workflow-operation-proof-run.yaml");
const destroy = step(proof, "Destroy the VM").guard!;

const passed = '{"id": "main", "verdict": "passed", "secs": 9.5}';
const failed = '{"id": "deny", "verdict": "failed", "cause": "assertion", "secs": 4, "failed": ["ufw"]}';
const pinned = { status: "succeeded" };
const proved = (report: string, status = "succeeded") => ({ status, outputs: { report: { stdout: report } } });
/** Whether the destroy step is skipped, so that the VM stays. */
const keeps = (inputs: Record<string, unknown>, steps: Record<string, unknown>) => skipped(destroy, { inputs, steps });

Deno.test("anchor: a guard that is true skips the step, as a guard of the repository already relies on", async () => {
    // The restore of `base` is skipped for a proof in CI, whose intent has no `restore_first`: that guard is true there.
    const restore = step(await read("workflow-proof-fleet-verify.yaml"), "Restore the base snapshot").guard!;
    assert(skipped(restore, { inputs: { intent: {} } }), "no restore_first: the restore is skipped");
    assert(!skipped(restore, { inputs: { intent: { restore_first: true } } }), "restore_first: the restore runs");
});

Deno.test("the proof run takes keep_failed as an optional boolean", () => {
    assert(JSON.stringify(proof.inputs.properties.keep_failed) === '{"type":"boolean"}');
});

const created = { status: "succeeded" };
const steps = (report: string | null, over: Record<string, unknown> = {}) => ({
    "Create the VM": created, "Pin the VM": pinned, ...(report === null ? {} : { "Prove the command": proved(report) }), ...over,
});

Deno.test("without keep_failed the destroy step runs, whatever the cases did", () => {
    assert(!keeps({}, steps(failed)));
    assert(!keeps({ keep_failed: false }, steps(failed)));
    assert(!keeps({}, steps(passed)));
    assert(!keeps({}, { "Create the VM": { status: "failed" } }));
});

Deno.test("with keep_failed a run whose cases did not all pass skips the destroy step, so its VM stays", () => {
    assert(keeps({ keep_failed: true }, steps(`${passed}\n${failed}`)));
    assert(keeps({ keep_failed: true }, steps(failed)));
});

Deno.test("with keep_failed a run that printed no verdict, or whose verify step failed, skips the destroy step", () => {
    assert(keeps({ keep_failed: true }, steps("")));
    assert(keeps({ keep_failed: true }, { "Create the VM": created, "Pin the VM": pinned, "Prove the command": { status: "failed" } }));
    assert(keeps({ keep_failed: true }, steps(null)));
});

Deno.test("with keep_failed a run that failed in its setup, before the VM was pinned or any case ran, skips the destroy step too (M5 try 11)", () => {
    assert(keeps({ keep_failed: true }, { "Create the VM": { status: "failed" } }));
    assert(keeps({ keep_failed: true }, { "Create the VM": created, "Pin the VM": { status: "failed" } }));
    assert(keeps({ keep_failed: true }, { "Create the VM": { status: "failed" }, "Pin the VM": { status: "skipped" }, "Prove the command": { status: "skipped" } }));
    assert(keeps({ keep_failed: true }, {}));
});

Deno.test("with keep_failed a run that succeeded in every part, with every verdict passed, still destroys the VM", () => {
    assert(!keeps({ keep_failed: true }, steps(passed)));
    assert(!keeps({ keep_failed: true }, steps(`${passed}\n${passed}`)));
});

Deno.test("with keep_failed a run that failed the check of the workspace commit, so that its proof step was skipped, skips the destroy step", () => {
    assert(keeps({ keep_failed: true }, steps(null, { "Prove the command": { status: "skipped" } })));
});
