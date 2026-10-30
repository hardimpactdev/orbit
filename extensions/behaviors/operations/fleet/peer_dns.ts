import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import { Globals } from "../../operation-runner/mod.ts";
import {
  DirectArgs,
  directOperation,
  DirectOperationCancelled,
  GatewayOperation,
} from "../_lib/direct.ts";
import { runNodeAgent } from "../_lib/node-agent.ts";
import {
  type NodeCallFunction,
  PeerDnsLocalInput,
  PeerDnsResult,
  reconcilePeerDns,
} from "./peer_dns_local.ts";

const Args = DirectArgs.extend({
  kind: z.literal("fleet.peer_dns"),
  node_id: z.number().int().positive(),
});
type Context = { globalArgs: z.infer<typeof Globals>; signal?: AbortSignal };

/** Inspect, repair only drift, and verify on this Node's configured user worker. */
export function peerDns(
  makeHttp = (ctx: Context) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
  invoke: NodeCallFunction = runNodeAgent,
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
              const input = PeerDnsLocalInput.parse(raw);
              if (input.node_id !== args.node_id) {
                throw new Error("Peer DNS target differs from admitted intent");
              }
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
              let effectStarted = false;
              try {
                const { result } = await reconcilePeerDns(input, {
                  signal: ctx.signal,
                  beforeEffect: cancelBeforeEffect,
                  invoke: (call, signal) => {
                    if (call.mutates) effectStarted = true;
                    return invoke(call, signal);
                  },
                });
                return result;
              } catch (error) {
                if (!effectStarted && ctx.signal?.aborted) {
                  throw new DirectOperationCancelled({ effectRunning: false });
                }
                throw error;
              }
            },
            PeerDnsResult.parse,
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
