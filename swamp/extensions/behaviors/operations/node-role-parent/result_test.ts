import { assertEquals } from "jsr:@std/assert@1.0.19";
import { parentResult } from "./result.ts";
const target = {
    schema_version: 1 as const,
    node_id: 9,
    assignment_id: 17,
    role: "websocket" as const,
    created: false,
    role_proven: true,
    certificates_proven: true,
    runtime_proven: true,
    publication_proven: true,
    baseline: [],
    footprint: { footprint_proven: true, artifacts: [] },
    cli: { outcome: "skipped" },
    follow_up: null,
};
Deno.test("target success followed by source failure keeps the exact target facts", () => {
    const result = parentResult({
        kind: "node.role_relocate",
        steps: {
            role_result: {
                status: "succeeded",
                outputs: target,
            },
            source_cleanup: { status: "failed" },
        },
    });
    assertEquals(result.ready, false);
    assertEquals(result.target_completed, true);
    assertEquals(result.source_completed, false);
    assertEquals(result.target, target);
    assertEquals(result.error_code, "node_role.source_cleanup_failed");
});
Deno.test("successful source cleanup needs its native receipt", () => {
    for (const value of [undefined, false, true]) {
        const result = parentResult({
            kind: "node.role_relocate",
            steps: {
                role_result: {
                    status: "succeeded",
                    outputs: target,
                },
                source_cleanup: {
                    status: "succeeded",
                    outputs: { result: { role_removed: value } },
                },
            },
        });
        assertEquals(result.ready, value === true);
    }
});
Deno.test("a failed target cannot report completed source or role", () => {
    const result = parentResult({
        kind: "node.role_add",
        steps: {
            role_result: {
                status: "succeeded",
                outputs: {
                    ...target,
                    role_proven: false,
                    runtime_proven: false,
                    error_code: "websocket.runtime_failed",
                },
            },
        },
    });
    assertEquals(result.ready, false);
    assertEquals(result.error_code, "websocket.runtime_failed");
    assertEquals(result.target?.publication_proven, true);
});
Deno.test("missing contribution after cancellation yields a terminal failure without dereferencing absent outputs", () => {
    assertEquals(
        parentResult({
            kind: "node.role_add",
            steps: { target_admission: { status: "cancelled" } },
        }).ready,
        false,
    );
});
Deno.test("a failed contribution never proves target readiness from its outputs", () => {
    const result = parentResult({
        kind: "node.role_add",
        steps: { role_result: { status: "failed", outputs: target } },
    });
    assertEquals(result.ready, false);
    assertEquals(result.target_completed, false);
    assertEquals(result.target, null);
});
Deno.test("cancelled rollback is an incomplete cleanup even after target proof", () => {
    const result = parentResult({
        kind: "node.role_add",
        steps: {
            role_result: { status: "succeeded", outputs: target },
            target_rollback: { status: "cancelled" },
        },
    });
    assertEquals(result.ready, false);
    assertEquals(result.target_completed, true);
    assertEquals(result.error_code, "websocket.rollback_failed");
});

Deno.test("failed residue remains retryable without failing a proven role", () => {
    const result = parentResult({
        kind: "node.role_add",
        steps: {
            role_result: { status: "succeeded", outputs: target },
            route_residue: {
                status: "failed",
                outputs: { result: { ready: true, changed: true } },
            },
        },
    });
    assertEquals(result.ready, true);
    assertEquals(result.target?.footprint.artifacts, [{
        name: "route-residue",
        status: "skipped",
        reason: "route_residue_cleanup_failed",
    }]);
});
Deno.test("successful residue contributes its own removal proof", () => {
    const result = parentResult({
        kind: "node.role_add",
        steps: {
            role_result: { status: "succeeded", outputs: target },
            route_residue: {
                status: "succeeded",
                outputs: { result: { ready: true, changed: true } },
            },
        },
    });
    assertEquals(result.target?.footprint.artifacts, [{
        name: "route-residue",
        status: "applied",
    }]);
});
