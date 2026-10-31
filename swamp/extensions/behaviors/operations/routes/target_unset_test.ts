import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import type { Http } from "../../automatic-deploy/gateway.ts";
import { targetUnset } from "./target-unset.ts";
const args = {
  operation_id: 17,
  kind: "route.target_unset" as const,
  node_id: 9,
  run_id: "a075eb23-7ac4-4487-80d1-57a038a92676",
};
function fixture(change: Record<string, unknown> = {}) {
  const calls: { path: string; body: unknown }[] = [];
  const http: Http = (method, path, body) => {
    calls.push({ path, body });
    return Promise.resolve({
      status: 200,
      body: {
        data: method === "GET"
          ? {
            id: 17,
            kind: args.kind,
            node_id: 9,
            status: "queued",
            cancel_requested: false,
            result: null,
            input: { route_id: 23, target_ids: [] },
            ...change,
          }
          : {},
      },
    });
  };
  return {
    calls,
    run: () =>
      targetUnset(() => http).methods.confirm.execute(args, {
        globalArgs: { gatewayUrl: "https://gateway.test", caFile: "/unused" },
      }),
  };
}
Deno.test("unset confirms empty admitted targets using only read and result", async () => {
  const f = fixture();
  assertEquals(await f.run(), { status: "succeeded" });
  assertEquals(f.calls.map((c) => c.path), [
    `/api/v1/direct-operations/17?run_id=${args.run_id}`,
    "/api/v1/direct-operations/17/result",
    "/api/v1/direct-operations/17/result",
  ]);
  assertEquals((f.calls.at(-1)!.body as Record<string, unknown>).result, {
    ready: true,
  });
});
Deno.test("unset refuses targets reappearing instead of clearing them in a worker", async () => {
  const f = fixture({ input: { route_id: 23, target_ids: [41] } });
  await assertRejects(f.run);
  assertEquals((f.calls.at(-1)!.body as Record<string, unknown>).result, {
    error_code: "route.target_conflict",
  });
});
Deno.test("unset respects cancellation and refuses foreign identity", async () => {
  const cancelled = fixture({ cancel_requested: true });
  assertEquals(await cancelled.run(), { status: "cancelled" });
  for (
    const change of [{ node_id: 14 }, { kind: "route.target_set" }, { id: 18 }]
  ) {
    const f = fixture(change);
    await assertRejects(f.run);
    assertEquals(f.calls.length, 1);
  }
});
