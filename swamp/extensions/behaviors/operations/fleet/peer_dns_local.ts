import { z } from "npm:zod@4.4.3";
import { type NodeCall, runNodeAgent } from "../_lib/node-agent.ts";
import {
  Inspection as InspectionSchema,
  matchesPolicy,
  PeerDnsInput,
} from "./contract.ts";

export const PeerDnsLocalInput = PeerDnsInput.extend({
  node_id: z.number().int().positive(),
  agent_sha256: z.string().regex(/^[a-f0-9]{64}$/),
}).refine((input) => input.server === input.dns_server);
export const PeerDnsRefusal = z.object({
  error_code: z.enum([
    "agent.version_mismatch",
    "vpn.peer_dns_busy",
    "vpn.peer_dns_unreadable",
    "vpn.peer_recovery_pending",
    "vpn.peer_dns_state_unsupported",
    "vpn.peer_dns_candidate_invalid",
    "vpn.peer_dns_apply_failed",
    "vpn.peer_dns_recovery_failed",
    "vpn.peer_dns_uplink_missing",
    "vpn.peer_dns_repair_failed",
    "vpn.peer_dns_reconcile_failed",
  ]),
});
export const PeerDnsResult = z.union([
  InspectionSchema.strict(),
  PeerDnsRefusal.strict(),
]);
export type Inspection = z.infer<typeof InspectionSchema>;
export type ClosedPeerDnsRefusal = z.infer<typeof PeerDnsRefusal>["error_code"];
export type NodeCallFunction = (
  call: NodeCall,
  signal?: AbortSignal,
) => Promise<unknown>;
export type ReconcilePeerDnsResult = {
  result: Inspection | { error_code: ClosedPeerDnsRefusal };
  changed: boolean;
};

/** Keep only known terminal agent refusals, without diagnostic output. */
function nodeReply(raw: unknown): unknown {
  const refusal = z.object({ error_code: z.string() }).safeParse(raw);
  return refusal.success
    ? PeerDnsRefusal.parse({
      error_code: refusal.data.error_code.replace(/^wireguard\./, "vpn."),
    })
    : raw;
}

/** Inspect, repair only drift, and verify on the admitted Node's local worker. */
export async function reconcilePeerDns(
  raw: z.infer<typeof PeerDnsLocalInput>,
  {
    signal,
    beforeEffect,
    invoke = runNodeAgent,
  }: {
    signal?: AbortSignal;
    beforeEffect?: () => Promise<void>;
    invoke?: NodeCallFunction;
  } = {},
): Promise<ReconcilePeerDnsResult> {
  const input = PeerDnsLocalInput.parse(raw);
  const inspect = async (inspectionSignal?: AbortSignal) =>
    PeerDnsResult.parse(
      nodeReply(
        await invoke({
          name: "wireguard.inspect-peer-dns",
          input: { server: input.server },
          pin: input.agent_sha256,
          sudo: true,
          mutates: false,
          deadlineMs: 10000,
        }, inspectionSignal),
      ),
    );
  signal?.throwIfAborted();
  let result = await inspect(signal);
  if ("error_code" in result) return { result, changed: false };
  let changed = false;
  if (!matchesPolicy(input, result)) {
    await beforeEffect?.();
    signal?.throwIfAborted();
    const repaired = z.union([
      z.object({}).strict(),
      PeerDnsRefusal.strict(),
    ]).parse(
      nodeReply(
        await invoke({
          name: "wireguard.repair-peer-dns",
          input: {
            dns_server: input.dns_server,
            public_key: input.public_key,
            domains: input.domains,
          },
          pin: input.agent_sha256,
          sudo: true,
          mutates: true,
          deadlineMs: 120000,
        }, signal),
      ),
    );
    if ("error_code" in repaired) {
      return { result: PeerDnsRefusal.parse(repaired), changed: false };
    }
    changed = true;
    // A completed mutation must be verified and projected after a late abort.
    result = await inspect();
  } else {
    await beforeEffect?.();
    signal?.throwIfAborted();
  }
  if ("error_code" in result) return { result, changed };
  return {
    result: !result.uplink
      ? { error_code: "vpn.peer_dns_uplink_missing" }
      : !matchesPolicy(input, result)
      ? { error_code: "vpn.peer_dns_reconcile_failed" }
      : result,
    changed,
  };
}
