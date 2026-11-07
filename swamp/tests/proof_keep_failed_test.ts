import { assert } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { Environment } from "npm:@marcbachmann/cel-js@7.6.1";

type Step = { name: string; guard?: string };
type Workflow = { inputs: { properties: Record<string, unknown> }; jobs: { steps: Step[] }[] };

const workflow = parse(await Deno.readTextFile(new URL("../workflows/workflow-operation-proof-run.yaml", import.meta.url))) as Workflow;
const destroy = workflow.jobs.flatMap((job) => job.steps).find((step) => step.name === "Destroy the VM")!;
// The guard as Swamp evaluates it: the expression inside `${{ }}`, with optional chaining on.
const guard = destroy.guard!.trim().replace(/^\$\{\{\s*/, "").replace(/\s*\}\}$/, "");
const environment = new Environment({ enableOptionalTypes: true, unlistedVariablesAreDyn: true });

const passed = '{"id": "main", "verdict": "passed", "secs": 9.5}';
const failed = '{"id": "deny", "verdict": "failed", "cause": "assertion", "secs": 4, "failed": ["ufw"]}';

/** Whether the destroy step runs (true) or is skipped so that the VM stays (false). */
function runs(inputs: Record<string, unknown>, steps: Record<string, unknown>): boolean {
    return environment.evaluate(guard, { inputs, steps }) as boolean;
}

const pinned = { status: "succeeded" };
const proved = (report: string, status = "succeeded") => ({ status, outputs: { report: { stdout: report } } });

Deno.test("the proof run takes keep_failed as an optional boolean", () => {
    assert(JSON.stringify(workflow.inputs.properties.keep_failed) === '{"type":"boolean"}');
});

Deno.test("without keep_failed the VM is always destroyed", () => {
    assert(runs({}, { "Pin the VM": pinned, "Prove the command": proved(failed) }));
    assert(runs({ keep_failed: false }, { "Pin the VM": pinned, "Prove the command": proved(failed) }));
});

Deno.test("with keep_failed a run whose cases did not all pass keeps its VM", () => {
    assert(!runs({ keep_failed: true }, { "Pin the VM": pinned, "Prove the command": proved(`${passed}\n${failed}`) }));
    assert(!runs({ keep_failed: true }, { "Pin the VM": pinned, "Prove the command": proved(failed) }));
});

Deno.test("with keep_failed a run that printed no verdict, or whose verify step failed, keeps its VM", () => {
    assert(!runs({ keep_failed: true }, { "Pin the VM": pinned, "Prove the command": proved("") }));
    assert(!runs({ keep_failed: true }, { "Pin the VM": pinned, "Prove the command": { status: "failed" } }));
    assert(!runs({ keep_failed: true }, { "Pin the VM": pinned }));
});

Deno.test("with keep_failed a run that passes, or whose VM never got ready, still destroys the VM", () => {
    assert(runs({ keep_failed: true }, { "Pin the VM": pinned, "Prove the command": proved(passed) }));
    assert(runs({ keep_failed: true }, { "Pin the VM": pinned, "Prove the command": proved(`${passed}\n${passed}`) }));
    assert(runs({ keep_failed: true }, { "Create the VM": { status: "failed" } }));
    assert(runs({ keep_failed: true }, { "Create the VM": { status: "succeeded" }, "Pin the VM": { status: "failed" } }));
});
