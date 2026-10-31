import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { removeLan } from "./lan.ts";
import { publication } from "./publication.ts";
import type { Http } from "../../automatic-deploy/gateway.ts";

const input = { route_id: 23, agent_sha256: "a".repeat(64) };
Deno.test("LAN removal uses only the exact admitted Route comment and pinned local agent", async () => {
  const calls: unknown[] = [];
  assertEquals(
    await removeLan(input, (call) => {
      calls.push(call);
      return Promise.resolve({ matches: true });
    }),
    { ready: true },
  );
  assertEquals(calls, [{
    name: "firewall.route-lan",
    input: { operation: "remove", route_id: 23 },
    pin: input.agent_sha256,
    sudo: true,
    mutates: true,
    deadlineMs: 120000,
    maxOutputBytes: 4096,
  }]);
});
Deno.test("LAN removal refuses worker-selected fields and requires proven absence", async () => {
  await assertRejects(() => removeLan({ ...input, operation: "converge" }));
  assertEquals(
    await removeLan(input, () => Promise.resolve({ matches: false })),
    { error_code: "node.firewall_convergence_failed" },
  );
  assertEquals(
    await removeLan(input, () =>
      Promise.resolve({
        error_code: "firewall.rule_conflict",
        stderr: "private diagnostic",
      })),
    { error_code: "firewall.rule_conflict" },
  );
});
Deno.test("native LAN removal reads and projects the same bound parent without a Gateway executor", async () => {
  const calls: string[] = [];
  const http: Http = (method, path) => {
    calls.push(path);
    return Promise.resolve({
      status: 200,
      body: {
        data: method === "GET"
          ? {
            id: 17,
            kind: "route.lan_remove",
            node_id: 9,
            status: "queued",
            cancel_requested: false,
            result: null,
            input,
          }
          : {},
      },
    });
  };
  assertEquals(
    await publication(() => http, () => Promise.resolve({ matches: true }))
      .methods.removeLan.execute({
        operation_id: 17,
        kind: "route.lan_remove",
        node_id: 9,
        run_id: "a075eb23-7ac4-4487-80d1-57a038a92676",
      }, {
        globalArgs: { gatewayUrl: "https://gateway.test", caFile: "/unused" },
      }),
    { status: "succeeded" },
  );
  assertEquals(calls.length, 3);
  assertEquals(
    calls.some((path) => /claim|heartbeat|primitive|children/.test(path)),
    false,
  );
});
