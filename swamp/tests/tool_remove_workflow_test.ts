/**
 * The order of operation-tool-remove-209 after its package step (docs/reference/tools.md#ownership), on the pinned engine's scheduling
 * rule: a failed removal step is followed by the absence check and the decode, so the receipt shows whether the package survived.
 */
import { assertEquals } from "jsr:@std/assert@1.0.19";
import { type Outcome, runWorkflow } from "./_support/swamp_workflow.ts";

const run = (outcomes: Record<string, Outcome>) =>
    runWorkflow("workflow-operation-tool-remove-209.yaml", "tool.remove", {
        instance_id: 0, manager: "apt", package: "tree", platform: "linux", architecture: "x86_64", repository: null, package_owned: true,
    }, {
        "Prepare package commands": { outputs: { remove_repository: false } },
        "Choose package action": { outputs: { ready: true, remove: true } },
        "Decode package state": { outputs: { compliant: true, absent: false, present: true } },
        ...outcomes,
    }, () => ({}));

Deno.test("a removal step that failed is followed by the absence check and the decode", () => {
    const ran = run({ "Remove the package": { status: "failed" } });
    assertEquals([ran["Remove the package"].status, ran["Check apt absence"].status, ran["Decode package state"].status, ran["Assert package state"].status],
        ["failed", "succeeded", "succeeded", "failed"]);
});

Deno.test("a removal step that succeeded is checked as before", () => {
    const ran = run({ "Decode package state": { outputs: { compliant: true, absent: true, present: false } } });
    assertEquals([ran["Check apt absence"].status, ran["Decode package state"].status, ran["Assert package state"].status], ["succeeded", "succeeded", "succeeded"]);
});
