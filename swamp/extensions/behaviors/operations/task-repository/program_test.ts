import { assertEquals, assertRejects, assertThrows } from "jsr:@std/assert@1";
import { behavior as assets } from "./assets.ts";
import { NativeResult, ScriptInput, ScriptResult } from "./input.ts";
import {
    analysisResult,
    gate,
    prepare,
    redactText,
    report,
    scriptResult,
} from "./program.ts";

const head = "a".repeat(40), tree = "b".repeat(40);
const ssh = {
    target: { user: "orbit", port: 22, host: "192.0.2.3", host_keys: [] },
};
const script = ScriptInput.parse({
    group_id: 8,
    instance_id: 9,
    node_id: 30,
    subtask: 10,
    checkout_path: "/srv/task",
    script: "dependencies",
    args: ["literal; $(nope)", "", " spaces "],
    ssr_port: 4177,
});
const analysis = {
    group_id: 8,
    instance_id: 9,
    node_id: 30,
    subtask: 10,
    checkout_path: "/srv/task",
    ssr_port: null,
    plan: { id: "plan", revision: 2 },
    plan_sha256: "c".repeat(64),
    base: head,
    head,
    tree,
    repository: "https://github.com/acme/test.git",
    branch: "task-8",
    ssh_execution: ssh,
};
const ok = (stdout: string, extra: Record<string, unknown> = {}) => ({
    status: "succeeded",
    outputs: { exitCode: 0, stdout, stderr: "", ...extra },
});
const snapshots = (phase: string, value: { head: string; tree: string }) => ({
    [`snapshot-${phase}-head`]: ok(value.head + "\n"),
    [`snapshot-${phase}-tree`]: ok(value.tree + "\n"),
});
const { execution_id: executionId } = await prepare({
    operation_id: 11,
    node_id: 30,
    kind: "tasks.analyze",
    intent: analysis,
});
const envelope = (fields: Record<string, unknown> = {}) =>
    JSON.stringify({
        schema: 1,
        execution_id: executionId,
        plan_sha256: analysis.plan_sha256,
        base: head,
        head,
        tree,
        status: "ready",
        diagnostics: [],
        report: { schema: "test.v1", data: {} },
        ...fields,
    });
const identity = {
    execution_id: executionId,
    plan_sha256: analysis.plan_sha256,
    base: head,
    head,
    tree,
};

Deno.test("prepare builds one literal argv in the workspace and no shell", async () => {
    const value = await prepare({
        operation_id: 11,
        node_id: 30,
        kind: "tasks.script",
        intent: { ...script, ssh_execution: ssh },
    });
    assertEquals(value.argv, [
        "env",
        "-C",
        "/srv/task",
        "COMPOSER_NO_INTERACTION=1",
        "ORBIT_CHECK_PROCESSES=1",
        "INERTIA_SSR_PORT=4177",
        "INERTIA_SSR_URL=http://127.0.0.1:4177",
        "./dependencies",
        "literal; $(nope)",
        "",
        " spaces ",
    ]);
    assertEquals(value.entrypoint, "dependencies");
    assertEquals(value.stdin, "");
});

Deno.test("prepare gives the analyzer its admitted plan and stable execution identity", async () => {
    const value = await prepare({
        operation_id: 11,
        node_id: 30,
        kind: "tasks.analyze",
        intent: analysis,
    });
    assertEquals(value.argv.slice(-1), ["./bin/task-analyze"]);
    assertEquals(value.execution_id.length, 32);
    assertEquals(JSON.parse(value.stdin), {
        plan: analysis.plan,
        plan_sha256: analysis.plan_sha256,
        base: head,
        head,
        tree,
        execution_id: value.execution_id,
    });
});

Deno.test("prepare refuses a different admitted Node", async () => {
    await assertRejects(
        () =>
            prepare({
                operation_id: 11,
                node_id: 31,
                kind: "tasks.script",
                intent: script,
            }),
        Error,
        "Wrong task VM Node",
    );
});

Deno.test("gate refuses an entrypoint that is not tracked as executable at HEAD", () => {
    const before = snapshots("before", { head, tree });
    for (
        const stdout of [
            "",
            "100644 blob 1234\tdependencies\n",
            "120000 blob 1234\tdependencies\n",
            "040000 tree 1234\tdependencies\n",
        ]
    ) {
        assertEquals(
            gate({
                kind: "tasks.script",
                intent: script,
                entrypoint: "dependencies",
                steps: { ...before, inspect: ok(stdout) },
            }).error_code,
            "tasks.script_unavailable",
        );
    }
    assertEquals(
        gate({
            kind: "tasks.script",
            intent: script,
            entrypoint: "dependencies",
            steps: {
                ...before,
                inspect: ok("100755 blob 1234\tdependencies\n"),
            },
        }).error_code,
        "",
    );
});

Deno.test("gate refuses a missing analyzer as unavailable and a moved candidate as stale", () => {
    const inspect = ok("100755 blob 1234\tbin/task-analyze\n");
    const digest = ok("d".repeat(64) + "  /srv/task/bin/task-analyze\n");
    const run = (steps: Record<string, unknown>) =>
        gate({
            kind: "tasks.analyze",
            intent: analysis,
            entrypoint: "bin/task-analyze",
            steps,
        });
    const clean = snapshots("before", { head, tree });
    assertEquals(run({ ...clean, inspect, entrypoint: digest }), {
        error_code: "",
        entrypoint_sha256: "d".repeat(64),
        before: { head, tree },
    });
    assertEquals(
        run({
            ...clean,
            inspect,
            entrypoint: { outputs: { exitCode: 1, stdout: "" } },
        }).error_code,
        "tasks.analysis_unavailable",
    );
    assertEquals(
        run({ ...clean, inspect: ok(""), entrypoint: digest }).error_code,
        "tasks.analysis_unavailable",
    );
    assertEquals(
        run({
            ...snapshots("before", { head, tree: "f".repeat(40) }),
            inspect,
            entrypoint: digest,
        }).error_code,
        "tasks.analysis_stale",
    );
});

Deno.test("script output is a redacted 16 KiB tail of stdout then stderr", () => {
    const stdout = "line\n".repeat(40000) +
        "Authorization: Bearer hidden-value\nDB_PASSWORD=hidden-password\n";
    const value: Record<string, unknown> = scriptResult({
        gate: { error_code: "", before: { head, tree } },
        steps: {
            ...snapshots("after", { head, tree: "c".repeat(40) }),
            execute: {
                outputs: {
                    exitCode: 23,
                    stdout,
                    stderr: "API_KEY=hidden-key\n",
                },
            },
        },
    });
    const parsed = ScriptResult.parse(value);
    assertEquals("error_code" in parsed, false);
    assertEquals(value.outcome, "failed");
    assertEquals(value.exit_code, 23);
    assertEquals(value.output_truncated, true);
    assertEquals(value.changed, true);
    assertEquals(
        new TextEncoder().encode(String(value.output)).length <= 16384,
        true,
    );
    assertEquals(String(value.output).includes("hidden-"), false);
    assertEquals(String(value.output).endsWith("API_KEY=[REDACTED]\n"), true);
});

Deno.test("a refused gate is the script result", () => {
    assertEquals(
        scriptResult({
            gate: { error_code: "tasks.script_unavailable" },
            steps: {},
        }),
        { error_code: "tasks.script_unavailable" },
    );
});

Deno.test("redaction keeps public text and hides credentials", () => {
    assertEquals(redactText("plain output\n"), "plain output\n");
    assertEquals(
        redactText("https://user:pw@example.test/x token=abc"),
        "https://[REDACTED]@example.test/x token=[REDACTED]",
    );
    assertEquals(
        redactText(
            "-----BEGIN PRIVATE KEY-----\nabc\n-----END PRIVATE KEY-----\nok",
        ),
        "[REDACTED]\nok",
    );
    assertEquals(redactText("ghp_" + "a".repeat(20)), "[REDACTED]");
});

Deno.test("strict reports reject duplicate, trailing, nonfinite, deep, foreign and private data", () => {
    const raw = envelope();
    assertEquals(report(raw, identity, 0).status, "ready");
    for (
        const value of [
            raw.replace('"schema":1', '"schema":2,"schema":1'),
            raw + " trailing",
            raw.replace('"data":{}', '"data":{"x":1e999}'),
            raw.replace('"data":{}', '"data":{"x":NaN}'),
            raw.replace(
                '"data":{}',
                '"data":{"x":' + "[".repeat(25) + "1" + "]".repeat(25) + "}",
            ),
            raw.replace('"data":{}', '"data":{"x":1,"x":2}'),
            raw.replace(`"head":"${head}"`, `"head":"${"f".repeat(40)}"`),
            raw.replace('"data":{}', '"data":{"API_KEY":"sensitive-value"}'),
            raw.replace(
                '"data":{}',
                '"data":{"API\\u005fKEY":"sensitive-value"}',
            ),
            raw.replace('"schema":1', '"schema":true'),
            raw.replace('"status":"ready"', '"status":"changed"'),
        ]
    ) {
        assertThrows(() => report(value, identity, 0));
    }
});

Deno.test("the exact size boundary and closed failure metadata", () => {
    const value = JSON.parse(envelope());
    value.report.data.text = "";
    value.report.data.text = "x".repeat(65536 - JSON.stringify(value).length);
    const raw = JSON.stringify(value);
    assertEquals(raw.length, 65536);
    assertEquals(report(raw, identity, 0).status, "ready");
    assertEquals(
        report(raw + " ", identity, 0).error_code,
        "tasks.analysis_report_too_large",
    );
    const failure = (size: unknown) =>
        JSON.stringify({
            schema: 1,
            error: {
                code: "tasks.analysis_report_too_large",
                required_bytes: size,
            },
        });
    assertEquals(
        report(failure(70000), identity, 1).error_code,
        "tasks.analysis_report_too_large",
    );
    for (
        const size of [
            null,
            true,
            "70000",
            70000.5,
            -1,
            65536,
            9007199254740992,
        ]
    ) {
        assertThrows(() => report(failure(size), identity, 1));
    }
    assertThrows(() => report(failure(70000), identity, 0));
});

Deno.test("analysis keeps the complete artifact and native provenance from the SSH exec", async () => {
    const raw = envelope({
        report: { schema: "test.v1", data: { complete: "é".repeat(6000) } },
    }) + "\n";
    const result = await analysisResult({
        operation_id: 11,
        intent: analysis,
        gate: {
            error_code: "",
            entrypoint_sha256: "d".repeat(64),
            before: { head, tree },
        },
        execution_id: executionId,
        steps: {
            ...snapshots("after", { head, tree }),
            execute: ok(raw, {
                startedAt: "2026-11-02T10:00:00.000Z",
                finishedAt: "2026-11-02T10:00:01.250Z",
                durationMs: 1250,
            }),
        },
    });
    NativeResult.parse(result);
    const value = result as Record<string, Record<string, unknown>>;
    assertEquals(value.outcome as unknown, "ready");
    assertEquals(value.artifact.content, raw);
    assertEquals(value.artifact.bytes, new TextEncoder().encode(raw).length);
    assertEquals(value.provenance.started_at, "2026-11-02T10:00:00.000Z");
    assertEquals(value.provenance.finished_at, "2026-11-02T10:00:01.250Z");
    assertEquals(value.provenance.duration_ms, 1250);
    assertEquals(value.provenance.entrypoint_sha256, "d".repeat(64));
    assertEquals(value.provenance.mechanical_llm_calls, 0);
});

Deno.test("analysis movement is changed; a failed run with no movement is analysis_failed", async () => {
    const shape = (exitCode: number, after: { head: string; tree: string }) =>
        analysisResult({
            operation_id: 11,
            intent: analysis,
            gate: {
                error_code: "",
                entrypoint_sha256: "d".repeat(64),
                before: { head, tree },
            },
            execution_id: executionId,
            steps: {
                ...snapshots("after", after),
                execute: ok(envelope(), { exitCode }),
            },
        });
    assertEquals(
        ((await shape(0, { head, tree: "c".repeat(40) })) as {
            outcome: string;
        }).outcome,
        "changed",
    );
    assertEquals(await shape(1, { head, tree }), {
        error_code: "tasks.analysis_failed",
    });
    assertEquals(
        await analysisResult({
            operation_id: 11,
            intent: analysis,
            gate: {
                error_code: "",
                entrypoint_sha256: "d".repeat(64),
                before: { head, tree },
            },
            execution_id: executionId,
            steps: {
                ...snapshots("after", { head, tree }),
                execute: ok('{"API_KEY":"x"}'),
            },
        }),
        { error_code: "tasks.analysis_report_invalid" },
    );
});

Deno.test("teardown assets resolve the shipped scripts directory", async () => {
    let value: Record<string, unknown> = {};
    await assets.methods.read.execute({
        operation_id: 11,
        node_id: 30,
        kind: "tasks.cancel",
        intent: { group_id: 8 },
    }, {
        extensionFile: (name) => "/native/bundle/" + name,
        writeResource: (_spec, _name, record) => {
            value = record as Record<string, unknown>;
            return Promise.resolve({ name: "assets" });
        },
    });
    assertEquals(value, {
        directory: "/native/bundle/scripts",
        input_json: JSON.stringify({ group_id: 8, operation_id: 11 }),
    });
});
