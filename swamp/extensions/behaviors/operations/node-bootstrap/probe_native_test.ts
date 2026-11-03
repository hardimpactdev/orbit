import { assertEquals, assertFalse, assertStringIncludes } from "jsr:@std/assert@1.0.19";
import { bootstrapFixture } from "../../../../tests/_support/bootstrap_fixture.ts";
import { outputs } from "../../../../tests/_support/platform_fixture.ts";

Deno.test("bootstrap probe uses the admitted target and keeps the vault identity private", async () => {
  const { repo, name, inputs, calls } = await bootstrapFixture();
  await using _ = repo;
  await repo.cli(["workflow", "validate", name]);
  const { run, history } = await repo.runWorkflow(name, inputs);
  assertEquals(run.status, "succeeded");
  assertEquals(JSON.parse(outputs(history, "probe-host").stdout).probed, true);
  assertStringIncludes(JSON.stringify(history), "bootstrap-ssh-99");
  const audit = await Deno.readTextFile(calls);
  assertStringIncludes(audit, "bootstrap@admitted-target");
  assertFalse(audit.includes("gateway.orbit"));
});
