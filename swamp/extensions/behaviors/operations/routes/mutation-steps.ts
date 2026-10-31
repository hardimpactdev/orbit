import { z } from "npm:zod@4.4.3";
import { DirectArgs, GatewayOperation } from "../_lib/direct.ts";
import { nativeRouteDnsGrace, RouteIntent } from "./convergence.ts";
import { routeWorkloadActions } from "./target-set.ts";
import { certificateActions } from "../certificates/actions.ts";
import { Removal } from "../certificates/input.ts";
import { convergeRouteLan } from "./firewall-local.ts";

const ObjectInput = z.record(z.string(), z.unknown());
export const RouteMutationEffect = DirectArgs.extend({
  node_id: z.number().int().positive(),
  target_node_id: z.number().int().positive(),
  route_id: z.number().int().positive(),
});
export const RouteMutationView = z.object({
  route_id: z.number().int().positive(),
  node_ids: z.array(z.number().int().positive()),
  route: RouteIntent,
  publication: z.object({ node_id: z.number().int().positive() }).passthrough(),
  environment_fields: z.record(z.string(), z.string()).default({}),
  workloads: z.array(
    z.object({
      route_id: z.number().int().positive(),
      node_id: z.number().int().positive(),
      instance_id: z.number().int().positive(),
      production: z.boolean(),
      certificate_scope: z.string(),
    }).passthrough(),
  ),
  environment_workloads: z.array(
    z.object({
      route_id: z.number().int().positive(),
      node_id: z.number().int().positive(),
      instance_id: z.number().int().positive(),
      production: z.boolean(),
      certificate_scope: z.string(),
    }).passthrough(),
  ),
  probes: z.array(ObjectInput).default([]),
  firewall: ObjectInput.nullable().optional(),
  cleanup: z.array(Removal),
  cleanup_lan: z.array(
    z.object({
      operation: z.literal("retire"),
      route_id: z.number().int().positive(),
      keep: z.array(z.object({ router: z.ipv4(), workload: z.ipv4() }).strict())
        .max(256),
    }).strict(),
  ).default([]),
});
const Parent = z.object({
  id: z.number().int().positive(),
  kind: DirectArgs.shape.kind,
  node_id: z.number().int().positive(),
  status: z.enum(["queued", "running", "succeeded", "failed", "cancelled"]),
  cancel_requested: z.boolean(),
  input: z.object({
    participant_node_id: z.number().int().positive(),
    publication: z.object({ node_id: z.number().int().positive() }),
    route_mutations: z.array(RouteMutationView),
  }),
});

/** Same root and placed Node in forward and explicit cancellation recovery. */
export async function readRouteMutationParticipant(
  gateway: Pick<GatewayOperation, "read">,
  args: z.infer<typeof RouteMutationEffect>,
  signal?: AbortSignal,
  options: { recovery?: boolean } = {},
) {
  const parent = Parent.parse(
    await gateway.read(options.recovery ? undefined : signal),
  );
  if (
    parent.id !== args.operation_id || parent.kind !== args.kind ||
    parent.node_id !== args.node_id ||
    parent.input.participant_node_id !== args.target_node_id ||
    parent.input.publication.node_id !== args.target_node_id ||
    !["queued", "running"].includes(parent.status)
  ) throw new Error("route.mutation_participant_invalid");
  if (!options.recovery && (parent.cancel_requested || signal?.aborted)) {
    throw new Error("operation.cancelled");
  }
  const matches = parent.input.route_mutations.filter((view) =>
    view.route_id === args.route_id
  );
  if (matches.length !== 1) throw new Error("route.mutation_invalid");
  const view = matches[0];
  if (
    view.publication.node_id !== args.target_node_id ||
    !view.node_ids.includes(args.target_node_id) ||
    [...view.workloads, ...view.environment_workloads].some((member) =>
      member.node_id !== args.target_node_id
    )
  ) throw new Error("route.mutation_placement_invalid");
  return view;
}

/** Parent owns fresh reads, checkpoints, signing and canonical whole-Node publication. */
export function routeMutationActions(
  workloads?: Parameters<typeof routeWorkloadActions>[0],
  network?: Parameters<typeof routeWorkloadActions>[1],
  certificates = certificateActions(),
  lan = convergeRouteLan,
) {
  return {
    ...routeWorkloadActions(workloads, network),
    async synchronizeEnvironment(
      view: z.infer<typeof RouteMutationView>,
      material: Record<string, unknown>,
      signal?: AbortSignal,
    ) {
      return await routeWorkloadActions(workloads, network)
        .synchronizeEnvironment(
          { ...view, workloads: view.environment_workloads },
          material,
          signal,
        );
    },
    async cleanup(
      view: z.infer<typeof RouteMutationView>,
      signal?: AbortSignal,
    ) {
      for (const leaf of view.cleanup) {
        if (leaf.node_id !== view.publication.node_id) {
          throw new Error("route.mutation_cleanup_invalid");
        }
        signal?.throwIfAborted();
        const result = await certificates.remove(leaf, signal);
        if ("error_code" in result) {
          throw new Error(String(result.error_code));
        }
        const { in_use: _unused, ...scope } = leaf;
        const inspected = await certificates.inspect(scope, result, signal);
        if ("error_code" in inspected || inspected.present !== false) {
          throw new Error("route.mutation_cleanup_failed");
        }
      }
      for (const rule of view.cleanup_lan) {
        signal?.throwIfAborted();
        const result = await lan(rule);
        if ("error_code" in result) {
          throw new Error(String(result.error_code));
        }
      }
      return { ready: true };
    },
  };
}
export { nativeRouteDnsGrace as routeMutationDnsGrace };
