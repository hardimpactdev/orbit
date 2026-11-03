import { assertEquals } from "jsr:@std/assert@1.0.19";
import { nativeRepo } from "../../tests/_support/native_harness.ts";

Deno.test("empty group fanout succeeds", async () => {
  await using repo = await nativeRepo();
  for (const name of ["group-fanout", "group-supervise"]) await repo.workflow(name);
  const { run } = await repo.runWorkflow("group-fanout", { groups: [], conflicts: [] });
  assertEquals(run.status, "succeeded");
});
