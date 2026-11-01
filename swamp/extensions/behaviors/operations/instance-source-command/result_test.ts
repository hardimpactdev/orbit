import { assertEquals, assertThrows } from "jsr:@std/assert@1";
import { branchAvailable, sourceResult } from "./assets.ts";
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

Deno.test("source refuses occupied branches before target preparation and keeps unrelated branches available", async () => {
    const root = await Deno.makeTempDir();
    try {
        const seed = root + "/default", target = root + "/new";
        await Deno.mkdir(seed);
        async function git(...args: string[]) {
            const result = await new Deno.Command("git", {
                args: ["-C", seed, ...args],
                stdout: "piped",
                stderr: "piped",
            }).output();
            assertEquals(
                result.code,
                0,
                new TextDecoder().decode(result.stderr),
            );
            return new TextDecoder().decode(result.stdout).trimEnd();
        }
        await git("init", "-q", "--initial-branch=master");
        await git("config", "user.name", "Fixture");
        await git("config", "user.email", "fixture@example.test");
        await Deno.writeTextFile(seed + "/README", "owned seed\n");
        await git("add", "README");
        await git("commit", "-qm", "seed");
        const commit = await git("rev-parse", "HEAD");
        const worktrees = out(await git("worktree", "list", "--porcelain"));
        const resolve = {
            instance_name: "new",
            default_branch: "master",
            branch_override: "master",
        };
        assertEquals(branchAvailable(resolve, target, worktrees), {
            available: false,
            error_code: "instance.branch_in_use",
        });
        assertEquals(
            branchAvailable(
                { ...resolve, branch_override: null },
                target,
                worktrees,
            ),
            { available: true },
        );
        assertEquals(branchAvailable(resolve, seed, worktrees), {
            available: true,
        });
        assertEquals(await git("rev-parse", "HEAD"), commit);
        assertEquals(await git("status", "--porcelain"), "");
        try {
            await Deno.stat(target);
            throw new Error("Target was created");
        } catch (error) {
            if (!(error instanceof Deno.errors.NotFound)) throw error;
        }
    } finally {
        await Deno.remove(root, { recursive: true });
    }
});
