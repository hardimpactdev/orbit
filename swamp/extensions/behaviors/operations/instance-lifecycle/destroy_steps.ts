import { z } from "npm:zod@4.4.3";
import { DirectArgs, type GatewayOperation } from "../_lib/direct.ts";
import type { StepListPort } from "../instance-deploy/run_list.ts";
import type { RuntimeInvoke } from "./destroy_runtime_system.ts";
import { DestroyLocal } from "./destroy_contract.ts";
import {
  DestroyRefusal,
  prepareDestroySources,
  removeDestroyRuntime,
} from "./destroy_local.ts";
import { type DestroyInvoke, destroyNode } from "./destroy_node.ts";
import {
  finalizeDestroySourcesWithMaterial,
  verifyDestroySourcesWithMaterial,
} from "./destroy_material.ts";
import { DestroyTeardown, runDestroyTeardown } from "./destroy_teardown.ts";

const Args = DirectArgs.extend({
  kind: z.literal("instance.destroy"),
  node_id: z.number().int().positive(),
});
const Current = z.object({
  id: z.number().int().positive(),
  kind: z.string(),
  node_id: z.number().int().positive().nullable(),
  status: z.enum(["queued", "running", "succeeded", "failed", "cancelled"]),
  cancel_requested: z.boolean(),
  input: z.object({
    instance_id: z.number().int().positive(),
    project_id: z.number().int().positive(),
    node_id: z.number().int().positive(),
    environment: z.literal("development"),
    direct_destroy: z.record(z.string(), z.unknown()),
  }),
});
type Gateway = Pick<GatewayOperation, "read" | "withPrivateMaterial">;
type Actions = {
  source?: DestroyInvoke;
  runtime?: RuntimeInvoke;
  app?: StepListPort["run"];
  verifySource?: Parameters<typeof verifyDestroySourcesWithMaterial>[2];
};
export type DestroyLocalStep =
  | "prepare_sources"
  | "teardown"
  | "runtime"
  | "verify_sources"
  | "finalize_sources";

/** Each placed step reads the same admitted parent; no child or parent projection. */
export async function runDestroyLocalStep(
  step: DestroyLocalStep,
  rawArgs: unknown,
  gateway: Gateway,
  actions: Actions = {},
  signal?: AbortSignal,
): Promise<Record<string, unknown>> {
  const args = Args.parse(rawArgs);
  const read = async () => {
    signal?.throwIfAborted();
    const current = Current.parse(await gateway.read(signal));
    if (
      current.id !== args.operation_id || current.kind !== args.kind ||
      current.node_id !== args.node_id ||
      current.input.node_id !== args.node_id
    ) throw new DestroyRefusal("instance.removal_conflict");
    if (!["queued", "running"].includes(current.status)) {
      throw new DestroyRefusal("operation.not_running");
    }
    const detail = current.input.direct_destroy;
    const local = DestroyLocal.parse({
      node_id: detail.node_id,
      members: detail.members,
      runtime: detail.runtime,
      database: detail.database,
    });
    const teardown = DestroyTeardown.parse({
      node_id: local.node_id,
      project_id: current.input.project_id,
      environment: current.input.environment,
      node_user: detail.node_user,
      teardown: detail.teardown,
    });
    if (
      local.node_id !== args.node_id ||
      local.members.some((member) =>
        member.source.user !== teardown.node_user
      ) ||
      !local.members.some((member) =>
        member.instance_id === current.input.instance_id
      ) || teardown.teardown.length !== local.members.length ||
      teardown.teardown.some((row) =>
        !local.members.some((member) =>
          member.instance_id === row.instance_id &&
          member.source.checkout === row.checkout
        )
      )
    ) throw new DestroyRefusal("instance.removal_conflict");
    return { local, teardown, cancel_requested: current.cancel_requested };
  };
  const { local, teardown, cancel_requested } = await read();
  if (cancel_requested) throw new DestroyRefusal("operation.cancelled");
  switch (step) {
    case "prepare_sources":
      return await prepareDestroySources(local, actions.source, signal);
    case "teardown": {
      const pending = [];
      for (const member of teardown.teardown) {
        if (member.steps.length > 0) {
          if ((await read()).cancel_requested) {
            throw new DestroyRefusal("operation.cancelled");
          }
          const source = local.members.find((row) =>
            row.instance_id === member.instance_id
          )!;
          const state = await (actions.source ?? destroyNode)(
            "instance.read-source-removal-state",
            {
              ...source.source,
              receipt: source.receipt,
              grouping_directory: source.grouping_directory,
            },
            source.source.user,
            signal,
          );
          if (typeof state.error_code === "string") {
            throw new DestroyRefusal(state.error_code);
          }
          const phase = z.enum([
            "present",
            "quarantined",
            "receipt_pending_cleanup",
            "completed",
          ]).parse(state.state);
          // Finalization can only follow successful teardown in this admitted DAG.
          // The authenticated source journal survives partial deletion and new roots.
          if (phase !== "present") continue;
        }
        pending.push(member);
      }
      if (pending.length === 0) {
        return {
          teardown_complete: true,
          instance_ids: teardown.teardown.map((row) => row.instance_id),
        };
      }
      await runDestroyTeardown(
        { ...teardown, teardown: pending },
        args.node_id,
        {
          read: async () => {
            const current = await read();
            return {
              input: current.teardown,
              cancel_requested: current.cancel_requested,
            };
          },
          privateMaterial: gateway,
          ...(actions.app === undefined ? {} : { run: actions.app }),
        },
        signal,
      );
      return {
        teardown_complete: true,
        instance_ids: teardown.teardown.map((row) => row.instance_id),
      };
    }
    case "runtime":
      return await removeDestroyRuntime(local, actions.runtime, signal);
    case "verify_sources":
      await verifyDestroySourcesWithMaterial(
        local,
        gateway,
        actions.verifySource,
        signal,
        actions.source,
      );
      return { verified: true };
    case "finalize_sources":
      return await finalizeDestroySourcesWithMaterial(
        local,
        gateway,
        actions.source,
        signal,
      );
  }
}
