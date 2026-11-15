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
    definition: { name: string };
    readResource(name: string): Promise<Record<string, unknown> | null>;
    writeResource(
        spec: string,
        name: string,
        value: Record<string, unknown>,
    ): Promise<{ name: string }>;
    queryData(predicate: string): Promise<unknown[]>;
}

/**
 * Swamp's data retention (`garbageCollection.data` in `.swamp.yaml`) deletes every record whose latest version is
 * older than its cutoff, and it exempts no Orbit type. The tick writes policy memory only when it changes, skips an
 * Instance with auto deploy off, and does not run at all while its schedule is paused, so a pause or block would be
 * lost. The `refresh` method, on its own schedule, writes again unchanged every record whose latest version is older
 * than this.
 */
export const POLICY_MEMORY_REFRESH_MS = 24 * 60 * 60 * 1000;
const POLICY_MEMORY_NAME = /^(state|development)-[1-9][0-9]*$/;
const PolicyMemoryRow = z.object({
    name: z.string(),
    specName: z.enum(["state", "development"]),
    createdAt: z.string(),
    attributes: z.record(z.string(), z.unknown()),
});

/** Writes unchanged every policy memory record of this behavior older than the refresh age. */
export async function refreshPolicyMemory(
    context: Pick<
        Context,
        "definition" | "queryData" | "readResource" | "writeResource"
    >,
    now: string,
): Promise<{ name: string }[]> {
    const rows = await context.queryData(
        `modelType == "@nckrtl/automatic-deploy" && modelName == ${
            JSON.stringify(context.definition.name)
        } && (specName == "state" || specName == "development")`,
    );
    const handles: { name: string }[] = [];
    for (const raw of rows) {
        const row = PolicyMemoryRow.parse(raw);
        // The tick names policy memory only this way; any other record is not its memory.
        if (!POLICY_MEMORY_NAME.test(row.name)) continue;
        const age = Date.parse(now) - Date.parse(row.createdAt);
        if (Number.isNaN(age)) {
            throw new Error(
                `Policy memory ${row.name} has no readable creation time: ${row.createdAt}`,
            );
        }
        if (age < POLICY_MEMORY_REFRESH_MS) continue;
        // The stored record, not the catalog row, is what is written again: a record that is gone or has changed
        // since the query is left alone, so a refresh never creates, revives or rolls back a record.
        const stored = await context.readResource(row.name);
        if (
            stored === null ||
            JSON.stringify(stored) !== JSON.stringify(row.attributes)
        ) continue;
        handles.push(
            await context.writeResource(row.specName, row.name, stored),
        );
    }
    return handles;
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
            refresh: {
                description:
                    "Write again, unchanged, every policy memory record older than a day, so data retention keeps it while the tick is paused.",
                arguments: z.object({}),
                execute: async (
                    _input: Record<string, never>,
                    context: Context,
                ) => ({
                    dataHandles: await refreshPolicyMemory(context, clock()),
                }),
            },
        },
    };
}
export const behavior = automaticDeploy();
