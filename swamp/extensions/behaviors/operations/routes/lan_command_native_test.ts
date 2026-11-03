import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { exists, firewallFixture, LOCAL_PIN } from "../../../../tests/_support/route_fixture.ts";
Deno.test("LAN tuple addition leaves other Routes and legacy tags alone", async () => {
  await using f = await firewallFixture();
  const legacy = ["192.168.6.9 443/tcp ALLOW IN 192.168.6.2 # orbit:route-48-lan"];
  await f.initialize([], legacy);
  const { history } = await f.lan();
  assertEquals(f.readState().tuples, [f.tuple]);
  assertEquals(f.readState().legacy, legacy);
  assert(!f.calls().some((c) => c.slice(0, 2).join(" ") === "--force delete"));
  assertEquals(JSON.parse(String(history.jobs[0].steps.at(-1)!.outputs?.stdout)), { ready: true });
});
Deno.test("last LAN Route withdraws the official tuple without numbered deletion", async () => {
  await using f = await firewallFixture();
  await f.initialize([f.tuple]);
  await f.lan({ ensure: "absent" });
  assertEquals(f.readState().tuples, []);
  assert(f.calls().some((c) => c.slice(0, 2).join(" ") === "delete allow"));
  assert(!f.calls().some((c) => c.includes("numbered")));
});
Deno.test("LAN rerun is compliant", async () => {
  await using f = await firewallFixture();
  await f.initialize([f.tuple]);
  await f.lan();
  assertEquals(f.readState().tuples, [f.tuple]);
  assert(f.calls().every((c) => JSON.stringify(c) === '["status"]'));
});
for (const failure of ["fail_apply", "ignore_apply"]) {
  Deno.test(`LAN ${failure} fails the run`, async () => {
    await using f = await firewallFixture();
    await f.initialize([], [], { [failure]: true });
    const { run } = await f.lan({ success: false });
    assertEquals(run.status, "failed");
    assertEquals(f.readState().tuples, []);
  });
}
Deno.test("multiple LAN tuples use distinct native items", async () => {
  await using f = await firewallFixture();
  const second = { ...f.tuple, source: "192.168.6.3" };
  await f.lan({
    rules: [{ ...f.tuple, ensure: "present", model_name: "r1" }, { ...second, ensure: "present", model_name: "r2" }],
  });
  assertEquals(
    f.readState().tuples.sort((a: { source: string }, b: { source: string }) => a.source.localeCompare(b.source)),
    [f.tuple, second],
  );
});
Deno.test("LAN apply child reports ready", async () => {
  await using f = await firewallFixture();
  const { run } = await f.lan({ child: true });
  assertEquals(run.status, "succeeded");
  assertEquals(f.readState().tuples, [f.tuple]);
});
Deno.test("wrong or missing LAN pins refuse before any UFW command", async () => {
  await using f = await firewallFixture();
  const { run } = await f.lan({ pins: [LOCAL_PIN.slice(0, -4) + "AgIC"], success: false });
  assertEquals(run.status, "failed");
  assertEquals(f.readState().tuples, []);
  assertEquals(f.calls(), []);
  const result = await f.refused();
  assertEquals(result.stdout, "");
  assert(result.stderr.includes("host_keys"));
  assert(!exists(f.audit));
});
