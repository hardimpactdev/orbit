import { z } from "npm:zod@4.4.3";
import { gatewayHttp, type Http } from "../../automatic-deploy/gateway.ts";
import { Globals } from "../../operation-runner/mod.ts";
import {
  DirectArgs,
  directOperation,
  GatewayOperation,
} from "../_lib/direct.ts";
import {
  AgentRekeyInput,
  AgentRekeyOutcome,
  rekeyAgent,
} from "./rekey_local.ts";

const Args = DirectArgs.extend({
  kind: z.literal("node.agent_rekey"),
  node_id: z.number().int().positive(),
});
const Observation = z.object({
  operation_id: z.number().int().positive(),
  run_id: z.string().uuid(),
  cancel_requested: z.boolean(),
}).strict();
const ExecuteArgs = Args.extend({ observation: Observation });
type Context = {
  globalArgs: z.infer<typeof Globals>;
  signal?: AbortSignal;
  writeResource: (
    spec: string,
    name: string,
    value: unknown,
  ) => Promise<unknown>;
};

/** One stable per-Node native method owns configure, private storage and projection. */
export function nodeAgentRekey(
  action = rekeyAgent,
  makeHttp: (ctx: Context) => Http = (ctx) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
) {
  return {
    type: "@nckrtl/node-agent-rekey",
    version: "2026.10.31.3",
    globalArguments: Globals,
    resources: {
      observation: {
        description:
          "Cancellation checkpoint before this native recovery effect",
        schema: Observation,
        lifetime: "1d",
        garbageCollection: 10,
      },
    },
    methods: {
      observe: {
        description:
          "Observe cancellation before admitting the native recovery effect",
        arguments: Args,
        execute: async (raw: z.infer<typeof Args>, ctx: Context) => {
          const args = Args.parse(raw);
          const current = z.object({
            id: z.literal(args.operation_id),
            node_id: z.literal(args.node_id),
            kind: z.literal(args.kind),
            cancel_requested: z.boolean(),
          }).parse(
            await new GatewayOperation(makeHttp(ctx), args).read(ctx.signal),
          );
          const observation = {
            operation_id: args.operation_id,
            run_id: args.run_id,
            cancel_requested: current.cancel_requested,
          };
          await ctx.writeResource("observation", "observation", observation);
          return observation;
        },
      },
      execute: {
        description:
          "Recover only the admitted Node agent and store its private hash",
        arguments: ExecuteArgs,
        execute: async (raw: z.infer<typeof ExecuteArgs>, ctx: Context) => {
          const args = ExecuteArgs.parse(raw);
          if (
            args.observation.operation_id !== args.operation_id ||
            args.observation.run_id !== args.run_id
          ) {
            throw new Error(
              "Recovery observation belongs to another native run",
            );
          }
          const gateway = new GatewayOperation(makeHttp(ctx), args);
          return await directOperation(
            args,
            {
              read: async () => {
                const current = z.record(z.string(), z.unknown()).parse(
                  await gateway.read(),
                );
                // Native resume reuses the pre-effect observation. It must reconcile
                // an uncertain rotation before a later cancellation can end the intent.
                return {
                  ...current,
                  cancel_requested: args.observation.cancel_requested,
                };
              },
              project: (body) => gateway.project(body),
            },
            async (value) => {
              const input = AgentRekeyInput.safeParse(value);
              if (!input.success || input.data.node_id !== args.node_id) {
                return {
                  rekeyed: false,
                  error_code: "node.agent_rekey_unavailable",
                };
              }
              return await action(
                input.data,
                (outputs) => gateway.storeSecretOutput(outputs),
                ctx.signal,
              );
            },
            AgentRekeyOutcome.parse,
          );
        },
      },
    },
  };
}

export const behavior = nodeAgentRekey();
