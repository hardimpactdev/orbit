import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { ExecutorError } from "../../operation-runner/mod.ts";
import { lifecycle, LifecycleInput } from "./lifecycle.ts";
import type { FamilyEffects } from "./runtime.ts";

const runtime = {
  task_vm_id: 5,
  process_id: 27,
  node_id: 12,
  user: "orbit",
  origin: "http://10.44.0.3:8317",
  sha256: "a".repeat(64),
  models: ["model"],
  artifact: "/artifacts/pi",
  deadline_at: 4_000_000_000,
  model_key: { $secret: "task_vm.model_key", id: 5 },
  token: { $secret: "task_vm.pi_token", id: 5 },
};
const intent = () =>
  LifecycleInput.parse({
    task_vm_id: 5,
    provider: "incus",
    host_node_id: 3,
    gateway_node_id: 1,
    guest_node_id: 12,
    execution_node_id: 3,
    variant: "incus-key",
    deadline_at: 4_000_000_000,
    token: { $secret: "upcloud.api", id: 5 },
    origin: runtime.origin,
    key: runtime.model_key,
    vm: { name: "tvm-5", project: "orbit-tasks", cidr: "10.251.77.0/24" },
    create: { kind: "create" },
    delete: { kind: "delete", pool: "orbit-tasks" },
  });
class Effects implements FamilyEffects {
  calls: {
    key: string;
    kind: string;
    node: number;
    input: Record<string, unknown>;
  }[] = [];
  state: Record<string, unknown> = {
    present: true,
    running: true,
    settled: true,
    image_matches: true,
    bootstrap_ready: true,
    address: "10.251.77.2",
    responds: true,
    runtime,
    worker_stop_required: false,
    node_id: 12,
    destroy_allowed: true,
    needs_wake: false,
  };
  onWait = () => {};
  waits = 0;
  failures = new Set<string>();
  receipts = new Map<string, Record<string, unknown>>();
  child(
    key: string,
    kind: string,
    node: number,
    input: Record<string, unknown>,
  ) {
    if (this.failures.has(key)) {
      return Promise.reject(new ExecutorError("task_vm.host_command_failed"));
    }
    if (!this.receipts.has(key)) {
      this.calls.push({ key, kind, node, input });
      this.receipts.set(
        key,
        ["task_vm.enroll_node", "task_vm.prepare_enrollment"].includes(kind)
          ? { ready: true, node_id: 12 }
          : { ready: true },
      );
    }
    return Promise.resolve(this.receipts.get(key)!);
  }
  read() {
    return Promise.resolve(structuredClone(this.state));
  }
  wait() {
    this.waits++;
    this.onWait();
    return Promise.resolve();
  }
}

Deno.test("provision creates, waits for cloud-init, enrolls and prepares the runtime in order", async () => {
  const effects = new Effects();
  effects.state.bootstrap_ready = false;
  effects.onWait = () => {
    effects.state.bootstrap_ready = true;
  };
  assertEquals(await lifecycle("task_vm.provision", intent(), effects), {
    ready: true,
    node_id: 12,
  });
  assertEquals(effects.calls.map((entry) => entry.key), [
    "lifecycle-intent",
    "provider-create",
    "prepare-enrollment",
    "node-enrollment",
    "enroll",
    "runtime",
  ]);
  assertEquals(effects.waits, 1);
  assertEquals(effects.calls[0].node, 3);
  assertEquals(effects.calls[5].node, 12);
});

Deno.test("enrollment of an existing VM never calls create again", async () => {
  const effects = new Effects();
  await lifecycle("task_vm.enroll", intent(), effects);
  assertEquals(effects.calls.map((entry) => entry.key), [
    "lifecycle-intent",
    "prepare-enrollment",
    "node-enrollment",
    "enroll",
    "runtime",
  ]);
});

Deno.test("wrong-image, missing, stopped and expired boots never enroll", async () => {
  for (
    const [patch, code] of [
      [{ image_matches: false }, "task_vm.image_mismatch"],
      [{ present: false }, "task_vm.vm_missing"],
      [{ running: false }, "task_vm.vm_not_running"],
      [
        { bootstrap_ready: false, bootstrap_expired: true },
        "task_vm.bootstrap_timeout",
      ],
    ] as const
  ) {
    const effects = new Effects();
    Object.assign(effects.state, patch);
    await assertRejects(
      () => lifecycle("task_vm.provision", intent(), effects),
      ExecutorError,
      code,
    );
    assertEquals(effects.calls.some((entry) => entry.key === "enroll"), false);
  }
});

Deno.test("resume starts once and waits for the agent and Pi before confirming readiness", async () => {
  const effects = new Effects();
  effects.state.responds = false;
  effects.onWait = () => {
    effects.state.responds = true;
  };
  assertEquals(await lifecycle("task_vm.resume", intent(), effects), {
    ready: true,
  });
  assertEquals(effects.calls.map((entry) => entry.key), [
    "lifecycle-intent",
    "provider-start",
    "runtime-confirmed",
  ]);
  assertEquals(effects.waits, 1);
});

Deno.test("park refuses missing or unsettled power and confirms a stop before success", async () => {
  for (
    const [patch, code] of [[{ present: false }, "task_vm.vm_missing"], [{
      settled: false,
    }, "task_vm.not_settled"]] as const
  ) {
    const effects = new Effects();
    Object.assign(effects.state, patch);
    await assertRejects(
      () => lifecycle("task_vm.park", intent(), effects),
      ExecutorError,
      code,
    );
    assertEquals(effects.calls.map((call) => call.key), ["lifecycle-intent"]);
  }
  const effects = new Effects();
  await lifecycle("task_vm.park", intent(), effects);
  assertEquals(effects.calls.map((entry) => entry.key), [
    "lifecycle-intent",
    "provider-stop",
    "confirm-stopped",
  ]);
});

Deno.test("destruction waits for preservation or confirmed VM loss before deleting anything", async () => {
  const effects = new Effects();
  Object.assign(effects.state, {
    destroy_allowed: false,
    needs_wake: true,
    pending_commit: "b".repeat(40),
  });
  effects.onWait = () => {
    effects.state.destroy_allowed = true;
    effects.state.needs_wake = false;
  };
  await lifecycle("task_vm.destroy", intent(), effects);
  assertEquals(effects.calls.map((entry) => entry.key), [
    "lifecycle-intent",
    "preservation-resume",
    "destroy-intent",
    "revoke-model-key",
    "provider-delete",
    "forget-workspaces",
    "node-removal",
    "forget-records",
  ]);
  assertEquals(effects.waits, 1);
});

Deno.test("deletion takes priority when neither worker stop nor power-off succeeds", async () => {
  const effects = new Effects();
  Object.assign(effects.state, {
    worker_stop_required: true,
    destroy_allowed: false,
    pending_commit: "b".repeat(40),
  });
  effects.failures.add("stop-worker");
  effects.failures.add("worker-power-off");
  await lifecycle("task_vm.destroy", intent(), effects);
  assertEquals(effects.waits, 0);
  assertEquals(
    effects.calls.some((entry) => entry.key === "provider-delete"),
    true,
  );
});

Deno.test("failed provider deletion keeps Node and workspace records", async () => {
  const effects = new Effects();
  effects.failures.add("provider-delete");
  await assertRejects(() => lifecycle("task_vm.destroy", intent(), effects));
  assertEquals(
    effects.calls.some((entry) => entry.key === "forget-records"),
    false,
  );
});

Deno.test("UpCloud effects use the Gateway and an opaque owner-bound API reference", async () => {
  const effects = new Effects();
  const value = intent();
  value.provider = "upcloud";
  value.host_node_id = null;
  value.execution_node_id = 1;
  value.variant = "upcloud-key";
  await lifecycle("task_vm.resume", value, effects);
  assertEquals(effects.calls[1], {
    key: "provider-start",
    kind: "task_vm.upcloud_start",
    node: 1,
    input: { task_vm_id: 5, token: { $secret: "upcloud.api", id: 5 } },
  });
});

Deno.test("UpCloud park escalates the proven soft stop in Swamp and waits for stopped power", async () => {
  const effects = new Effects();
  const admitted = {
    ...intent(),
    provider: "upcloud" as const,
    host_node_id: null,
    execution_node_id: 1,
  };
  effects.receipts.set("provider-stop", {
    ready: true,
    effect_finished_at: "2020-01-01T00:00:00Z",
  });
  effects.onWait = () => {
    effects.state.running = false;
  };
  await lifecycle("task_vm.park", admitted, effects);
  assertEquals(effects.calls.map((call) => call.key), [
    "lifecycle-intent",
    "provider-hard-stop",
    "confirm-stopped",
  ]);
  assertEquals(effects.calls[1].kind, "task_vm.upcloud_hard_stop");
  assertEquals(effects.waits, 1);
});

Deno.test("UpCloud destruction waits for power and confirmed absence before forgetting records", async () => {
  const effects = new Effects();
  const admitted = {
    ...intent(),
    provider: "upcloud" as const,
    host_node_id: null,
    execution_node_id: 1,
  };
  effects.onWait = () => {
    if (effects.calls.some((call) => call.key === "provider-delete")) {
      effects.state.present = false;
    } else effects.state.running = false;
  };
  await lifecycle("task_vm.destroy", admitted, effects);
  const keys = effects.calls.map((call) => call.key);
  assertEquals(
    keys.indexOf("delete-power-off") < keys.indexOf("provider-delete"),
    true,
  );
  assertEquals(keys.at(-1), "forget-records");
  assertEquals(effects.waits, 2);
});

Deno.test("UpCloud provisioning starts an adopted allocation and waits through transitional power", async () => {
  const effects = new Effects();
  const admitted = {
    ...intent(),
    provider: "upcloud" as const,
    host_node_id: null,
    execution_node_id: 1,
  };
  effects.state.running = false;
  effects.state.settled = false;
  effects.onWait = () => {
    effects.state.running = true;
    effects.state.settled = true;
  };
  await lifecycle("task_vm.provision", admitted, effects);
  assertEquals(effects.calls.map((call) => call.key), [
    "lifecycle-intent",
    "provider-create",
    "provider-start",
    "prepare-enrollment",
    "node-enrollment",
    "enroll",
    "runtime",
  ]);
  assertEquals(effects.waits, 1);
});

Deno.test("VM destruction proves each owning Route removal after provider deletion and before record cleanup", async () => {
  const effects = new Effects();
  const admitted = {
    ...intent(),
    workspace_routes: [{ route_id: 41, node_id: 12, instance_id: 90 }],
  };
  await lifecycle("task_vm.destroy", admitted, effects);
  const keys = effects.calls.map((call) => call.key);
  assertEquals(
    keys.indexOf("provider-delete") < keys.indexOf("workspace-route-41"),
    true,
  );
  assertEquals(
    keys.indexOf("workspace-route-41") < keys.indexOf("forget-records"),
    true,
  );
  assertEquals(
    effects.calls.find((call) => call.key === "workspace-route-41"),
    {
      key: "workspace-route-41",
      kind: "route.destroy",
      node: 12,
      input: { route_id: 41, offline: true },
    },
  );
});

Deno.test("a refused workspace Route removal retains the VM’s Node and workspace records", async () => {
  const effects = new Effects();
  effects.failures.add("workspace-route-41");
  const admitted = {
    ...intent(),
    workspace_routes: [{ route_id: 41, node_id: 12, instance_id: 90 }],
  };
  await assertRejects(() => lifecycle("task_vm.destroy", admitted, effects));
  assertEquals(
    effects.calls.some((call) => call.key === "forget-records"),
    false,
  );
});

Deno.test("an already active reserved Node skips enrollment and prepares runtime", async () => {
  const effects = new Effects();
  effects.receipts.set("prepare-enrollment", {
    ready: true,
    node_id: 12,
    enrolled: true,
  });
  await lifecycle("task_vm.enroll", intent(), effects);
  assertEquals(effects.calls.map((call) => call.key), [
    "lifecycle-intent",
    "enroll",
    "runtime",
  ]);
});

Deno.test("an uncertain Node enrollment preserves the reservation and defers runtime until replay", async () => {
  const effects = new Effects();
  const original = effects.child.bind(effects);
  let lostReply = true;
  effects.child = async (key, kind, node, input) => {
    const result = await original(key, kind, node, input);
    if (key === "node-enrollment" && lostReply) {
      lostReply = false;
      throw new ExecutorError("operation.outcome_unknown");
    }
    return result;
  };
  await assertRejects(
    () => lifecycle("task_vm.enroll", intent(), effects),
    ExecutorError,
    "operation.outcome_unknown",
  );
  assertEquals(effects.calls.some((call) => call.key === "runtime"), false);
  await lifecycle("task_vm.enroll", intent(), effects);
  assertEquals(
    effects.calls.filter((call) => call.key === "node-enrollment").length,
    1,
  );
  assertEquals(
    effects.calls.find((call) => call.key === "node-enrollment")?.node,
    12,
  );
  assertEquals(effects.calls.at(-1)?.key, "runtime");
});

Deno.test("workspace cleanup precedes Node removal and a removal refusal keeps final records", async () => {
  const effects = new Effects();
  effects.failures.add("node-removal");
  await assertRejects(() => lifecycle("task_vm.destroy", intent(), effects));
  assertEquals(effects.calls.at(-1)?.key, "forget-workspaces");
  assertEquals(
    effects.calls.some((call) => call.key === "forget-records"),
    false,
  );
});

Deno.test("a VM without a recorded guest Node omits Node removal", async () => {
  const effects = new Effects();
  await lifecycle(
    "task_vm.destroy",
    { ...intent(), guest_node_id: null },
    effects,
  );
  assertEquals(
    effects.calls.some((call) => call.key === "node-removal"),
    false,
  );
  assertEquals(effects.calls.at(-2)?.key, "forget-workspaces");
  assertEquals(effects.calls.at(-1)?.key, "forget-records");
});
