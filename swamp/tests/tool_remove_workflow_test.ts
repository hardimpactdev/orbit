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
        "Prepare package commands": { outputs: { remove_repository: false, readable: null } },
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

Deno.test("a Linux Homebrew removal makes the prefix readable after its package step, and an apt removal does not", () => {
    const brew = runWorkflow("workflow-operation-tool-remove-209.yaml", "tool.remove", {
        instance_id: 0, manager: "brew", package: "git", platform: "linux", architecture: "x86_64", repository: null, package_owned: true,
    }, {
        "Prepare package commands": { outputs: { remove_repository: false, readable: ["/usr/bin/find"] } },
        "Choose package action": { outputs: { ready: true, remove: true } },
        "Decode package state": { outputs: { compliant: true, absent: true, present: false } },
    }, () => ({}));
    assertEquals(brew["Make the Homebrew prefix readable"].status, "succeeded");
    assertEquals(brew["Remove the package"].end <= brew["Make the Homebrew prefix readable"].start, true);
    assertEquals(run({ "Prepare package commands": { outputs: { remove_repository: false, readable: null } } })["Make the Homebrew prefix readable"].status, "skipped");
});

Deno.test("a failed Linux Homebrew removal still makes the prefix readable, and the run keeps the failure", () => {
    const outcomes = {
        "Prepare package commands": { outputs: { remove_repository: false, readable: ["/usr/bin/find"] } },
        "Choose package action": { outputs: { ready: true, remove: true } },
        "Decode package state": { outputs: { compliant: false, absent: false, present: true } },
        "Remove the package": { status: "failed" },
    };
    const brew = runWorkflow("workflow-operation-tool-remove-209.yaml", "tool.remove", {
        instance_id: 0, manager: "brew", package: "git", platform: "linux", architecture: "x86_64", repository: null, package_owned: true,
    }, outcomes, () => ({}));
    const unprepared = runWorkflow("workflow-operation-tool-remove-209.yaml", "tool.remove", {
        instance_id: 0, manager: "brew", package: "git", platform: "linux", architecture: "x86_64", repository: null, package_owned: true,
    }, { ...outcomes, "Prepare package commands": { status: "failed" } }, () => ({}));
    assertEquals(unprepared["Make the Homebrew prefix readable"].status, "skipped");
    assertEquals([brew["Remove the package"].status, brew["Make the Homebrew prefix readable"].status, brew["Assert package state"].status], ["failed", "succeeded", "failed"]);
});
