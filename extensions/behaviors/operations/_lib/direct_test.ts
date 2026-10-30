import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import {
  directOperation,
  DirectOperationCancelled,
  GatewayOperation,
} from "./direct.ts";

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

Deno.test("native first read waits only for an unbound run before any projection or effect", async () => {
  const f = fixture();
  const calls: string[] = [];
  let bound = false;
  const gateway = new GatewayOperation(
    async (method, path, body) => {
      calls.push(method);
      assertEquals(path.startsWith("/api/v1/direct-operations/71"), true);
      if (!bound) {
        assertEquals(method, "GET");
        assertEquals(f.effects(), 0);
        return {
          status: 409,
          body: { error: { code: "operation.run_unbound" } },
        };
      }
      if (method === "POST") {
        await f.gateway.project(
          body as Record<string, unknown>,
        );
      }
      return { status: 200, body: { data: await f.gateway.read() } };
    },
    args,
    () => {
      bound = true;
      return Promise.resolve();
    },
  );

  await directOperation(args, gateway, f.action, (r) => r);

  assertEquals(calls, ["GET", "GET", "POST", "POST"]);
  assertEquals(f.effects(), 1);
});

Deno.test("native binding wait stops on its deadline or cancellation without effects", async () => {
  for (const cancel of [false, true]) {
    let now = 0;
    let reads = 0;
    const controller = new AbortController();
    const gateway = new GatewayOperation(
      () => {
        reads++;
        return Promise.resolve({
          status: 409,
          body: { error: { code: "operation.run_unbound" } },
        });
      },
      args,
      () => {
        now = 90_000;
        if (cancel) controller.abort(new Error("native cancellation"));
        return Promise.resolve();
      },
      () => now,
    );

    await assertRejects(
      () => gateway.read(controller.signal),
      Error,
      cancel ? "native cancellation" : "deadline expired",
    );
    assertEquals(reads, 1);
  }
});

Deno.test("native first read never waits for other errors and never retries a mutation", async () => {
  for (
    const [status, code] of [[409, "operation.run_mismatch"], [
      403,
      "node.access_denied",
    ], [503, "operation.run_unbound"]] as const
  ) {
    let calls = 0;
    const gateway = new GatewayOperation(
      () => {
        calls++;
        return Promise.resolve({ status, body: { error: { code } } });
      },
      args,
      () => {
        throw new Error("must not wait");
      },
    );
    await assertRejects(() => gateway.read(), Error, "request did not succeed");
    assertEquals(calls, 1);
  }
  let calls = 0;
  const gateway = new GatewayOperation(
    () => {
      calls++;
      return Promise.resolve({
        status: 409,
        body: { error: { code: "operation.run_unbound" } },
      });
    },
    args,
    () => {
      throw new Error("must not wait");
    },
  );
  await assertRejects(() => gateway.primitive(), Error, "not bound");
  assertEquals(calls, 1);
});

Deno.test("native cancellation bounds an unresolved first read without effects", async () => {
  const controller = new AbortController();
  let calls = 0;
  const gateway = new GatewayOperation(() => {
    calls++;
    return new Promise(() => {});
  }, args);
  const pending = gateway.read(controller.signal);
  controller.abort(new Error("cancel pending read"));
  await assertRejects(() => pending, Error, "cancel pending read");
  assertEquals(calls, 1);
});

Deno.test("a read-only pending wait observes Gateway cancellation before accepting an applied result", async () => {
  const f = fixture();
  let cancelRequested = false;
  let status = "pending";
  const gateway = {
    ...f.gateway,
    read: async () => ({
      ...await f.gateway.read(),
      cancel_requested: cancelRequested,
    }),
  };
  const result = await directOperation(args, gateway, async () => {
    assertEquals(status, "pending");
    cancelRequested = true;
    status = "applied";
    const current = await gateway.read();
    if (current.cancel_requested) {
      throw new DirectOperationCancelled({ effectRunning: false });
    }
    return { status };
  }, (r) => r);

  assertEquals(result, { status: "cancelled" });
  assertEquals(f.effects(), 0);
  assertEquals(f.projected.map((p) => p.status), ["running", "cancelled"]);
  assertEquals(f.projected[1].result, {});
  assertEquals(f.projected[1].error_code, null);
});

Deno.test("only explicit no-effect cancellation can end a running direct action as cancelled", async () => {
  for (
    const error of [
      new Error("uncertain root mutation"),
      new DOMException("native abort during mutation", "AbortError"),
    ]
  ) {
    const f = fixture();
    await assertRejects(() =>
      directOperation(args, f.gateway, () => {
        throw error;
      }, (r) => r)
    );
    assertEquals(f.projected.map((p) => p.status), ["running"]);
  }
  const f = fixture();
  const controller = new AbortController();
  assertEquals(
    await directOperation(
      args,
      f.gateway,
      () => {
        controller.abort();
        return Promise.resolve({ applied: true });
      },
      (r) => r,
      controller.signal,
    ),
    { status: "succeeded" },
  );
  assertEquals(f.projected[1].result, { applied: true });
});
