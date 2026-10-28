import { z } from "npm:zod@4.4.3";
import { GatewayClient, GatewayGap, gatewayHttp } from "./gateway.ts";
import {
  DecisionSchema,
  type Gateway,
  PolicyError,
  StateSchema,
  tick,
} from "./policy.ts";

interface Context {
  globalArgs: { gatewayUrl: string; caFile: string };
  signal?: AbortSignal;
  readResource(name: string): Promise<Record<string, unknown> | null>;
  writeResource(
    spec: string,
    name: string,
    value: Record<string, unknown>,
  ): Promise<{ name: string }>;
}
/** Injection is for official-kit tests. The registered behavior always uses the real Gateway adapter. */
export function automaticDeploy(
  gateway?: Gateway,
  clock = () => new Date().toISOString(),
) {
  return {
    type: "@nckrtl/automatic-deploy",
    version: "2026.10.28.1",
    globalArguments: z.object({
      gatewayUrl: z.string().url().default("https://gateway.orbit"),
      caFile: z.string().default(
        "/usr/local/share/ca-certificates/orbit-managed-root-ca.crt",
      ),
    }),
    resources: {
      state: {
        description: "Policy memory and admission intent; never deploy success",
        schema: StateSchema,
        lifetime: "infinite",
        garbageCollection: 10,
      },
      decision: {
        description:
          "Policy projection and undelivered alert/Activity intents, or Gateway gaps",
        schema: DecisionSchema,
        lifetime: "infinite",
        garbageCollection: 50,
      },
    },
    methods: {
      tick: {
        description:
          "One production policy tick. Stops on missing Gateway contracts; no schedule or live cutover.",
        arguments: z.object({ instance: z.number().int().positive() }),
        execute: async (input: { instance: number }, context: Context) => {
          const { instance } = z.object({
            instance: z.number().int().positive(),
          }).parse(input);
          const stored = await context.readResource(`state-${instance}`);
          const previous = StateSchema.parse(stored ?? {});
          const handles: { name: string }[] = [];
          let decision;
          try {
            decision = await tick(
              instance,
              previous,
              gateway ??
                new GatewayClient(
                  gatewayHttp(
                    context.globalArgs.gatewayUrl,
                    context.globalArgs.caFile,
                    context.signal,
                  ),
                ),
              async (state) => {
                if (
                  JSON.stringify(state) ===
                    JSON.stringify(
                      await context.readResource(`state-${instance}`),
                    )
                ) return;
                handles.push(
                  await context.writeResource(
                    "state",
                    `state-${instance}`,
                    state,
                  ),
                );
              },
              clock(),
            );
          } catch (error) {
            if (
              !(error instanceof GatewayGap) && !(error instanceof PolicyError)
            ) throw error;
            handles.push(
              await context.writeResource("decision", `decision-${instance}`, {
                ready: false,
                error_code: error instanceof GatewayGap
                  ? "automatic.gateway_gap"
                  : error.code,
                gateway_gaps: error instanceof GatewayGap ? error.gaps : [],
                projection: null,
                alerts: [],
                activity: null,
              }),
            );
            throw error;
          }
          if (!decision) {
            handles.push(
              await context.writeResource(
                "decision",
                `decision-${instance}`,
                {
                  ready: true,
                  error_code: null,
                  gateway_gaps: [],
                  projection: null,
                  alerts: [],
                  activity: null,
                },
              ),
            );
            return { dataHandles: handles };
          }
          handles.push(
            await context.writeResource("decision", `decision-${instance}`, {
              ready: decision.state.result !== "failed",
              error_code: decision.state.error_code,
              gateway_gaps: [],
              projection: decision.projection,
              alerts: decision.alerts,
              activity: decision.activity,
            }),
          );
          if (decision?.state.result === "failed") {
            throw new PolicyError(
              decision.state.error_code ?? "deployment.automatic_failed",
              decision.state.message ?? "The automatic deployment tick failed.",
            );
          }
          return { dataHandles: handles };
        },
      },
    },
  };
}
export const behavior = automaticDeploy();
