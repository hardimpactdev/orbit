import { assertEquals, assertRejects } from "jsr:@std/assert@1";
import {
  convergeFirewall,
  convergeRouteLan,
  removeRouteLan,
} from "./firewall-local.ts";
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
  await assertRejects(() =>
    convergeRouteLan({
      operation: "converge",
      route_id: 7,
      router: "not-ip",
      workload: "192.168.6.18",
    }, () => {
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
