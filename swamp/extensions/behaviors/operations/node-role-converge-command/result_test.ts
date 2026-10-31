import { assertEquals, assertThrows } from "jsr:@std/assert@1";
import { roleConvergeResult, Stage } from "./result.ts";

function receipt() {
    return {
        assignment_id: 18,
        created: false,
        baseline: Stage.options.map((stage) => ({ stage, ok: true })),
        dependencies_ready: true,
        runtime_completed: true,
        publication_phases: [{
            phase: "certificates_published",
            node_id: 13,
        }],
        footprint: { footprint_proven: true, artifacts: [] },
        cli: { completed: true, outcome: "cli_present" },
    };
}

Deno.test("complete native baseline and publication prove the role while retaining terminal contributions", () => {
    const args = receipt();
    const result = roleConvergeResult(args);
    assertEquals(result.role_proven, true);
    assertEquals(result.target_completed, true);
    assertEquals(result.runtime_completed, true);
    assertEquals(result.publication_phases, args.publication_phases);
    assertEquals("follow_up" in result ? result.follow_up : undefined, null);
});

Deno.test("partial or failed baseline cannot activate a role even when publication succeeds", () => {
    const args = receipt();
    args.baseline.pop();
    const uncertain = roleConvergeResult(args);
    assertEquals(uncertain.role_proven, false);
    assertEquals(
        "error_code" in uncertain ? uncertain.error_code : null,
        "node_role.baseline_uncertain",
    );
    assertEquals(uncertain.runtime_completed, true);
    args.baseline.push({ stage: "router-lan", ok: false });
    const failed = roleConvergeResult(args);
    assertEquals(failed.role_proven, false);
    assertEquals(
        "error_code" in failed ? failed.error_code : null,
        "node_role.baseline_failed",
    );
    assertEquals(
        "failed_step" in failed ? failed.failed_step : null,
        "router-lan",
    );
});

Deno.test("publication readiness gates the target and duplicate receipts cannot count as completion", () => {
    const args = receipt();
    args.dependencies_ready = false;
    const result = roleConvergeResult(args);
    assertEquals(result.target_completed, false);
    assertEquals(
        "error_code" in result ? result.error_code : null,
        "node_role.publication_failed",
    );
    args.baseline[1] = args.baseline[0];
    assertThrows(() => roleConvergeResult(args), Error, "duplicate");
});

Deno.test("footprint and CLI follow-ups preserve the proven role with explicit public failures", () => {
    const args = {
        ...receipt(),
        footprint: {
            footprint_proven: false,
            artifacts: [],
            error_code: "agent.version_mismatch",
        },
        cli: { error_code: "cli.foreign_binary" },
    };
    const result = roleConvergeResult(args);
    assertEquals(result.role_proven, true);
    assertEquals(
        "follow_up" in result ? result.follow_up : null,
        "role-footprint:agent.version_mismatch, role-cli:cli.foreign_binary",
    );
});
