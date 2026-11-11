import {
    assert,
    assertEquals,
    assertStringIncludes,
} from "jsr:@std/assert@1.0.19";
import {
    BRIDGE_WRITER,
    BRIDGE_RECORD_MUTATIONS,
    bridgeContractViolations,
    bridgeInodeViolations,
    bridgeImportViolations,
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
        await write(repo + "/.gitignore", "__pycache__/\n");
        const swamp = repo + "/swamp";
        await copyPolicy(swamp);
        await write(
            swamp + "/config/agent-command-kinds.json",
            '{"agent.status":"observer"}',
        );
        const source = swamp + "/extensions/behaviors/caller.ts";
        const runner = swamp + "/bin/check-swamp-base-policy.py";
        const runnerSource = await Deno.readTextFile(
            script("check-swamp-base-policy.py"),
        );
        await write(runner, runnerSource);
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
                swamp + "/bin/check-swamp-shape.ts",
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
        assertStringIncludes(policy.stdout, "baseline_repair=true");
        const checker = swamp + "/bin/check-swamp-shape.ts";
        const checkerSource = await Deno.readTextFile(checker);
        await write(
            checker,
            checkerSource.replace(
                "production(file) &&",
                '!file.startsWith("bin/check-swamp-base-policy") && production(file) &&',
            ),
        );
        await write(
            runner,
            runnerSource.replace("import tempfile\n", "import tempfile\nimport time\n") +
                "\ntime.sleep(1)\n",
        );
        await commit(repo, "gate-only debt cannot hide behind a stale-label repair");
        assertEquals(
            await git(repo, "diff", "--name-only", base, "HEAD"),
            [
                "swamp/bin/check-swamp-base-policy.py",
                "swamp/bin/check-swamp-shape.ts",
                "swamp/config/swamp-shape-baseline.json",
            ].join("\n"),
        );
        const hidden = await shape();
        assertEquals(hidden.code, 0, hidden.stdout + hidden.stderr);
        const debt = await frozen();
        assert(debt.code !== 0, debt.stdout + debt.stderr);
        assertStringIncludes(debt.stderr, "custom-control 1 exceeds 0");
        await write(runner, runnerSource);
        await write(checker, checkerSource);
        for (const invalid of [
            { ...baseline.entries[0], count: 2 },
            { ...baseline.entries[0], evidence: ["POST /api/v1/another"] },
        ]) {
            await write(ledger, JSON.stringify({ schema: 1, entries: [invalid] }));
            await commit(repo, "bootstrap cannot change counts or invent labels");
            assert((await frozen()).code !== 0);
        }
        await write(ledger, JSON.stringify(baseline));
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

Deno.test("bridge transaction contract is enforced by current and immutable base rules", async () => {
    await temporary(async (repo) => {
        await init(repo);
        await write(repo + "/.gitignore", "__pycache__/\n");
        const swamp = repo + "/swamp";
        await copyPolicy(swamp);
        await write(
            swamp + "/config/agent-command-kinds.json",
            '{"agent.status":"observer"}',
        );
        const ledger = swamp + "/config/swamp-shape-baseline.json";
        const baseline = JSON.stringify({ schema: 1, entries: [] });
        await write(ledger, baseline);
        // Freeze the structural policy first. Candidate source and checker changes
        // cannot modify its rules, imports or zero-debt baseline afterwards.
        const base = await commit(
            repo,
            "freeze bridge transaction policy without a runtime caller",
        );
        const source = swamp + "/ansible/renamed_writer.py";
        const checker = swamp + "/bin/check-swamp-shape.ts";
        const original = await Deno.readTextFile(checker);
        const current = () =>
            run(Deno.execPath(), ["run", "-A", checker, "--base", base], {
                cwd: swamp,
            });
        const frozen = () =>
            run("python3", [
                script("check-swamp-base-policy.py"),
                "--base",
                base,
            ], {
                cwd: swamp,
                env: { DENO_BIN: Deno.execPath() },
            });
        await write(source, BRIDGE_WRITER);
        await commit(
            repo,
            "valid complete transaction under a different filename",
        );
        for (const check of [current, frozen]) {
            const result = await check();
            assertEquals(result.code, 0, result.stdout + result.stderr);
        }
        for (const [name, variant] of bridgeContractViolations) {
            await write(source, variant);
            await commit(repo, name);
            for (const check of [current, frozen]) {
                const result = await check();
                assert(result.code !== 0, name + result.stdout + result.stderr);
                assertStringIncludes(result.stderr, "custom-control");
                assertStringIncludes(result.stderr, "exceeds 0");
            }
            assertEquals(
                await Deno.readTextFile(ledger),
                baseline,
                "No grandfather allowance",
            );
        }
        // Even a candidate checker that accepts everything cannot hide the broken
        // custody from the real frozen checker and its immutable Python helper.
        await write(checker, "Deno.exit(0);\n");
        await commit(repo, "candidate checker cannot waive bridge custody");
        assertEquals((await current()).code, 0);
        const rejected = await frozen();
        assert(rejected.code !== 0, rejected.stdout + rejected.stderr);
        assertStringIncludes(rejected.stderr, "custom-control");
        await write(source, BRIDGE_WRITER);
        await write(checker, original);
        await commit(repo, "restore valid transaction");
        const restored = await frozen();
        assertEquals(restored.code, 0, restored.stdout + restored.stderr);
    });
});

Deno.test("current and frozen bridge policy reject inode replacement and imported unlocked entry points", async () => {
    await temporary(async (repo) => {
        await init(repo);
        await write(repo + "/.gitignore", "__pycache__/\n");
        const swamp = repo + "/swamp";
        await copyPolicy(swamp);
        await write(
            swamp + "/config/agent-command-kinds.json",
            '{"agent.status":"observer"}',
        );
        const baseline = JSON.stringify({ schema: 1, entries: [] });
        const ledger = swamp + "/config/swamp-shape-baseline.json";
        await write(ledger, baseline);
        const base = await commit(
            repo,
            "freeze inode and import custody policy",
        );
        const source = swamp + "/ansible/package/renamed_writer.py";
        const current = () =>
            run(Deno.execPath(), [
                "run",
                "-A",
                swamp + "/bin/check-swamp-shape.ts",
                "--base",
                base,
            ], { cwd: swamp });
        const frozen = () =>
            run("python3", [
                script("check-swamp-base-policy.py"),
                "--base",
                base,
            ], { cwd: swamp, env: { DENO_BIN: Deno.execPath() } });
        async function check(rejected: boolean, name: string) {
            await commit(repo, name);
            for (const command of [current, frozen]) {
                const result = await command();
                assertEquals(
                    result.code !== 0,
                    rejected,
                    name + result.stdout + result.stderr,
                );
                if (rejected) {
                    assertStringIncludes(result.stderr, "custom-control");
                }
            }
            assertEquals(await Deno.readTextFile(ledger), baseline);
        }
        await write(source, BRIDGE_WRITER);
        await check(false, "valid packaged writer");
        await write(source, BRIDGE_RECORD_MUTATIONS);
        await check(
            false,
            "safe staged record commits and helper deletion remain supported",
        );
        for (const [name, variant] of bridgeInodeViolations) {
            await write(source, variant);
            await check(true, name);
        }
        await write(source, BRIDGE_WRITER);
        for (const [name, path, peer] of bridgeImportViolations) {
            const caller = swamp + "/ansible/" + path;
            await write(caller, peer);
            await check(true, name);
            await Deno.remove(caller);
        }
        await check(false, "removing unlocked importers restores the proof");
    });
});
