import { z } from "npm:zod@4.4.3";
import { ExecutorError } from "../../operation-runner/mod.ts";
import {
  type FamilyEffects,
  RuntimeInput,
  SecretReference,
} from "./runtime.ts";

export const LifecycleInput = z.object({
  task_vm_id: z.number().int().positive(),
  provider: z.enum(["incus", "upcloud"]),
  host_node_id: z.number().int().positive().nullable(),
  gateway_node_id: z.number().int().positive(),
  guest_node_id: z.number().int().positive().nullable(),
  deadline_at: z.number().int().positive(),
  execution_node_id: z.number().int().positive(),
  variant: z.string(),
  workspace_routes: z.array(
    z.object({
      route_id: z.number().int().positive(),
      node_id: z.number().int().positive(),
      instance_id: z.number().int().positive(),
    }).strict(),
  ).default([]),
  effect_key: z.string().optional(),
  token: SecretReference,
  origin: z.string().nullable(),
  key: SecretReference.nullable(),
  vm: z.record(z.string(), z.unknown()).optional(),
  create: z.record(z.string(), z.unknown()).optional(),
  delete: z.record(z.string(), z.unknown()).optional(),
}).strict();
export type LifecycleIntent = z.infer<typeof LifecycleInput>;

async function provider(
  input: LifecycleIntent,
  effects: FamilyEffects,
  key: string,
  verb: "create" | "start" | "stop" | "hard_stop" | "delete",
) {
  if (input.provider === "incus") {
    if (!input.host_node_id || !input.vm) {
      throw new Error("Missing admitted Incus host");
    }
    return await effects.child(
      key,
      `task_vm.incus_${verb}`,
      input.host_node_id,
      {
        task_vm_id: input.task_vm_id,
        vm: input.vm,
        operation: verb === "create"
          ? input.create
          : verb === "delete"
          ? input.delete
          : { kind: verb },
      },
    );
  }
  return await effects.child(
    key,
    `task_vm.upcloud_${verb}`,
    input.gateway_node_id,
    {
      task_vm_id: input.task_vm_id,
      token: input.token,
    },
  );
}

async function stopUpCloud(
  input: LifecycleIntent,
  effects: FamilyEffects,
  key = "provider-stop",
  hard = false,
) {
  let result = await provider(input, effects, key, hard ? "hard_stop" : "stop");
  let deadline = typeof result.effect_finished_at === "string"
    ? Date.parse(result.effect_finished_at) / 1000 + (hard ? 120 : 150)
    : input.deadline_at;
  for (;;) {
    const state = await effects.read("lifecycle");
    if (
      state.present !== true ||
      (state.settled === true && state.running === false)
    ) return;
    if (Date.now() / 1000 >= deadline) {
      if (hard) throw new ExecutorError("task_vm.upcloud_timeout");
      result = await provider(
        input,
        effects,
        "provider-hard-stop",
        "hard_stop",
      );
      hard = true;
      deadline = typeof result.effect_finished_at === "string"
        ? Date.parse(result.effect_finished_at) / 1000 + 120
        : input.deadline_at;
    }
    await effects.wait();
  }
}

export async function lifecycle(
  kind: string,
  input: LifecycleIntent,
  effects: FamilyEffects,
): Promise<Record<string, unknown>> {
  const node = input.host_node_id ?? input.gateway_node_id;
  await effects.child("lifecycle-intent", "task_vm.lifecycle_intent", node, {
    task_vm_id: input.task_vm_id,
  });
  if (kind === "task_vm.provision" || kind === "task_vm.enroll") {
    const created = kind === "task_vm.provision"
      ? await provider(input, effects, "provider-create", "create")
      : null;
    if (kind === "task_vm.provision" && input.provider === "upcloud") {
      await provider(input, effects, "provider-start", "start");
    }
    const bootDeadline = typeof created?.effect_finished_at === "string"
      ? Date.parse(created.effect_finished_at) / 1000 + 600
      : input.deadline_at;
    const bootstrap = async () => {
      for (;;) {
        const state = await effects.read("lifecycle");
        if (state.present !== true) {
          throw new ExecutorError("task_vm.vm_missing");
        }
        if (state.running !== true) {
          if (state.settled === true) {
            throw new ExecutorError("task_vm.vm_not_running");
          }
          if (Date.now() / 1000 >= bootDeadline) {
            throw new ExecutorError("task_vm.bootstrap_timeout");
          }
          await effects.wait();
          continue;
        }
        if (state.image_matches === false) {
          throw new ExecutorError("task_vm.image_mismatch");
        }
        if (
          state.bootstrap_ready === true && typeof state.address === "string"
        ) {
          return;
        }
        if (
          state.bootstrap_expired === true || Date.now() / 1000 >= bootDeadline
        ) throw new ExecutorError("task_vm.bootstrap_timeout");
        await effects.wait();
      }
    };
    if (effects.stage) await effects.stage("enrollment-bootstrap", bootstrap);
    else await bootstrap();
    const prepared = await effects.child(
      "prepare-enrollment",
      "task_vm.prepare_enrollment",
      node,
      { task_vm_id: input.task_vm_id },
    );
    const reserved = z.number().int().positive().parse(prepared.node_id);
    if (prepared.enrolled !== true) {
      // Gateway stores the exact ProvisionNodeData; the child API submits only this key.
      await effects.child("node-enrollment", "node.add", reserved, {});
    }
    const enrolled = await effects.child(
      "enroll",
      "task_vm.enroll_node",
      node,
      { task_vm_id: input.task_vm_id },
    );
    const state = await effects.read("lifecycle");
    const runtime = RuntimeInput.parse(state.runtime);
    await effects.child(
      "runtime",
      "task_vm.prepare_runtime",
      runtime.node_id,
      runtime,
    );
    return { ready: true, node_id: enrolled.node_id };
  }
  if (kind === "task_vm.resume") {
    await provider(input, effects, "provider-start", "start");
    for (;;) {
      const state = await effects.read("lifecycle");
      if (state.responds === true) {
        const runtime = RuntimeInput.parse(state.runtime);
        await effects.child(
          "runtime-confirmed",
          "task_vm.runtime_confirm",
          runtime.node_id,
          { task_vm_id: input.task_vm_id, sha256: runtime.sha256 },
        );
        return { ready: true };
      }
      await effects.wait();
    }
  }
  if (kind !== "task_vm.destroy") {
    throw new Error("Unknown task VM lifecycle kind");
  }
  let force = false;
  let state = await effects.read("lifecycle");
  if (
    state.worker_stop_required === true && typeof state.node_id === "number"
  ) {
    try {
      await effects.child(
        "stop-worker",
        "task_vm.stop_worker",
        state.node_id,
        {},
      );
    } catch (error) {
      if (
        error instanceof ExecutorError &&
        [
          "operation.waiting",
          "operation.lease_lost",
          "operation.outcome_unknown",
        ].includes(
          error.code,
        )
      ) throw error;
      try {
        if (input.provider === "upcloud") {
          await stopUpCloud(input, effects, "worker-power-off", true);
        } else await provider(input, effects, "worker-power-off", "stop");
      } catch (error) {
        if (
          error instanceof ExecutorError &&
          [
            "operation.waiting",
            "operation.lease_lost",
            "operation.outcome_unknown",
          ].includes(
            error.code,
          )
        ) throw error;
        force = true;
      }
    }
  }
  for (;;) {
    state = await effects.read("lifecycle");
    if (force || state.destroy_allowed === true) break;
    // Unpublished reviewed work stays until normal removal pushes it or a host sweep proves the VM lost.
    if (state.needs_wake === true) {
      await effects.child("preservation-resume", "task_vm.resume", node, {
        ...input,
        deadline_at: Math.min(
          input.deadline_at,
          Math.floor(Date.now() / 1000) + 540,
        ),
      });
    }
    await effects.wait();
  }
  await effects.child("destroy-intent", "task_vm.mark_destroying", node, {
    task_vm_id: input.task_vm_id,
  });
  if (input.key !== null && input.origin !== null) {
    await effects.child("revoke-model-key", "task_vm.model_revoke", node, {
      task_vm_id: input.task_vm_id,
      origin: input.origin,
      key: input.key,
    });
  }
  if (input.provider === "upcloud") {
    await stopUpCloud(input, effects, "delete-power-off", true);
  }
  await provider(input, effects, "provider-delete", "delete");
  if (input.provider === "upcloud") {
    while ((await effects.read("lifecycle")).present === true) {
      await effects.wait();
    }
  }
  for (const route of input.workspace_routes) {
    await effects.child(
      `workspace-route-${route.route_id}`,
      "route.destroy",
      route.node_id,
      { route_id: route.route_id, offline: true },
    );
  }
  await effects.child("forget-workspaces", "task_vm.forget_workspaces", node, {
    task_vm_id: input.task_vm_id,
  });
  if (input.guest_node_id !== null) {
    await effects.child("node-removal", "node.remove", input.guest_node_id, {});
  }
  await effects.child("forget-records", "task_vm.destroy_records", node, {
    task_vm_id: input.task_vm_id,
  });
  return { ready: true };
}
