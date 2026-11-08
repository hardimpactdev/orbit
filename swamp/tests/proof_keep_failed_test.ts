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
 * a falsy one runs it). The first version of the release guard read the other way round and released the VM it meant to keep (M5 try 8).
 */
function skipped(guard: string, context: Record<string, unknown>): boolean {
    return environment.evaluate(expression(guard), context) === true;
}

const proof = await read("workflow-operation-proof-run.yaml");
const release = step(proof, "Release the slot").guard!;

const passed = '{"id": "main", "verdict": "passed", "secs": 9.5}';
const failed = '{"id": "deny", "verdict": "failed", "cause": "assertion", "secs": 4, "failed": ["ufw"]}';
const proved = (report: string, status = "succeeded") => ({ status, outputs: { report: { stdout: report } } });
/** Whether the release is skipped, so that the slot stays. */
const keeps = (inputs: Record<string, unknown>, steps: Record<string, unknown>) => skipped(release, { inputs: { ...inputs, intent: { verifications: [{ step: "Prove assigned batches-0" }] } }, steps });

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
    "Claim a slot": created, ...(report === null ? {} : { "Prove assigned batches-0": proved(report) }), ...over,
});

Deno.test("without keep_failed the release runs, whatever the cases did", () => {
    assert(!keeps({}, steps(failed)));
    assert(!keeps({ keep_failed: false }, steps(failed)));
    assert(!keeps({}, steps(passed)));
    assert(!keeps({}, { "Claim a slot": { status: "failed" } }));
});

Deno.test("with keep_failed a run whose cases did not all pass skips the release, so its slot stays", () => {
    assert(keeps({ keep_failed: true }, steps(`${passed}\n${failed}`)));
    assert(keeps({ keep_failed: true }, steps(failed)));
});

Deno.test("with keep_failed a run that printed no verdict, or whose verify step failed, skips the release", () => {
    assert(keeps({ keep_failed: true }, steps("")));
    assert(keeps({ keep_failed: true }, { "Claim a slot": created, "Prove assigned batches-0": { status: "failed" } }));
    assert(keeps({ keep_failed: true }, steps(null)));
});

Deno.test("with keep_failed a run that failed in its claim, before any case ran, skips the release too (M5 try 11)", () => {
    assert(keeps({ keep_failed: true }, { "Claim a slot": { status: "failed" } }));
    assert(keeps({ keep_failed: true }, { "Claim a slot": { status: "failed" }, "Prove assigned batches-0": { status: "skipped" } }));
    assert(keeps({ keep_failed: true }, {}));
});

Deno.test("with keep_failed a run that succeeded in every part, with every verdict passed, still releases the slot", () => {
    assert(!keeps({ keep_failed: true }, steps(passed)));
    assert(!keeps({ keep_failed: true }, steps(`${passed}\n${passed}`)));
});

Deno.test("with keep_failed a run that failed the check of the workspace commit, so that its proof step was skipped, skips the release", () => {
    assert(keeps({ keep_failed: true }, steps(null, { "Prove assigned batches-0": { status: "skipped" } })));
});
