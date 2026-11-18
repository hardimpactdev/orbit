import { assertEquals, assertRejects } from "jsr:@std/assert@1";
import { behavior, branchAvailable, profileRead } from "./assets.ts";
const out = (stdout: string) => ({ stdout, exitCode: 0 });
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

Deno.test("the clone profile read reports partial, unsafe and composer checkouts", () => {
    const intent = { project_type: "laravel-app" };
    assertEquals(profileRead("regular\nmissing", intent), { kind: "partial" });
    assertEquals(profileRead("absent\nmissing", intent), { kind: "none" });
    assertEquals(profileRead("regular\nunsafe", intent), { kind: "unsafe" });
    assertEquals(
        (profileRead('regular\nfile\n{"require":{"php":"^8.4"}}', intent) as {
            kind: string;
        }).kind,
        "composer",
    );
    assertEquals(
        profileRead("regular\nfile\n{}", { project_type: "monorepo" }),
        { kind: "none" },
    );
});

Deno.test("clone validates the recorded container seed and the checkout origin", async () => {
  const commit = "a".repeat(40);
  const intent = {
    branch: "main",
    repository_identity: "github.com/example/tool",
  };
  const observations = {
    branch: out("main"),
    commit: out(commit),
    status: out(""),
    submodules: out(""),
  };
  const checkout = {
    ...observations,
    intent,
    origin: out("https://github.com/example/tool.git"),
  };
  const container = {
    ...observations,
    intent: { ...intent, container: { seed_commit: commit } },
    origin: {},
  };
  const { execute } = behavior.methods.cloneCandidate;
  const results: unknown[] = [];
  const context = {
    writeResource: async (_spec: string, _name: string, value: unknown) =>
      results.push(value),
  };

  await execute(checkout, context);
  await execute(container, context);
  assertEquals(results, [{ commit }, { commit }]);

  for (
    const input of [
      { ...checkout, origin: out("https://github.com/example/other.git") },
      { ...container, commit: out("b".repeat(40)) },
      { ...container, branch: out("other") },
      { ...container, status: out("?? local-file") },
      { ...container, submodules: out("unexpected submodule") },
    ]
  ) {
    await assertRejects(
      () => execute(input, context),
      Error,
      "instance.clone_candidate_source_invalid",
    );
  }
  assertEquals(results.length, 2);
});
