import { assertEquals, assertRejects } from "jsr:@std/assert@1";
import {
  nativeProvision,
  type ProvisionActions,
  provisionEnrollment,
} from "./provision_direct.ts";

import {
  args,
  provider,
  provisionFixture as fixture,
  publicKey,
} from "./provision_fixture.ts";

Deno.test("missing same-root enrollment refuses before provider allocation", async () => {
  const f = fixture();
  await assertRejects(
    () => nativeProvision(() => f.http).methods.admit.execute(args, f.context),
    Error,
    "operation.kind_unavailable",
  );
  assertEquals(f.calls.filter((call) => call.method === "POST"), []);
  assertEquals(f.stages, []);
});
for (
  const field of [
    "gateway_node_id",
    "issuer_node_id",
    "guest_node_id",
    "model_router_node_id",
  ] as const
) {
  Deno.test(`admission refuses top-level ${field} that differs from enrollment before provider effects`, async () => {
    const f = fixture();
    f.operation.input = { ...f.input, [field]: f.input[field] + 1 };
    await assertRejects(() => f.model.methods.admit.execute(args, f.context));
    assertEquals(f.stages, []);
    assertEquals(f.calls.filter((call) => call.method === "POST"), []);
  });
}
for (
  const change of [
    { hub_id: 2 },
    { context_node_id: 210 },
    { executor_node_id: 15 },
    { excluded_node_id: 208 },
    { agent_sha256: "d".repeat(64) },
  ]
) {
  Deno.test(`admission refuses divergent public hub ${Object.keys(change)[0]} before provider effects`, async () => {
    const f = fixture();
    f.operation.input = {
      ...f.input,
      _wireguard: { ...f.input._wireguard, ...change },
    };
    await assertRejects(() => f.model.methods.admit.execute(args, f.context));
    assertEquals(f.stages, []);
    assertEquals(f.calls.filter((call) => call.method === "POST"), []);
  });
}
Deno.test("admission refuses a provider host that differs from the enrollment executor", async () => {
  const f = fixture();
  f.operation.node_id = 77;
  f.operation.input = { ...f.input, node_id: 77, host_node_id: 77 };
  await assertRejects(() =>
    f.model.methods.admit.execute({ ...args, node_id: 77 }, f.context)
  );
  assertEquals(f.stages, []);
  assertEquals(f.calls.filter((call) => call.method === "POST"), []);
});
Deno.test("admission records running without executing lifecycle actions", async () => {
  const f = fixture();
  await f.model.methods.admit.execute(args, f.context);
  assertEquals(f.operation.status, "running");
  assertEquals(f.stages, []);
  assertEquals(
    (f.resources.get("admission") as { input: unknown }).input,
    f.input,
  );
});
Deno.test("prestart cancellation never allocates a provider", async () => {
  const f = fixture();
  f.operation.cancel_requested = true;
  await f.model.methods.admit.execute(args, f.context);
  assertEquals(f.operation.status, "cancelled");
  assertEquals(f.stages, []);
});
Deno.test("every stage observes cancellation before its next effect", async () => {
  const f = fixture();
  f.operation.cancel_requested = true;
  await f.model.methods.stage.execute({
    ...args,
    admission: f.admission,
    stage: "worker-ready",
    provider,
    outcomes: [],
  }, f.context);
  assertEquals(f.stages, []);
  assertEquals(
    (f.resources.get("worker-ready") as { error_code: string }).error_code,
    "operation.cancelled",
  );
});
Deno.test("guest readiness uses public intent without reading private material", async () => {
  const f = fixture();
  await f.model.methods.stage.execute({
    ...args,
    admission: f.admission,
    stage: "worker-ready",
    provider,
    outcomes: [],
  }, f.context);
  assertEquals(f.stages, ["worker-ready"]);
  assertEquals(f.calls.map((call) => call.method), ["GET"]);
});
Deno.test("root records public network key before hub execution and no foreign result POST follows", async () => {
  const f = fixture();
  await f.model.methods.stage.execute({
    ...args,
    admission: f.admission,
    stage: "prepare-network",
    provider,
    outcomes: [],
  }, f.context);
  assertEquals(f.operation.result, {
    node: { wireguard_public_key: publicKey },
  });
  assertEquals(f.stages, ["prepare-network"]);
});
Deno.test("shared hub and model mutations cannot run through the per-run stage method", async () => {
  const f = fixture();
  for (const stage of ["publish-hub", "issue-model"]) {
    await assertRejects(() =>
      f.model.methods.stage.execute({
        ...args,
        admission: f.admission,
        stage,
        provider,
        outcomes: [],
      } as never, f.context)
    );
  }
  assertEquals(f.stages, []);
});
Deno.test("private exception and output fields never enter native resources", async () => {
  const f = fixture();
  f.actions.provider = () => Promise.reject(new Error("ucat_PRIVATE TOKEN"));
  await f.model.methods.stage.execute({
    ...args,
    admission: f.admission,
    stage: "provider",
    provider: null,
    outcomes: [],
  }, f.context);
  assertEquals(JSON.stringify([...f.resources]).includes("PRIVATE"), false);
  f.actions.bootstrap = () =>
    Promise.resolve({ bootstrapped: true, token: "PRIVATE" });
  await f.model.methods.stage.execute({
    ...args,
    admission: f.admission,
    stage: "bootstrap",
    provider,
    outcomes: [],
  }, f.context);
  assertEquals(JSON.stringify([...f.resources]).includes("PRIVATE"), false);
});
Deno.test("all successful ordered native stages produce one authoritative enrollment result", async () => {
  const f = fixture();
  await f.model.methods.project.execute({
    ...args,
    admission: f.admission,
    outcomes: f.outcomes,
  }, f.context);
  assertEquals(f.operation.status, "succeeded");
  const result = f.calls.at(-1)?.body?.result as Record<string, unknown>;
  assertEquals(result.guest_node_id, 208);
  assertEquals(result.enrollment, {
    ready: true,
    node: {
      ssh_host_key_type: "ssh-ed25519",
      ssh_host_key: "public-key",
      ssh_host_fingerprint: "SHA256:public",
      address: "10.251.77.2",
      wireguard_public_key: publicKey,
    },
    process: { status: "active" },
  });
  const posts = f.calls.filter((call) => call.method === "POST").length;
  await f.model.methods.project.execute({
    ...args,
    admission: f.admission,
    outcomes: f.outcomes,
  }, f.context);
  assertEquals(f.calls.filter((call) => call.method === "POST").length, posts);
});
Deno.test("incomplete work cannot mark the VM ready", async () => {
  const f = fixture();
  await assertRejects(
    () =>
      f.model.methods.project.execute({
        ...args,
        admission: f.admission,
        outcomes: f.outcomes.slice(0, 3),
      }, f.context),
    Error,
    "task_vm.provision_refused",
  );
  assertEquals(f.operation.status, "failed");
});

Deno.test("root checkpoints network enrollment after publication before issuer and guest work", async () => {
  const f = fixture();
  await f.model.methods.stage.execute({
    ...args,
    admission: f.admission,
    stage: "publish-network",
    provider,
    outcomes: f.outcomes.slice(0, 4),
  }, f.context);
  assertEquals(f.stages, ["publish-network"]);
  assertEquals(f.operation.status, "running");
  assertEquals(f.operation.result, { node: { network_enrolled: true } });
  assertEquals(f.calls.filter((call) => call.method === "POST").length, 1);
});

Deno.test("failed network publication creates no network-enrolled checkpoint", async () => {
  const f = fixture();
  f.actions["publish-network"] = () =>
    Promise.reject(new Error("private network failure"));
  await f.model.methods.stage.execute({
    ...args,
    admission: f.admission,
    stage: "publish-network",
    provider,
    outcomes: f.outcomes.slice(0, 4),
  }, f.context);
  assertEquals(f.calls.filter((call) => call.method === "POST"), []);
  assertEquals(
    (f.resources.get("publish-network") as { ok: boolean }).ok,
    false,
  );
});

Deno.test("enrollment lower closures are constructed from each stage's refreshed network key", async () => {
  const f = fixture();
  let captured: string | null = null;
  const enrolled = provisionEnrollment((input) => {
    captured = input.wireguard_public_key;
    return {
      "publish-network": () => {
        if (input.wireguard_public_key !== publicKey) {
          throw new Error("task_vm.enrollment_unavailable");
        }
        return Promise.resolve({ network_configured: true });
      },
    };
  });
  const model = nativeProvision(() => f.http, { ...f.actions, ...enrolled });
  await model.methods.stage.execute({
    ...args,
    admission: f.admission,
    stage: "publish-network",
    provider,
    outcomes: f.outcomes.slice(0, 4),
  }, f.context);
  assertEquals(captured, publicKey);
  assertEquals(f.operation.result, { node: { network_enrolled: true } });
});

Deno.test("incomplete native lower bindings refuse before creating a provider", async () => {
  const f = fixture();
  const actions = { ...f.actions } as Partial<ProvisionActions>;
  delete actions["configure-worker"];
  const model = nativeProvision(() => f.http, actions as ProvisionActions);
  await assertRejects(
    () => model.methods.admit.execute(args, f.context),
    Error,
    "operation.kind_unavailable",
  );
  assertEquals(f.stages, []);
  assertEquals(f.calls.filter((call) => call.method === "POST"), []);
});

Deno.test("root projects bootstrap public identity before any exact guest stage", async () => {
  const f = fixture();
  await f.model.methods.stage.execute({
    ...args,
    admission: f.admission,
    stage: "bootstrap",
    provider,
    outcomes: f.outcomes.slice(0, 1),
  }, f.context);
  assertEquals(f.operation.result, {
    node: {
      ssh_host_key_type: "ssh-ed25519",
      ssh_host_key: "public-key",
      ssh_host_fingerprint: "SHA256:public",
    },
  });
  assertEquals(f.calls.filter((call) => call.method === "POST").length, 1);
});
