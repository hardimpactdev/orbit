import { nativeRepo } from "../../tests/_support/native_harness.ts";
import { copy } from "../../tests/_support/platform_fixture.ts";

Deno.test("private callbacks leave no credential bytes in native outputs or scratch files", async () => {
  await using repo = await nativeRepo();
  await repo.copyBehaviors("automatic-deploy", "operations/_lib");
  await copy(repo, "tests/fixtures/private-material/mod.ts", "extensions/behaviors/private_material/mod.ts");
  await copy(repo, "tests/fixtures/private-material/workflow.yaml", "workflows/workflow-private-material-proof.yaml");
  const secret = `private-sentinel-${crypto.randomUUID()}`;
  repo.secrets.push(secret);
  repo.env.ORBIT_PRIVATE_TEST_SENTINEL = secret;
  await repo.cli(["workflow", "validate", "private-material-proof"]);
  for (const attack of ["safe", "direct", "nested", "getter", "toJSON"]) {
    await repo.runWorkflow("private-material-proof", { failure: false, attack });
  }
  await repo.runWorkflow("private-material-proof", { failure: true, attack: "safe" }, { success: false });
});
