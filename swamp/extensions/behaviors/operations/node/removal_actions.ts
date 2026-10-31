import { z } from "npm:zod@4.4.3";
import { ProxyNodeIds } from "./role_metrics.ts";
import { type NetworkSystem, runNetworkSystem } from "./network_system.ts";
import { removeNodeArtifacts } from "./removal_system.ts";
import { convergeLocalDependencies } from "./converge_local.ts";
import type { Snapshot } from "./converge_direct_contract.ts";
import {
  type RemovalRouteDependencies,
  RemovalRouteInput,
  removeNodeRoutes,
  restoreNodeRoutes,
} from "./removal_routes.ts";
import {
  convergeParticipantNetwork,
  ParticipantNetworkInput,
} from "./network_participant.ts";
import {
  type NodeRemovalSnapshot,
  type RemovalActions,
  type RemovalPlacement,
} from "./removal_local.ts";

const Id = z.number().int().positive();
const ObjectInput = z.record(z.string(), z.unknown());
const Pin = z.string().regex(/^[a-f0-9]{64}$/);
/** Fresh public state selected by the authenticated, admitted participant. */
export const RemovalParticipantInput = z.object({
  schema_version: z.literal(1),
  removal_node_id: Id,
  node_id: Id,
  agent_sha256: Pin,
  network: ParticipantNetworkInput.nullable(),
  residue: ObjectInput.nullable(),
  route_removal: RemovalRouteInput.nullable().optional(),
  proxy_node_ids: ProxyNodeIds.optional(),
  metrics_fleet: z.unknown().optional(),
  metrics_excluded_node_ids: z.array(Id).optional(),
  metrics_runtime_required: z.boolean().optional(),
  dns: z.object({
    records: z.string().max(4194304),
    catalog: z.string().max(4194304),
  }).strict().nullable(),
}).strict();
export type RemovalParticipantInput = z.infer<typeof RemovalParticipantInput>;
export type RemovalActionDependencies = {
  read: (
    snapshot: NodeRemovalSnapshot,
    placement: RemovalPlacement,
    signal?: AbortSignal,
  ) => Promise<unknown>;
  execute?: NetworkSystem;
  network?: typeof convergeParticipantNetwork;
  residue?: typeof convergeLocalDependencies.residue;
  dns?: typeof convergeLocalDependencies.dns;
  routes?: typeof removeNodeRoutes;
  restoreRoutes?: typeof restoreNodeRoutes;
  withEnvironment?: RemovalRouteDependencies["withEnvironment"];
};

function ready(raw: unknown, expected: z.ZodType) {
  const error = z.object({ error_code: z.literal("agent.version_mismatch") })
    .safeParse(raw);
  if (error.success) {
    return { ready: false as const, error_code: error.data.error_code };
  }
  if (!expected.safeParse(raw).success) {
    throw new Error("The Node cleanup action outcome is uncertain");
  }
  return { ready: true as const };
}
/** Canonical fixed local programs. A parent supplies one placed call per exact effect Node.
 * Hub publication uses the separate canonical wireguard-hub-N behavior, whose lock encloses
 * its fresh private read. Metrics and reachability contributors must be supplied by their owner.
 */
export function nodeRemovalLocalActions(
  deps: RemovalActionDependencies,
): RemovalActions {
  const execute = deps.execute ?? runNetworkSystem;
  const participantNetwork = deps.network ?? convergeParticipantNetwork;
  const residue = deps.residue ?? convergeLocalDependencies.residue;
  const dns = deps.dns ?? convergeLocalDependencies.dns;
  const input = async (
    s: NodeRemovalSnapshot,
    p: RemovalPlacement,
    signal?: AbortSignal,
  ) => {
    if (p.transport !== "local" || p.effect_node_id !== p.execution_node_id) {
      throw new Error("Cleanup must run on its exact admitted Node");
    }
    const current = RemovalParticipantInput.parse(
      await deps.read(s, p, signal),
    );
    if (
      current.node_id !== p.effect_node_id ||
      current.removal_node_id !== s.node_id ||
      (current.node_id === s.node_id && s.offline)
    ) {
      throw new Error("The cleanup public view has a foreign Node binding");
    }
    if (
      current.network !== null &&
      (current.network.node_id !== current.node_id ||
        current.network.agent_sha256 !== current.agent_sha256)
    ) {
      throw new Error(
        "The cleanup network policy has a foreign participant identity",
      );
    }
    return current;
  };
  const network = async (
    s: NodeRemovalSnapshot,
    p: RemovalPlacement,
    signal?: AbortSignal,
  ) => {
    const current = await input(s, p, signal);
    if (current.network === null) {
      throw new Error("The cleanup network contributor is unavailable");
    }
    const outcome = await participantNetwork(current.network, execute, signal);
    return outcome.status === "succeeded"
      ? { ready: true as const }
      : { ready: false as const, error_code: outcome.error_code };
  };
  return {
    peer: network,
    restore_peer: network,
    residue: async (s, p, signal) => {
      const current = await input(s, p, signal);
      if (current.residue === null) {
        throw new Error("The cleanup residue contributor is unavailable");
      }
      const snapshot: Snapshot = {
        schema_version: 1,
        node_id: current.node_id,
        agent_sha256: current.agent_sha256,
        force_artifacts: true,
        first_visit: false,
        artifacts: [],
        payloads: { "route-residue": current.residue },
        network: null,
        refusal: null,
      };
      return ready(
        await residue(snapshot, signal),
        z.object({ changed: z.boolean() }),
      );
    },
    routes: async (s, p, signal) => {
      const current = await input(s, p, signal);
      if (
        !current.route_removal ||
        current.route_removal.member.node_id !== p.effect_node_id
      ) {
        throw new Error(
          "The admitted Route cleanup contributor is unavailable",
        );
      }
      return await (deps.routes ?? removeNodeRoutes)(
        current.route_removal,
        execute,
        signal,
        { withEnvironment: deps.withEnvironment },
      );
    },
    restore_routes: async (s, p, signal) => {
      const current = await input(s, p, signal);
      if (!current.route_removal) {
        throw new Error("The admitted Route restoration is unavailable");
      }
      return await (deps.restoreRoutes ?? restoreNodeRoutes)(
        current.route_removal,
        execute,
        signal,
        {
          withEnvironment: deps.withEnvironment,
        },
      );
    },
    dns: async (s, p, signal) => {
      const current = await input(s, p, signal);
      if (current.dns === null) {
        throw new Error("The admitted DNS publication is unavailable");
      }
      return ready(
        await dns(current.dns, signal),
        z.object({ ready: z.literal(true) }),
      );
    },
    restore_dns: async (s, p, signal) => {
      const current = await input(s, p, signal);
      if (current.dns === null) {
        throw new Error("The admitted DNS restoration is unavailable");
      }
      return ready(
        await dns(current.dns, signal),
        z.object({ ready: z.literal(true) }),
      );
    },
    guest: async (s, p, signal) => {
      if (
        s.machine_deleted || s.offline || p.effect_node_id !== s.node_id ||
        p.execution_node_id !== s.node_id || p.transport !== "local"
      ) {
        throw new Error(
          "The removed or offline guest cannot receive a cleanup command",
        );
      }
      const raw = z.object({
        schema_version: z.literal(1),
        removal_node_id: z.literal(s.node_id),
        node_id: z.literal(s.node_id),
        agent_sha256: Pin,
      }).strict().parse(await deps.read(s, p, signal));
      return await removeNodeArtifacts(
        { node_id: raw.node_id },
        execute,
        signal,
      );
    },
  };
}
