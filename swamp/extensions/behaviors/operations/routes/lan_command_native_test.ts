import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { firewallFixture } from "../../../../tests/_support/route_fixture.ts";
Deno.test("LAN tuple addition leaves other Routes and legacy tags alone", async () => {
  await using f = await firewallFixture();
  const legacy = ["192.168.6.9 443/tcp ALLOW IN 192.168.6.2 # orbit:route-48-lan"];
  await f.initialize([], legacy);
  const second = { ...f.tuple, source: "192.168.6.3" };
  const { run, history } = await f.lan({
    rules: [{ ...f.tuple, ensure: "present", model_name: "r1" }, { ...second, ensure: "present", model_name: "r2" }],
  });
  assertEquals(run.status, "succeeded");
  assertEquals(f.readState().tuples.sort((a: { source: string }, b: { source: string }) => a.source.localeCompare(b.source)), [f.tuple, second]);
  assertEquals(f.readState().legacy, legacy);
  assert(!f.calls().some((c) => c.slice(0, 2).join(" ") === "--force delete"));
  assertEquals(JSON.parse(String(history.jobs[0].steps.at(-1)!.outputs?.result_json)), { ready: true });
});
Deno.test("last LAN Route withdraws the official tuple without numbered deletion", async () => {
  await using f = await firewallFixture();
  await f.initialize([f.tuple]);
  await f.lan({ ensure: "absent" });
  assertEquals(f.readState().tuples, []);
  assert(f.calls().some((c) => c.slice(0, 2).join(" ") === "delete allow"));
  assert(!f.calls().some((c) => c.includes("numbered")));
});

Deno.test("LAN ignore_apply fails the run", async () => {
  await using f = await firewallFixture();
  await f.initialize([], [], { ignore_apply: true });
  const { run } = await f.lan({ success: false });
  assertEquals(run.status, "failed");
  assertEquals(f.readState().tuples, []);
});
