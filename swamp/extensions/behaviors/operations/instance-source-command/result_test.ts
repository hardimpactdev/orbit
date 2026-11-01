import { assertEquals, assertThrows } from "jsr:@std/assert@1";
import { sourceResult } from "./assets.ts";
const intent = {
    checkout: "/srv/apps/project/main",
    repository_identity: "github.com/example/project",
    branch: "main",
    project_type: "monorepo",
    php_versions: ["8.5"],
};
const out = (stdout: string) => ({ stdout, exitCode: 0 });
const facts = {
    top: out(intent.checkout),
    common: out(intent.checkout + "/.git"),
    worktrees: out(
        `worktree ${intent.checkout}\nHEAD ${
            "a".repeat(40)
        }\nbranch refs/heads/main\n`,
    ),
    origin: out("git@github.com:example/project.git"),
    branch: out("main\n"),
    commit: out("a".repeat(40)),
    default: out("refs/remotes/origin/main"),
    filesystem: out(
        JSON.stringify({ digest: "b".repeat(64), profile: { kind: "none" } }),
    ),
};
Deno.test("registration retains admitted checkout, detached state, profile and source digest", () => {
    assertEquals(sourceResult("instance.register", intent, facts), {
        registered: true,
        branch: "main",
        commit: "a".repeat(40),
        detached: false,
        digest: "b".repeat(64),
        common: intent.checkout + "/.git",
        default_branch: "main",
        inferred_slug: "project",
        php_version: null,
        laravel: false,
    });
    assertEquals(
        (sourceResult("instance.register", intent, {
            ...facts,
            branch: out(""),
        }) as Record<string, unknown>)
            .detached,
        true,
    );
});
Deno.test("rename records only an already checked out branch", () => {
    assertEquals(sourceResult("instance.rename", intent, facts), {
        branch: "main",
    });
    assertThrows(
        () =>
            sourceResult(
                "instance.rename",
                { ...intent, branch: "feature" },
                facts,
            ),
        Error,
        "instance.branch_not_checked_out",
    );
    assertEquals(
        sourceResult("instance.rename", { ...intent, branch: null }, {
            ...facts,
            branch: out(""),
        }),
        { branch: null },
    );
});
Deno.test("source comparison refuses a foreign origin, layout and sibling worktree", () => {
    for (
        const change of [
            { origin: out("https://github.com/foreign/project.git") },
            { top: out("/srv/foreign") },
            { common: out("/srv/seed/.git") },
            {
                worktrees: out(
                    `worktree ${intent.checkout}\n\nworktree /srv/other\n`,
                ),
            },
        ]
    ) {
        assertThrows(() =>
            sourceResult("instance.register", intent, { ...facts, ...change })
        );
    }
    assertThrows(() =>
        sourceResult("instance.register", intent, {
            ...facts,
            origin: out("https://secret@github.com/example/project.git"),
        })
    );
});
