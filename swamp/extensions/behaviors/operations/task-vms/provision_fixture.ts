import type { Http } from "../../automatic-deploy/gateway.ts";
import {
  nativeProvision,
  type ProvisionAction,
  type ProvisionActions,
  ProvisionInput,
  ProvisionStages,
} from "./provision_direct.ts";

export const args = {
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
export const publicKey = "a".repeat(43) + "=";
export const provider = {
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
export function provisionFixture() {
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
