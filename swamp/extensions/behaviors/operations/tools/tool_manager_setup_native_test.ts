import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { calls, exists, read } from "./tool_fixture_test.ts";
import { managerSetupFixture } from "./tool_manager_setup_fixture_test.ts";

Deno.test("first use installs exact packages and creates managed manifest", async () => {
    await using f = await managerSetupFixture();
    await f.run();
    assertEquals(read(`${f.scope}/composer.json`), { require: {} });
    assert(Deno.statSync(`${f.scope}/vendor/bin`).isDirectory);
    const install = calls(f.audit).find((a) => a.includes("install"))!;
    assertEquals(install.at(-1), "composer=2.9.5-1");
    assert(install.includes("--no-remove"));
    assert(install.includes("APT::Get::List-Cleanup=false"));
});
Deno.test("existing Composer requirements survive first use recovery", async () => {
    await using f = await managerSetupFixture();
    await Deno.mkdir(f.scope);
    const original = '{"require":{"vendor/existing-tool":"^2.0"}}\n';
    await Deno.writeTextFile(`${f.scope}/composer.json`, original);
    await f.run();
    assertEquals(Deno.readTextFileSync(`${f.scope}/composer.json`), original);
});
Deno.test("failed package install never publishes manager readiness", async () => {
    await using f = await managerSetupFixture();
    f.repo.env.APT_FAIL = "1";
    const { run } = await f.run(false);
    assertEquals(run.status, "failed");
    assert(!exists(`${f.scope}/composer.json`));
});
Deno.test("ready manager needs no install or scope rewrite", async () => {
    await using f = await managerSetupFixture();
    await Deno.writeTextFile(f.state, "2.9.5-1");
    await f.run();
    assert(!exists(f.audit));
    assert(!exists(f.scope));
});
Deno.test("convergence keeps matching packages and repairs manager scope", async () => {
    await using f = await managerSetupFixture();
    await Deno.writeTextFile(f.state, "2.9.5-1");
    f.input.converge = true;
    f.input.intent.setup.apt.packages.push({
        name: "git",
        version: "2.9.5-1",
        artifact: {
            url: "https://example.invalid/already-installed.deb",
            algorithm: "sha256",
            digest: "a".repeat(64),
        },
    });
    const { history } = await f.run();
    const folders = history.jobs.flatMap((j) => j.steps).filter((s) =>
        s.name.startsWith("Prepare manager folders-")
    ) as unknown as { startedAt: string; completedAt: string }[];
    folders.sort((a, b) => a.startedAt.localeCompare(b.startedAt));
    assert(folders.length >= 2);
    for (let i = 1; i < folders.length; i++) {
        assert(
            folders[i - 1].completedAt <= folders[i].startedAt,
            "Manager resource dispatches must not exhaust worker memory",
        );
    }
    assert(!exists(f.audit));
    assertEquals(read(`${f.scope}/composer.json`), { require: {} });
    assert(Deno.statSync(`${f.scope}/vendor/bin`).isDirectory);
});
