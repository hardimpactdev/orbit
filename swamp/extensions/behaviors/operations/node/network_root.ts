import { z } from "npm:zod@4.4.3";
import { gatewayHttp, type Http } from "../../automatic-deploy/gateway.ts";

import { DirectArgs, GatewayOperation, Globals } from "../_lib/direct.ts";
import {
  HubPublicationInput,
  HubPublicationOutcome,
} from "./wireguard_local.ts";
import {
  localPeerNetwork,
  NodeNetworkSnapshot,
  PeerNetworkOutcome,
  PreparePeerInput,
  PublishPeerInput,
} from "./network_parent.ts";
import { networkCommand, runNetworkSystem } from "./network_system.ts";
const Args = DirectArgs.extend({
  kind: z.literal("node.network_converge"),
  node_id: z.number().int().positive(),
});
const Network = NodeNetworkSnapshot;
const Admission = z.object({
  network: Network,
  hub: HubPublicationInput,
  started_at: z.string(),
  ready: z.boolean(),
}).strict();
const StageArgs = Args.extend({ admission: Admission });
const PreparedArgs = StageArgs.extend({ prepared: PeerNetworkOutcome });
const HubArgs = PreparedArgs.extend({ hub_outcome: HubPublicationOutcome });
const CommitArgs = PreparedArgs.extend({ published: PeerNetworkOutcome });
type Context = {
  globalArgs: z.infer<typeof Globals>;
  signal?: AbortSignal;
  writeResource: (
    spec: string,
    name: string,
    value: unknown,
  ) => Promise<unknown>;
};
export function nodeNetworkRoot(
  actions = localPeerNetwork,
  makeHttp: (ctx: Context) => Http = (ctx) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
  invoke = runNetworkSystem,
) {
  const gateway = (raw: z.infer<typeof Args>, ctx: Context) =>
    new GatewayOperation(makeHttp(ctx), raw);
  const fail = async (
    raw: z.infer<typeof StageArgs>,
    ctx: Context,
    error_code: string,
  ) => {
    await gateway(raw, ctx).project({
      status: "running",
      started_at: raw.admission.started_at,
      result: { network_proven: false, error_code },
      error_code: null,
    });
  };
  return {
    type: "@nckrtl/node-network-direct",
    version: "2026.10.31.1",
    globalArguments: Globals,
    resources: {
      admission: {
        description: "Public peer and hub admission",
        schema: Admission,
        lifetime: "1d",
        garbageCollection: 10,
      },
      decision: {
        description: "Bounded hub stage decision",
        schema: z.object({ ok: z.boolean() }).strict(),
        lifetime: "1d",
        garbageCollection: 10,
      },
      recovery: {
        description: "Bounded peer recovery decision",
        schema: z.object({ restore_hub: z.boolean() }).strict(),
        lifetime: "1d",
        garbageCollection: 10,
      },
      peer: {
        description: "Bounded peer stage",
        schema: PeerNetworkOutcome,
        lifetime: "1d",
        garbageCollection: 10,
      },
    },
    methods: {
      admit: {
        description: "Read the peer root intent",
        arguments: Args,
        execute: async (raw: z.infer<typeof Args>, ctx: Context) => {
          const args = Args.parse(raw);
          const gw = gateway(args, ctx);
          const current = z.object({
            id: z.literal(args.operation_id),
            kind: z.literal(args.kind),
            node_id: z.literal(args.node_id),
            status: z.string(),
            cancel_requested: z.boolean(),
            input: z.object({ network: Network, hub: HubPublicationInput }),
          }).parse(await gw.read(ctx.signal));
          if (
            current.input.network.node_id !== args.node_id ||
            current.input.hub.context_node_id !== args.node_id
          ) throw new Error("Invalid peer root binding");
          const admission = {
            ...current.input,
            started_at: new Date().toISOString(),
            ready:
              !["succeeded", "failed", "cancelled"].includes(current.status) &&
              !current.cancel_requested,
          };
          if (
            current.cancel_requested &&
            !current.status.match(/^(succeeded|failed|cancelled)$/)
          ) {
            await gw.project({
              status: "cancelled",
              started_at: admission.started_at,
              finished_at: new Date().toISOString(),
              result: {},
            });
          } else if (admission.ready) {
            await gw.project({
              status: "running",
              started_at: admission.started_at,
              result: {},
            });
          }
          await ctx.writeResource("admission", "admission", admission);
          return admission;
        },
      },
      prepare: {
        description: "Prepare the exact local peer key",
        arguments: StageArgs,
        execute: async (raw: z.infer<typeof StageArgs>, ctx: Context) => {
          const args = StageArgs.parse(raw);
          if (!args.admission.ready) {
            throw new Error("The peer root is not active");
          }
          const n = args.admission.network;
          const result = await actions.prepare(
            PreparePeerInput.parse({
              schema_version: 1,
              node_id: n.node_id,
              agent_sha256: n.agent_sha256,
              existing_public_key: n.existing_key,
            }),
            ctx.signal,
          );
          if (result.status === "failed") {
            await fail(
              args,
              ctx,
              result.error_code,
            );
          }
          await ctx.writeResource("peer", "prepare", result);
          return result;
        },
      },
      record: {
        description: "Record the public peer key before fresh hub publication",
        arguments: PreparedArgs,
        execute: async (raw: z.infer<typeof PreparedArgs>, ctx: Context) => {
          const args = PreparedArgs.parse(raw);
          if (!args.admission.ready) {
            throw new Error("The peer root is not active");
          }
          if (
            args.prepared.status !== "succeeded" || !args.prepared.public_key
          ) throw new Error("The peer key preparation refused");
          await gateway(args, ctx).project({
            status: "running",
            started_at: args.admission.started_at,
            result: { public_key: args.prepared.public_key },
          });
          return { recorded: true };
        },
      },
      hubResult: {
        description: "Record a settled hub refusal before peer publication",
        arguments: HubArgs,
        execute: async (raw: z.infer<typeof HubArgs>, ctx: Context) => {
          const args = HubArgs.parse(raw);
          if (!args.admission.ready) {
            throw new Error("The peer root is not active");
          }
          if (args.hub_outcome.status === "failed") {
            await fail(args, ctx, args.hub_outcome.error_code);
          }
          const decision = { ok: args.hub_outcome.status === "succeeded" };
          await ctx.writeResource("decision", "hub-decision", decision);
          return decision;
        },
      },
      publish: {
        description: "Publish only the prepared exact peer",
        arguments: PreparedArgs,
        execute: async (raw: z.infer<typeof PreparedArgs>, ctx: Context) => {
          const args = PreparedArgs.parse(raw);
          if (!args.admission.ready) {
            throw new Error("The peer root is not active");
          }
          if (
            args.prepared.status !== "succeeded" || !args.prepared.public_key
          ) throw new Error("The peer key is unavailable");
          const n = args.admission.network;
          const result = await actions.publish(
            PublishPeerInput.parse({
              schema_version: 1,
              node_id: n.node_id,
              agent_sha256: n.agent_sha256,
              existing_public_key: n.existing_key,
              peer: { ...n.peer, peer_public_key: args.prepared.public_key },
            }),
            ctx.signal,
          );
          if (result.status === "failed") {
            await fail(
              args,
              ctx,
              result.error_code,
            );
          }
          await ctx.writeResource("peer", "publish", result);
          return result;
        },
      },
      recover: {
        description:
          "Restore the exact pending peer transaction before failed-root settlement",
        arguments: StageArgs,
        execute: async (raw: z.infer<typeof StageArgs>, ctx: Context) => {
          const args = StageArgs.parse(raw);
          const save = async (restore_hub: boolean) => {
            const decision = { restore_hub };
            await ctx.writeResource("recovery", "recovery", decision);
            return decision;
          };
          if (!args.admission.ready) return await save(false);
          const current = z.object({ status: z.string() }).parse(
            await gateway(args, ctx).read(),
          );
          if (["succeeded", "failed", "cancelled"].includes(current.status)) {
            return await save(false);
          }
          const n = args.admission.network;
          const recovery = await actions.recover({
            schema_version: 1,
            node_id: n.node_id,
            agent_sha256: n.agent_sha256,
            peer: n.peer,
          });
          if (recovery.restore_public_key) {
            await gateway(args, ctx).project({
              status: "running",
              started_at: args.admission.started_at,
              result: { network_proven: false, restore_peer_key: true },
              error_code: null,
            });
          }
          return await save(recovery.restore_public_key);
        },
      },
      commit: {
        description: "Apply admitted firewall and commit the peer transaction",
        arguments: CommitArgs,
        execute: async (raw: z.infer<typeof CommitArgs>, ctx: Context) => {
          const args = CommitArgs.parse(raw);
          if (!args.admission.ready) {
            throw new Error("The peer root is not active");
          }
          if (
            args.published.status !== "succeeded" ||
            args.prepared.status !== "succeeded" || !args.prepared.public_key
          ) throw new Error("Peer publication refused");
          const n = args.admission.network;
          const firewall = await networkCommand(
            "firewall.rules",
            n.trust,
            invoke,
            ctx.signal,
          );
          if (
            firewall !== null && typeof firewall === "object" &&
            "error_code" in firewall
          ) throw new Error("The peer firewall result is uncertain");
          if (
            !z.object({ matches: z.literal(true) }).safeParse(firewall).success
          ) throw new Error("The peer firewall result is uncertain");
          const result = await actions.commit({
            schema_version: 1,
            node_id: n.node_id,
            agent_sha256: n.agent_sha256,
            public_key: args.prepared.public_key,
          }, ctx.signal);
          if (result.status === "failed") {
            await fail(args, ctx, result.error_code);
          } else {
            await gateway(args, ctx).project({
              status: "succeeded",
              started_at: args.admission.started_at,
              finished_at: new Date().toISOString(),
              result: {
                network_proven: true,
                public_key: args.prepared.public_key,
                retained: n.peer.retain === true,
              },
              error_code: null,
            });
          }
          await ctx.writeResource("peer", "commit", result);
          return result;
        },
      },
    },
  };
}
export const behavior = nodeNetworkRoot();
