import { assertEquals, assertThrows } from "jsr:@std/assert@1";
import { finish, Input, prepare } from "./turn.ts";

const head = "a".repeat(40), tree = "b".repeat(40);
export function turnInput(role = "implementer") {
    return Input.parse({
        new_session: true,
        group_id: 10,
        instance_id: 12,
        node_id: 30,
        subtask: 11,
        thread_id: 14,
        checkout_path: "/srv/task",
        session_id: "session-14",
        role,
        prompt: "Fix the requested task",
        model: "orbit-task-vm/gpt-6.1-sol",
        effort: "high",
        pi_host: "10.44.0.30",
        pi_port: 3774,
        candidate: role === "reviewer" ? head : null,
        deliverables: [],
        analysis_evidence: null,
    });
}
function result(role = "implementer") {
    return {
        input: turnInput(role),
        before: JSON.stringify({ head, tree }),
        after: JSON.stringify({ head, tree }),
        restored: "",
        difference: "",
        terminal: JSON.stringify({
            state: "done",
            turnId: "operation-15",
            usage: {
                input: 10,
                output: 5,
                cacheRead: 8,
                cacheWrite: 2,
                total: 25,
                calls: 1,
                peakContext: 20,
            },
        }),
        receipt: JSON.stringify({
            thread: 14,
            outcome: role === "reviewer" ? "approved" : "ready_for_review",
            summary: "Changed the requested code",
        }),
    };
}
Deno.test("public shaping preserves the immutable Pi key and session settings", () => {
    const input = turnInput();
    const first = prepare({ operation_id: 15, node_id: 30, intent: input });
    assertEquals(
        first,
        prepare({ operation_id: 15, node_id: 30, intent: input }),
    );
    assertEquals(JSON.parse(first.send_json).key, "operation-15");
    assertEquals(JSON.parse(first.create_json).model, input.model);
    input.new_session = false;
    assertEquals(
        JSON.parse(
            prepare({ operation_id: 15, node_id: 30, intent: input })
                .intent_json,
        )
            .new_session,
        false,
    );
});
Deno.test("untrusted prompt expressions remain inert JSON content", () => {
    const input = turnInput();
    input.prompt =
        "Do not execute $(touch /tmp/unsafe), `id`, or ${{ env.HOME }}";
    assertEquals(
        JSON.parse(
            prepare({ operation_id: 15, node_id: 30, intent: input }).send_json,
        ).text
            .startsWith(input.prompt),
        true,
    );
});
Deno.test("turn result retains the existing board token metrics", () => {
    const value = JSON.parse(finish(result()).result_json);
    assertEquals(value.state, "done");
    assertEquals(value.receipt.outcome, "ready_for_review");
    assertEquals(value.metrics, {
        tokens: 25,
        input_tokens: 12,
        cached_input_tokens: 8,
        output_tokens: 5,
        model_calls: 1,
        peak_context_tokens: 20,
    });
});
Deno.test("wrong thread, role and incomplete blocked receipts never authorize progress", () => {
    for (
        const receipt of [
            { thread: 99, outcome: "ready_for_review", summary: "Old" },
            { thread: 14, outcome: "approved", summary: "Wrong role" },
            { thread: 14, outcome: "blocked", summary: "Missing question" },
        ]
    ) {
        assertEquals(
            JSON.parse(
                finish({ ...result(), receipt: JSON.stringify(receipt) })
                    .result_json,
            ).receipt,
            null,
        );
    }
});
Deno.test("missing completion or failed cleanup produces a failed terminal result", () => {
    for (const overrides of [{ terminal: "" }, { failure: true }]) {
        const value = JSON.parse(
            finish({ ...result(), ...overrides }).result_json,
        );
        assertEquals(value.state, "failed");
        assertEquals(value.error_code, "tasks.turn_failed");
        assertEquals(value.receipt, null);
    }
});
Deno.test("review evidence retains the restored candidate identity", () => {
    const value = JSON.parse(
        finish({
            ...result("reviewer"),
            restored: JSON.stringify({ head, tree }),
        }).result_json,
    );
    assertEquals(value.after_reset, { head, tree });
    assertEquals(value.receipt.outcome, "approved");
});
Deno.test("analysis plus prompt cannot exceed the existing prompt bound", () => {
    const input = turnInput();
    input.prompt = "x".repeat(100000);
    assertThrows(
        () => prepare({ operation_id: 15, node_id: 30, intent: input }),
        Error,
        "tasks.analysis_prompt_too_large",
    );
});

Deno.test("a mismatched worker Node is refused before any placed action", () => {
    assertThrows(
        () => prepare({ operation_id: 15, node_id: 31, intent: turnInput() }),
        Error,
        "tasks.node_mismatch",
    );
});
