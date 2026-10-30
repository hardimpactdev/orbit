import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { incusDirect } from "./incus_direct.ts";
import type { Http } from "../../automatic-deploy/gateway.ts";
import { Globals } from "../../operation-runner/mod.ts";

const args = {
  operation_id: 7,
  kind: "task_vm.incus_start" as const,
  node_id: 13,
  run_id: "ba535a54-91f9-4a2e-8466-5e6820d0a36e",
};
class Fixture {
  operation = {
    id: 7,
    kind: "task_vm.incus_start",
    node_id: 13,
    status: "queued",
    cancel_requested: false,
    result: null as Record<string, unknown> | null,
    input: {
      agent_sha256: "a".repeat(64),
      intent: {
        task_vm_id: 5,
        vm: { project: "orbit-tasks", name: "tvm-5", cidr: "10.251.77.0/24" },
        operation: { kind: "start" },
      },
    },
  };
  unbound = false;
  loseResult = false;
  loseAction = false;
  result: Record<string, unknown> = { ready: true };
  calls: string[] = [];
  actions: { pin: string; input: Record<string, unknown> }[] = [];
  http: Http = (method, path, body) => {
    this.calls.push(path);
    if (this.unbound) {
      return Promise.resolve({
        status: 409,
        body: { error: { code: "operation.run_unbound" } },
      });
    }
    if (method === "POST") {
      const data = body as Record<string, unknown>;
      this.operation.status = data.status as string;
      this.operation.result = data.result as Record<string, unknown> ?? null;
      if (this.loseResult && data.status === "succeeded") {
        this.loseResult = false;
        return Promise.reject(new Error("lost result reply"));
      }
    }
    return Promise.resolve({
      status: 200,
      body: { data: structuredClone(this.operation) },
    });
  };
  execute(signal?: AbortSignal) {
    const action = (pin: string, input: Record<string, unknown>) => {
      this.actions.push({ pin, input });
      if (this.loseAction) {
        this.loseAction = false;
        return Promise.reject(new Error("uncertain Node result"));
      }
      return Promise.resolve(this.result);
    };
    return incusDirect(() => this.http, action).methods.execute.execute(args, {
      globalArgs: Globals.parse({
        gatewayUrl: "https://gateway.orbit",
        caFile: "/ca.pem",
      }),
      signal,
    });
  }
}

Deno.test("direct Incus binds read/action/projection to one admitted VM and digest", async () => {
  const f = new Fixture();
  assertEquals(await f.execute(), { status: "succeeded" });
  assertEquals(f.actions, [{
    pin: "a".repeat(64),
    input: { vm: f.operation.input.intent.vm, operation: { kind: "start" } },
  }]);
  assertEquals(f.calls, [
    `/api/v1/direct-operations/7?run_id=${args.run_id}`,
    "/api/v1/direct-operations/7/result",
    "/api/v1/direct-operations/7/result",
  ]);
});

Deno.test("native cancellation of an unbound first read performs no action or projection", async () => {
  const f = new Fixture();
  f.unbound = true;
  const controller = new AbortController();
  const http = f.http;
  f.http = (method, path, body, key) => {
    const response = http(method, path, body, key);
    controller.abort(new Error("Native run cancelled before binding"));
    return response;
  };
  await assertRejects(() => f.execute(controller.signal));
  assertEquals(f.actions, []);
  assertEquals(f.calls.length, 1);
});

Deno.test("foreign Node, VM identity and command variants refuse before sudo", async () => {
  for (
    const change of [
      (f: Fixture) => {
        f.operation.node_id = 9;
      },
      (f: Fixture) => {
        f.operation.input.intent.vm.name = "tvm-6";
      },
      (f: Fixture) => {
        f.operation.input.intent.operation.kind = "delete";
      },
    ]
  ) {
    const f = new Fixture();
    change(f);
    await assertRejects(() => f.execute());
    assertEquals(f.actions, []);
  }
});

Deno.test("cancellation before the Incus effect never runs sudo", async () => {
  for (const aborted of [false, true]) {
    const f = new Fixture();
    f.operation.cancel_requested = !aborted;
    await f.execute(aborted ? AbortSignal.abort() : undefined);
    assertEquals(f.operation.status, "cancelled");
    assertEquals(f.actions, []);
  }
});

Deno.test("terminal replay after a lost projection reply does not repeat Incus", async () => {
  const f = new Fixture();
  f.loseResult = true;
  await assertRejects(() => f.execute());
  assertEquals(await f.execute(), { status: "succeeded" });
  assertEquals(f.actions.length, 1);
});

Deno.test("lost Node reply leaves no success and native replay uses the same convergent input", async () => {
  const f = new Fixture();
  f.loseAction = true;
  await assertRejects(() => f.execute());
  assertEquals(f.operation.status, "running");
  assertEquals(await f.execute(), { status: "succeeded" });
  assertEquals(f.actions[0], f.actions[1]);
});

Deno.test("unproven or raw command output cannot become Incus completion", async () => {
  for (
    const result of [{ ready: false }, {
      ready: true,
      stderr: "private output",
    }]
  ) {
    const f = new Fixture();
    f.result = result;
    await assertRejects(() => f.execute());
    assertEquals(f.operation.status, "running");
  }
  const f = new Fixture();
  f.result = { error_code: "task_vm.incus_not_running" };
  await assertRejects(() => f.execute(), Error, "task_vm.incus_not_running");
  assertEquals(f.operation.result, f.result);
  assertEquals(f.operation.status, "failed");
});
