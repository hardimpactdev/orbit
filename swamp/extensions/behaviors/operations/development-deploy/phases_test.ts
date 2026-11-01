import { assertEquals } from "jsr:@std/assert@1.0.19";
import { phaseResult } from "./phases.ts";

const step = (value: unknown) => ({
    status: "succeeded",
    outputs: { stdout: JSON.stringify(value) },
});
const source = step({ ready: true, commit: "a".repeat(40) });

Deno.test("a failed source keeps its public refusal and no successful checkout claim", () => {
    const result = phaseResult(
        step({
            ready: false,
            error_code: "deployment.checkout_dirty",
            commit: "b".repeat(40),
        }),
        null,
    );
    assertEquals([
        result.ready,
        result.error_code,
        result.failed_step,
        result.commit,
    ], [
        false,
        "deployment.checkout_dirty",
        "source_preparation",
        "b".repeat(40),
    ]);
});
Deno.test("command failure keeps the attempted commit and exact failing command", () => {
    const result = phaseResult(
        source,
        step({
            ready: false,
            error_code: "deployment.step_failed",
            failed_step: "before_activation.build",
            output_tail: "safe",
            warnings: 1,
        }),
    );
    assertEquals([
        result.ready,
        result.commit,
        result.failed_step,
        result.output_tail,
        result.warnings,
    ], [false, "a".repeat(40), "before_activation.build", "safe", 1]);
});
Deno.test("transport failure has no readable output and never becomes success", () => {
    const result = phaseResult(source, { status: "failed" });
    assertEquals([result.ready, result.error_code, result.failed_step], [
        false,
        "operation.native_failed",
        "before_activation",
    ]);
});
Deno.test("an empty admitted command phase needs no skipped-step object", () => {
    const result = phaseResult(source, null, false);
    assertEquals([result.ready, result.commit, result.failed_step], [
        true,
        "a".repeat(40),
        null,
    ]);
});
Deno.test("successful phases preserve best effort warnings", () => {
    const result = phaseResult(
        source,
        step({
            ready: true,
            warnings: 2,
            output_tail: "Best-effort step failed (exit 42): warm",
        }),
    );
    assertEquals([result.ready, result.failed_step, result.warnings], [
        true,
        null,
        2,
    ]);
    assertEquals("error_code" in result, false);
    assertEquals(result.output_tail, "Best-effort step failed (exit 42): warm");
});

Deno.test("only a successful VP verification publishes the captured runtime reference", () => {
    const runtime = { store: "/home/app/.vite-plus", node_version: "24.1.0" };
    const commands = step({ ready: true, warnings: 0 });
    assertEquals(
        phaseResult(source, commands, true, step({ ready: true }), runtime)
            .vp_environment,
        { ...runtime, status: "active" },
    );
    assertEquals(
        phaseResult(source, commands, true, { status: "failed" }, runtime)
            .vp_environment,
        null,
    );
    assertEquals(
        phaseResult(source, commands, true, step({ ready: false }), runtime)
            .vp_environment,
        null,
    );
});
