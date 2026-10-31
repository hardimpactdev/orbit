import { assertEquals, assertRejects } from "jsr:@std/assert@1";
import type { Http } from "../../automatic-deploy/gateway.ts";
import {
  nativeProvision,
  type ProvisionAction,
  type ProvisionActions,
  provisionEnrollment,
  ProvisionInput,
  ProvisionStages,
} from "./provision_direct.ts";

const args = {
  operation_id: 7,
  kind: "task_vm.provision" as const,
  node_id: 13,
  run_id: "a075eb23-7ac4-4487-80d1-57a038a92676",
};
const hub = {
  schema_version: 1,
  executor_node_id: 14,
  hub_id: 1,
  context_node_id: 208,
  excluded_node_id: null,
  agent_sha256: "c".repeat(64),
} as const;
const publicKey = "a".repeat(43) + "=";
const provider = {
  address: "10.251.77.2",
  provider_id: null,
  image_fingerprint: "a".repeat(64),
  ssh_host_key: null,
  running: true as const,
  settled: true as const,
};
function stageOutput(
  stage: typeof ProvisionStages[number],
): Record<string, unknown> {
  if (stage === "provider") return provider;
  if (stage === "bootstrap") {
    return {
      bootstrapped: true,
      guest_node_id: 208,
      node: {
        ssh_host_key_type: "ssh-ed25519",
        ssh_host_key: "public-key",
        ssh_host_fingerprint: "SHA256:public",
      },
    };
  }
  if (stage === "prepare-network") return { wireguard_public_key: publicKey };
  if (stage === "worker-ready") {
    return { worker_ready: true, guest_node_id: 208 };
  }
  if (stage === "confirm-runtime") {
    return { ready: true, guest_node_id: 208, process: { status: "active" } };
  }
  return { configured: true };
}
function fixture() {
  const input = ProvisionInput.parse({
    eligible: true,
    _wireguard: hub,
    task_vm_id: 5,
    node_id: 13,
    host_node_id: 13,
    gateway_node_id: 14,
    issuer_node_id: 209,
    guest_node_id: 208,
    enrollment: {
      _wireguard: hub,
      "task_vm_id": 5,
      "guest_node_id": 208,
      "execution_node_id": 13,
      "model_router_node_id": 17,
      "model_origin": "http://10.44.0.3:8317",
      "gateway_node_id": 14,
      "issuer_node_id": 209,
      "provider": "incus",
      "name": "tvm-5",
      "user": "orbit",
      "agent_sha256":
        "cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc",
      "agent_artifact_url": "https://artifacts.example/observer",
      "vm": {
        "project": "orbit-tasks",
        "name": "tvm-5",
      },
      "agent": {
        "configuration": 'gateway_url="https://gateway.orbit"\n',
        "certificate": "public-ca",
        "unit": "public-unit",
        "checkouts": [],
      },
      "bootstrap": {},
      "node": {
        "node_id": 208,
        "name": "tvm-5",
        "user": "orbit",
        "wireguard_ip": "10.44.0.208",
      },
      "network": {
        "server_public_key": "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb=",
        "endpoint": "vpn.example:51820",
        "address": "10.44.0.208/24",
        "subnet": "10.44.0.0/24",
        "dns_server": "10.44.0.1",
        "domains": [
          "orbit",
        ],
      },
      "wireguard_public_key": null,
      "pi_url": "http://10.44.0.208:3774",
      "runtime": {
        "guest_node_id": 208,
        "user": "orbit",
        "agent_sha256":
          "cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc",
        "pi_sha256":
          "eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee",
        "origin": "http://10.44.0.3:8317",
        "models": [
          "fixture",
        ],
        "process": {
          "process_id": 7,
          "name": "orbit-process-7-pi-server.service",
          "runtime": "systemd",
          "agent_sha256":
            "cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc",
          "content_hash":
            "ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff",
          "desired": "running",
          "on_demand": false,
          "activation_pending": false,
          "retired": null,
        },
      },
    },
    model_router_node_id: 17,
    model_origin: "http://10.44.0.3:8317",
    participant_node_ids: [13, 14, 209, 208, 1],
    private_bindings: {},
    name: "tvm-5",
    provider: "incus" as const,
    provider_id: null,
    wireguard_ip: "10.44.0.208",
    image_fingerprint: "a".repeat(64),
    recipe_identity: "sha256:" + "b".repeat(64),
    agent_sha256: "c".repeat(64),
    deadline_at: "2286-11-20T17:46:39+00:00",
    gateway_public_key: "ssh-ed25519 fixture",
    upcloud: null,
    vm: { project: "orbit-tasks", name: "tvm-5", cidr: "10.251.77.0/24" },
    create: {
      kind: "create" as const,
      image: "a".repeat(64),
      expected_fingerprint: "a".repeat(64),
      base_alias: "orbit-task-base",
      gateway_public_key: "ssh-ed25519 fixture",
      cpus: 2,
      memory: "4GiB",
      disk: "20GiB",
      network: "orbittask0",
      pool: "orbit-tasks",
      topology: false,
    },
  });
  const operation = {
    id: 7,
    kind: args.kind,
    node_id: 13,
    status: "queued",
    cancel_requested: false,
    input,
    result: {} as Record<string, unknown>,
  };
  const resources = new Map<string, unknown>();
  const calls: {
    method: string;
    path: string;
    body?: Record<string, unknown>;
  }[] = [];
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
    if (body?.status) operation.status = String(body.status);
    if (body?.result) operation.result = body.result as Record<string, unknown>;
    return Promise.resolve({ status: 200, body: { data: operation } });
  };
  const stages: string[] = [];
  const action =
    (stage: typeof ProvisionStages[number]): ProvisionAction => () => {
      stages.push(stage);
      return Promise.resolve(stageOutput(stage));
    };
  const actions: ProvisionActions = {
    provider: action("provider"),
    bootstrap: action("bootstrap"),
    "prepare-network": action("prepare-network"),
    "publish-network": action("publish-network"),
    "issue-worker": action("issue-worker"),
    "configure-worker": action("configure-worker"),
    "worker-ready": action("worker-ready"),
    "prepare-runtime": action("prepare-runtime"),
    "confirm-runtime": action("confirm-runtime"),
  };
  const model = nativeProvision(() => http, actions);
  const admission = {
    active: true,
    started_at: "2026-10-31T00:00:00.000Z",
    input,
  };
  const outcomes = ProvisionStages.map((stage) => ({
    stage,
    ok: true,
    error_code: null,
    output: stageOutput(stage),
  }));
  return {
    input,
    operation,
    resources,
    calls,
    context,
    http,
    actions,
    model,
    admission,
    outcomes,
    stages,
  };
}
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
