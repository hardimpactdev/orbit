import { assertEquals, assertStringIncludes } from "jsr:@std/assert@1.0.19";
import { nativeRepo, ROOT, swampBinary } from "../../../tests/_support/native_harness.ts";

const gateway = "gateway-workflow-grants.json", serve = "serve-grants.json";
const decode = (bytes: Uint8Array) => new TextDecoder().decode(bytes);

for (const scenario of ["current", "backtracking", "regexp", "CEL", "unknown", "account"]) {
  Deno.test(`release grants: ${scenario}`, async () => {
    await using repo = await nativeRepo();
    await Deno.mkdir(`${repo.dir}/config`);
    for (const name of [gateway, serve]) await Deno.copyFile(`${ROOT}/config/${name}`, `${repo.dir}/config/${name}`);
    const read = async (name: string) => JSON.parse(await Deno.readTextFile(`${repo.dir}/config/${name}`));
    const manifest = await read(gateway), policy = await read(serve);
    if (scenario === "backtracking") manifest.vaults.at(-1).key_pattern = "^database-server-[1-9][0-9]*(?:-client-[0-9]+)?$";
    if (scenario === "regexp") policy.grants.at(-1).condition = 'key.matches("[")';
    if (scenario === "CEL") policy.grants.at(-1).condition = 'key.matches("^safe$") && missing_field == 1';
    await Deno.writeTextFile(`${repo.dir}/config/${gateway}`, JSON.stringify(manifest));
    await Deno.writeTextFile(`${repo.dir}/config/${serve}`, JSON.stringify(policy));
    const binary = await swampBinary();
    if (scenario === "unknown") {
      // Exercise the production coverage gate itself; its CLI remains a Python program.
      await Deno.writeTextFile(`${repo.dir}/config/extra-grants.json`, '{"grants":[]}');
      for (const name of ["bin/check-grant-patterns.py", "bin/validate-swamp-workflows.py", "extensions/behaviors/node-worker/config.ts"]) {
        await Deno.mkdir(`${repo.dir}/${name.slice(0, name.lastIndexOf("/"))}`, {recursive:true});
        await Deno.copyFile(`${ROOT}/${name}`, `${repo.dir}/${name}`);
      }
      const result = await new Deno.Command("python3", {
        args:[`${repo.dir}/bin/check-grant-patterns.py`, "--cache", binary.slice(0, binary.lastIndexOf("/"))],
        stdout:"piped", stderr:"piped", signal:AbortSignal.timeout(60_000),
      }).output();
      assertEquals(result.success, false);
      assertStringIncludes(decode(result.stderr), "complete release-renderer coverage");
      return;
    }
    const rendered = await new Deno.Command("php", {
      args:[`${ROOT}/../apps/gateway/bin/render-swamp-grants`, repo.dir], stdout:"piped", stderr:"piped",
      signal:AbortSignal.timeout(30_000),
    }).output();
    assertEquals(rendered.success, true, decode(rendered.stderr));
    const files = JSON.parse(decode(rendered.stdout)) as Record<string,string>;
    let count = 0;
    for (const [name, content] of Object.entries(files)) {
      await Deno.mkdir(`${repo.dir}/${name.slice(0, name.lastIndexOf("/"))}`, {recursive:true});
      await Deno.writeTextFile(`${repo.dir}/${name}`, content);
      count += JSON.parse(content).grants.length;
    }
    const args = ["serve", "check-config", "--repo-dir", repo.dir, "--auth-mode", "token", "--admins", "user:orbit-grant-ci"];
    let output: string;
    if (scenario === "account") {
      const account = `${repo.dir}/account`;
      await Deno.mkdir(account);
      const result = await new Deno.Command(binary, {
        args:[...args,"--json"], clearEnv:true, stdout:"piped", stderr:"piped", signal:AbortSignal.timeout(60_000),
        env:{PATH:Deno.env.get("PATH")!, HOME:account, XDG_CONFIG_HOME:account, XDG_CACHE_HOME:account,
          SWAMP_CONFIG_DIR:account, SWAMP_HOME:account, SWAMP_NO_UPDATE_CHECK:"1", SWAMP_NO_TELEMETRY:"1"},
      }).output();
      assertEquals(result.success, false);
      output = decode(result.stdout) + decode(result.stderr);
    } else {
      const result = await repo.cli(args, {success:scenario === "current"});
      output = result.stdout + result.stderr;
    }
    if (scenario === "current") {
      const report = JSON.parse(output);
      assertEquals([report.passed, report.wouldStart, report.grantFilesChecked], [true, true, 2]);
      assertEquals(count, manifest.vaults.length + manifest.workflows.length + policy.grants.length);
    } else assertStringIncludes(output, {backtracking:"catastrophic backtracking", regexp:"regular expression", CEL:"CEL", account:"auth_gate_blocked"}[scenario]!);
  });
}
