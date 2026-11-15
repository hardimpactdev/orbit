import { assertEquals } from "jsr:@std/assert@1";
import { type Task, trackedStep } from "../../../../bin/gate-test-support.ts";
import { checked, turnDataMethods } from "./turn.ts";

const head = "a".repeat(40), tree = "b".repeat(40);

Deno.test("the local check of a fix names the commit to push only when it passed", () => {
    const commit = "c".repeat(40);
    const made = JSON.stringify({ outcome: "passed", commit, tree, head });
    assertEquals(checked({ result_json: made }), {
        passed: true,
        outcome: "passed",
        commit,
        tree,
        result_json: made,
    });
    for (
        const result of [{ outcome: "changed", commit: null, tree }, {
            outcome: "failed",
            commit: null,
            tree,
        }, {
            outcome: "passed",
            commit,
            tree,
            error_code: "tasks.repository_execution_unknown",
        }, { outcome: "passed", commit: null, tree }]
    ) {
        assertEquals(
            checked({ result_json: JSON.stringify(result) }).passed,
            false,
        );
    }
    assertEquals(checked({ result_json: "" }), {
        passed: false,
        outcome: "",
        commit: "",
        tree: "",
        result_json: "",
    });
});

// The check exports its receipt from a direct behavior step, so a caller reads it without a child's child.
Deno.test("the check exports its receipt directly and retains failed evidence", async () => {
    const producer = trackedStep("operation-task-check", "Read the result")
        .task as Task;
    assertEquals(producer.type, "behavior");
    assertEquals(producer.behaviorType, "@nckrtl/task-turn-data");
    assertEquals(producer.methodName, "checked");
    for (const outcome of ["passed", "failed", "changed"]) {
        const receipt = {
            outcome,
            check_id: 2162,
            commit: outcome === "passed" ? head : null,
            tree,
            exit_code: outcome === "failed" ? 1 : 0,
            output: "Project check evidence",
        };
        let exported: Record<string, unknown> = {};
        await turnDataMethods.checked.execute(
            { result_json: JSON.stringify(receipt) },
            {
                writeResource: async (_spec, _name, value) => {
                    exported = value;
                },
            },
        );
        assertEquals(JSON.parse(String(exported.result_json)), receipt);
        assertEquals(exported.passed, outcome === "passed");
        assertEquals(exported.commit, receipt.commit ?? "");
    }
});
