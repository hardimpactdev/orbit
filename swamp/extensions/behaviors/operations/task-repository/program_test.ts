import { assertEquals, assertRejects } from "jsr:@std/assert@1";
import { behavior as assets } from "./assets.ts";
import { ScriptInput, ScriptResult } from "./input.ts";
import { gate, prepare, scriptResult } from "./program.ts";

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
const ok = (stdout: string, extra: Record<string, unknown> = {}) => ({
    status: "succeeded",
    outputs: { exitCode: 0, stdout, stderr: "", ...extra },
});
const snapshots = (phase: string, value: { head: string; tree: string }) => ({
    [`snapshot-${phase}-head`]: ok(value.head + "\n"),
    [`snapshot-${phase}-tree`]: ok(value.tree + "\n"),
});
/** A private worker directory holding one downloaded output file. */
async function downloaded(name: string, content: string): Promise<string> {
    const directory = await Deno.makeTempDir();
    await Deno.writeTextFile(`${directory}/${name}`, content);
    return directory;
}
Deno.test("prepare builds one literal argv in the workspace with its output in a file", async () => {
    const value = await prepare({
        operation_id: 11,
        node_id: 30,
        kind: "tasks.script",
        intent: { ...script, ssh_execution: ssh },
        root: "/tmp/run",
    });
    // The fixed shell text only redirects; the command stays literal argv.
    assertEquals(value.argv.slice(0, 6), [
        "/bin/bash",
        "-c",
        'mkdir -p -m 0700 -- "$1" && exec >"$1/$2" 2>&1 && shift 2 && exec "$@"',
        "orbit-output",
        "/tmp/run",
        "output",
    ]);
    assertEquals(value.argv.slice(6), [
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

Deno.test("prepare refuses a different admitted Node", async () => {
    await assertRejects(
        () =>
            prepare({
                operation_id: 11,
                node_id: 31,
                kind: "tasks.script",
                intent: script,
                root: "/tmp/run",
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

Deno.test("script output is a redacted 16 KiB tail of the downloaded file, which is deleted", async () => {
    const local = await downloaded(
        "output",
        "line\n".repeat(40000) +
            "Authorization: Bearer hidden-value\nDB_PASSWORD=hidden-password\n" +
            "API_KEY=hidden-key\n",
    );
    const value: Record<string, unknown> = await scriptResult({
        gate: { error_code: "", before: { head, tree } },
        steps: {
            ...snapshots("after", { head, tree: "c".repeat(40) }),
            execute: { outputs: { exitCode: 23, stdout: "", stderr: "" } },
        },
        local,
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
    assertEquals([...Deno.readDirSync(local)], []);
});

Deno.test("a script that ran without a downloaded output file is unknown", async () => {
    assertEquals(
        await scriptResult({
            gate: { error_code: "", before: { head, tree } },
            steps: {
                ...snapshots("after", { head, tree }),
                execute: { outputs: { exitCode: 0, stdout: "" } },
            },
            local: await Deno.makeTempDir(),
        }),
        { error_code: "tasks.repository_execution_unknown" },
    );
});

Deno.test("a refused gate is the script result", async () => {
    assertEquals(
        await scriptResult({
            gate: { error_code: "tasks.script_unavailable" },
            steps: {},
            local: "/nonexistent",
        }),
        { error_code: "tasks.script_unavailable" },
    );
});

Deno.test("teardown assets record one SSH exec argv per teardown command", async () => {
    let value: Record<string, unknown> = {};
    await assets.methods.read.execute({
        operation_id: 11,
        node_id: 30,
        kind: "tasks.cancel",
        intent: {
            group_id: 8,
            checkout_path: "/home/orbit/apps/proof/workspace",
            ssh_execution: { target: { user: "orbit" } },
            teardown: [{
                input_json: JSON.stringify({ command: "rm -f db.sqlite" }),
                timeout_seconds: 30,
            }],
        },
        root: "/tmp/teardown",
    }, {
        writeResource: (_spec, _name, record) => {
            value = record as Record<string, unknown>;
            return Promise.resolve({ name: "assets" });
        },
    });
    assertEquals(value, {
        teardown: [[
            "/bin/bash",
            "-c",
            'mkdir -p -m 0700 -- "$1" && exec >"$1/$2" 2>&1 && shift 2 && exec "$@"',
            "orbit-output",
            "/tmp/teardown",
            "teardown-0",
            "env",
            "-C",
            "/home/orbit/apps/proof/workspace",
            "COMPOSER_NO_INTERACTION=1",
            "ORBIT_CHECK_PROCESSES=1",
            "COMPOSER_CACHE_DIR=/home/orbit/.cache/composer",
            "ORBIT_SEED_PATH=",
            "ORBIT_SEED_COMMIT=",
            "/bin/bash",
            "-lc",
            "rm -f db.sqlite",
        ]],
    });
});
