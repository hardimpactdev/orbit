import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { routeFixture, skipped } from "../../../../tests/_support/route_fixture.ts";

Deno.test("state-only Route create touches no Node", async () => {
  await using f = await routeFixture("create");
  const { run, calls, steps } = await f.perform();
  assertEquals([run.status, calls], ["succeeded", []]);
  assert(
    Object.keys(steps).every((n) =>
      [
        "Make a CSR on the Node",
        "Sign the CSR",
        "Allow the Router on LAN",
        "Install cert, publish site",
        "Probe the site over TLS",
        "Open the Ingress edge",
        "Publish private DNS",
      ].includes(n)
    ),
  );
});
Deno.test("Route create grants run in list order inside the site step", async () => {
  await using f = await routeFixture("create");
  const paths = ["/home", "/home/orbit", "/home/orbit/apps"];
  f.intent.site_nodes = [7];
  f.intent.acl = paths.map((path) => ({ node_id: 7, argv: ["/usr/bin/setfacl", "-m", "u:caddy:--x", "--", path] }));
  const { run, calls, steps } = await f.perform();
  assertEquals(run.status, "succeeded");
  assertEquals(calls[0].slice(-3), ["getent", "passwd", "orbit"]);
  const grants = calls.filter((c) => c.includes("/usr/bin/setfacl"));
  assertEquals(grants.map((c) => c.at(-1)), paths);
  assertEquals(grants[0].slice(0, 5), ["sudo", "-n", "--", "timeout", "120"]);
  assertEquals(steps["Install cert, publish site-7"].status, "succeeded");
});
Deno.test("failed Route create command stops PHP tests and probes", async () => {
  await using f = await routeFixture("create");
  f.intent.site_nodes = [7];
  f.intent.acl = [{ node_id: 7, argv: ["/usr/bin/setfacl", "-m", "u:caddy:--x", "--", "/home"] }];
  f.intent.php_tests = [{ node_id: 7, argv: ["/usr/sbin/php-fpm8.5", "-t"] }];
  f.intent.probes = [{ node_id: 7, address: "127.0.0.1", domain: "shop.test" }];
  const { run, calls, steps } = await f.perform(1, false);
  assertEquals(run.status, "failed");
  assert(!calls.some((c) => c.join(" ").includes("php-fpm8.5")));
  assertEquals(steps["Install cert, publish site-7"].status, "failed");
  skipped(steps, "Probe the site over TLS");
});
Deno.test("failed Route create TLS probe fails the run", async () => {
  await using f = await routeFixture("create");
  f.intent.probes = [{ node_id: 7, address: "127.0.0.1", domain: "shop.test" }];
  const { run, steps } = await f.perform(0, false);
  assertEquals(run.status, "failed");
  assert(Object.entries(steps).some(([n, s]) => n.startsWith("Probe the site over TLS") && s.status === "failed"));
});
