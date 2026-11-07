import { assertEquals, assertRejects, assertThrows } from "jsr:@std/assert@1";
import {
    checked,
    checkReceipt,
    difference,
    finish,
    Input,
    outcome,
    prepare,
    receipt,
    round,
    settle,
    terminal,
} from "./turn.ts";

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
            receipt: {
                thread: 14,
                outcome: role === "reviewer" ? "approved" : "ready_for_review",
                summary: "Changed the requested code",
            },
            error: null,
        }),
    };
}
function withReceipt(receipt: unknown, role = "implementer") {
    return JSON.parse(
        finish({
            ...result(role),
            receipt: JSON.stringify({ receipt, error: null }),
        }).result_json,
    );
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
Deno.test("an oversized prompt ends the turn with its own code and sends nothing", () => {
    const input = turnInput();
    input.prompt = "x".repeat(100000);
    const prepared = prepare({ operation_id: 15, node_id: 30, intent: input });
    assertEquals(prepared.error_code, "tasks.prompt_too_large");
    const value = JSON.parse(
        finish({
            ...result(),
            failure: true,
            failure_code: prepared.error_code,
        }).result_json,
    );
    assertEquals(value.state, "failed");
    assertEquals(value.error_code, "tasks.prompt_too_large");
});
Deno.test("turn result retains the existing board token metrics", () => {
    assertEquals(finish(result()).successful, true);
    const value = JSON.parse(finish(result()).result_json);
    assertEquals(value.state, "done");
    assertEquals(value.receipt.outcome, "ready_for_review");
    assertEquals(value.metrics, {
        tokens: 25,
        cost_usd: null,
        input_tokens: 12,
        cached_input_tokens: 8,
        output_tokens: 5,
        model_calls: 1,
        peak_context_tokens: 20,
    });
});
Deno.test("each broken receipt rule gives its own fixed code and no receipt", () => {
    for (
        const [receipt, code] of [
            [
                { thread: 99, outcome: "ready_for_review", summary: "Old" },
                "tasks.receipt_thread_mismatch",
            ],
            [
                { outcome: "ready_for_review", summary: "No thread" },
                "tasks.receipt_thread_mismatch",
            ],
            [{
                thread: "14",
                outcome: "ready_for_review",
                summary: "Text thread",
            }, "tasks.receipt_thread_mismatch"],
            [
                { thread: 14, outcome: "approved", summary: "Wrong role" },
                "tasks.receipt_outcome_invalid",
            ],
            [
                { thread: 14, outcome: "finished", summary: "Unknown" },
                "tasks.receipt_outcome_invalid",
            ],
            [
                { thread: 14, outcome: "ready_for_review", summary: " " },
                "tasks.receipt_summary_missing",
            ],
            [
                { thread: 14, outcome: "ready_for_review" },
                "tasks.receipt_summary_missing",
            ],
            [
                { thread: 14, outcome: "blocked", summary: "Missing question" },
                "tasks.receipt_question_missing",
            ],
            [{
                thread: 14,
                outcome: "blocked",
                summary: "Blank",
                question: " ",
            }, "tasks.receipt_question_missing"],
            [{
                thread: 14,
                outcome: "ready_for_review",
                summary: "Done",
                deliverables: { a: 1 },
            }, "tasks.receipt_invalid"],
            [{
                thread: 14,
                outcome: "ready_for_review",
                summary: "Done",
                deliverables: ["a"],
            }, "tasks.receipt_invalid"],
            [
                { thread: 14, outcome: "ready_for_review", summary: 4 },
                "tasks.receipt_invalid",
            ],
            [[], "tasks.receipt_invalid"],
            ["text", "tasks.receipt_invalid"],
        ] as const
    ) {
        const value = withReceipt(receipt);
        assertEquals(
            [value.receipt, value.receipt_error],
            [null, code],
            JSON.stringify(receipt),
        );
        assertEquals(value.state, "done");
    }
});
Deno.test("a valid receipt keeps its public fields and drops unknown ones", () => {
    const value = withReceipt({
        thread: 14,
        outcome: "blocked",
        summary: "Needs a decision",
        question: "Which export format?",
        deliverables: { "export-test": "tests/Feature/ExportTest.php" },
        nonce: "ignored",
    });
    assertEquals(value.receipt_error, null);
    assertEquals(value.receipt, {
        thread: 14,
        outcome: "blocked",
        summary: "Needs a decision",
        question: "Which export format?",
        deliverables: { "export-test": "tests/Feature/ExportTest.php" },
    });
    const consult = withReceipt({
        thread: 14,
        outcome: "answered",
        summary: "Use CSV",
        cause: "scope",
    }, "consult");
    assertEquals(consult.receipt.cause, "scope");
    const approval = {
        thread: 14,
        outcome: "approved",
        summary: "Checked",
        pull_request: {
            summary: "Adds export",
            changes: ["Export command"],
            breaking: [],
        },
    };
    assertEquals(
        withReceipt(approval, "reviewer").receipt.pull_request,
        approval.pull_request,
    );
});
Deno.test("no receipt and an unreadable receipt file are evidence, not failures", () => {
    const none = JSON.parse(
        finish({
            ...result(),
            receipt: JSON.stringify({ receipt: null, error: null }),
        }).result_json,
    );
    assertEquals([none.state, none.receipt, none.receipt_error], [
        "done",
        null,
        null,
    ]);
    const unread = JSON.parse(finish({ ...result(), receipt: "" }).result_json);
    assertEquals([unread.receipt, unread.receipt_error], [null, null]);
    const invalid = JSON.parse(
        finish({
            ...result(),
            receipt: JSON.stringify({
                receipt: null,
                error: "tasks.receipt_invalid",
            }),
        }).result_json,
    );
    assertEquals([invalid.state, invalid.receipt, invalid.receipt_error], [
        "done",
        null,
        "tasks.receipt_invalid",
    ]);
});
Deno.test("the prompt states the receipt file, its thread and the role's outcomes", () => {
    for (
        const [role, outcomes] of [
            ["implementer", "ready_for_review, blocked"],
            ["reviewer", "approved, changes_requested, blocked"],
            ["consult", "answered, blocked"],
        ]
    ) {
        const input = turnInput(role);
        const text = JSON.parse(
            prepare({ operation_id: 15, node_id: 30, intent: input })
                .send_json,
        ).text;
        assertEquals(text.includes("writing .git/orbit/receipt.json"), true);
        assertEquals(
            text.includes("- thread is 14, the thread of this turn."),
            true,
        );
        assertEquals(text.includes(`- outcome is one of: ${outcomes}.`), true);
        assertEquals(
            text.includes("- question is required when outcome is blocked."),
            true,
        );
        assertEquals(text.includes(".git/orbit/turn "), false);
        const example = JSON.parse(text.split("\nExample: ")[1].split("\n")[0]);
        assertEquals(checkReceipt(example, input).error, null);
    }
});
Deno.test("missing completion or failed cleanup produces a failed terminal result", () => {
    for (const overrides of [{ terminal: "" }, { failure: true }]) {
        assertEquals(finish({ ...result(), ...overrides }).successful, false);
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
Deno.test("assembled prompt cannot exceed the existing prompt bound", () => {
    const input = turnInput();
    input.prompt = "x".repeat(100000);
    assertEquals(
        prepare({ operation_id: 15, node_id: 30, intent: input }).error_code,
        "tasks.prompt_too_large",
    );
});

Deno.test("a mismatched worker Node is refused before any placed action", () => {
    assertThrows(
        () => prepare({ operation_id: 15, node_id: 31, intent: turnInput() }),
        Error,
        "tasks.node_mismatch",
    );
});

Deno.test("private worker readers return fixed codes and never quote their input", async () => {
    const dir = await Deno.makeTempDir();
    const path = dir + "/terminal.json";
    const turn = "operation-15";
    const write = (value: unknown) =>
        Deno.writeTextFile(
            path,
            typeof value === "string" ? value : JSON.stringify(value),
        );
    await assertRejects(
        () => terminal({ path, turn }),
        Error,
        "tasks.pi_completion_unconfirmed",
    );
    await write("{private-pi-text");
    const invalid = await assertRejects(() => terminal({ path, turn }), Error);
    assertEquals(invalid.message, "tasks.pi_event_invalid");
    await write({ state: "done", turnId: "operation-14", usage: {} });
    await assertRejects(
        () => terminal({ path, turn }),
        Error,
        "tasks.pi_completion_unconfirmed",
    );
    for (const usage of [{ total: -1 }, { total: "4" }, { total: true }, []]) {
        await write({ state: "done", turnId: turn, usage });
        await assertRejects(
            () => terminal({ path, turn }),
            Error,
            "tasks.pi_usage_invalid",
        );
    }
    await write({
        state: "failed",
        turnId: turn,
        usage: { total: 4, provider_key: "x" },
    });
    assertEquals(JSON.parse(await terminal({ path, turn })), {
        state: "failed",
        turnId: turn,
        usage: { total: 4 },
        transcript: [],
    });
    assertEquals(JSON.parse(await receipt({ path: dir + "/missing.json" })), {
        receipt: null,
        error: null,
    });
    const unreadable = { receipt: null, error: "tasks.receipt_invalid" };
    await Deno.symlink(path, dir + "/link.json");
    assertEquals(
        JSON.parse(await receipt({ path: dir + "/link.json" })),
        unreadable,
    );
    await Deno.writeTextFile(dir + "/big.json", " ".repeat(65537));
    assertEquals(
        JSON.parse(await receipt({ path: dir + "/big.json" })),
        unreadable,
    );
    await Deno.writeTextFile(dir + "/text.json", "{private-receipt-text");
    const text = await receipt({ path: dir + "/text.json" });
    assertEquals(JSON.parse(text), unreadable);
    assertEquals(text.includes("private-receipt-text"), false);
    await Deno.writeTextFile(dir + "/counts", "1\t2\ta\tb\0-\t-\tbinary\0");
    await Deno.writeTextFile(dir + "/patch", "token=private-patch-text\n");
    const shaped = JSON.parse(
        await difference({ counts: dir + "/counts", patch: dir + "/patch" }),
    );
    assertEquals(shaped.files, [
        { path: "a\tb", insertions: 1, deletions: 2 },
        { path: "binary", insertions: 0, deletions: 0 },
    ]);
    assertEquals(shaped.diff, "token=[REDACTED]\n");
    await Deno.remove(dir, { recursive: true });
});

Deno.test("a named turn key replaces the operation key of one Pi message", () => {
    const input = turnInput();
    const named = prepare({
        operation_id: 15,
        node_id: 30,
        intent: input,
        turn_key: "prep-15-fix-1",
    });
    assertEquals(named.turn_key, "prep-15-fix-1");
    assertEquals(JSON.parse(named.send_json).key, "prep-15-fix-1");
    for (const turn_key of [undefined, ""]) {
        const plain = prepare({
            operation_id: 15,
            node_id: 30,
            intent: input,
            turn_key,
        });
        assertEquals(plain.turn_key, "operation-15");
        assertEquals(JSON.parse(plain.send_json).key, "operation-15");
    }
    assertThrows(() =>
        prepare({
            operation_id: 15,
            node_id: 30,
            intent: input,
            turn_key: "a b",
        })
    );
});
Deno.test("a parent run reads the outcome and the commit a finished turn left", () => {
    const moved = "c".repeat(40), newTree = "d".repeat(40);
    const reviewed = (outcome_: string, state = "done") =>
        JSON.stringify({
            state,
            receipt: { outcome: outcome_, summary: "Two findings." },
            before: { head, tree },
            after: { head: moved, tree: newTree },
        });
    assertEquals(outcome({ result_json: reviewed("approved") }), {
        outcome: "approved",
        approved: true,
        summary: "Two findings.",
        moved: true,
        head: moved,
        tree: newTree,
    });
    assertEquals(
        outcome({ result_json: reviewed("changes_requested") }).approved,
        false,
    );
    assertEquals(
        outcome({ result_json: reviewed("approved", "failed") }).approved,
        false,
    );
    const unmoved = outcome({
        result_json: JSON.stringify({
            state: "done",
            receipt: null,
            before: { head, tree },
            after: { head, tree },
        }),
    });
    assertEquals([unmoved.moved, unmoved.outcome, unmoved.head], [
        false,
        "",
        head,
    ]);
    assertEquals(
        outcome({
            result_json: JSON.stringify({
                state: "failed",
                receipt: null,
                before: null,
                after: null,
            }),
        }).head,
        "",
    );
});

Deno.test("a prompt append reaches the Pi message before the receipt instruction", () => {
    const prepared = prepare({
        operation_id: 15,
        node_id: 30,
        intent: turnInput(),
        prompt_append: "Findings: fix the title.",
    });
    const text: string = JSON.parse(prepared.send_json).text;
    assertEquals(
        text.indexOf("Fix the requested task") <
            text.indexOf("Findings: fix the title."),
        true,
    );
    assertEquals(
        text.indexOf("Findings: fix the title.") <
            text.indexOf("End your turn"),
        true,
    );
});
Deno.test("the review loop keeps its state across a review and a fix, and refuses a fix that made no commit", () => {
    const next = "c".repeat(40), nextTree = "d".repeat(40);
    const turn = (
        receipt: Record<string, string> | null,
        after: { head: string; tree: string },
    ) => JSON.stringify({
        state: "done",
        receipt,
        before: { head, tree },
        after,
    });
    const state = { approved: false, head, tree };
    const reviewed = settle({
        ...state,
        review: turn({
            outcome: "changes_requested",
            summary: "Fix the title.",
        }, { head, tree }),
        fix: "",
    });
    assertEquals(reviewed, {
        approved: false,
        head,
        tree,
        findings: "Fix the title.",
    });
    const fixed = settle({
        ...reviewed,
        review: "",
        fix: turn({ outcome: "ready_for_review", summary: "Done." }, {
            head: next,
            tree: nextTree,
        }),
    });
    assertEquals(fixed, {
        approved: false,
        head: next,
        tree: nextTree,
        findings: "",
    });
    assertEquals(
        settle({
            ...fixed,
            review: turn({ outcome: "approved", summary: "Clean." }, {
                head: next,
                tree: nextTree,
            }),
            fix: "",
        }).approved,
        true,
    );
    assertEquals(settle({ ...fixed, review: "", fix: "" }), { ...fixed });
    assertThrows(
        () =>
            settle({
                ...state,
                review: "",
                fix: turn({ outcome: "ready_for_review", summary: "Done." }, {
                    head,
                    tree,
                }),
            }),
        Error,
        "tasks.prep_not_committed",
    );
});

Deno.test("a refused runner plan comes first in the findings and no review approves past it", () => {
    const turn = (receipt: Record<string, string>) =>
        JSON.stringify({
            state: "done",
            receipt,
            before: { head, tree },
            after: { head, tree },
        });
    const base = { approved: false, head, tree, fix: "" };
    const refusal =
        "cases file refused: case x setup[2]: step ufw_delete is not allowed in setup\n";
    const approvedButRefused = settle({
        ...base,
        review: turn({ outcome: "approved", summary: "Clean." }),
        plan_exit: 3,
        plan_output: refusal,
    });
    assertEquals(approvedButRefused.approved, false);
    assertEquals(
        approvedButRefused.findings.includes(
            "step ufw_delete is not allowed in setup",
        ),
        true,
    );
    assertEquals(
        approvedButRefused.findings.startsWith(
            "The runner plan of the cases file was refused (exit 3)",
        ),
        true,
    );
    const both = settle({
        ...base,
        review: turn({
            outcome: "changes_requested",
            summary: "1. Fix the title.",
        }),
        plan_exit: 3,
        plan_output: refusal,
    });
    assertEquals(both.findings.endsWith("1. Fix the title."), true);
    assertEquals(
        both.findings.indexOf("refused") <
            both.findings.indexOf("Fix the title"),
        true,
    );
    // A plan that exited 0, one that did not run, and a round with no plan keep the review as it was.
    for (
        const plan of [
            { plan_exit: 0, plan_output: "ok" },
            { plan_exit: null },
            {},
        ]
    ) {
        const kept = settle({
            ...base,
            review: turn({ outcome: "approved", summary: "Clean." }),
            ...plan,
        });
        assertEquals([kept.approved, kept.findings], [true, "Clean."]);
    }
});

const turnJson = (state: string, receipt: unknown) =>
    JSON.stringify({ state, receipt, before: null, after: null });

Deno.test("a review round is judged from the outcome of the reviewer turn", () => {
    const pass = round({
        result_json: turnJson("done", {
            outcome: "approved",
            summary: "Nothing to fix.",
        }),
        round: 1,
        head,
    });
    assertEquals(pass.verdict, "pass");
    assertEquals(pass.findings, "");
    assertEquals(
        pass.body,
        "**Review round 1** of `aaaaaaa`: **pass**\n\nNothing to fix.",
    );
    const findings = round({
        result_json: turnJson("done", {
            outcome: "changes_requested",
            summary: "docs/a.md: missing case",
        }),
        round: 2,
        head,
    });
    assertEquals(findings.verdict, "findings");
    assertEquals(findings.findings, "docs/a.md: missing case");
    assertEquals(
        findings.body,
        "**Review round 2** of `aaaaaaa`: **findings**\n\ndocs/a.md: missing case",
    );
});

Deno.test("a review round without a finished approving or requesting turn has no verdict", () => {
    for (
        const json of [
            turnJson("failed", { outcome: "approved", summary: "x" }),
            turnJson("done", { outcome: "ready_for_review", summary: "x" }),
            turnJson("done", null),
        ]
    ) {
        const failed = round({ result_json: json, round: 3, head });
        assertEquals(failed.verdict, "failed");
        assertEquals(failed.findings, "");
        assertEquals(
            failed.body.startsWith(
                "**Review round 3** of `aaaaaaa`: **no verdict**",
            ),
            true,
        );
    }
});

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

Deno.test("a review round whose reviewer turn left no result has no verdict", () => {
    const failed = round({ result_json: "", round: 1, head });
    assertEquals(failed.verdict, "failed");
    assertEquals(failed.findings, "");
    assertEquals(
        failed.body,
        "**Review round 1** of `aaaaaaa`: **no verdict**",
    );
});

Deno.test("an absent terminal report omits metrics instead of erasing earlier usage", () => {
    const value = JSON.parse(
        finish({ ...result(), terminal: "", failure: true }).result_json,
    );
    assertEquals(value.state, "failed");
    assertEquals(Object.hasOwn(value, "metrics"), false);
});

Deno.test("terminal shaping preserves nullable cost and omits unavailable token counters", () => {
    for (const cost of [0.75, null]) {
        const value = JSON.parse(
            finish({
                ...result(),
                terminal: JSON.stringify({
                    state: "done",
                    turnId: "operation-15",
                    usage: { total: 140, cost },
                }),
            }).result_json,
        );
        assertEquals(value.metrics, { tokens: 140, cost_usd: cost });
    }
});

Deno.test("the recorded stream produces redacted bounded transcript evidence and priced usage", async () => {
    const dir = await Deno.makeTempDir();
    try {
        const path = dir + "/terminal.json";
        const turn = "operation-15";
        const entry = (id: string, text: string) => ({
            id,
            timestamp: "",
            message: { role: "assistant", content: [{ type: "text", text }] },
        });
        await Deno.writeTextFile(
            path,
            [
                {
                    kind: "snapshot",
                    entries: [entry("old", "x".repeat(270000))],
                },
                {
                    kind: "entry",
                    entry: entry(
                        "new",
                        "Authorization: Bearer private-test-token",
                    ),
                },
                {
                    kind: "state",
                    turnId: turn,
                    state: "done",
                    usage: { total: 140, cost: 0.75 },
                },
            ].map((event) => JSON.stringify(event)).join("\n"),
        );
        const recorded = JSON.parse(await terminal({ path, turn }));
        assertEquals(recorded.usage, { total: 140, cost: 0.75 });
        assertEquals(
            recorded.transcript.map((entry: { id: string }) => entry.id),
            ["orbit-truncated", "new"],
        );
        assertEquals(
            JSON.stringify(recorded).includes("private-test-token"),
            false,
        );
        const value = JSON.parse(
            finish({ ...result(), terminal: JSON.stringify(recorded) })
                .result_json,
        );
        assertEquals(value.transcript, recorded.transcript);
        assertEquals(value.subtask, 11);
    } finally {
        await Deno.remove(dir, { recursive: true });
    }
});
