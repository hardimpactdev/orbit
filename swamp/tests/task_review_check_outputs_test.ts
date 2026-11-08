import { assertEquals, assertStringIncludes } from "jsr:@std/assert@1.0.19";
import { type Task, trackedStep } from "../bin/gate-test-support.ts";
import { turnDataMethods } from "../extensions/behaviors/operations/tasks/turn.ts";

// Swamp exports direct behavior outputs only, not outputs of a child's child.
// Check Orbit's producer/consumer boundary without emulating the runtime or CEL.
Deno.test("review fixes consume a direct check receipt and retain failed evidence", async () => {
  const producer = trackedStep("operation-task-check", "Read the result")
    .task as Task;
  const consumer = trackedStep(
    "operation-task-review-round",
    "Settle the check",
  );
  assertEquals(producer.type, "behavior");
  assertEquals(producer.behaviorType, "@nckrtl/task-turn-data");
  assertEquals(producer.methodName, "checked");
  assertStringIncludes(
    String(consumer.task.inputs.result_json),
    'steps["Check the fix"].outputs["Read the result"].result_json',
  );
  assertEquals(
    String(consumer.task.inputs.result_json).includes(
      'outputs["Push the candidate"]',
    ),
    false,
  );
  for (const outcome of ["passed", "failed", "changed"]) {
    const receipt = {
      outcome,
      check_id: 2162,
      commit: outcome === "passed" ? "a".repeat(40) : null,
      tree: "b".repeat(40),
      exit_code: outcome === "failed" ? 1 : 0,
      output: "Project check evidence",
    };
    let exported: Record<string, unknown> = {};
    const input = { result_json: JSON.stringify(receipt) };
    await turnDataMethods.checked.execute(input, {
      writeResource: async (_spec, _name, value) => {
        exported = value;
      },
    });
    assertEquals(JSON.parse(String(exported.result_json)), receipt);
    assertEquals(exported.passed, outcome === "passed");
    assertEquals(exported.commit, receipt.commit ?? "");
  }
});
