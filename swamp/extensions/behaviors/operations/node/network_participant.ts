import { z } from "npm:zod@4.4.3";
import {
  networkCommand,
  type NetworkSystem,
  runNetworkSystem,
} from "./network_system.ts";

export const ParticipantNetworkInput = z.object({
  schema_version: z.literal(1),
  node_id: z.number().int().positive(),
  agent_sha256: z.string().regex(/^[a-f0-9]{64}$/),
  router_lan: z.object({
    desired: z.array(z.record(z.string(), z.unknown())),
    expand: z.boolean(),
    prune: z.boolean(),
  }).strict().nullable(),
  peer_dns: z.object({
    dns_server: z.ipv4(),
    public_key: z.string().regex(/^[A-Za-z0-9+/]{43}=$/),
    domains: z.array(z.string().min(1).max(253).regex(/^[A-Za-z0-9.-]+$/)).min(
      1,
    ).max(512),
  }).strict().nullable(),
}).strict();
export type ParticipantNetworkInput = z.infer<typeof ParticipantNetworkInput>;
const Refusal = z.enum([
  "agent.version_mismatch",
  "firewall.input_invalid",
  "firewall.status_failed",
  "firewall.status_unrecognized",
  "firewall.rule_collision",
  "firewall.apply_failed",
  "firewall.reconcile_failed",
  "firewall.verify_failed",
  "firewall.remove_failed",
  "firewall.remove_verify_failed",
  "node.firewall_convergence_failed",
  "router.lan_ingress_recovery_failed",
  "wireguard.peer_dns_busy",
  "wireguard.peer_recovery_pending",
  "wireguard.peer_dns_state_unsupported",
  "wireguard.peer_dns_candidate_invalid",
  "wireguard.peer_dns_apply_failed",
  "wireguard.peer_dns_uplink_missing",
]);
export const ParticipantNetworkOutcome = z.discriminatedUnion("status", [
  z.object({
    schema_version: z.literal(1),
    node_id: z.number().int().positive(),
    status: z.literal("succeeded"),
    projected: z.literal(true),
  }).strict(),
  z.object({
    schema_version: z.literal(1),
    node_id: z.number().int().positive(),
    status: z.literal("failed"),
    error_code: Refusal,
  }).strict(),
]);
export type ParticipantNetworkOutcome = z.infer<
  typeof ParticipantNetworkOutcome
>;
const uncertain = () =>
  new Error("The participant network outcome is uncertain");

/** Fixed parent-local actions. No child, new key, peer restart or Gateway effect. */
export async function convergeParticipantNetwork(
  raw: ParticipantNetworkInput,
  invoke: NetworkSystem = runNetworkSystem,
  signal?: AbortSignal,
): Promise<ParticipantNetworkOutcome> {
  const parsed = ParticipantNetworkInput.safeParse(raw);
  if (!parsed.success) throw new Error("Invalid participant network intent");
  const input = parsed.data;
  const commands: {
    name: string;
    input: Record<string, unknown>;
    firewall: boolean;
  }[] = [];
  if (input.router_lan) {
    commands.push({
      name: "firewall.router-lan",
      input: input.router_lan,
      firewall: true,
    });
  }
  if (input.peer_dns) {
    commands.push({
      name: "wireguard.repair-peer-dns",
      input: input.peer_dns,
      firewall: false,
    });
  }
  for (const command of commands) {
    signal?.throwIfAborted();
    let reply: unknown;
    try {
      reply = await networkCommand(command.name, command.input, invoke, signal);
    } catch {
      throw uncertain();
    }
    if (reply !== null && typeof reply === "object" && "error_code" in reply) {
      const code = Refusal.safeParse(reply.error_code);
      if (!code.success) throw uncertain();
      return {
        schema_version: 1,
        node_id: input.node_id,
        status: "failed",
        error_code: code.data,
      };
    }
    if (
      !(command.firewall
        ? z.object({ matches: z.literal(true) }).strict()
        : z.object({}).strict()).safeParse(reply).success
    ) throw uncertain();
  }
  return {
    schema_version: 1,
    node_id: input.node_id,
    status: "succeeded",
    projected: true,
  };
}
