import { assertEquals, assertThrows } from "jsr:@std/assert@1.0.19";
import {
  canRemoveAfterRollbackTeardown,
  canRollbackSetup,
  ErrorCode,
  sourceResolution,
  StepListResult,
} from "./contract.ts";

Deno.test("only the create that activated the Instance can roll back confirmed setup failure", () => {
  for (
    const [outcome, permitted] of [
      ["failed", true],
      ["missing", true],
      ["deadline", true],
      ["busy", false],
      ["unconfirmed", false],
      ["cancelled", false],
    ] as const
  ) {
    const failure = { outcome, error_code: "instance.setup_step_failed" };
    assertEquals(canRollbackSetup(failure, true), permitted);
    assertEquals(canRollbackSetup(failure, false), false);
    assertEquals(canRemoveAfterRollbackTeardown(failure), permitted);
  }
  assertEquals(canRemoveAfterRollbackTeardown(null), true);
});

Deno.test("source resolution requires the selected branch and a complete commit identity", () => {
  for (const length of [40, 64]) {
    const receipt = { branch: "feature", commit: "a".repeat(length) };
    assertEquals(sourceResolution(receipt, "feature"), receipt);
  }
  assertThrows(() =>
    sourceResolution({ branch: "other", commit: "a".repeat(40) }, "feature")
  );
  for (
    const commit of ["a".repeat(39), "a".repeat(41), "A".repeat(40), "HEAD", ""]
  ) {
    assertThrows(() =>
      sourceResolution({ branch: "feature", commit }, "feature")
    );
  }
});

Deno.test("persisted errors accept only the fixed code alphabet", () => {
  for (
    const code of [
      "instance.setup_step_failed",
      "operation.cancelled",
      "command.deadline_exceeded",
    ]
  ) {
    assertEquals(ErrorCode.parse(code), code);
  }
  for (
    const code of ["", "Error", "error: token", "error\nsecret", "error/value"]
  ) {
    assertEquals(ErrorCode.safeParse(code).success, false);
  }
});

Deno.test("step-list success omits failure metadata while failures retain their full contract", () => {
  assertEquals(StepListResult.parse({ outcome: "succeeded" }), {
    outcome: "succeeded",
  });
  for (
    const outcome of [
      "busy",
      "missing",
      "failed",
      "unconfirmed",
      "deadline",
      "cancelled",
    ]
  ) {
    assertThrows(() => StepListResult.parse({ outcome }));
    assertThrows(() => StepListResult.parse({ outcome, error_code: null }));
    assertThrows(() => StepListResult.parse({ outcome, failed_step: null }));
  }
});
