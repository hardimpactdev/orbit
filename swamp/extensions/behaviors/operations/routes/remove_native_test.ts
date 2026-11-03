import { assertEquals } from "jsr:@std/assert@1.0.19";
import { routeFixture, skipped } from "../../../../tests/_support/route_fixture.ts";

Deno.test("offline Route removal skips the Node that does not answer", async () => {
  await using f = await routeFixture("removal");
  f.pools("pools", 7, 8);
  Object.assign(f.intent, { offline: true, answer_nodes: [7, 8], answer_index: { "7": 0, "8": 1 } });
  const { run, calls, steps } = await f.perform(0, true, "node-8.invalid");
  assertEquals(run.status, "succeeded");
  assertEquals(steps["Check each Node answers-1"].status, "failed");
  assertEquals(calls.filter((c) => c.join(" ").includes("php-fpm8.5")).length, 1);
  assertEquals(steps["Converge PHP-FPM pools-0"].status, "succeeded");
  assertEquals(steps["Converge PHP-FPM pools-1"].status, "skipped");
});
Deno.test("Route removal without offline stops after a failed Node command", async () => {
  await using f = await routeFixture("removal");
  f.pools("pools", 7);
  f.intent.check_nodes = [7];
  const { run, steps } = await f.perform(1, false);
  assertEquals(run.status, "failed");
  assertEquals(steps["Converge PHP-FPM pools-0"].status, "failed");
  skipped(steps, "Check nothing is left");
});
