import { assertEquals } from "jsr:@std/assert@1.0.19";
import { nativeRepo, ROOT } from "../../../tests/_support/native_harness.ts";

Deno.test("release grants: current", async () => {
    await using repo = await nativeRepo();
    await Deno.mkdir(`${repo.dir}/config`);
    for (const name of ["gateway-workflow-grants.json", "serve-grants.json"]) {
        await Deno.copyFile(`${ROOT}/config/${name}`, `${repo.dir}/config/${name}`);
    }
    const manifest = JSON.parse(await Deno.readTextFile(`${repo.dir}/config/gateway-workflow-grants.json`));
    const policy = JSON.parse(await Deno.readTextFile(`${repo.dir}/config/serve-grants.json`));
    const rendered = await new Deno.Command("php", {
        args: [`${ROOT}/../apps/gateway/bin/render-swamp-grants`, repo.dir],
        stdout: "piped",
        stderr: "piped",
        signal: AbortSignal.timeout(30_000),
    }).output();
    assertEquals(rendered.success, true, new TextDecoder().decode(rendered.stderr));
    const files = JSON.parse(new TextDecoder().decode(rendered.stdout)) as Record<string, string>;
    let count = 0;
    for (const [name, content] of Object.entries(files)) {
        await Deno.mkdir(`${repo.dir}/${name.slice(0, name.lastIndexOf("/"))}`, { recursive: true });
        await Deno.writeTextFile(`${repo.dir}/${name}`, content);
        count += JSON.parse(content).grants.length;
    }
    const { stdout } = await repo.cli([
        "serve",
        "check-config",
        "--repo-dir",
        repo.dir,
        "--auth-mode",
        "token",
        "--admins",
        "user:orbit-grant-ci",
    ]);
    const report = JSON.parse(stdout);
    assertEquals([report.passed, report.wouldStart, report.grantFilesChecked], [true, true, 2]);
    assertEquals(count, manifest.vaults.length + manifest.workflows.length + policy.grants.length);
});
