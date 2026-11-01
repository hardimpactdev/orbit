import { parse } from "jsr:@std/yaml@1.0.12";
import {
  assertEquals,
  assertRejects,
  assertThrows,
} from "jsr:@std/assert@1.0.19";
import type { Http } from "../../automatic-deploy/gateway.ts";
import {
  createTaskVmEnrollmentAdapter,
  type EnrollmentDirectContext,
  type EnrollmentStageActions,
  taskVmEnrollmentDirect,
  TaskVmEnrollmentInput,
  type TaskVmEnrollmentIntent,
  TaskVmEnrollmentStages,
} from "./enrollment_direct.ts";
const key = "a".repeat(43) + "=";
const publicBody =
  "AAAAC3NzaC1lZDI1NTE5AAAAIAEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEB";
const sshIdentity = {
  ssh_host_key_type: "ssh-ed25519" as const,
  ssh_host_key: publicBody,
  ssh_host_fingerprint: "SHA256:RXm/ruZ0eTzRXKwi1AQEDynB0VgHQ2ac9KPSFdf/YnA",
};
const input = (): TaskVmEnrollmentIntent => ({
  task_vm_id: 184,
  guest_node_id: 212,
  execution_node_id: 13,
  gateway_node_id: 14,
  issuer_node_id: 217,
  model_router_node_id: 3,
  _wireguard: {
    schema_version: 1,
    executor_node_id: 14,
    hub_id: 1,
    context_node_id: 212,
    excluded_node_id: null,
    agent_sha256: "b".repeat(64),
  },
  provider: "incus",
  name: "tvm-184",
  agent_sha256: "b".repeat(64),
  node: {
    node_id: 212,
    name: "tvm-184",
    user: "orbit",
    wireguard_ip: "10.44.0.212",
  },
  wireguard_public_key: null,
});
const material = {
  withPrivateMaterial: async () => {},
  storeSecretOutput: async () => {},
};
const args = {
  operation_id: 7,
  kind: "task_vm.enroll" as const,
  node_id: 9,
  run_id: "00000000-0000-4000-8000-000000000007",
};
function fixture(actions: EnrollmentStageActions) {
  const root = {
    id: 7,
    kind: "task_vm.enroll",
    node_id: 9,
    status: "queued",
    cancel_requested: false,
    input: input(),
  };
  const posted: Record<string, unknown>[] = [];
  const resources = new Map<string, Record<string, unknown>>();
  const peer = { node: 9 };
  const http: Http = (method, path, body) => {
    assertEquals(path.startsWith("/api/v1/direct-operations/7"), true);
    if (method === "GET") {
      return Promise.resolve({
        status: 200,
        body: { data: structuredClone(root) },
      });
    }
    assertEquals(
      peer.node,
      root.node_id,
      "Only the root Node may POST a result",
    );
    assertEquals(path, "/api/v1/direct-operations/7/result");
    const result = body as Record<string, unknown>;
    posted.push(result);
    root.status = String(result.status);
    const node = (result.result as Record<string, unknown>).node as
      | Record<string, unknown>
      | undefined;
    if (node?.wireguard_public_key) {
      root.input.wireguard_public_key = String(node.wireguard_public_key);
    }
    return Promise.resolve({ status: 200, body: { data: {} } });
  };
  const ctx: EnrollmentDirectContext = {
    globalArgs: { gatewayUrl: "https://gateway.orbit", caFile: "/public-ca" },
    readResource: (name) => Promise.resolve(resources.get(name) ?? null),
    writeResource: (_spec, name, data) => {
      resources.set(name, data);
      return Promise.resolve({ name });
    },
  };
  return {
    root,
    posted,
    resources,
    peer,
    ctx,
    behavior: taskVmEnrollmentDirect(() => actions, () => http),
  };
}
Deno.test("reusable adapter exposes only public key and readiness after native lower success", async () => {
  const seen: string[] = [];
  const actions: EnrollmentStageActions = Object.fromEntries(
    TaskVmEnrollmentStages.map((stage) => [stage, () => {
      seen.push(stage);
      return Promise.resolve(
        stage === "bootstrap"
          ? {
            bootstrapped: true,
            node: sshIdentity,
            private: "secret-port-output",
          }
          : stage === "prepare-network"
          ? { wireguard_public_key: key, private: "secret-port-output" }
          : { private: "secret-port-output" },
      );
    }]),
  );
  const intent = input();
  for (
    const stage of TaskVmEnrollmentStages.filter((stage) =>
      !["issue-model", "publish-hub", "issue-worker", "configure-worker", "worker-ready"].includes(stage)
    )
  ) {
    const result = await createTaskVmEnrollmentAdapter(
      intent,
      async () => {},
      material,
      actions,
    ).execute(stage);
    assertEquals(JSON.stringify(result).includes("secret-port-output"), false);
    if (stage === "prepare-network") {
      assertEquals(result, { wireguard_public_key: key });
      intent.wireguard_public_key = key;
    }
    if (stage === "confirm-runtime") {
      assertEquals(result, {
        ready: true,
        guest_node_id: 212,
        process: { status: "active" },
      });
    }
  }
  assertEquals(
    seen,
    TaskVmEnrollmentStages.filter((stage) =>
      !["issue-model", "publish-hub", "issue-worker", "configure-worker", "worker-ready"].includes(stage)
    ),
  );
});
Deno.test("adapter refuses missing native ports, missing key, invalid identity, revoked admission and abort", async () => {
  await assertRejects(
    () =>
      createTaskVmEnrollmentAdapter(input(), async () => {}, material, {})
        .execute("bootstrap"),
    Error,
    "task_vm.enrollment_unavailable",
  );
  let calls = 0;
  const actions: EnrollmentStageActions = {
    "publish-network": () => {
      calls++;
      return Promise.resolve({});
    },
    bootstrap: () => {
      calls++;
      return Promise.resolve({});
    },
  };
  await assertRejects(() =>
    createTaskVmEnrollmentAdapter(input(), async () => {}, material, actions)
      .execute("publish-network")
  );
  await assertRejects(() =>
    createTaskVmEnrollmentAdapter(
      input(),
      () => Promise.reject(new Error("superseded")),
      material,
      actions,
    ).execute("bootstrap")
  );
  await assertRejects(() =>
    createTaskVmEnrollmentAdapter(input(), async () => {}, material, actions)
      .execute("bootstrap", AbortSignal.abort())
  );
  assertEquals(calls, 0);
  assertThrows(() =>
    TaskVmEnrollmentInput.parse({ ...input(), guest_node_id: 13 })
  );
  assertThrows(() =>
    TaskVmEnrollmentInput.parse({ ...input(), provider: "upcloud" })
  );
});
Deno.test("foreign stages never POST; root projects prepared key before hub and network enrollment before guest access", async () => {
  const f = fixture({
    bootstrap: () => Promise.resolve({ bootstrapped: true, node: sshIdentity }),
    "prepare-network": () => Promise.resolve({ wireguard_public_key: key }),
    "publish-network": () => Promise.resolve({}),
    "confirm-runtime": () => Promise.resolve({}),
  });
  assertEquals(await f.behavior.methods.admit.execute(args, f.ctx), {
    dataHandles: [{ name: "placement-" + args.run_id }],
  });
  assertEquals(f.resources.get("placement-" + args.run_id), {
    execution_node_id: 13,
    gateway_node_id: 14,
    issuer_node_id: 217,
    model_router_node_id: 3,
    _wireguard: input()._wireguard,
    guest_node_id: 212,
  });
  f.peer.node = 13;
  await f.behavior.methods.step.execute({ ...args, stage: "bootstrap" }, f.ctx);
  assertEquals(f.posted.length, 1);
  f.peer.node = 9;
  await f.behavior.methods.project.execute(
    { ...args, projection: "bootstrap" },
    f.ctx,
  );
  assertEquals(f.posted.at(-1)!.result, { node: sshIdentity });
  assertEquals(f.root.status, "running");
  f.peer.node = 13;
  const before = f.posted.length;
  assertEquals(
    await f.behavior.methods.step.execute(
      { ...args, stage: "prepare-network" },
      f.ctx,
    ),
    { dataHandles: [{ name: "prepare-network-" + args.run_id }] },
  );
  assertEquals(f.posted.length, before);
  assertEquals(f.root.input.wireguard_public_key, null);
  f.peer.node = 9;
  await f.behavior.methods.project.execute(
    { ...args, projection: "network" },
    f.ctx,
  );
  assertEquals(f.root.input.wireguard_public_key, key);
  assertEquals(f.posted.at(-1)!.result, {
    node: { wireguard_public_key: key },
  });
  f.peer.node = 13;
  await f.behavior.methods.step.execute(
    { ...args, stage: "publish-network" },
    f.ctx,
  );
  f.peer.node = 9;
  await f.behavior.methods.project.execute(
    { ...args, projection: "enrolled" },
    f.ctx,
  );
  assertEquals(f.posted.at(-1)!.result, { node: { network_enrolled: true } });
  assertEquals(f.root.status, "running");
  f.peer.node = 212;
  await f.behavior.methods.step.execute(
    { ...args, stage: "confirm-runtime" },
    f.ctx,
  );
  assertEquals(f.root.status, "running");
  f.peer.node = 9;
  await f.behavior.methods.project.execute(
    { ...args, projection: "complete" },
    f.ctx,
  );
  assertEquals(f.root.status, "succeeded");
  assertEquals(f.posted.at(-1)!.result, {
    ready: true,
    guest_node_id: 212,
    process: { status: "active" },
  });
});
Deno.test("foreign failure is sanitized in native output and only root posts terminal status", async () => {
  const f = fixture({
    bootstrap: () => Promise.reject(new Error("private provider diagnostic")),
  });
  f.peer.node = 13;
  await assertRejects(
    () =>
      f.behavior.methods.step.execute({ ...args, stage: "bootstrap" }, f.ctx),
    Error,
    "task_vm.enrollment_failed",
  );
  assertEquals(f.posted, []);
  assertEquals(f.resources.get("failure-" + args.run_id), {
    error_code: "task_vm.enrollment_failed",
  });
  f.peer.node = 9;
  await f.behavior.methods.project.execute(
    { ...args, projection: "failure" },
    f.ctx,
  );
  assertEquals(f.root.status, "failed");
  assertEquals(f.posted.at(-1)!.error_code, "task_vm.enrollment_failed");
});
Deno.test("foreign cancellation runs no lower action and root alone records cancelled", async () => {
  let calls = 0;
  const f = fixture({
    bootstrap: () => {
      calls++;
      return Promise.resolve({});
    },
  });
  f.root.cancel_requested = true;
  f.peer.node = 13;
  await assertRejects(
    () =>
      f.behavior.methods.step.execute({ ...args, stage: "bootstrap" }, f.ctx),
    Error,
    "operation.cancelled",
  );
  assertEquals(f.posted, []);
  f.peer.node = 9;
  await f.behavior.methods.project.execute(
    { ...args, projection: "failure" },
    f.ctx,
  );
  assertEquals(f.root.status, "cancelled");
  assertEquals(calls, 0);
});
Deno.test("native DAG binds callbacks to the root and foreign effects to exact admitted workers", async () => {
  for (const kind of ["enroll", "prepare-runtime"]) {
    const wf = parse(
      await Deno.readTextFile(
        new URL(
          "../../../../workflows/workflow-operation-task-vm-" + kind + ".yaml",
          import.meta.url,
        ),
      ),
    ) as {
      inputs: { required: string[] };
      jobs: {
        name: string;
        target: string;
        labels: { node: string };
        dependsOn?: { job: string; condition: { type: string } }[];
        steps: {
          writes: boolean;
          task: {
            behaviorType: string;
            behaviorName: string;
            methodName: string;
            inputs: Record<string, unknown>;
          };
        }[];
      }[];
    };
    assertEquals(wf.inputs.required, ["operation_id", "node_id", "kind"]);
    const stages = wf.jobs.filter((j) => j.steps[0].task.methodName === "step");
    assertEquals(
      stages.map((j) => j.name),
      kind === "enroll"
        ? TaskVmEnrollmentStages.filter((stage) =>
          stage !== "issue-model" && stage !== "publish-hub"
        )
        : ["worker-ready", "prepare-runtime", "confirm-runtime"],
    );
    const placement =
      'data.latest("task-vm-enrollment-" + run.id, "placement-" + run.id).attributes.';
    for (const job of wf.jobs) {
      const task = job.steps[0].task;
      if (job.name === "issue-model") {
        assertEquals(task.behaviorType, "@nckrtl/task-vm-model-keys");
        assertEquals(task.methodName, "issue");
        assertEquals(
          task.behaviorName,
          '${{ "task-vm-model-router-" + string(' + placement +
            "model_router_node_id) }}",
        );
        assertEquals(Object.keys(task.inputs).sort(), [
          "kind",
          "node_id",
          "operation_id",
          "run_id",
        ]);
        assertEquals(job.steps[0].writes, true);
        assertEquals(
          job.target,
          '${{ "orbit-node-" + string(' + placement + "gateway_node_id) }}",
        );
        assertEquals(
          job.labels.node,
          "${{ string(" + placement + "gateway_node_id) }}",
        );
      } else if (job.name === "publish-hub") {
        assertEquals(task.behaviorType, "@nckrtl/node-wireguard-direct");
        assertEquals(task.methodName, "publish");
        assertEquals(
          task.behaviorName,
          '${{ "wireguard-hub-" + string(' + placement +
            "_wireguard.hub_id) }}",
        );
        assertEquals(
          job.target,
          '${{ "orbit-node-" + string(' + placement +
            "_wireguard.executor_node_id) }}",
        );
        assertEquals(
          job.labels.node,
          "${{ string(" + placement + "_wireguard.executor_node_id) }}",
        );
        assertEquals(Object.keys(task.inputs).sort(), [
          "kind",
          "node_id",
          "operation_id",
          "run_id",
          "snapshot",
        ]);
        assertEquals(
          task.inputs.snapshot,
          "${{ " + placement + "_wireguard }}",
        );
        const assertion = job.steps[1] as unknown as {
          dependsOn: { step: string; condition: { type: string } }[];
          task: { type: string; expr: string };
        };
        assertEquals(assertion.dependsOn, [{
          step: "publish-hub",
          condition: { type: "succeeded" },
        }]);
        assertEquals(assertion.task.type, "assert");
        assertEquals(
          assertion.task.expr,
          'steps["publish-hub"].outputs.status == "succeeded"',
        );
      } else if (task.methodName !== "step") {
        assertEquals(
          job.target,
          '${{ "orbit-node-" + string(inputs.node_id) }}',
        );
        assertEquals(job.labels.node, "${{ string(inputs.node_id) }}");
      } else if (job.name === "issue-worker") {
        assertEquals(
          job.target,
          '${{ "orbit-node-" + string(' + placement + "issuer_node_id) }}",
        );
        assertEquals(
          job.labels.node,
          "${{ string(" + placement + "issuer_node_id) }}",
        );
      } else {
        const field = [
            "bootstrap",
            "prepare-network",
            "publish-network",
            "configure-worker",
          ].includes(job.name)
          ? "execution_node_id"
          : "guest_node_id";
        assertEquals(
          job.target,
          '${{ "orbit-node-" + string(' + placement + field + ") }}",
        );
        assertEquals(
          job.labels.node,
          "${{ string(" + placement + field + ") }}",
        );
      }
    }
    if (kind === "enroll") {
      assertEquals(
        wf.jobs.find((j) => j.name === "prepare-network")!.dependsOn![0].job,
        "project-bootstrap",
      );
      assertEquals(
        wf.jobs.find((j) => j.name === "publish-hub")!.dependsOn![0].job,
        "project-network",
      );
      assertEquals(
        wf.jobs.find((j) => j.name === "issue-worker")!.dependsOn![0].job,
        "project-enrolled",
      );
    }
  }
});

Deno.test("generic per-run enrollment refuses shared model and hub mutation even when a callback is supplied", async () => {
  let called = false;
  const adapter = createTaskVmEnrollmentAdapter(
    input(),
    async () => {},
    material,
    {
      "issue-model": () => {
        called = true;
        return Promise.resolve({});
      },
      "publish-hub": () => {
        called = true;
        return Promise.resolve({});
      },
    },
  );
  await assertRejects(
    () => adapter.execute("issue-model"),
    Error,
    "task_vm.enrollment_unavailable",
  );
  await assertRejects(
    () => adapter.execute("publish-hub"),
    Error,
    "task_vm.enrollment_unavailable",
  );
  assertEquals(called, false);
});

Deno.test("UpCloud enrollment binds the admitted Gateway instead of a fleet-specific ID", () => {
  const base = input();
  const admitted = {
    ...base,
    provider: "upcloud",
    execution_node_id: 37,
    gateway_node_id: 37,
    _wireguard: { ...base._wireguard, executor_node_id: 37 },
  };
  assertEquals(TaskVmEnrollmentInput.parse(admitted).execution_node_id, 37);
  assertThrows(() =>
    TaskVmEnrollmentInput.parse({ ...admitted, execution_node_id: 14 })
  );
});
