import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { chart, routeFixture, skipped } from "../../../../tests/_support/route_fixture.ts";

Deno.test("Route target set runs the chart", async () => {
  await using f = await routeFixture("target-set");
  assertEquals((await f.perform()).run.status, "succeeded");
  await chart("operation-route-target-set", [
    "Make CSRs on new Nodes",
    "Sign the CSRs",
    "Install cert, publish site",
    "Stage the new targets",
    "Probe each new target",
    "Switch the Router pool",
    "Retire detached targets",
    "Move private DNS",
  ]);
});
Deno.test("development target set runs only the publish step", async () => {
  await using f = await routeFixture("target-set");
  f.pools("publish", 8);
  const { run, calls, steps } = await f.perform();
  assertEquals(run.status, "succeeded");
  assertEquals(calls[0].slice(-3), ["getent", "passwd", "orbit"]);
  assert(calls.some((c) => c.join(" ").includes("php-fpm8.5")));
  assertEquals(steps["Install cert, publish site-0"].status, "succeeded");
  assert(
    !Object.keys(steps).some((n) => n.startsWith("Stage the new targets-") || n.startsWith("Switch the Router pool-")),
  );
});
Deno.test("production target set switches Router after staging and before retirement", async () => {
  await using f = await routeFixture("target-set");
  f.pools("stage", 8);
  f.pools("router", 9);
  f.pools("retire", 7);
  f.intent.retire_nodes = [7];
  const { run, calls, steps } = await f.perform();
  assertEquals(run.status, "succeeded");
  assertEquals(calls.filter((c) => c.join(" ").includes("php-fpm8.5")).length, 3);
  assert(steps["Stage the new targets-0"].completedAt <= steps["Switch the Router pool-0"].startedAt);
  assert(steps["Switch the Router pool-0"].completedAt <= steps["Retire detached targets-0"].startedAt);
  assertEquals(steps["Switch the Router pool-0"].status, "succeeded");
});
for (const probe of [true, false]) {
  Deno.test(`failed target ${probe ? "probe" : "stage"} keeps the Router pool`, async () => {
    await using f = await routeFixture("target-set");
    f.pools("stage", 8);
    f.pools("router", 9);
    if (probe) f.intent.probes = [{ node_id: 8, address: "127.0.0.1", domain: "shop.test" }];
    const { run, steps } = await f.perform(probe ? 0 : 1, false);
    assertEquals(run.status, "failed");
    assertEquals(steps[probe ? "Probe each new target-0" : "Stage the new targets-0"].status, "failed");
    skipped(steps, "Switch the Router pool");
  });
}
