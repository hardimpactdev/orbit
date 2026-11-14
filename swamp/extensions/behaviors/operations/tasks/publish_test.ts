import { assertEquals } from "jsr:@std/assert@1";
import {
    BASE_MARK,
    BASE_PATHS,
    baseFacts,
    BodyLimit,
    imported,
    PublishInput,
    pullPlan,
    pushed,
    verify,
    withBase,
    withWaivers,
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
Deno.test("a draft PR is opened as a draft and an open PR keeps its draft state", () => {
    const input = PublishInput.parse({
        ...publishInput(),
        pull_request: {
            title: "Spec",
            body: "Chart, page and cases",
            draft: true,
        },
    });
    const create = pullPlan({ input, steps: steps() });
    assertEquals(JSON.parse(create.body), {
        title: "Spec",
        body: "Chart, page and cases",
        draft: true,
        head: "task-1",
        base: "main",
    });
    const update = pullPlan({
        input,
        steps: { ...steps(), read_pull: out(0, JSON.stringify([open()])) },
    });
    assertEquals(JSON.parse(update.body), {
        title: "Spec",
        body: "Chart, page and cases",
    });
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

const files = (
    overrides: Partial<
        Record<"head" | "base" | "behind" | "overlap" | "merge", string>
    > = {},
) => ({
    head: "c".repeat(40) + "\n",
    base: "a".repeat(40) + "\n",
    behind: "2\n",
    overlap: "",
    merge: "",
    ...overrides,
});

Deno.test("a copy's base is stated, behind or not, and only a real conflict refuses", () => {
    assertEquals(baseFacts(0, files({ behind: "0\n" })).facts?.behind_by, 0);
    assertEquals(baseFacts(0, files()), {
        facts: {
            base_commit: "a".repeat(40),
            default_commit: "c".repeat(40),
            behind_by: 2,
            overlapping_paths: [],
            overlapping_more: 0,
        },
        error_code: "",
    });
    assertEquals(
        baseFacts(0, files({ overlap: "app/a.php\0docs/b.md\0" })).facts
            ?.overlapping_paths,
        ["app/a.php", "docs/b.md"],
    );
    assertEquals(
        baseFacts(
            3,
            files({ merge: "d".repeat(40) + "\0app/a.php\0app/a.php\0x\ny\0" }),
        ),
        {
            facts: null,
            error_code: 'tasks.publish_conflict: "app/a.php", "x\\ny"',
        },
    );
    // A read that failed, or a conflict code without its reply, is an error, never a pass or a conflict.
    assertEquals(baseFacts(2, files()).error_code, "tasks.publish_failed");
    assertEquals(
        baseFacts(3, files({ merge: "d".repeat(40) + "\0" })).error_code,
        "tasks.publish_failed",
    );
    assertEquals(
        baseFacts(0, files({ head: "" })).error_code,
        "tasks.publish_failed",
    );
});

Deno.test("the base path lists stop at their bound and count the rest", () => {
    const many = Array.from(
        { length: BASE_PATHS + 5 },
        (_, index) => `f${index}`,
    );
    const stated =
        baseFacts(0, files({ overlap: many.join("\0") + "\0" })).facts;
    assertEquals([stated?.overlapping_paths.length, stated?.overlapping_more], [
        BASE_PATHS,
        5,
    ]);
    const refused = baseFacts(
        3,
        files({ merge: ["d".repeat(40), ...many].join("\0") + "\0" }),
    ).error_code;
    assertEquals(refused.endsWith('"f99" and 5 more'), true);
});

Deno.test("the body ends with one Base section, also on a re-publish", () => {
    const facts = baseFacts(0, files({ overlap: "app/a.php\0" })).facts;
    const once = withBase("Adds the export.", facts);
    assertEquals(
        once,
        [
            "Adds the export.",
            "",
            BASE_MARK,
            "## Base",
            "",
            "- Checked base: `" + "a".repeat(40) + "`",
            "- Default branch head: `" + "c".repeat(40) + "`",
            "- Behind by: 2 commits",
            "- Overlapping paths:",
            "  - `app/a.php`",
            "",
        ].join("\n"),
    );
    assertEquals(withBase(once, facts), once);
    assertEquals(
        withBase(
            "Adds the export.",
            baseFacts(0, files({ behind: "1\n" })).facts,
        ).includes("- Behind by: 1 commit\n- Overlapping paths: none"),
        true,
    );
    // A task VM publish, or a run before the base step, leaves the caller's body as it is.
    assertEquals(withBase("Adds the export.", null), "Adds the export.");
});

Deno.test("the planned pull request carries the Base section on creation and update", () => {
    const facts = baseFacts(0, files()).facts;
    const statement = JSON.stringify(facts);
    for (const rows of [[], [open()]]) {
        const plan = pullPlan({
            input: publishInput(),
            steps: { read_pull: out(0, JSON.stringify(rows)) },
            base: statement,
        });
        assertEquals(
            JSON.parse(plan.body).body,
            withBase("Adds the export. $(touch inert)", facts),
        );
    }
    const plain = pullPlan({
        input: publishInput(),
        steps: { read_pull: out(0, "[]") },
        base: "",
    });
    assertEquals(
        JSON.parse(plain.body).body,
        "Adds the export. $(touch inert)",
    );
});

Deno.test("a copy's publish appends the proposed body's waiver lines that the body lacks", () => {
    const proposed = [
        "Docs-unaffected: docs/reference/mcp.mdx: the tool schemas follow the request rules.",
        "Some prose that is no waiver.",
        "Docs-unaffected: docs/reference/projects.md: only the column exists.\r",
        "Docs-unaffected: docs/reference/mcp.mdx: the tool schemas follow the request rules.",
        "Docs-unaffected: /etc/passwd: no docs path.",
    ].join("\n");

    assertEquals(
        withWaivers("Adds the export.", proposed),
        "Adds the export.\n\nDocs-unaffected: docs/reference/mcp.mdx: the tool schemas follow the request rules.\nDocs-unaffected: docs/reference/projects.md: only the column exists.",
    );
});

Deno.test("a copy's publish keeps the body when there is no proposed body, or it holds every line already", () => {
    const line = "Docs-unaffected: docs/reference/mcp.mdx: generated.";
    assertEquals(withWaivers("Adds the export.", null), "Adds the export.");
    assertEquals(
        withWaivers(`Adds the export.\n\n${line}`, line),
        `Adds the export.\n\n${line}`,
    );
});

Deno.test("the waiver lines stop where the body would pass the publish limit", () => {
    const body = "x".repeat(BodyLimit - 60);
    const short = "Docs-unaffected: docs/a.md: ok.";
    const long = "Docs-unaffected: docs/reference/tasks.md: " + "y".repeat(80);
    const result = withWaivers(body, `${short}\n${long}`);
    assertEquals(result, `${body}\n\n${short}`);
    assertEquals(result.length <= BodyLimit, true);
});

Deno.test("waiver lines after an earlier Base section stay, before the one new section", () => {
    const facts = baseFacts(0, files({ overlap: "app/a.php\0" })).facts;
    const waiver = "Docs-unaffected: docs/reference/x.md: no change.";
    const earlier = withBase("Adds the export.", facts);
    // A re-publish: the caller's body carries the earlier section, and withWaivers appends a line after it.
    const body = withWaivers(earlier, waiver);
    assertEquals(body.endsWith(waiver), true);
    const republished = withBase(body, facts);
    assertEquals(republished, withBase("Adds the export.\n\n" + waiver, facts));
    assertEquals(republished.split(BASE_MARK).length, 2);
    assertEquals(
        republished.indexOf(waiver) < republished.indexOf(BASE_MARK),
        true,
    );
});

Deno.test("the Base section keeps the body within its limit", () => {
    const paths = Array.from(
        { length: 5 },
        (_, index) => `apps/gateway/app/Domain/Tasks/Changed${index}.php`,
    );
    const facts = baseFacts(0, files({ overlap: paths.join("\0") + "\0" }))
        .facts;
    const full = withBase("x", facts);
    // A body that leaves no room for the path list states their count instead.
    const tight = "x".repeat(BodyLimit - (full.length - 1) + 1);
    const counted = withBase(tight, facts);
    assertEquals(counted.length <= BodyLimit, true);
    assertEquals(
        counted.endsWith(
            "- Overlapping paths: 5, listed in the publish result\n",
        ),
        true,
    );
    // A body at the limit gets no section; the publish result still states the base.
    const limit = "x".repeat(BodyLimit);
    assertEquals(withBase(limit, facts), limit);
});
