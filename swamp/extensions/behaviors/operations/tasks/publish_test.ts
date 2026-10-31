import { assertEquals, assertThrows } from "jsr:@std/assert@1";
import { finish, prepare, PublishInput, verify } from "./publish.ts";

export function publishInput(withPr = true) {
    return PublishInput.parse({
        group_id: 1,
        instance_id: 2,
        node_id: 3,
        check: 4,
        checkout_path: "/srv/workspace",
        commit: "a".repeat(40),
        tree: "b".repeat(40),
        repository: "acme/shop",
        base: "main",
        branch: "task-1",
        pull_request: withPr
            ? {
                title: "Export orders",
                body: "Adds the export. $(touch inert)",
            }
            : null,
    });
}
function result(withPr = true) {
    const input = publishInput(withPr);
    return {
        input,
        before: JSON.stringify({ head: input.commit, tree: input.tree }),
        after: JSON.stringify({ head: input.commit, tree: input.tree }),
        exported: '{"exported":true}',
        imported: '{"imported":true}',
        lookup: '{"number":null}',
        tip: '{"commit":null}',
        push: '{"pushed":true}',
        pull: withPr ? '{"number":12}' : "",
        failure: false,
    };
}
Deno.test("publication preserves the admitted candidate and caller PR prose as inert JSON", () => {
    const input = publishInput();
    const value = JSON.parse(prepare({ intent: input, node_id: 3 }).input_json);
    assertEquals(value, input);
    assertThrows(() => prepare({ intent: input, node_id: 4 }), Error);
    assertThrows(
        () => prepare({ intent: { ...input, branch: "task-2" }, node_id: 3 }),
        Error,
    );
});
Deno.test("a moved checked tree is refused before publication", () => {
    const input = publishInput();
    assertEquals(
        verify({
            input,
            value: JSON.stringify({ head: input.commit, tree: input.tree }),
        }).admitted,
        true,
    );
    assertEquals(
        verify({
            input,
            value: JSON.stringify({ head: input.commit, tree: "f".repeat(40) }),
        }).admitted,
        false,
    );
});
Deno.test("terminal publication returns the existing public PR result and push-only null fields", () => {
    assertEquals(JSON.parse(finish(result()).result_json), {
        branch: "task-1",
        commit: "a".repeat(40),
        pull_request_number: 12,
        pull_request_url: "https://github.com/acme/shop/pull/12",
    });
    assertEquals(
        JSON.parse(finish(result(false)).result_json).pull_request_url,
        null,
    );
});
Deno.test("identical remote tips need no second push", () => {
    const args = result();
    args.tip = JSON.stringify({ commit: args.input.commit });
    args.push = "";
    assertEquals(
        JSON.parse(finish(args).result_json).commit,
        args.input.commit,
    );
});
Deno.test("public refusal codes survive export, push and PR failures", () => {
    for (
        const [field, error_code] of [
            ["exported", "tasks.repository_execution_unknown"],
            ["push", "tasks.not_fast_forward"],
            ["lookup", "tasks.head_moved"],
            ["pull", "tasks.publish_failed"],
        ]
    ) {
        assertEquals(
            JSON.parse(
                finish({ ...result(), [field]: JSON.stringify({ error_code }) })
                    .result_json,
            ),
            { error_code },
        );
    }
});
Deno.test("missing completion proof never becomes successful publication", () => {
    assertEquals(
        JSON.parse(finish({ ...result(), pull: "" }).result_json).error_code,
        "tasks.publish_failed",
    );
    assertEquals(
        JSON.parse(finish({ ...result(), failure: true }).result_json)
            .error_code,
        "tasks.publish_failed",
    );
});
