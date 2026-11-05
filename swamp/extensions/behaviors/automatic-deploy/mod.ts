import { z } from "npm:zod@4.4.3";
import {
    type DevelopmentContext,
    DevelopmentState,
    type Emit,
} from "./development.ts";
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
    development(
        context: DevelopmentContext,
        now: string,
        filter: number[],
        emit: Emit,
    ): Promise<{ name: string }[]>;
    discover(filter: number[]): Promise<number[]>;
    guard(instance: number, observation: Observation): Promise<void>;
    publish(
        instance: number,
        projection: NonNullable<Observation["publication"]>,
        observation: Observation,
    ): Promise<void>;
}

/** One fleet behavior lock owns all state and effect ordering. */
export function automaticDeploy(
    gateway?: LiveGateway,
    clock = () => new Date().toISOString(),
) {
    return {
        type: "@nckrtl/automatic-deploy",
        version: "2026.11.02.1",
        globalArguments: z.object({
            gatewayUrl: z.string().url().default("https://gateway.orbit"),
            caFile: z.string().default(
                "/usr/local/share/ca-certificates/orbit-managed-root-ca.crt",
            ),
        }),
        resources: {
            development: {
                description:
                    "Development admission intent and public policy state",
                schema: DevelopmentState,
                lifetime: "infinite",
                garbageCollection: 10,
            },
            state: {
                description:
                    "Policy memory, admission intent and raised events; never deploy success",
                schema: StateSchema,
                lifetime: "infinite",
                garbageCollection: 10,
            },
            fire: {
                description:
                    "This fire's new policy events and failures, for the Gateway's terminal projection",
                schema: z.object({
                    fire_json: z.string(),
                    failure_count: z.number().int().nonnegative(),
                }),
                lifetime: "infinite",
                garbageCollection: 10,
            },
        },
        methods: {
            tick: {
                description:
                    "Discover production and default development Instances and run native policy after Gateway ownership admission.",
                arguments: TickArguments,
                execute: async (
                    input: { instances?: number[] },
                    context: Context,
                ) => {
                    const { instances } = TickArguments.parse(input);
                    const client = gateway ??
                        new GatewayClient(
                            gatewayHttp(
                                context.globalArgs.gatewayUrl,
                                context.globalArgs.caFile,
                                context.signal,
                            ),
                        );
                    const handles = new Map<string, { name: string }>();
                    const failures: { instance: number; error: unknown }[] = [];
                    const events: {
                        instance: number;
                        key: string;
                        body: PolicyEvent;
                    }[] = [];
                    const emit: Emit = (instance, key, body) =>
                        events.push({ instance, key, body });
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
                                        JSON.stringify(
                                            await context.readResource(name),
                                        )
                                ) return;
                                handles.set(
                                    name,
                                    await context.writeResource(
                                        "state",
                                        name,
                                        state,
                                    ),
                                );
                            };
                            const raised = new Set(
                                previous.events.map((e) => e.key),
                            );
                            const decision = await tick(
                                instance,
                                previous,
                                client,
                                save,
                                clock(),
                                async (observation) => {
                                    observed = observation;
                                    // Guard before saving state or admitting a deploy.
                                    await client.guard(instance, observation);
                                },
                            );
                            if (!decision || !observed) continue;
                            for (const event of decision.state.events) {
                                if (!raised.has(event.key)) {
                                    emit(instance, event.key, event.body);
                                }
                            }
                            try {
                                await client.publish(
                                    instance,
                                    decision.projection,
                                    observed,
                                );
                            } catch (error) {
                                // Admission may already have added history. Keep its durable receipt;
                                // the next tick reads the newer history instead of publishing stale state.
                                if (
                                    !(error instanceof PolicyError) ||
                                    error.code !==
                                        "instance.auto_deploy_observation_changed"
                                ) throw error;
                            }
                            if (decision.state.result === "failed") {
                                throw new PolicyError(
                                    decision.state.error_code ??
                                        "deployment.automatic_failed",
                                    decision.state.message ??
                                        "Automatic deployment failed.",
                                );
                            }
                        } catch (error) {
                            if (isPolicyRefusal(error)) continue;
                            failures.push({ instance, error });
                        }
                    }
                    for (
                        const handle of await client.development(
                            context,
                            clock(),
                            instances,
                            emit,
                        )
                    ) {
                        handles.set(handle.name, handle);
                    }
                    // The Gateway records events and failures from the fire's terminal result; no step calls it for them.
                    // A failed Instance no longer stops the tick, so the other events still arrive; the assert step fails the run.
                    handles.set(
                        "fire",
                        await context.writeResource("fire", "fire", {
                            fire_json: JSON.stringify({
                                events,
                                failures: failures.map((
                                    { instance, error },
                                ) => ({
                                    instance,
                                    error_code: error instanceof PolicyError
                                        ? error.code
                                        : "automatic.tick_failed",
                                    message: (error instanceof Error
                                        ? error.message
                                        : String(error)).slice(0, 4096),
                                })),
                            }),
                            failure_count: failures.length,
                        }),
                    );
                    return { dataHandles: [...handles.values()] };
                },
            },
        },
    };
}
export const behavior = automaticDeploy();
