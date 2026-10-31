import { z } from "npm:zod@4.4.3";
import { ExecutorError } from "../../operation-runner/mod.ts";
import type { FamilyEffects } from "./runtime.ts";
export const SweepInput = z.object({
  node_id: z.number().int().positive(),
  provider: z.enum(["incus", "upcloud"]),
  sweep_id: z.number().int().positive(),
  deadline_at: z.number().int().positive(),
}).strict();
export async function sweepHost(
  input: z.infer<typeof SweepInput>,
  effects: FamilyEffects,
) {
  const snapshot = await effects.read("sweep");
  const deletions = z.array(z.record(z.string(), z.unknown())).parse(
    snapshot.deletions,
  );
  const common = {
    node_id: input.node_id,
    provider: input.provider,
    sweep_id: input.sweep_id,
  };
  let failed = false;
  for (const deletion of deletions) {
    try {
      if (input.provider === "upcloud" && deletion.kind === "server") {
        await effects.child(
          `stop-server-${String(deletion.uuid)}`,
          "task_vm.sweep_stop_upcloud",
          input.node_id,
          {
            ...common,
            deletion,
            token: { $secret: "upcloud.api", id: input.node_id },
          },
        );
        for (;;) {
          const states = (await effects.read("sweep")).server_states;
          if (!states || typeof states !== "object") {
            throw new ExecutorError("task_vm.invalid_host_output");
          }
          const state =
            (states as Record<string, unknown>)[String(deletion.uuid)];
          if (state === undefined || state === "stopped") break;
          await effects.wait();
        }
      }
      await effects.child(
        `delete-${String(deletion.kind)}-${
          String(deletion.uuid ?? deletion.name)
        }`,
        input.provider === "upcloud"
          ? "task_vm.sweep_delete_upcloud"
          : "task_vm.sweep_delete",
        input.node_id,
        {
          ...common,
          deletion,
          ...(input.provider === "upcloud"
            ? { token: { $secret: "upcloud.api", id: input.node_id } }
            : {}),
        },
      );
      if (input.provider === "upcloud" && deletion.kind === "server") {
        for (;;) {
          const states = (await effects.read("sweep")).server_states;
          if (!states || typeof states !== "object") {
            throw new ExecutorError("task_vm.invalid_host_output");
          }
          if (!(String(deletion.uuid) in states)) break;
          await effects.wait();
        }
      }
    } catch (error) {
      if (
        !(error instanceof ExecutorError) ||
        [
          "operation.waiting",
          "operation.lease_lost",
          "operation.outcome_unknown",
          "operation.execution_busy",
          "operation.cancelled",
        ].includes(error.code)
      ) throw error;
      failed = true;
    }
  }
  await effects.child(
    "observe",
    "task_vm.sweep_observe",
    input.node_id,
    common,
  );
  if (failed) throw new ExecutorError("task_vm.host_command_failed");
  return { ready: true };
}
