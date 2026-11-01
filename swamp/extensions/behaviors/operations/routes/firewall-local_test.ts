import { assertEquals, assertRejects } from "jsr:@std/assert@1";
import { convergeFirewall, removeRouteLan } from "./firewall-local.ts";
Deno.test("Route LAN direct program receives only typed exact Route data", async () => {
  let sent: unknown;
  assertEquals(
    await removeRouteLan(
      { operation: "remove", route_id: 7 },
      (_source, input) => {
        sent = input;
        return Promise.resolve({ matches: true });
      },
    ),
    { matches: true },
  );
  assertEquals(sent, { mode: "route", operation: "remove", route_id: 7 });
  for (const operation of ["converge", "retire"]) {
    await assertRejects(() =>
      removeRouteLan({ operation, route_id: 7 }, () => {
        throw new Error("must not invoke");
      })
    );
  }
  await assertRejects(() =>
    removeRouteLan({ operation: "remove", route_id: 0 }, () => {
      throw new Error("must not invoke");
    })
  );
});
Deno.test("role firewall sends a fixed program and strips no foreign inputs", async () => {
  let sent: unknown;
  assertEquals(
    await convergeFirewall(
      { desired: [], remove: [], enable: false },
      (_source, input) => {
        sent = input;
        return Promise.resolve({ matches: true });
      },
    ),
    { matches: true },
  );
  assertEquals(sent, { mode: "rules", desired: [], remove: [], enable: false });
  await assertRejects(() =>
    convergeFirewall({
      desired: [],
      remove: [],
      enable: false,
      command: "anything",
    })
  );
});
Deno.test("direct firewall propagates bounded ownership refusal", async () => {
  assertEquals(
    await convergeFirewall(
      { desired: [], remove: [], enable: false },
      () => Promise.resolve({ error_code: "node.firewall_convergence_failed" }),
    ),
    { error_code: "node.firewall_convergence_failed" },
  );
});
