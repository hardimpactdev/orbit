import { assertEquals, assertRejects } from "jsr:@std/assert@1";
import { settings } from "./settings.ts";

const input = {
  agent_pin: "a".repeat(64),
  storage: {
    root: "/srv/apps",
    user: "orbit",
    group: "orbit",
    create_missing: true,
  },
};
const args = {
  operation_id: 7,
  node_id: 9,
  kind: "node.settings" as const,
  run_id: "a075eb23-7ac4-4487-80d1-57a038a92676",
};
const context = {
  globalArgs: { gatewayUrl: "https://gateway.orbit", caFile: "/tmp/test-ca" },
};
function fixture() {
  const operation = {
    id: 7,
    node_id: 9,
    kind: "node.settings",
    status: "queued",
    input,
    result: null,
    cancel_requested: false,
  };
  const calls: { method: string; path: string; body: unknown }[] = [];
  const http = (method: string, path: string, body?: unknown) => {
    calls.push({ method, path, body });
    return Promise.resolve({ status: 200, body: { data: operation } });
  };
  return { operation, calls, http };
}
Deno.test("shared direct writer reads admitted input, calls one local action and projects success", async () => {
  const f = fixture();
  let actions = 0;
  await settings((actual) => {
    assertEquals(actual, input);
    actions++;
    return Promise.resolve(true);
  }, () => f.http).methods.execute.execute(args, context);
  assertEquals(actions, 1);
  assertEquals(f.calls.map((c) => c.path), [
    "/api/v1/direct-operations/7?run_id=" + args.run_id,
    "/api/v1/direct-operations/7/result",
    "/api/v1/direct-operations/7/result",
  ]);
  assertEquals((f.calls.at(-1)!.body as { result: unknown }).result, {
    prepared: true,
  });
});
Deno.test("typed local refusal projects its bounded error", async () => {
  const f = fixture();
  await assertRejects(() =>
    settings(() => Promise.resolve(false), () => f.http).methods.execute
      .execute(args, context)
  );
  assertEquals(
    (f.calls.at(-1)!.body as { error_code: string }).error_code,
    "node.settings_root_failed",
  );
});
Deno.test("an unbound first read never starts the local action", async () => {
  let actions = 0;
  await assertRejects(() =>
    settings(() => {
      actions++;
      return Promise.resolve(true);
    }, () => () => Promise.resolve({ status: 409, body: {} })).methods.execute
      .execute(args, context)
  );
  assertEquals(actions, 0);
});
Deno.test("another Node is refused before the local action", async () => {
  const f = fixture();
  f.operation.node_id = 10;
  let actions = 0;
  await assertRejects(() =>
    settings(() => {
      actions++;
      return Promise.resolve(true);
    }, () => f.http).methods.execute.execute(args, context)
  );
  assertEquals(actions, 0);
});
Deno.test("cancellation before the effect projects cancellation without calling root", async () => {
  const f = fixture();
  f.operation.cancel_requested = true;
  let actions = 0;
  await settings(() => {
    actions++;
    return Promise.resolve(true);
  }, () => f.http).methods.execute.execute(args, context);
  assertEquals(actions, 0);
  assertEquals(
    (f.calls.at(-1)!.body as { status: string }).status,
    "cancelled",
  );
});
Deno.test("uncertain local result fails natively without projecting false success", async () => {
  const f = fixture();
  await assertRejects(() =>
    settings(() => Promise.reject(new Error("uncertain")), () => f.http).methods
      .execute.execute(args, context)
  );
  assertEquals(f.calls.length, 2);
  assertEquals((f.calls.at(-1)!.body as { status: string }).status, "running");
});
