import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { chart, routeFixture, skipped } from "../../../../tests/_support/route_fixture.ts";

Deno.test("Route update runs the chart with no Node effects for an empty plan", async () => {
  await using f = await routeFixture("update");
  const { run, calls } = await f.perform();
  assertEquals([run.status, calls], ["succeeded", []]);
  await chart("operation-route-update", [
    "Make a CSR on the Node",
    "Sign the CSR",
    "Install cert, publish site",
    "Write the new APP_URL",
    "Probe the site over TLS",
    "Open or close the edge",
    "Move private DNS",
    "Wait the DNS grace",
    "Withdraw the old site",
  ]);
});
Deno.test("Route update retires the old site only after publish and DNS grace", async () => {
  await using f = await routeFixture("update");
  f.pools("publish", 7);
  f.pools("retire", 7);
  f.intent.retire_nodes = [7];
  f.intent.grace = [{ seconds: 1 }];
  const { run, calls, steps } = await f.perform();
  assertEquals(run.status, "succeeded");
  assertEquals(calls.filter((c) => c.join(" ").includes("php-fpm8.5")).length, 2);
  assert(steps["Install cert, publish site-0"].completedAt <= steps["Wait the DNS grace-0"].startedAt);
  assert(steps["Wait the DNS grace-0"].completedAt <= steps["Withdraw the old site-0"].startedAt);
});
Deno.test("Route update publish failure stops subsequent steps", async () => {
  await using f = await routeFixture("update");
  f.pools("publish", 7);
  f.pools("app_url", 8);
  const { run, steps } = await f.perform(1, false);
  assertEquals(run.status, "failed");
  assertEquals(steps["Install cert, publish site-0"].status, "failed");
  skipped(steps, "Write the new APP_URL");
});

Deno.test("failed Route update probe keeps the old site", async () => {
  await using f = await routeFixture("update");
  f.pools("retire", 7);
  f.intent.retire_nodes = [7];
  f.intent.probes = [{ node_id: 7, address: "127.0.0.1", domain: "shop.test" }];
  const { run, calls, steps } = await f.perform(0, false);
  assertEquals(run.status, "failed");
  assertEquals(steps["Probe the site over TLS-0"].status, "failed");
  assertEquals(calls, []);
  skipped(steps, "Withdraw the old site");
});
