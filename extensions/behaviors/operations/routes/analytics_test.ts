import { assertEquals } from "jsr:@std/assert@1.0.19";
import { ParentFixture as Fixture } from "./parent_fixture.ts";

Deno.test("analytics publication yields for certificates, Caddy and DNS in separate phases", async () => {
  const f = new Fixture();
  assertEquals((await f.execute()).status, "waiting");
  assertEquals([...f.children.keys()], [f.keys[0]]);
  f.settle();
  assertEquals((await f.execute()).status, "waiting");
  assertEquals([...f.children.keys()], f.keys.slice(0, 2));
  f.settle();
  assertEquals((await f.execute()).status, "waiting");
  f.settle();
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(f.children.size, 3);
  assertEquals(f.calls.filter((c) => c.path.endsWith("/wait")).length, 3);
  assertEquals(f.calls.filter((c) => c.path.endsWith("/complete")).length, 1);
});
Deno.test("failed analytics certificate proof never publishes a site or admits Caddy", async () => {
  const f = new Fixture();
  await f.execute();
  const child = f.children.get(f.keys[0])!;
  child.status = "failed";
  child.error_code = "certificate.request_invalid";
  assertEquals((await f.execute()).status, "failed");
  assertEquals(f.children.size, 1);
  assertEquals(
    f.calls.some((c) => c.body.key === "stage-open.publish-sites"),
    false,
  );
});
Deno.test("public analytics verifies the private hop before activating and opening the edge", async () => {
  const f = new Fixture();
  f.public = true;
  f.immediate = true;
  assertEquals((await f.execute()).status, "succeeded");
  const phases = f.calls.filter((c) => c.path.endsWith("/run")).map((c) =>
    c.body.key
  );
  assertEquals(
    phases.indexOf("stage-commit.public-edge-verified") <
      phases.indexOf("stage-open.public-activated"),
    true,
  );
  assertEquals(
    phases.indexOf("stage-commit.public-activated") <
      phases.indexOf("stage-open.ingress-firewall"),
    true,
  );
  assertEquals(
    [...f.children.values()].filter((child) =>
      child.kind === "certificate.converge"
    ).map((child) => child.node_id),
    [2],
  );
});
Deno.test("cancellation before an analytics effect admits no children", async () => {
  const f = new Fixture();
  f.operation.cancel_requested = true;
  assertEquals((await f.execute()).status, "cancelled");
  assertEquals(f.children.size, 0);
});
