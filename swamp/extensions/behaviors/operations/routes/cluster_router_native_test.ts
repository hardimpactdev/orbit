import { assertEquals } from "jsr:@std/assert@1.0.19";
import { routeFixture } from "../../../../tests/_support/route_fixture.ts";

Deno.test("cluster Router unset runs its chart", async () => {
  await using f = await routeFixture("cluster");
  const { run, calls } = await f.perform(0, true, "", true);
  assertEquals([run.status, calls], ["succeeded", []]);
});

Deno.test("cluster Router restores only after publishing failed", async () => {
  await using f = await routeFixture("cluster");
  f.pools("publish", 7);
  f.pools("restore", 7);
  const { run, steps } = await f.perform(1, false);
  assertEquals(run.status, "failed");
  assertEquals(steps["Install certs, publish-0"].status, "failed");
  assertEquals(steps["Restore the before state"].status, "failed");
});
Deno.test("passing cluster Router change restores nothing", async () => {
  await using f = await routeFixture("cluster");
  f.pools("publish", 7);
  f.pools("retire", 5);
  f.intent.retire_nodes = [5];
  const { run, steps } = await f.perform();
  assertEquals(run.status, "succeeded");
  assertEquals(steps["Retire the old Router-0"].status, "succeeded");
  assertEquals(steps["Restore the before state"].status, "skipped");
});
Deno.test("failed cluster Router retirement never restores the old Router", async () => {
  await using f = await routeFixture("cluster");
  f.pools("retire", 5);
  f.intent.retire_nodes = [5];
  const { steps } = await f.perform(1, false);
  assertEquals(steps["Retire the old Router-0"].status, "failed");
  assertEquals(steps["Restore the before state"].status, "skipped");
});
