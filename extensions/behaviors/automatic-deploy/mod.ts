import { z } from "npm:zod@4.4.3";
import { GatewayClient, gatewayHttp } from "./gateway.ts";
import {
  type Gateway,
  isPolicyRefusal,
  type Observation,
  PolicyError,
  type PolicyEvent,
  type State,
  StateSchema,
  tick,
} from "./policy.ts";

const TickArguments = z.object({
  instances: z.array(z.number().int().positive()).default([]),
});

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
export interface LiveGateway extends Gateway {
  discover(filter: number[]): Promise<number[]>;
  guard(instance: number, observation: Observation): Promise<void>;
  publish(
    instance: number,
    projection: NonNullable<Observation["publication"]>,
    observation: Observation,
  ): Promise<void>;
  event(
    instance: number,
    body: PolicyEvent,
    key: string,
  ): ReturnType<Gateway["operation"]>;
}

/** One fleet behavior lock owns all state and effect ordering. */
export function automaticDeploy(
  gateway?: LiveGateway,
  clock = () => new Date().toISOString(),
) {
  return {
    type: "@nckrtl/automatic-deploy",
    version: "2026.10.28.5",
    globalArguments: z.object({
      gatewayUrl: z.string().url().default("https://gateway.orbit"),
      caFile: z.string().default(
        "/usr/local/share/ca-certificates/orbit-managed-root-ca.crt",
      ),
    }),
    resources: {
      state: {
        description:
          "Policy memory, admission intent and policy-event receipts; never deploy success",
        schema: StateSchema,
        lifetime: "infinite",
        garbageCollection: 10,
      },
    },
    methods: {
      tick: {
        description:
          "Discover enabled production Instances and run one live tick per Instance, after Gateway ownership admission.",
        arguments: TickArguments,
        execute: async (input: { instances?: number[] }, context: Context) => {
          const { instances } = TickArguments.parse(input);
          const client = gateway ??
            new GatewayClient(
              gatewayHttp(
                context.globalArgs.gatewayUrl,
                context.globalArgs.caFile,
                context.signal,
              ),
            );
          const handles: { name: string }[] = [];
          const failures: { instance: number; error: unknown }[] = [];
          for (const instance of await client.discover(instances)) {
            try {
              const name = `state-${instance}`;
              const previous = StateSchema.parse(
                await context.readResource(name) ?? {},
              );
              let observed: Observation | undefined;
              const save = async (state: State) => {
                if (
                  JSON.stringify(state) ===
                    JSON.stringify(await context.readResource(name))
                ) return;
                handles.push(await context.writeResource("state", name, state));
              };
              const deliver = async (state: State) => {
                for (const event of state.events) {
                  if (
                    event.receipt &&
                    ["succeeded", "failed", "cancelled"].includes(
                      String(event.receipt.status),
                    )
                  ) continue;
                  const operation = event.operation_id
                    ? await client.operation(event.operation_id)
                    : await client.event(instance, event.body, event.key);
                  event.operation_id = operation.id;
                  event.receipt = { ...operation };
                  await save(state);
                }
              };
              const decision = await tick(
                instance,
                previous,
                client,
                save,
                clock(),
                async (observation, state) => {
                  observed = observation;
                  // Guard before saving state, delivering events, or admitting a deploy.
                  await client.guard(instance, observation);
                  await deliver(state);
                },
              );
              if (!decision || !observed) continue;
              await deliver(decision.state);
              await client.publish(instance, decision.projection, observed);
              if (decision.state.result === "failed") {
                throw new PolicyError(
                  decision.state.error_code ?? "deployment.automatic_failed",
                  decision.state.message ?? "Automatic deployment failed.",
                );
              }
            } catch (error) {
              if (isPolicyRefusal(error)) continue;
              failures.push({ instance, error });
            }
          }
          if (failures.length) {
            throw new AggregateError(
              failures.map(({ error }) => error),
              failures.map(({ instance, error }) =>
                `Instance ${instance}: ${
                  error instanceof PolicyError
                    ? `${error.code}: ${error.message}`
                    : error instanceof Error
                    ? error.message
                    : String(error)
                }`
              ).join("; "),
            );
          }
          return { dataHandles: handles };
        },
      },
    },
  };
}
export const behavior = automaticDeploy();
