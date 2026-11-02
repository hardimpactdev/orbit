import { assertEquals, assertThrows } from "jsr:@std/assert@1";
import {
    finish,
    imported,
    prepare,
    PublishInput,
    pullPlan,
    pushed,
    verify,
} from "./publish.ts";

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
const raw = (exitCode: number, stdout = "", stderr = "") => ({
    exitCode,
    stdout,
    stderr,
});
const out = (exitCode: number, stdout = "", stderr = "") => ({
    outputs: raw(exitCode, stdout, stderr),
});
const open = (sha = "a".repeat(40)) => ({
    number: 12,
    state: "open",
    base: { ref: "main" },
    head: { ref: "task-1", sha, repo: { full_name: "acme/shop" } },
});
function steps(withPr = true): Record<string, unknown> {
    const input = publishInput(withPr);
    return {
        "snapshot-tree": out(0, input.tree + "\n"),
        "snapshot-head": out(0, input.commit + "\n"),
        import: out(0, `${input.commit}\n${input.tree}\n`),
        push: out(0, "To https://github.com/acme/shop.git\n*\tdone\n"),
        ...(withPr
            ? {
                read_pull: out(0, "[]"),
                write_pull: out(0, JSON.stringify({ number: 12 })),
            }
            : {}),
    };
}
const result = (input: unknown, values: Record<string, unknown>) =>
    JSON.parse(finish({ input, steps: values }).result_json);

Deno.test("prepare builds the push target and PR head and refuses a wrong target", () => {
    const input = publishInput();
    assertEquals(prepare({ intent: input, node_id: 3 }), {
        remote: "https://github.com/acme/shop.git",
        refspec: `${"a".repeat(40)}:refs/heads/task-1`,
        object_format: "sha1",
        pull_head: "acme:task-1",
    });
    assertEquals(
        prepare({
            intent: { ...input, commit: "c".repeat(64) },
            node_id: 3,
        }).object_format,
        "sha256",
    );
    assertThrows(() => prepare({ intent: input, node_id: 4 }), Error);
    assertThrows(
        () => prepare({ intent: { ...input, branch: "task-2" }, node_id: 3 }),
        Error,
    );
});
Deno.test("a moved HEAD or working tree is refused before export", () => {
    const input = publishInput();
    assertEquals(verify({ input, steps: steps() }), {
        admitted: true,
        error_code: "",
    });
    assertEquals(
        verify({
            input,
            steps: { ...steps(), "snapshot-tree": out(0, "f".repeat(40)) },
        }),
        { admitted: false, error_code: "tasks.head_moved" },
    );
    assertEquals(
        verify({ input, steps: { ...steps(), "snapshot-head": out(128) } })
            .admitted,
        false,
    );
});
Deno.test("the imported bundle must hold exactly the admitted commit and tree", () => {
    const input = publishInput();
    assertEquals(
        imported(
            raw(0, `${input.commit}\n${input.tree}\n`),
            input.commit,
            input.tree,
        ),
        { imported: true, error_code: "" },
    );
    assertEquals(
        imported(
            raw(0, `${"f".repeat(40)}\n${input.tree}\n`),
            input.commit,
            input.tree,
        )
            .error_code,
        "tasks.head_moved",
    );
    assertEquals(
        imported(raw(128), input.commit, input.tree).error_code,
        "tasks.repository_execution_unknown",
    );
});
Deno.test("push refusals keep their public codes", () => {
    assertEquals(pushed(raw(0)), { pushed: true, error_code: "" });
    assertEquals(
        pushed(raw(1, "!\trefs/heads/task-1\t[rejected] (fetch first)"))
            .error_code,
        "tasks.not_fast_forward",
    );
    assertEquals(
        pushed(raw(1, "", " ! [rejected] task-1 (non-fast-forward)"))
            .error_code,
        "tasks.not_fast_forward",
    );
    assertEquals(
        pushed(
            raw(
                1,
                "",
                "refusing to allow a GitHub App to create or update workflow `.github/workflows/ci.yml`",
            ),
        ).error_code,
        "tasks.workflows_permission",
    );
    assertEquals(
        pushed(raw(128, "", "fatal: unable to access")).error_code,
        "tasks.push_failed",
    );
});
Deno.test("an open PR is updated, otherwise one is opened, with caller prose unchanged", () => {
    const input = publishInput();
    const create = pullPlan({ input, steps: steps() });
    assertEquals(create.method, "POST");
    assertEquals(create.path, "repos/acme/shop/pulls");
    assertEquals(JSON.parse(create.body), {
        ...input.pull_request,
        head: "task-1",
        base: "main",
    });
    const update = pullPlan({
        input,
        steps: { ...steps(), read_pull: out(0, JSON.stringify([open()])) },
    });
    assertEquals(
        [update.method, update.path, update.number],
        ["PATCH", "repos/acme/shop/pulls/12", 12],
    );
    assertEquals(JSON.parse(update.body), input.pull_request);
});
Deno.test("a PR on another candidate, a duplicate or a failed lookup refuses the write", () => {
    const input = publishInput();
    const plan = (stdout: string, code = 0) =>
        pullPlan({
            input,
            steps: { ...steps(), read_pull: out(code, stdout) },
        });
    assertEquals(
        plan(JSON.stringify([open("f".repeat(40))])).error_code,
        "tasks.head_moved",
    );
    assertEquals(
        plan(JSON.stringify([open(), open()])).error_code,
        "tasks.publish_failed",
    );
    assertEquals(plan("", 1).error_code, "tasks.publish_failed");
    const other = open();
    other.head.repo.full_name = "fork/shop";
    assertEquals(plan(JSON.stringify([other])).method, "POST");
});
Deno.test("terminal publication returns the public PR result and push-only null fields", () => {
    assertEquals(result(publishInput(), steps()), {
        branch: "task-1",
        commit: "a".repeat(40),
        pull_request_number: 12,
        pull_request_url: "https://github.com/acme/shop/pull/12",
    });
    assertEquals(result(publishInput(false), steps(false)), {
        branch: "task-1",
        commit: "a".repeat(40),
        pull_request_number: null,
        pull_request_url: null,
    });
});
Deno.test("public refusal codes survive snapshot, import, push and PR failures", () => {
    const input = publishInput();
    for (
        const [name, value, error_code] of [
            ["snapshot-tree", out(0, "f".repeat(40)), "tasks.head_moved"],
            ["import", out(128), "tasks.repository_execution_unknown"],
            ["push", out(1, "", "(fetch first)"), "tasks.not_fast_forward"],
            [
                "read_pull",
                out(0, JSON.stringify([open("f".repeat(40))])),
                "tasks.head_moved",
            ],
            ["write_pull", out(1, ""), "tasks.publish_failed"],
        ] as const
    ) {
        assertEquals(result(input, { ...steps(), [name]: value }), {
            error_code,
        });
    }
});
Deno.test("missing completion proof never becomes successful publication", () => {
    const input = publishInput();
    const without = (name: string) => {
        const values = steps();
        delete values[name];
        return result(input, values);
    };
    assertEquals(without("snapshot-head"), {
        error_code: "tasks.publish_failed",
    });
    assertEquals(without("import"), { error_code: "tasks.publish_failed" });
    assertEquals(without("push"), { error_code: "tasks.push_failed" });
    assertEquals(without("write_pull"), { error_code: "tasks.publish_failed" });
    assertEquals(
        result(input, {
            ...steps(),
            read_pull: out(0, JSON.stringify([open()])),
            write_pull: out(0, JSON.stringify({ number: 13 })),
        }),
        { error_code: "tasks.publish_failed" },
    );
});
