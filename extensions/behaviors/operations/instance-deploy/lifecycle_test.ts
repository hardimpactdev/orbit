import { assertEquals } from "jsr:@std/assert@1.0.19";
import { lifecycleFailure } from "./lifecycle.ts";

Deno.test("setup and teardown preserve confirmed failure classifications", () => {
  for (const phase of ["setup", "teardown"] as const) {
    for (const exit of [126, 127]) {
      assertEquals(
        lifecycleFailure(phase, { exit_code: exit, timed_out: false }, 30, 30),
        {
          outcome: "missing",
          error_code: `instance.${phase}_step_unavailable`,
        },
      );
    }
    for (const exit of [1, 124]) {
      assertEquals(
        lifecycleFailure(
          phase,
          { exit_code: exit, timed_out: exit === 124 },
          30,
          30,
        ),
        {
          outcome: "failed",
          error_code: `instance.${phase}_step_failed`,
        },
      );
    }
    assertEquals(
      lifecycleFailure(phase, { exit_code: 0, timed_out: false }, 30, 30),
      null,
    );
  }
});
Deno.test("busy, cancellation and uncertain transport outcomes never become confirmed failures", () => {
  assertEquals(
    lifecycleFailure("setup", { error_code: "instance.lifecycle_busy" }, 30, 30)
      ?.outcome,
    "busy",
  );
  assertEquals(
    lifecycleFailure("setup", { exit_code: 75, timed_out: false }, 30, 30)
      ?.outcome,
    "busy",
  );
  assertEquals(
    lifecycleFailure("setup", { error_code: "operation.cancelled" }, 30, 30)
      ?.outcome,
    "cancelled",
  );
  for (
    const raw of [null, {}, { exit_code: 125, timed_out: false }, {
      error_code: "operation.outcome_unknown",
    }]
  ) {
    assertEquals(
      lifecycleFailure("setup", raw, 30, 30)?.outcome,
      "unconfirmed",
    );
  }
});
Deno.test("request budget cuts retain their deadline outcome", () => {
  assertEquals(lifecycleFailure("setup", null, 0, 30), {
    outcome: "deadline",
    error_code: "command.deadline_exceeded",
  });
  assertEquals(
    lifecycleFailure("teardown", { exit_code: 124, timed_out: true }, 10, 30),
    { outcome: "deadline", error_code: "command.deadline_exceeded" },
  );
});

Deno.test("a Gateway deadline refusal retains its explicit deadline classification", () => {
  assertEquals(
    lifecycleFailure(
      "setup",
      { error_code: "command.deadline_exceeded" },
      30,
      30,
    ),
    { error_code: "command.deadline_exceeded", outcome: "deadline" },
  );
});
