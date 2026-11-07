import {
    assert,
    assertEquals,
    assertStringIncludes,
} from "jsr:@std/assert@1.0.19";
import {
    commit,
    copyPolicy,
    git,
    init,
    run,
    script,
    temporary,
    write,
} from "./gate-test-support.ts";

Deno.test("frozen Task queue allowance accepts only the exact public rename", async () => {
    await temporary(async (repo) => {
        await init(repo);
        const swamp = repo + "/swamp";
        const queue =
            "extensions/behaviors/projects/horizon-new-dawn/_lib/queue.ts";
        const old =
            'deps.http("POST", "/api/v1/task-groups", {tasks: plan.tasks.map(f)});';
        const renamed = old.replaceAll("/api/v1/task-groups", "/api/v1/tasks")
            .replace("tasks: plan.tasks.map(", "subtasks: plan.tasks.map(");
        await write(swamp + "/" + queue, old);
        await write(
            swamp + "/config/swamp-shape-baseline.json",
            '{"schema":1,"entries":[]}',
        );
        // A frozen rule has no knowledge of the new URL or policy helpers.
        await write(
            swamp + "/bin/check-swamp-shape.ts",
            `Deno.exit(await Deno.readTextFile(${JSON.stringify(queue)}) === ${
                JSON.stringify(old)
            } ? 0 : 1);`,
        );
        const base = await commit(repo, "frozen queue source");
        async function check(source: string) {
            await write(swamp + "/" + queue, source);
            await commit(repo, "queue update");
            const result = await run("python3", [
                script("check-swamp-base-policy.py"),
                "--base",
                base,
            ], { cwd: swamp, env: { DENO_BIN: Deno.execPath() } });
            assertEquals(await Deno.readTextFile(swamp + "/" + queue), source);
            return result.code;
        }
        assertEquals(await check(renamed), 0);
        assert(
            await check(
                renamed + '\ndeps.http("POST", "/api/v1/tasks", {});',
            ) !== 0,
        );
        assert(await check(renamed.replace("POST", "DELETE")) !== 0);
    });
});

Deno.test("real frozen policy owns limits and dependencies", async () => {
    await temporary(async (repo) => {
        await init(repo);
        const swamp = repo + "/swamp",
            checker = swamp + "/bin/check-swamp-shape.ts";
        await copyPolicy(swamp);
        await write(
            checker,
            "import {proof} from './policy.ts'; if(proof !== 1) throw new Error('Untrusted policy dependency');\n" +
                await Deno.readTextFile(checker),
        );
        await write(swamp + "/bin/policy.ts", "export const proof=1;\n");
        await write(
            swamp + "/config/agent-command-kinds.json",
            '{"agent.status":"observer"}',
        );
        const source = swamp + "/extensions/behaviors/old.ts";
        const originalSource =
            "export const behavior={type:'@fixture/old'};\nawait delay(10);\n";
        await write(source, originalSource);
        const original = await commit(repo, "old source");
        const seeded = await run(Deno.execPath(), [
            "run",
            "-A",
            checker,
            "--base",
            original,
            "--write-baseline",
        ], { cwd: swamp });
        assertEquals(seeded.code, 0, seeded.stderr);
        const base = await commit(repo, "frozen rules and debt allowance");
        await write(
            swamp + "/config/swamp-shape-baseline.json",
            JSON.stringify({ schema: 1, entries: [] }),
        );
        await write(checker, "Deno.exit(0);\n");
        await write(swamp + "/bin/policy.ts", "export const proof=0;\n");
        await commit(repo, "new rules classify original source differently");
        async function check() {
            const before = await git(repo, "status", "--porcelain");
            const result = await run("python3", [
                script("check-swamp-base-policy.py"),
                "--base",
                base,
            ], { cwd: swamp, env: { DENO_BIN: Deno.execPath() } });
            assertEquals(
                await git(repo, "status", "--porcelain"),
                before,
                "Caller stays untouched",
            );
            assertEquals(
                (await git(repo, "worktree", "list", "--porcelain")).split(
                    "worktree ",
                ).length,
                2,
            );
            return result;
        }
        const initial = await check();
        assertEquals(initial.code, 0, initial.stdout + initial.stderr);
        await write(source, originalSource + "await delay(20);\n");
        await commit(
            repo,
            "new debt cannot hide behind changed rules or baseline",
        );
        const debt = await check();
        assert(debt.code !== 0);
        assertStringIncludes(debt.stderr, "custom-control 2 exceeds 1");
        await write(source, originalSource);
        const renamed = source.replace("old.ts", "renamed.ts");
        await Deno.rename(source, renamed);
        await commit(repo, "renaming cannot move a frozen allowance");
        assert((await check()).code !== 0);
        await write(renamed, "export const behavior={type:'@fixture/old'};\n");
        await commit(
            repo,
            "actual debt removal has no stale allowance failure",
        );
        assertEquals((await check()).code, 0);
    });
});
