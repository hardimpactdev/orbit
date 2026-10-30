import { assertEquals, assertRejects, assertThrows } from "jsr:@std/assert@1";
import { Intent, lifecycleIntent } from "./lifecycle_intent_direct.ts";
import { invocationFor } from "./contract.ts";
import type { Http } from "../../automatic-deploy/gateway.ts";
import { Globals } from "../../operation-runner/mod.ts";

const args = {
  operation_id: 7,
  node_id: 14,
  kind: "task_vm.lifecycle_intent" as const,
  run_id: "48c05fc0-e1ed-4beb-bfea-68cb224ff8e1",
};
class Fixture {
  operation = {
    id: 7,
    kind: "task_vm.lifecycle_intent",
    node_id: 14,
    status: "queued",
    result: null as unknown,
    cancel_requested: false,
    input: { task_vm_id: 5 },
  };
  calls: { method: string; path: string; body: unknown }[] = [];
  loseProjection = false;
  http: Http = (method, path, body) => {
    this.calls.push({ method, path, body });
    if (method === "POST") {
      const value = body as Record<string, unknown>;
      this.operation.status = value.status as string;
      this.operation.result = value.result ?? null;
      if (this.loseProjection && value.status === "succeeded") {
        this.loseProjection = false;
        return Promise.reject(new Error("lost reply"));
      }
    }
    return Promise.resolve({
      status: 200,
      body: { data: structuredClone(this.operation) },
    });
  };
  execute(signal?: AbortSignal) {
    return lifecycleIntent(() => this.http).methods.execute.execute(args, {
      globalArgs: Globals.parse({
        gatewayUrl: "https://gateway.orbit",
        caFile: "/ca.pem",
      }),
      signal,
    });
  }
}
Deno.test("acknowledgement only reads its admitted identity and projects ready", async () => {
  const f = new Fixture();
  assertEquals(await f.execute(), { status: "succeeded" });
  assertEquals(f.operation.result, { ready: true });
  assertEquals(f.calls.map((c) => [c.method, c.path]), [
    ["GET", `/api/v1/direct-operations/7?run_id=${args.run_id}`],
    ["POST", "/api/v1/direct-operations/7/result"],
    ["POST", "/api/v1/direct-operations/7/result"],
  ]);
});
Deno.test("lost terminal reply reuses acknowledgement without another projection", async () => {
  const f = new Fixture();
  f.loseProjection = true;
  await assertRejects(() => f.execute());
  const calls = f.calls.length;
  assertEquals(await f.execute(), { status: "succeeded" });
  assertEquals(f.calls.length, calls + 1);
});
Deno.test("cancellation records no acknowledgement", async () => {
  for (const signal of [undefined, AbortSignal.abort()]) {
    const f = new Fixture();
    f.operation.cancel_requested = !signal;
    assertEquals(await f.execute(signal), { status: "cancelled" });
    assertEquals(f.operation.result, {});
  }
});
Deno.test("a foreign Node or Operation is refused before projection", async () => {
  for (const field of ["id", "node_id"] as const) {
    const f = new Fixture();
    f.operation[field] = 999;
    await assertRejects(() => f.execute());
    assertEquals(f.calls.length, 1);
  }
});
Deno.test("missing or command-shaped intent cannot be acknowledged", async () => {
  for (
    const input of [{}, { task_vm_id: 5, action: "delete" }, {
      task_vm_id: "5",
    }]
  ) {
    assertEquals(Intent.safeParse(input).success, false);
  }
  const f = new Fixture();
  f.operation.input.task_vm_id = 0;
  await assertRejects(() => f.execute());
  assertEquals(f.operation.status, "running");
});
Deno.test("the retired lifecycle intent transport is no longer callable", () => {
  assertThrows(() =>
    invocationFor("task_vm.lifecycle_intent", { task_vm_id: 5 })
  );
});
