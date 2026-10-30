import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import type { Http } from "../../automatic-deploy/gateway.ts";
import { websocketRegistry } from "./websocket_direct.ts";

const args = {
  operation_id: 71,
  kind: "websocket.sync" as const,
  node_id: 14,
  run_id: "a075eb23-7ac4-4487-80d1-57a038a92676",
};
const id = "b075eb23-7ac4-4487-80d1-57a038a92676";
const ctx = {
  globalArgs: { gatewayUrl: "https://gateway.orbit", caFile: "/test/ca.pem" },
};
class Fixture {
  bound = true;
  states = ["pending", "applied"];
  registryId = id;
  clock = 0;
  increment = 2000;
  reads = 0;
  onWait?: () => void;
  onRegistryRead?: () => void;
  calls: { method: string; path: string; body?: unknown }[] = [];
  operation = {
    ...args,
    kind: args.kind as string,
    id: 71,
    status: "queued",
    input: { registry_operation_id: id, epoch: "epoch-1", revision: 7 },
    result: null as unknown,
    cancel_requested: false,
  };
  http: Http = (method, path, body) =>
    Promise.resolve().then(() => {
      this.calls.push({ method, path, body });
      if (!this.bound) {
        return {
          status: 403,
          body: { error: { code: "operation.run_mismatch" } },
        };
      }
      if (path.startsWith("/api/v1/websocket-operations/")) {
        this.reads++;
        this.onRegistryRead?.();
        return {
          status: 200,
          body: {
            operation: {
              id: this.registryId,
              epoch: "epoch-1",
              revision: 7,
              state: this.states.length > 1
                ? this.states.shift()
                : this.states[0],
              processes: [{ error: "private-diagnostic" }],
            },
          },
        };
      }
      if (method === "GET") {
        return { status: 200, body: { data: this.operation } };
      }
      if (!path.endsWith("/result")) {
        throw new Error("No execution relay is permitted");
      }
      Object.assign(this.operation, body);
      return { status: 200, body: { data: this.operation } };
    });
  run(
    kind: "websocket.sync" | "websocket.app_create" | "websocket.app_destroy" =
      "websocket.sync",
  ) {
    this.operation.kind = kind;
    return websocketRegistry(() => this.http, () => this.clock, () => {
      this.clock += this.increment;
      this.onWait?.();
      return Promise.resolve();
    })
      .methods.confirm.execute({ ...args, kind }, ctx);
  }
}

Deno.test("WebSocket direct confirmation follows only its admitted registry read and strips diagnostics", async () => {
  const f = new Fixture();
  assertEquals(await f.run(), { status: "succeeded" });
  assertEquals(f.reads, 2);
  assertEquals(f.operation.result, {
    outcome: "succeeded",
    data: { backend: "applied" },
  });
  assertEquals(
    f.calls.some((call) => /primitive|claim|heartbeat|receipt/.test(call.path)),
    false,
  );
  assertEquals(JSON.stringify(f.calls).includes("private-diagnostic"), false);
});

Deno.test("WebSocket direct read refuses before Gateway binding and a foreign registry result", async () => {
  const f = new Fixture();
  f.bound = false;
  await assertRejects(() => f.run());
  assertEquals(f.reads, 0);
  f.bound = true;
  f.registryId = "c075eb23-7ac4-4487-80d1-57a038a92676";
  await assertRejects(() => f.run(), Error, "websocket.registry_mismatch");
  assertEquals(f.operation.status, "running");
});

Deno.test("WebSocket cancellation during a pending wait or applied read wins before completion", async () => {
  for (const moment of ["pending", "applied"]) {
    const f = new Fixture();
    if (moment === "pending") {
      f.onWait = () => {
        f.operation.cancel_requested = true;
      };
    } else {
      f.states = ["applied"];
      f.onRegistryRead = () => {
        f.operation.cancel_requested = true;
      };
    }
    assertEquals(await f.run(), { status: "cancelled" });
    assertEquals(f.operation.status, "cancelled");
    assertEquals(f.operation.result, {});
    assertEquals(f.reads, 1);
  }
});

Deno.test("WebSocket superseded and bounded pending waits become explicit terminal failures", async () => {
  for (const state of ["pending", "superseded"]) {
    const f = new Fixture();
    f.states = [state];
    f.increment = 150000;
    await assertRejects(() => f.run(), Error, `websocket.registry_${state}`);
    assertEquals(f.operation.status, "failed");
    assertEquals(f.operation.result, {
      error_code: `websocket.registry_${state}`,
    });
    assertEquals(f.reads, state === "pending" ? 2 : 1);
  }
});

Deno.test("WebSocket terminal replay and initial cancellation never repeat confirmation reads", async () => {
  const f = new Fixture();
  await f.run();
  const count = f.reads;
  await f.run();
  assertEquals(f.reads, count);
  const cancelled = new Fixture();
  cancelled.operation.cancel_requested = true;
  assertEquals(await cancelled.run(), { status: "cancelled" });
  assertEquals(cancelled.reads, 0);
});

Deno.test("WebSocket cancellation in the final bounded delay wins over timeout", async () => {
  const f = new Fixture();
  f.states = ["pending"];
  f.increment = 300000;
  f.onWait = () => {
    f.operation.cancel_requested = true;
  };
  assertEquals(await f.run(), { status: "cancelled" });
  assertEquals(f.operation.status, "cancelled");
});

Deno.test("WebSocket app mutations confirm their existing revision without credentials or replaying a mutation", async () => {
  for (
    const kind of ["websocket.app_create", "websocket.app_destroy"] as const
  ) {
    const f = new Fixture();
    assertEquals(await f.run(kind), { status: "succeeded" });
    assertEquals(f.operation.result, {
      outcome: "succeeded",
      data: { backend: "applied" },
    });
    assertEquals(
      f.calls.every((call) =>
        call.method === "GET" || call.path.endsWith("/result")
      ),
      true,
    );
    const reads = f.reads;
    await f.run(kind);
    assertEquals(f.reads, reads);
  }
});
