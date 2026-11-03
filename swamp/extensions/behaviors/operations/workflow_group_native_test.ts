import { assertEquals } from "jsr:@std/assert@1.0.19";
import { nativeRepo } from "../../../tests/_support/native_harness.ts";

Deno.test("empty group fanout succeeds", async () => {
  await using repo = await nativeRepo();
  await repo.workflow("group-fanout");
  assertEquals((await repo.runWorkflow("group-fanout", { groups: [], conflicts: [] })).run.status, "succeeded");
});
