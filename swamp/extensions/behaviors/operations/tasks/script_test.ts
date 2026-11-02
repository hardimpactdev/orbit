import { assertEquals, assertRejects } from "jsr:@std/assert@1";
import { behavior } from "../task-repository/assets.ts";
import { ScriptInput, ScriptResult } from "../task-repository/input.ts";

const input = ScriptInput.parse({
    group_id: 8,
    instance_id: 9,
    node_id: 30,
    subtask: 10,
    checkout_path: "/srv/task",
    script: "scripts/dependencies",
    args: ["literal; $(nope)", "", " spaces "],
    ssr_port: 4177,
});
Deno.test("repository assets carry literal public intent and native file metadata", async () => {
    let value: Record<string, unknown> = {};
    await behavior.methods.read.execute({
        operation_id: 11,
        node_id: 30,
        kind: "tasks.script",
        intent: input,
    }, {
        extensionFile: (name) => "/native/bundle/" + name,
        writeResource: (_spec, _name, record) => {
            value = record as Record<string, unknown>;
            return Promise.resolve({ name: "assets" });
        },
    });
    assertEquals(JSON.parse(String(value.input_json)), {
        ...input,
        operation_id: 11,
    });
    assertEquals(value.directory, "/native/bundle/scripts");
    assertEquals(
        value.result_script,
        "/native/bundle/scripts/script_result.py",
    );
});
Deno.test("repository metadata refuses a different admitted Node before exposing actions", async () => {
    await assertRejects(
        () =>
            behavior.methods.read.execute({
                operation_id: 11,
                node_id: 31,
                kind: "tasks.script",
                intent: input,
            }, {
                extensionFile: () => {
                    throw new Error("not reached");
                },
                writeResource: () => Promise.reject(new Error("not reached")),
            }),
        Error,
        "Wrong task VM Node",
    );
});
Deno.test("nonzero script results remain typed business failures", () => {
    const result = ScriptResult.parse({
        outcome: "failed",
        exit_code: 23,
        output: "Done",
        output_truncated: false,
        changed: false,
        head: "a".repeat(40),
        tree: "b".repeat(40),
    });
    assertEquals("error_code" in result, false);
});
