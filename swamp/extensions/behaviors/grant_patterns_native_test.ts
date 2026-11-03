import { assertEquals, assertStringIncludes } from "jsr:@std/assert@1.0.19";
import { ROOT, swampBinary } from "../../tests/_support/native_harness.ts";
import { ok, pythonTool, scratch } from "../../tests/_support/gate_fixture.ts";

async function fixture() {
  const repo = await scratch("grant-policy-");
  for (const name of ["gateway-workflow-grants.json", "serve-grants.json"]) {
    await repo.write(`config/${name}`, await Deno.readTextFile(`${ROOT}/config/${name}`));
  }
  return {
    ...repo,
    async change(name: string, field: "key_pattern" | "condition", value: string) {
      const path = `${repo.dir}/config/${name}.json`;
      const config = JSON.parse(await Deno.readTextFile(path));
      (config.vaults ?? config.grants).at(-1)[field] = value;
      await Deno.writeTextFile(path, JSON.stringify(config));
    },
    async check(emptyEnv = false) {
      const version = (await Deno.readTextFile(`${ROOT}/.swamp.yaml`)).match(/swampVersion: (\S+)/)![1];
      return pythonTool(
        "check-grant-patterns",
        "print(json.dumps(tool.validate_grants(Path(sys.argv[2]),Path(sys.argv[3]),sys.argv[4],{} if sys.argv[5]=='empty' else os.environ)))",
        [repo.dir, await swampBinary(), version, emptyEnv ? "empty" : "inherited"],
      );
    },
  };
}

Deno.test("current Gateway and serve grants pass native startup", async () => {
  await using repo = await fixture();
  const report = JSON.parse(ok(await repo.check()));
  const gateway = JSON.parse(await Deno.readTextFile(`${repo.dir}/config/gateway-workflow-grants.json`));
  const serve = JSON.parse(await Deno.readTextFile(`${repo.dir}/config/serve-grants.json`));
  assertEquals(report.grant_files, 2);
  assertEquals(report.grants, gateway.vaults.length + gateway.workflows.length + serve.grants.length);
});
Deno.test("optional group after a quantifier in a late Gateway row is refused", async () => {
  await using repo = await fixture();
  await repo.change("gateway-workflow-grants", "key_pattern", "^database-server-[1-9][0-9]*(?:-client-[0-9]+)?$");
  const result = await repo.check();
  assertEquals(result.code, 1);
  assertStringIncludes(result.stderr, "catastrophic backtracking");
});
Deno.test("invalid regexes and complete CEL conditions in serve grants are refused", async () => {
  await using repo = await fixture();
  for (
    const [condition, error] of [['key.matches("[")', "regular expression"], [
      'key.matches("^safe$") && missing_field == 1',
      "CEL",
    ]]
  ) {
    await repo.change("serve-grants", "condition", condition);
    const result = await repo.check();
    assertEquals(result.code, 1);
    assertStringIncludes(result.stderr, error);
  }
});
Deno.test("unknown grant configuration cannot be silently skipped", async () => {
  await using repo = await fixture();
  await repo.write("config/extra-grants.json", '{"grants":[]}');
  const result = await repo.check();
  assertEquals(result.code, 1);
  assertStringIncludes(result.stderr, "complete release-renderer coverage");
});
Deno.test("missing account fails rather than reporting successful validation", async () => {
  await using repo = await fixture();
  const result = await repo.check(true);
  assertEquals(result.code, 1);
  assertStringIncludes(result.stderr, "auth_gate_blocked");
});
