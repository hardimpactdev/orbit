import {
  assertEquals,
  assertRejects,
  assertStringIncludes,
} from "jsr:@std/assert@1.0.19";
import { type Task, trackedStep } from "../bin/gate-test-support.ts";
import { turnDataMethods } from "../extensions/behaviors/operations/tasks/turn.ts";

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

const head = "a".repeat(40), tree = "b".repeat(40);
function evidence() {
  return {
    head,
    tree,
    handoff: {
      check_id: 2162,
      outcome: "passed",
      commit: head,
      tree,
      exit_code: 0,
    },
    hosted_checks: [{
      name: "Required checks",
      status: "completed",
      conclusion: "success",
      head,
      app: "github-actions",
    }],
  };
}
async function reviewerMessage(checks: unknown) {
  let output: Record<string, unknown> = {};
  await turnDataMethods.reviewPrompt.execute({
    send_json: JSON.stringify({
      key: "review-round-15-review",
      text: "Review the chart and cases.",
    }),
    role: "reviewer",
    candidate: head,
    tree,
    evidence: checks,
  }, {
    writeResource: async (_spec, _name, value) => {
      output = value;
    },
  });
  return JSON.parse(String(output.send_json));
}

Deno.test("reviewer receives the passed handoff and hosted Required-check conclusions", async () => {
  const message = await reviewerMessage(evidence());
  assertStringIncludes(message.text, "Review the chart and cases.");
  assertEquals(
    JSON.parse(message.text.split("outside this VM):\n")[1]),
    evidence(),
  );
  assertEquals(message.key, "review-round-15-review");
});

for (
  const part of ["head", "handoff head", "hosted head", "tree", "handoff tree"]
) {
  Deno.test(`reviewer rejects evidence with another ${part}`, async () => {
    const checks = evidence();
    const other = "c".repeat(40);
    if (part === "head") checks.head = other;
    if (part === "handoff head") checks.handoff.commit = other;
    if (part === "hosted head") checks.hosted_checks[0].head = other;
    if (part === "tree") checks.tree = other;
    if (part === "handoff tree") checks.handoff.tree = other;
    await assertRejects(
      () => reviewerMessage(checks),
      Error,
      part.includes("tree")
        ? "tasks.review_evidence_tree_mismatch"
        : "tasks.review_evidence_head_mismatch",
    );
  });
}

Deno.test("reviewer rejects raw fields and failed check evidence", async () => {
  await assertRejects(() =>
    reviewerMessage({ ...evidence(), token: "unexpected" })
  );
  const failed = evidence();
  failed.hosted_checks[0].conclusion = "failure";
  await assertRejects(() => reviewerMessage(failed));
});

Deno.test("turns without review evidence retain their original prompt", async () => {
  const message = await reviewerMessage(null);
  assertEquals(message, {
    key: "review-round-15-review",
    text: "Review the chart and cases.",
  });
});
