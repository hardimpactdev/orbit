import { assertEquals, assertRejects } from "jsr:@std/assert@1";
import type { Http } from "../../automatic-deploy/gateway.ts";
import { nativeResume } from "./resume_direct.ts";

const args = {
  operation_id: 7,
  kind: "task_vm.resume" as const,
  node_id: 13,
  run_id: "a075eb23-7ac4-4487-80d1-57a038a92676",
};
function fixture() {
  const input = {
    eligible: true,
    task_vm_id: 5,
    node_id: 13,
    guest_node_id: 208,
    name: "tvm-5",
    provider: "incus" as const,
    provider_id: null,
    agent_sha256: "a".repeat(64),
    guest_agent_sha256: "b".repeat(64),
    user: "orbit",
    pi_sha256: "c".repeat(64),
    pi_url: "http://10.44.0.208:3774",
    deadline_at: 9999999999,
    vm: { project: "orbit-tasks", name: "tvm-5", cidr: "10.251.77.0/24" },
  };
  const operation = {
    ...args,
    id: args.operation_id,
    input,
    status: "queued",
    cancel_requested: false,
  };
  const calls: {
    method: string;
    path: string;
    body?: Record<string, unknown>;
  }[] = [];
  const resources = new Map<string, unknown>();
  const context = {
    globalArgs: {
      gatewayUrl: "https://gateway.orbit",
      caFile: "/tmp/root.crt",
    },
    writeResource: (_spec: string, name: string, value: unknown) => {
      resources.set(name, value);
      return Promise.resolve({ name });
    },
  };
  const http: Http = (method, path, raw) => {
    const body = raw as Record<string, unknown> | undefined;
    calls.push({ method, path, body });
    if (body && typeof body.status === "string") operation.status = body.status;
    return Promise.resolve({ status: 200, body: { data: operation } });
  };
  const outcomes = (["provider", "guest", "pi"] as const).map((stage) => ({
    stage,
    ok: true,
    error_code: null,
  }));
  const actionCalls: string[] = [];
  const model = nativeResume(() => http, (stage) => {
    actionCalls.push(stage);
    return Promise.resolve();
  });
  const admission = {
    active: true,
    status: "running" as const,
    started_at: "2026-10-31T00:00:00.000Z",
    input,
  };
  return {
    input,
    operation,
    calls,
    resources,
    context,
    http,
    model,
    outcomes,
    actionCalls,
    admission,
  };
}

Deno.test("resume admission records running once with safe guest placement and one native identity", async () => {
  const f = fixture();
  await f.model.methods.admit.execute(args, f.context);
  assertEquals(f.operation.status, "running");
  assertEquals(
    (f.resources.get("admission") as { input: unknown }).input,
    f.input,
  );
  assertEquals(f.calls.map((call) => call.path), [
    `/api/v1/direct-operations/7?run_id=${args.run_id}`,
    "/api/v1/direct-operations/7/result",
  ]);
  assertEquals(f.actionCalls, []);
});

Deno.test("pre-start cancellation records no resume action and preserves a terminal root on retry", async () => {
  const f = fixture();
  f.operation.cancel_requested = true;
  await f.model.methods.admit.execute(args, f.context);
  assertEquals(f.operation.status, "cancelled");
  const posts = f.calls.filter((call) => call.method === "POST").length;
  await f.model.methods.admit.execute(args, f.context);
  assertEquals(f.calls.filter((call) => call.method === "POST").length, posts);
  assertEquals(f.actionCalls, []);
});

Deno.test("guest inspection makes no Gateway read or private callback", async () => {
  const f = fixture();
  await f.model.methods.stage.execute({
    ...args,
    stage: "guest",
    admission: f.admission,
  }, f.context);
  assertEquals(f.calls, []);
  assertEquals(f.actionCalls, ["guest"]);
  assertEquals(f.resources.get("guest"), {
    stage: "guest",
    ok: true,
    error_code: null,
  });
});

Deno.test("ownership and cancellation are read again before a new provider effect", async () => {
  const f = fixture();
  f.operation.cancel_requested = true;
  await f.model.methods.stage.execute({
    ...args,
    stage: "provider",
    admission: f.admission,
  }, f.context);
  assertEquals(f.actionCalls, []);
  assertEquals(f.resources.get("provider"), {
    stage: "provider",
    ok: false,
    error_code: "operation.cancelled",
  });
});

Deno.test("provider failure preserves only a bounded native outcome without private exception text", async () => {
  const f = fixture();
  const model = nativeResume(
    () => f.http,
    () => Promise.reject(new Error("private-pi-token ucat_PRIVATE")),
  );
  await model.methods.stage.execute({
    ...args,
    stage: "provider",
    admission: f.admission,
  }, f.context);
  assertEquals(f.resources.get("provider"), {
    stage: "provider",
    ok: false,
    error_code: "task_vm.resume_failed",
  });
  assertEquals(JSON.stringify([...f.resources]).includes("PRIVATE"), false);
});

Deno.test("only the full ordered native resume result projects ready", async () => {
  const f = fixture();
  await f.model.methods.project.execute({
    ...args,
    admission: f.admission,
    outcomes: f.outcomes,
  }, f.context);
  assertEquals(f.calls.at(-1)?.body?.result, {
    task_vm_id: 5,
    guest_node_id: 208,
    ready: true,
  });
  assertEquals(f.operation.status, "succeeded");
  const posts = f.calls.filter((call) => call.method === "POST").length;
  await f.model.methods.project.execute({
    ...args,
    admission: f.admission,
    outcomes: f.outcomes,
  }, f.context);
  assertEquals(f.calls.filter((call) => call.method === "POST").length, posts);
});

Deno.test("missing or out-of-order native stages cannot claim runtime readiness", async () => {
  const scenarios: ("provider" | "guest" | "pi")[][] = [["provider"], [
    "provider",
    "pi",
    "guest",
  ]];
  for (const stages of scenarios) {
    const f = fixture();
    await assertRejects(() =>
      f.model.methods.project.execute({
        ...args,
        admission: f.admission,
        outcomes: stages.map((stage) => ({
          stage,
          ok: true,
          error_code: null,
        })),
      }, f.context)
    );
    assertEquals(f.operation.status, "failed");
    assertEquals(f.calls.at(-1)?.body?.error_code, "task_vm.resume_refused");
  }
});

Deno.test("post-effect cancellation is acknowledged only after all three completed stages", async () => {
  const f = fixture();
  f.operation.cancel_requested = true;
  await f.model.methods.project.execute({
    ...args,
    admission: f.admission,
    outcomes: f.outcomes,
  }, f.context);
  assertEquals(f.operation.status, "cancelled");
  assertEquals(f.calls.at(-1)?.body?.result, {});
});
