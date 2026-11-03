import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { exists, firewallFixture, LOCAL_PIN, run } from "../../../../tests/_support/route_fixture.ts";
async function fixture() {
  const f = await firewallFixture();
  await f.initialize([f.tuple], [], { backend: "active" });
  const ingress = (ensure: string, success = true, pins = [LOCAL_PIN]) =>
    run(
      f.repo,
      "route-ingress-apply",
      {
        rule: { node_id: 9, rules: [80, 443].map((port) => ({ port, ensure })) },
        control_node_id: 209,
        connection: f.connection,
        host_keys: pins,
      },
      "route.update",
      success,
    );
  return { ...f, ingress };
}
Deno.test("public ingress add and withdrawal preserve source LAN rules", async () => {
  await using f = await fixture();
  await f.ingress("present");
  const rules = f.readState().tuples;
  for (const row of [f.tuple, ...[80, 443].map((port) => ({ port, protocol: "tcp", source: "Anywhere" }))]) {
    assert(rules.some((r: unknown) => JSON.stringify(r) === JSON.stringify(row)));
  }
  await f.ingress("absent");
  assertEquals(f.readState().tuples, [f.tuple]);
});
Deno.test("zero-effect success cannot prove ingress", async () => {
  await using f = await fixture();
  await f.initialize([f.tuple], [], { ignore_apply: true });
  await f.ingress("present", false);
  assertEquals(f.readState().tuples, [f.tuple]);
});
Deno.test("wrong ingress pin cannot observe or change public ports", async () => {
  await using f = await fixture();
  await f.ingress("present", false, [LOCAL_PIN.slice(0, -4) + "AgIC"]);
  assert(!exists(f.audit));
  assertEquals(f.readState().tuples, [f.tuple]);
});
Deno.test("repeated ingress publication is a no-op and keeps legacy LAN tags", async () => {
  await using f = await fixture();
  const legacy = ["192.168.6.9 443/tcp ALLOW IN 192.168.6.2 # orbit:route-47-lan"];
  await f.initialize([f.tuple], legacy);
  await f.ingress("present");
  await Deno.remove(f.audit);
  await f.ingress("present");
  assertEquals(f.calls(), [["status"], ["status"]]);
  assertEquals(f.readState().legacy, legacy);
});
