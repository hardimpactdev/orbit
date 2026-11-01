import { assertEquals, assertRejects } from "jsr:@std/assert@1";
import {
    behavior,
    candidateResult,
    ciResult,
    localResult,
    setupResult,
} from "./check.ts";
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

Deno.test("a failed Project check retains failed evidence when command deliverables are skipped", () => {
    const observation = (value: unknown) => ({
        status: "succeeded",
        outputs: { stdout: JSON.stringify(value) },
    });
    const value = localResult({
        ...input,
        phase: "handoff",
        command: "exit 23",
        deliverables: [{
            id: "project-check",
            type: "command",
            path: "",
            change: "any",
            command: "touch never",
            directory: ".",
            fails_on_base: false,
            paths: [],
        }],
    }, {
        original: observation({ head: commit }),
        before: observation({ head: commit, tree: commit }),
        after: observation({ head: commit, tree: commit }),
        project: observation({ exit_code: 23, output: "project check failed" }),
        "deliverable-0": { status: "skipped" },
        diff: observation({ diff: [] }),
        changed: observation({ diff: [] }),
    });
    if (!("outcome" in value)) {
        throw new Error("Expected public local check evidence");
    }
    assertEquals(value.outcome, "failed");
    assertEquals(value.exit_code, 23);
    assertEquals(value.evidence?.commands, {});
    assertEquals(value.deliverables[0].outcome, "failed");
    assertEquals(value.commit, null);
});

function activeCheckContext(active: Record<string, unknown> | null) {
    const writes: { spec: string; name: string; value: unknown }[] = [];
    return {
        writes,
        context: {
            extensionFile(name: string) {
                return name;
            },
            readResource(name: string) {
                assertEquals(name, "active");
                return Promise.resolve(active);
            },
            writeResource(spec: string, name: string, value: unknown) {
                writes.push({ spec, name, value });
                return Promise.resolve({});
            },
        },
    };
}

Deno.test("pending admission refuses an absent load-time seed without losing the handoff", async () => {
    const fixture = activeCheckContext(null);
    await assertRejects(
        () => behavior.methods.pending.execute(pending, fixture.context),
        Error,
        "tasks.check_active_uninitialized",
    );
    assertEquals(fixture.writes, []);
});

Deno.test("pending admission merges with the current active record and preserves other Operations", async () => {
    const other = {
        ...pending,
        inputs: { ...pending.inputs, operation_id: 8 },
    };
    const fixture = activeCheckContext({ schema: 1, entries: [other] });
    await behavior.methods.pending.execute(pending, fixture.context);
    const active = fixture.writes.find((item) => item.name === "active");
    assertEquals(active?.spec, "active");
    assertEquals(
        (active?.value as { entries: typeof pending[] }).entries.map((row) =>
            row.inputs.operation_id
        ),
        [8, 7],
    );
});

Deno.test("a repeated admission cannot overwrite a different recorded root handoff", async () => {
    const previous = {
        ...pending,
        root_run_id: "ad94b7fa-e19a-456a-8610-d0b6a00ed89e",
    };
    const fixture = activeCheckContext({ schema: 1, entries: [previous] });
    await assertRejects(
        () => behavior.methods.pending.execute(pending, fixture.context),
        Error,
        "tasks.check_handoff_changed",
    );
    assertEquals(fixture.writes, []);
});

Deno.test("load initialization is explicit and cannot overwrite an existing active record", async () => {
    const fresh = activeCheckContext(null);
    await behavior.methods.initialize.execute({ checks: [] }, fresh.context);
    assertEquals(fresh.writes, [{
        spec: "active",
        name: "active",
        value: { schema: 1, entries: [] },
    }]);
    const current = activeCheckContext({ schema: 1, entries: [pending] });
    await assertRejects(
        () =>
            behavior.methods.initialize.execute(
                { checks: [] },
                current.context,
            ),
        Error,
        "tasks.check_active_initialized",
    );
    assertEquals(current.writes, []);
});

Deno.test("snapshot refuses missing initialization instead of silently dispatching an empty queue", async () => {
    const current = activeCheckContext(null);
    await assertRejects(
        () => behavior.methods.snapshot.execute({}, current.context),
        Error,
        "tasks.check_active_uninitialized",
    );
    assertEquals(current.writes, []);
});

Deno.test("replayed admission keeps a newer CI observation on the same root and commit", async () => {
    const current = activeCheckContext({
        schema: 1,
        entries: [{
            ...pending,
            result: { ...pending.result, ci: { state: "running" } },
        }],
    });
    await behavior.methods.pending.execute(pending, current.context);
    assertEquals(current.writes.some((row) => row.name === "active"), false);
});

Deno.test("aggregate settlement preserves intervening admissions, changed roots and pending observations", async () => {
    const changed = {
        ...pending,
        inputs: { ...pending.inputs, operation_id: 8 },
        root_run_id: "ad94b7fa-e19a-456a-8610-d0b6a00ed89e",
    };
    const admitted = {
        ...pending,
        inputs: { ...pending.inputs, operation_id: 9 },
    };
    const waiting = {
        ...pending,
        inputs: { ...pending.inputs, operation_id: 10 },
    };
    const current = activeCheckContext({
        schema: 1,
        entries: [pending, changed, admitted, waiting],
    });
    const complete = { ...pending, pending: false, status: "succeeded" };
    await behavior.methods.settle.execute({
        values: [complete, { ...complete, inputs: changed.inputs }, {
            ...waiting,
            status: "succeeded",
            result: { ...waiting.result, ci: { state: "running" } },
        }],
    }, current.context);
    const state = current.writes.find((row) => row.name === "active")!
        .value as { entries: typeof pending[] };
    assertEquals(state.entries.map((row) => row.inputs.operation_id), [
        8,
        9,
        10,
    ]);
    assertEquals(state.entries[0], changed);
    assertEquals(state.entries[1], admitted);
    assertEquals((state.entries[2].result as Record<string, unknown>).ci, {
        state: "running",
    });
    const value = current.writes.find((row) => row.spec === "public")!
        .value as { result_json: string };
    assertEquals(
        JSON.parse(value.result_json).checks.map((
            row: { operation_id: number },
        ) => row.operation_id),
        [7, 10],
    );
});

Deno.test("an over-capacity admission leaves the current active record intact", async () => {
    const current = activeCheckContext({
        schema: 1,
        entries: Array.from(
            { length: 128 },
            (_, i) => ({
                ...pending,
                inputs: { ...pending.inputs, operation_id: i + 1 },
            }),
        ),
    });
    await assertRejects(
        () =>
            behavior.methods.pending.execute({
                ...pending,
                inputs: { ...pending.inputs, operation_id: 1000 },
            }, current.context),
        Error,
    );
    assertEquals(current.writes, []);
});

Deno.test("an oversized active record refuses dispatch instead of building a large worker frame", async () => {
    const current = activeCheckContext({
        schema: 1,
        entries: [{ ...pending, output: "x".repeat(8 * 1024 * 1024) }],
    });
    await assertRejects(
        () => behavior.methods.snapshot.execute({}, current.context),
        Error,
        "tasks.check_active_capacity",
    );
    assertEquals(current.writes, []);
});
