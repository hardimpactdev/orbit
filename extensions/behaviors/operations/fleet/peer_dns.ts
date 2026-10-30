import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import { Globals } from "../../operation-runner/mod.ts";
import {
  DirectArgs,
  directOperation,
  DirectOperationCancelled,
  GatewayOperation,
} from "../_lib/direct.ts";
import { type NodeCall, runNodeAgent } from "../_lib/node-agent.ts";
import { Inspection, matchesPolicy, PeerDnsInput } from "./contract.ts";

const Args = DirectArgs.extend({
  kind: z.literal("fleet.peer_dns"),
  node_id: z.number().int().positive(),
});
const Input = PeerDnsInput.extend({
  node_id: z.number().int().positive(),
  agent_sha256: z.string().regex(/^[a-f0-9]{64}$/),
}).refine((input) => input.server === input.dns_server);
const Refusal = z.object({
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
const Result = z.union([Inspection.strict(), Refusal.strict()]);
type Context = { globalArgs: z.infer<typeof Globals>; signal?: AbortSignal };

/** Keep only known terminal agent refusals, without diagnostic output. */
function nodeReply(raw: unknown): unknown {
  const refusal = z.object({ error_code: z.string() }).safeParse(raw);
  return refusal.success
    ? Refusal.parse({
      error_code: refusal.data.error_code.replace(/^wireguard\./, "vpn."),
    })
    : raw;
}

/** Inspect, repair only drift, and verify on this Node's configured user worker. */
export function peerDns(
  makeHttp = (ctx: Context) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
  invoke: (call: NodeCall, signal?: AbortSignal) => Promise<unknown> =
    runNodeAgent,
) {
  return {
    type: "@nckrtl/fleet-peer-dns",
    version: "2026.10.30.1",
    globalArguments: Globals,
    methods: {
      reconcile: {
        description: "Reconcile the admitted peer DNS policy locally",
        arguments: Args,
        execute: (args: z.infer<typeof Args>, ctx: Context) => {
          const gateway = new GatewayOperation(makeHttp(ctx), args);
          return directOperation(
            args,
            gateway,
            async (raw) => {
              const input = Input.parse(raw);
              if (input.node_id !== args.node_id) {
                throw new Error("Peer DNS target differs from admitted intent");
              }
              const inspect = async (signal?: AbortSignal) =>
                Result.parse(
                  nodeReply(
                    await invoke({
                      name: "wireguard.inspect-peer-dns",
                      input: { server: input.server },
                      pin: input.agent_sha256,
                      sudo: true,
                      mutates: false,
                      deadlineMs: 10000,
                    }, signal),
                  ),
                );
              const cancelBeforeEffect = async () => {
                if (ctx.signal?.aborted) {
                  throw new DirectOperationCancelled({ effectRunning: false });
                }
                const current = z.object({ cancel_requested: z.boolean() })
                  .parse(await gateway.read());
                if (current.cancel_requested || ctx.signal?.aborted) {
                  throw new DirectOperationCancelled({ effectRunning: false });
                }
              };
              let result: z.infer<typeof Result>;
              try {
                result = await inspect(ctx.signal);
              } catch (error) {
                if (ctx.signal?.aborted) {
                  throw new DirectOperationCancelled({ effectRunning: false });
                }
                throw error;
              }
              if ("error_code" in result) return result;
              if (!matchesPolicy(input, result)) {
                await cancelBeforeEffect();
                const repaired = z.union([
                  z.object({}).strict(),
                  Refusal.strict(),
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
                    }, ctx.signal),
                  ),
                );
                if ("error_code" in repaired) return repaired;
                // A completed mutation must be verified and projected after a late abort.
                result = await inspect();
              } else {
                await cancelBeforeEffect();
              }
              if ("error_code" in result) return result;
              return !result.uplink
                ? { error_code: "vpn.peer_dns_uplink_missing" }
                : !matchesPolicy(input, result)
                ? { error_code: "vpn.peer_dns_reconcile_failed" }
                : result;
            },
            Result.parse,
            ctx.signal,
          );
        },
      },
    },
  };
}
export const behavior = {
  ...peerDns(),
  // Keep the literal visible to native source indexing.
  type: "@nckrtl/fleet-peer-dns",
};
