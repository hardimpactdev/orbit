import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.14";
import { scheduleControl } from "./control.ts";
const args = {
  operation_id: 17,
  node_id: 9,
  kind: "schedule.run" as const,
  run_id: "a075eb23-7ac4-4487-80d1-57a038a92676",
};
const input = {
  id: "9a067a27-c2ce-45c5-83f5-735e03dc5b2f",
  user: "orbit",
  working_directory: "/home/orbit",
};
function fixture() {
  const calls: { method: string; path: string; body: unknown }[] = [];
  const seen: unknown[] = [];
  const current = {
    id: 17,
    node_id: 9,
    kind: "schedule.run",
    input: { ...input },
    cancel_requested: false,
    status: "queued",
    result: null as unknown,
  };
  let unbound = false;
  const control = scheduleControl(() => (method, path, body) => {
    calls.push({ method, path, body });
    return Promise.resolve({
      status: unbound ? 409 : 200,
      body: { data: current },
    });
  }, (kind, admitted) => {
    seen.push({ kind, input: admitted });
    return Promise.resolve({ ready: true });
  });
  const ctx = {
    globalArgs: { gatewayUrl: "https://gateway.orbit", caFile: "test-ca" },
  };
  return {
    control,
    ctx,
    calls,
    seen,
    current,
    refuseUnbound: () => {
      unbound = true;
    },
  };
}
Deno.test("shared protocol passes only admitted input to the local Schedule action", async () => {
  const f = fixture();
  assertEquals(await f.control.methods.control.execute(args, f.ctx), {
    status: "succeeded",
  });
  assertEquals(f.seen, [{ kind: args.kind, input }]);
  assertEquals(f.calls.map((call) => call.method), ["GET", "POST", "POST"]);
  assertEquals(
    f.calls.every((call) =>
      call.path.startsWith("/api/v1/direct-operations/17")
    ),
    true,
  );
  assertEquals((f.calls[2].body as Record<string, unknown>).result, {
    ready: true,
  });
});
Deno.test("unbound first read fails before any action or worker projection", async () => {
  const f = fixture();
  f.refuseUnbound();
  await assertRejects(() => f.control.methods.control.execute(args, f.ctx));
  assertEquals(f.seen, []);
  assertEquals(f.calls.length, 1);
});
Deno.test("foreign admitted Node is refused by the shared binding", async () => {
  const f = fixture();
  f.current.node_id = 8;
  await assertRejects(
    () => f.control.methods.control.execute(args, f.ctx),
    Error,
    "does not match",
  );
  assertEquals(f.seen, []);
  assertEquals(f.calls.length, 1);
});
Deno.test("an already projected result is read without another action", async () => {
  const f = fixture();
  f.current.status = "succeeded";
  f.current.result = { ready: true };
  assertEquals(await f.control.methods.control.execute(args, f.ctx), {
    status: "succeeded",
  });
  assertEquals(f.seen, []);
  assertEquals(f.calls.length, 1);
});
Deno.test("cancellation is checked before the action", async () => {
  const f = fixture();
  f.current.cancel_requested = true;
  assertEquals(await f.control.methods.control.execute(args, f.ctx), {
    status: "cancelled",
  });
  assertEquals(f.seen, []);
});
Deno.test("non-admitted commands are rejected before the Node action", async () => {
  const f = fixture();
  Object.assign(f.current.input, { command: "unadmitted" });
  await assertRejects(() => f.control.methods.control.execute(args, f.ctx));
  assertEquals(f.seen, []);
});
