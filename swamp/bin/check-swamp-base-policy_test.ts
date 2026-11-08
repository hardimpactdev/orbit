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

Deno.test("stale baseline evidence can be repaired without growing immutable source debt", async () => {
    await temporary(async (repo) => {
        await init(repo);
        const swamp = repo + "/swamp";
        await copyPolicy(swamp);
        await write(
            swamp + "/config/agent-command-kinds.json",
            '{"agent.status":"observer"}',
        );
        const source = swamp + "/extensions/behaviors/caller.ts";
        const current = 'deps.http("POST", "/api/v1/current", {});\n';
        await write(source, current);
        const ledger = swamp + "/config/swamp-shape-baseline.json";
        const baseline = {
            schema: 1,
            entries: [{
                file: "extensions/behaviors/caller.ts",
                rule: "gateway-http",
                count: 1,
                evidence: ["POST /api/v1/retired"],
            }],
        };
        await write(ledger, JSON.stringify(baseline));
        const base = await commit(
            repo,
            "immutable source with stale evidence label",
        );
        baseline.entries[0].evidence = ["POST /api/v1/current"];
        await write(ledger, JSON.stringify(baseline));
        await commit(
            repo,
            "correct the existing label without changing source",
        );
        const shape = () =>
            run(Deno.execPath(), [
                "run",
                "-A",
                script("check-swamp-shape.ts"),
                "--base",
                base,
            ], { cwd: swamp });
        const frozen = () =>
            run("python3", [
                script("check-swamp-base-policy.py"),
                "--base",
                base,
            ], {
                cwd: swamp,
                env: { DENO_BIN: Deno.execPath() },
            });
        const repaired = await shape();
        assertEquals(repaired.code, 0, repaired.stdout + repaired.stderr);
        const policy = await frozen();
        assertEquals(policy.code, 0, policy.stdout + policy.stderr);
        for (
            const changed of [
                current + current,
                current.replace("/current", "/another"),
            ]
        ) {
            await write(source, changed);
            await commit(
                repo,
                "operational source change cannot grow counts or sites",
            );
            assert((await shape()).code !== 0);
            assert((await frozen()).code !== 0);
        }
    });
});
