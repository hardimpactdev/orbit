import { assertEquals } from "jsr:@std/assert@1";
import { imported, PublishInput, pullPlan, pushed, verify } from "./publish.ts";

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
Deno.test("a duplicate, wrong base or failed lookup refuses the write", () => {
    const input = publishInput();
    const plan = (stdout: string, code = 0) =>
        pullPlan({
            input,
            steps: { ...steps(), read_pull: out(code, stdout) },
        });
    assertEquals(plan(JSON.stringify([open("f".repeat(40))])).method, "PATCH");
    const wrongBase = open();
    wrongBase.base.ref = "other";
    assertEquals(
        plan(JSON.stringify([wrongBase])).error_code,
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
