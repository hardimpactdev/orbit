import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { removeLan } from "./lan.ts";
import { publication } from "./publication.ts";
const input = { route_id: 23 };
Deno.test("LAN removal invokes the fixed program for the exact admitted Route", async () => {
  const calls: unknown[] = [];
  assertEquals(
    await removeLan(input, (_program, payload) => {
      calls.push(payload);
      return Promise.resolve({ matches: true });
    }),
    { ready: true },
  );
  assertEquals(calls, [{ mode: "route", operation: "remove", route_id: 23 }]);
});
Deno.test("LAN removal refuses worker-selected fields and untyped absence", async () => {
  await assertRejects(() => removeLan({ ...input, operation: "converge" }));
  await assertRejects(() =>
    removeLan(input, () => Promise.resolve({ matches: false }))
  );
  assertEquals(
    await removeLan(
      input,
      () => Promise.resolve({ error_code: "firewall.rule_collision" }),
    ),
    { error_code: "firewall.rule_collision" },
  );
});
Deno.test("standalone LAN withdrawal has no Gateway callback behavior", () => {
  assertEquals("removeLan" in publication().methods, false);
});
