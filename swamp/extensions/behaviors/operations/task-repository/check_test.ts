import { assertEquals } from "jsr:@std/assert@1";
import { candidateResult, ciResult, setupResult } from "./check.ts";
const commit = "a".repeat(40), start = "2026-10-31T22:00:00Z";
const input = {
    group_id: 1,
    instance_id: 2,
    node_id: 3,
    subtask: 4,
    check_id: 5,
    checkout_path: "/srv/check",
    ssr_port: null,
    phase: "handoff",
    command: null,
    setup: [],
    deliverables: [],
    base: commit,
    message: "Check",
    repository: "acme/check",
    check_name: "Required checks",
    branch: "task-1-handoff",
};
const pending = {
    inputs: { operation_id: 7, node_id: 3, kind: "tasks.check", intent: input },
    root_run_id: "a4944b1e-a6d9-4a8b-8ec0-c065b4a50caa",
    admitted_at: start,
    pushed_at: start,
    pending: true,
    result: {
        outcome: "passed",
        check_id: 5,
        commit,
        tree: "b".repeat(40),
        failed_step: null,
    },
};
const run = {
    id: 1,
    name: "Required checks",
    head_sha: commit,
    status: "completed",
    conclusion: "success",
    app: { slug: "github-actions" },
};
Deno.test("one pending observation remains pending and a later tick can finish the exact SHA", () => {
    const active = ciResult(pending, {
        runs: [{ ...run, status: "in_progress", conclusion: null }],
    }, "2026-10-31T22:01:00Z");
    assertEquals(active.pending, true);
    const passed = ciResult(active, { runs: [run] }, "2026-10-31T22:02:00Z");
    assertEquals(passed.pending, false);
    assertEquals(passed.status, "succeeded");
    assertEquals(passed.result.ci.commit, commit);
});
Deno.test("a named code failure finishes as failed evidence while unreadable CI refuses", () => {
    const failed = ciResult(pending, {
        runs: [{ ...run, conclusion: "failure" }],
    }, "2026-10-31T22:01:00Z");
    assertEquals(failed.pending, false);
    assertEquals(failed.result.outcome, "failed");
    assertEquals(failed.result.failed_step, "ci");
    const unavailable = ciResult(pending, {
        error_code: "tasks.handoff_ci_unavailable",
    }, "2026-10-31T22:01:00Z");
    assertEquals(unavailable.pending, false);
    assertEquals(unavailable.status, "failed");
});
Deno.test("missing CI and credential lifetime end through later scheduled observations", () => {
    assertEquals(
        ciResult(pending, { runs: [] }, "2026-10-31T22:01:00Z").pending,
        true,
    );
    assertEquals(
        ciResult(pending, { runs: [] }, "2026-10-31T22:11:00Z").pending,
        false,
    );
    const expired = ciResult(pending, {
        runs: [{ ...run, status: "in_progress", conclusion: null }],
    }, "2026-10-31T22:56:00Z");
    assertEquals(expired.pending, false);
    assertEquals(expired.status, "failed");
});
Deno.test("a committed candidate must keep the checked tree", () => {
    assertEquals(
        candidateResult({ outcome: "passed", tree: "b".repeat(40) }, {
            commit,
            tree: "c".repeat(40),
        }).outcome,
        "changed",
    );
});

Deno.test("the hosted CI window starts at push after long local execution", () => {
    const longLocal = {
        ...pending,
        admitted_at: "2026-10-31T20:00:00Z",
        pushed_at: "2026-10-31T22:00:00Z",
    };
    const value = ciResult(longLocal, {
        runs: [{ ...run, status: "in_progress", conclusion: null }],
    }, "2026-10-31T22:01:00Z");
    assertEquals(value.pending, true);
});

Deno.test("missing setup output is a failed preparation", () => {
    const setup = setupResult({
        ...input,
        phase: "baseline",
        setup: [{ name: "prepare", command: "true", timeout_seconds: 30 }],
    }, {});
    assertEquals(setup.passed, false);
    assertEquals(setup.exit_code, 255);
});
