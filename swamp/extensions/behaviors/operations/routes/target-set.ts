import { z } from "npm:zod@4.4.3";
import { DirectArgs, GatewayOperation } from "../_lib/direct.ts";
import { routeWorkloadLocal } from "./route-workload-local.ts";
import { convergeFirewall, convergeRouteLan } from "./firewall-local.ts";
import { verifyLocal } from "./verification.ts";
import { certificateActions } from "../certificates/actions.ts";
import { Leaf, Removal } from "../certificates/input.ts";

const ObjectInput = z.record(z.string(), z.unknown());
const Effect = DirectArgs.extend({
  node_id: z.number().int().positive(),
  target_node_id: z.number().int().positive(),
});
const Ids = z.array(z.number().int().positive());
const Member = z.object({
  route_id: z.number().int().positive(),
  instance_id: z.number().int().positive(),
  node_id: z.number().int().positive(),
  production: z.boolean(),
  certificate_scope: z.string(),
}).passthrough();
export const TargetIntent = z.object({
  route_id: z.number().int().positive(),
  mode: z.enum(["target", "targets", "targets_recover", "state_only"]),
  binding_only: z.boolean(),
  noop: z.boolean(),
  recover_forward: z.boolean(),
  prepared: z.array(Member),
  workloads: z.array(Member),
  staging_certificates: z.array(Leaf),
  live_certificates: z.array(Leaf),
  certificates: z.array(Leaf),
  staging_removals: z.array(Removal),
  rollback_lan: z.array(
    z.object({
      node_id: z.number().int().positive(),
      operation: z.literal("remove"),
      route_id: z.number().int().positive(),
    }).strict(),
  ).default([]),
  retire_lan: z.array(
    z.object({
      node_id: z.number().int().positive(),
      operation: z.literal("remove"),
      route_id: z.number().int().positive(),
    }).strict(),
  ).default([]),
  node_ids: Ids,
  workload_node_ids: Ids,
  router_node_ids: Ids,
  dns_node_ids: Ids,
  caddy_node_ids: Ids,
  vacated_route_ids: Ids,
  removals: z.array(
    z.object({
      instance_id: z.number().int().positive(),
      node_id: z.number().int().positive(),
    }).passthrough(),
  ),
  proposal: ObjectInput,
});
const Parent = z.object({
  id: z.number().int().positive(),
  kind: z.string(),
  node_id: z.number().int().positive(),
  status: z.string(),
  cancel_requested: z.boolean(),
  input: z.object({
    target: TargetIntent,
    environment_fields: z.record(z.string(), z.string()).default({}),
    publication: z.object({
      node_id: z.number().int().positive(),
      agent_sha256: z.string().optional(),
    }).passthrough(),
    workloads: z.array(Member),
    probes: z.array(ObjectInput).default([]),
    firewall: ObjectInput.nullable().optional(),
  }),
});
export type RouteTargetRoot = z.infer<typeof DirectArgs> & { node_id: number };
export type RouteTargetEffect = z.infer<typeof Effect>;
type NodeArgs = RouteTargetEffect;
export type RouteTargetView = z.infer<typeof Parent>["input"];

/** Read only the public view selected for this authenticated, admitted effect Node. */
export async function readTargetParticipant(
  gateway: Pick<GatewayOperation, "read">,
  args: NodeArgs,
  signal?: AbortSignal,
  options: { recovery?: boolean } = {},
) {
  const current = Parent.parse(
    await gateway.read(options.recovery ? undefined : signal),
  );
  if (
    current.id !== args.operation_id || current.kind !== args.kind ||
    current.node_id !== args.node_id ||
    current.input.publication.node_id !== args.target_node_id ||
    !current.input.target.node_ids.includes(args.target_node_id)
  ) throw new Error("route.target_participant_invalid");
  if (
    current.input.workloads.some((member) =>
      member.node_id !== args.target_node_id
    )
  ) throw new Error("route.target_workload_invalid");
  if (!options.recovery && (current.cancel_requested || signal?.aborted)) {
    throw new Error("operation.cancelled");
  }
  if (!["queued", "running"].includes(current.status)) {
    throw new Error("route.target_finished");
  }
  return current.input;
}
/** A bounded domain transition only; the composing root owns its projection. */
export async function routeTargetTransition(
  gateway: Pick<GatewayOperation, "project">,
  checkpoint: string,
) {
  z.string().regex(/^[a-z][a-z0-9-]{0,99}$/).parse(checkpoint);
  await gateway.project({ status: "running", result: { checkpoint } });
  return { ready: true };
}

export type RouteWorkloadView = Pick<
  RouteTargetView,
  "publication" | "environment_fields" | "workloads" | "probes" | "firewall"
>;

/** Local effects shared by target mutation and Instance transfer in their own root. */
export function routeWorkloadActions(
  workloads: Pick<
    ReturnType<typeof routeWorkloadLocal>,
    "prepareRuntime" | "grantSourceAccess" | "synchronizeEnvironment"
  > = routeWorkloadLocal(),
  network: {
    lan: (raw: unknown) => Promise<Record<string, unknown>>;
    firewall: (raw: unknown) => Promise<Record<string, unknown>>;
    verify: (raw: unknown) => Promise<Record<string, unknown>>;
  } = {
    lan: convergeRouteLan,
    firewall: convergeFirewall,
    verify: verifyLocal,
  },
) {
  const success = (value: Record<string, unknown>) => {
    if (value.error_code !== undefined) {
      throw new Error(String(value.error_code));
    }
  };
  return {
    async prepare(view: RouteWorkloadView, signal?: AbortSignal) {
      for (const member of view.workloads) {
        signal?.throwIfAborted();
        if (member.production) {
          success(await workloads.prepareRuntime(member, signal));
        } else if (member.source_access != null) {
          success(await workloads.grantSourceAccess(member.source_access));
        }
      }
      return { ready: true };
    },
    async lan(view: RouteWorkloadView, signal?: AbortSignal) {
      for (const member of view.workloads) {
        signal?.throwIfAborted();
        if (member.lan != null) success(await network.lan(member.lan));
      }
      return { ready: true };
    },
    async firewall(view: RouteWorkloadView, signal?: AbortSignal) {
      signal?.throwIfAborted();
      if (view.firewall) success(await network.firewall(view.firewall));
      return { ready: true };
    },
    async verify(view: RouteWorkloadView, signal?: AbortSignal) {
      for (const probe of view.probes) {
        signal?.throwIfAborted();
        success(await network.verify(probe));
      }
      return { ready: true };
    },
    async synchronizeEnvironment(
      view: RouteWorkloadView,
      material: Record<string, unknown>,
      signal?: AbortSignal,
    ) {
      const nodeId = view.publication.node_id;
      const field = view.environment_fields[String(nodeId)];
      if (!field) return { ready: true };
      const owners = new Set(
        view.workloads.map((member) => member.instance_id),
      );
      const writes = z.array(
        z.object({
          instance_id: z.number().int().positive(),
          name: z.enum([
            "instance.write-environment",
            "instance.write-directory-url",
            "instance.write-development-url",
          ]),
          input: ObjectInput,
        }).strict(),
      ).parse(material[field]);
      if (writes.some((write) => !owners.has(write.instance_id))) {
        throw new Error("route.environment_owner_invalid");
      }
      signal?.throwIfAborted();
      success(await workloads.synchronizeEnvironment(writes));
      return { ready: true };
    },
  };
}

/** Target-specific retirement composes the same placed workload actions. */
export function routeTargetActions(
  workloads: Parameters<typeof routeWorkloadActions>[0] = routeWorkloadLocal(),
  network: Parameters<typeof routeWorkloadActions>[1] = {
    lan: convergeRouteLan,
    firewall: convergeFirewall,
    verify: verifyLocal,
  },
  certificates = certificateActions(),
) {
  const success = (value: Record<string, unknown>) => {
    if (value.error_code !== undefined) {
      throw new Error(String(value.error_code));
    }
  };
  return {
    ...routeWorkloadActions(workloads, network),
    async cleanup(
      view: RouteTargetView,
      rollback: boolean,
      signal?: AbortSignal,
    ) {
      const nodeId = view.publication.node_id;
      for (
        const rule of rollback
          ? view.target.rollback_lan
          : view.target.retire_lan
      ) {
        if (rule.node_id !== nodeId) continue;
        signal?.throwIfAborted();
        const { node_id: _node, ...input } = rule;
        success(await network.lan(input));
      }
      for (const leaf of view.target.staging_removals) {
        if (leaf.node_id !== nodeId) continue;
        signal?.throwIfAborted();
        success(await certificates.remove(leaf, signal));
      }
      return { ready: true };
    },
  };
}
