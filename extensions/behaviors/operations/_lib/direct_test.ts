import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { directOperation, GatewayOperation } from "./direct.ts";

const args = {
  operation_id: 71,
  kind: "sample.apply",
  node_id: null,
  run_id: "a075eb23-7ac4-4487-80d1-57a038a92676",
};
function fixture() {
  const projected: Record<string, unknown>[] = [];
  let effects = 0;
  const gateway = {
    read: () =>
      Promise.resolve({
        id: 71,
        kind: args.kind,
        node_id: null,
        status: "queued",
        result: null,
        cancel_requested: false,
      }),
    project: (data: Record<string, unknown>) => {
      projected.push(data);
      return Promise.resolve(null);
    },
  };
  return {
    gateway,
    projected,
    action: () => {
      effects++;
      return Promise.resolve({ changed: true });
    },
    effects: () => effects,
  };
}
Deno.test("shared template supports a typed local action with no Gateway effect call", async () => {
  const f = fixture();
  assertEquals(await directOperation(args, f.gateway, f.action, (r) => r), {
    status: "succeeded",
  });
  assertEquals(f.effects(), 1);
  assertEquals(f.projected.map((p) => p.status), ["running", "succeeded"]);
  assertEquals(f.projected[1].result, { changed: true });
});
Deno.test("shared template gives a local Node action only the admitted safe input", async () => {
  const f = fixture();
  const read = f.gateway.read;
  const seen: unknown[] = [];
  await directOperation(args, {
    ...f.gateway,
    read: async () => ({ ...await read(), input: { instance_id: 42 } }),
  }, (input) => {
    seen.push(input);
    return Promise.resolve({ ready: true });
  }, (r) => r);
  assertEquals(seen, [{ instance_id: 42 }]);
});
Deno.test("shared template refuses foreign operation identity before action or projection", async () => {
  for (
    const change of [{ id: 72 }, { kind: "foreign.apply" }, { node_id: 9 }]
  ) {
    const f = fixture();
    const read = f.gateway.read;
    const gateway = {
      ...f.gateway,
      read: async () => ({ ...await read(), ...change }),
    };
    await assertRejects(
      () => directOperation(args, gateway, f.action, (r) => r),
      Error,
      "does not match",
    );
    assertEquals(f.effects(), 0);
    assertEquals(f.projected, []);
  }
});
Deno.test("shared template leaves native failure handling in Swamp and never retries an action", async () => {
  const f = fixture();
  let attempts = 0;
  await assertRejects(() =>
    directOperation(args, f.gateway, () => {
      attempts++;
      throw new Error("uncertain external result");
    }, (r) => r)
  );
  assertEquals(attempts, 1);
  assertEquals(f.projected.map((p) => p.status), ["running"]);
});
Deno.test("shared template checks cancellation before a local Node action", async () => {
  const f = fixture();
  const controller = new AbortController();
  controller.abort();
  assertEquals(
    await directOperation(
      args,
      f.gateway,
      f.action,
      (r) => r,
      controller.signal,
    ),
    { status: "cancelled" },
  );
  assertEquals(f.effects(), 0);
});
Deno.test("shared template projects safe family refusals and rejects free-form diagnostics", async () => {
  const f = fixture();
  await assertRejects(
    () =>
      directOperation(
        args,
        f.gateway,
        () => Promise.resolve({ error_code: "sample.refused" }),
        (r) => r,
      ),
    Error,
    "sample.refused",
  );
  assertEquals(f.projected[1].error_code, "sample.refused");
  const g = fixture();
  await assertRejects(() =>
    directOperation(
      args,
      g.gateway,
      () => Promise.resolve({ error_code: "x".repeat(101) }),
      (r) => r,
    )
  );
  assertEquals(g.projected.length, 1);
});
Deno.test("shared gateway client binds every projection to the workflow run and hides remote diagnostics", async () => {
  const calls: unknown[] = [];
  const gateway = new GatewayOperation(
    (_m, path, body) => {
      calls.push({ path, body });
      return Promise.resolve({ status: 200, body: { data: {} } });
    },
    args,
  );
  await gateway.project({ status: "running", run_id: "foreign" });
  assertEquals(calls, [{
    path: "/api/v1/direct-operations/71/result",
    body: { status: "running", run_id: args.run_id },
  }]);
  const bad = new GatewayOperation(
    () =>
      Promise.resolve({ status: 503, body: { error: "private credential" } }),
    args,
  );
  await assertRejects(
    () => bad.read(),
    Error,
    "Direct Operation request did not succeed",
  );
});
