import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { removeLan } from "./lan.ts";
import { publication } from "./publication.ts";
import type { Http } from "../../automatic-deploy/gateway.ts";
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
Deno.test("native LAN removal projects its bound intent without Gateway execution", async () => {
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
    await publication(
      () => http,
      undefined,
      undefined,
      undefined,
      () => Promise.resolve({ matches: true }),
    ).methods.removeLan.execute({
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
