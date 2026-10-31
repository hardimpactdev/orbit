import { z } from "npm:zod@4.4.3";
import { gatewayHttp, type Http } from "../../automatic-deploy/gateway.ts";

import { DirectArgs, GatewayOperation, Globals } from "../_lib/direct.ts";
import { runNetworkSystem } from "./network_system.ts";
import {
  type BootstrapEnvironment,
  type BootstrapSsh,
  bootstrapSsh,
} from "../node-bootstrap/bootstrap_ssh.ts";
import {
  convergeWireGuardHub,
  HubPrivateConfiguration,
  HubPublicationInput,
  HubPublicationOutcome,
} from "./wireguard_local.ts";

const Args = DirectArgs.extend({ node_id: z.number().int().positive() });
const PublishArgs = Args.extend({
  snapshot: HubPublicationInput,
  restoring: z.boolean().optional(),
});
const Admission = z.object({
  snapshot: HubPublicationInput,
  started_at: z.string(),
  ready: z.boolean(),
}).strict();
const ProjectArgs = Args.extend({
  admission: Admission,
  outcome: HubPublicationOutcome,
});
type Context = {
  createCelEnvironment?: BootstrapEnvironment["createCelEnvironment"];
  globalArgs: z.infer<typeof Globals>;
  signal?: AbortSignal;
  writeResource: (
    spec: string,
    name: string,
    value: unknown,
  ) => Promise<unknown>;
};

/** All parents share wireguard-hub-N. Its lock encloses fresh material and apply. */
export function nodeWireGuard(
  action = convergeWireGuardHub,
  makeHttp: (ctx: Context) => Http = (ctx) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
  invoke = runNetworkSystem,
  ssh: BootstrapSsh = bootstrapSsh,
) {
  return {
    type: "@nckrtl/node-wireguard-direct",
    version: "2026.10.31.4",
    globalArguments: Globals,
    resources: {
      admission: {
        description: "Public hub participant admission",
        schema: Admission,
        lifetime: "1d",
        garbageCollection: 10,
      },
      outcome: {
        description: "Bounded hub publication outcome",
        schema: HubPublicationOutcome,
        lifetime: "1d",
        garbageCollection: 10,
      },
    },
    methods: {
      admit: {
        description: "Read the immutable hub participant intent",
        arguments: Args,
        execute: async (raw: z.infer<typeof Args>, ctx: Context) => {
          const args = Args.parse(raw);
          const gateway = new GatewayOperation(makeHttp(ctx), args);
          const current = z.object({
            id: z.literal(args.operation_id),
            node_id: z.literal(args.node_id),
            kind: z.literal(args.kind),
            status: z.string(),
            cancel_requested: z.boolean(),
            input: HubPublicationInput,
          }).parse(await gateway.read(ctx.signal));
          if (current.input.executor_node_id !== args.node_id) {
            throw new Error("The hub root is not on its admitted Node");
          }
          const terminal = ["succeeded", "failed", "cancelled"].includes(
            current.status,
          );
          const admission = {
            snapshot: current.input,
            started_at: new Date().toISOString(),
            ready: !terminal && !current.cancel_requested,
          };
          if (!terminal) {
            await gateway.project(
              current.cancel_requested
                ? {
                  status: "cancelled",
                  started_at: admission.started_at,
                  finished_at: new Date().toISOString(),
                  result: {},
                }
                : {
                  status: "running",
                  started_at: admission.started_at,
                  result: {},
                },
            );
          }
          await ctx.writeResource("admission", "admission", admission);
          return admission;
        },
      },
      publish: {
        description:
          "Publish the canonical hub on its exact admitted Node worker",
        arguments: PublishArgs,
        execute: async (raw: z.infer<typeof PublishArgs>, ctx: Context) => {
          const args = PublishArgs.parse(raw);
          const gateway = new GatewayOperation(makeHttp(ctx), args);
          const restoring = args.restoring === true;
          const signal = restoring ? undefined : ctx.signal;
          const guard = async () => {
            signal?.throwIfAborted();
            const current = z.object({
              id: z.literal(args.operation_id),
              node_id: z.literal(args.node_id),
              kind: z.literal(args.kind),
              status: z.string(),
              cancel_requested: z.boolean(),
              result: z.object({ restoring: z.boolean().optional() }).nullable()
                .optional(),
              input: z.unknown().optional(),
            }).parse(await gateway.read(signal));
            if (restoring) {
              const input = z.object({ restore_hub: HubPublicationInput })
                .parse(current.input);
              if (
                args.kind !== "node.remove" ||
                current.result?.restoring !== true ||
                JSON.stringify(input.restore_hub) !==
                  JSON.stringify(args.snapshot)
              ) throw new Error("operation.execution_forbidden");
            }
            if (current.cancel_requested && !restoring) {
              throw new Error("operation.cancelled");
            }
            if (["succeeded", "failed", "cancelled"].includes(current.status)) {
              throw new Error("operation.finished");
            }
          };
          // The behavior instance lock encloses both fresh reads and the effect.
          await guard();
          let outcome: z.infer<typeof HubPublicationOutcome> | undefined;
          await gateway.withPrivateMaterial(async (material) => {
            const parsed = HubPrivateConfiguration.safeParse(
              material.configuration,
            );
            if (!parsed.success) throw new Error("The hub material is invalid");
            if (args.snapshot.executor_node_id !== args.snapshot.hub_id) {
              const connection = parsed.data.connection;
              if (
                !connection || !ctx.createCelEnvironment ||
                connection.target.node_id !== args.snapshot.hub_id
              ) {
                throw new Error("The hub SSH participant is invalid");
              }
            }
            await guard();
            outcome = await action(
              args.snapshot,
              parsed.data,
              invoke,
              signal,
              ctx.createCelEnvironment
                ? {
                  environment: {
                    createCelEnvironment: ctx.createCelEnvironment,
                  },
                  ssh,
                }
                : undefined,
            );
          });
          if (!outcome) throw new Error("The hub outcome is uncertain");
          await ctx.writeResource("outcome", "outcome", outcome);
          return outcome;
        },
      },
      project: {
        description: "Record the native hub outcome",
        arguments: ProjectArgs,
        execute: async (raw: z.infer<typeof ProjectArgs>, ctx: Context) => {
          const args = ProjectArgs.parse(raw);
          const outcome = args.outcome;
          await new GatewayOperation(makeHttp(ctx), args).project({
            status: outcome.status,
            started_at: args.admission.started_at,
            finished_at: new Date().toISOString(),
            result: outcome,
            error_code: outcome.status === "failed" ? outcome.error_code : null,
          });
          return outcome;
        },
      },
    },
  };
}
export const behavior = nodeWireGuard();
