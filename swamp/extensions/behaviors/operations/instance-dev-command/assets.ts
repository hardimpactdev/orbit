import { z } from "npm:zod@4.4.3";
import { MigratePhases, migrateResult, writtenFailure } from "./migrate_result.ts";
import { containerFailure, createResult, fleetFailure, Step } from "./result.ts";
const ObjectValue = z.record(z.string(), z.unknown());
type Context = {
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
/** The part a failed clone T3 step was running, from the run folder's `t3.json`, or null when the step left none. */
async function t3Part(folder: string): Promise<"agent" | "t3" | null> {
    try {
        const parsed = z.object({t3_part: z.enum(["agent", "t3"])}).safeParse(JSON.parse(await Deno.readTextFile(folder + "/t3.json")));
        return parsed.success ? parsed.data.t3_part : null;
    } catch (error) {
        if (error instanceof Deno.errors.NotFound || error instanceof SyntaxError) return null;
        throw error;
    }
}

/** The run folder's result file, or null when no phase wrote one. */
async function resultFile(folder: string): Promise<unknown> {
    try {
        return JSON.parse(await Deno.readTextFile(folder + "/result.json"));
    } catch (error) {
        if (error instanceof Deno.errors.NotFound || error instanceof SyntaxError) return null;
        throw error;
    }
}

export const behavior = {
    type: "@nckrtl/instance-dev-command",
    version: "2026.11.13.2",
    resources: {
        result: { schema: ObjectValue, lifetime: "1d", garbageCollection: 10 },
    },
    methods: {
        create_result: {
            description:
                "Map the create phase steps to one public terminal receipt",
            arguments: z.object({
                intent: ObjectValue,
                folder: z.string().regex(/^\/tmp\/orbit-ansible-[A-Za-z0-9-]+$/),
                checkout: Step.nullable(),
                database: z.string(),
                setup: z.string(),
                processes: z.array(z.string()),
                route: z.string(),
                certificates: z.array(z.string()).default([]),
                t3_observation: z.string().default(""),
                sessions_observation: z.string().default(""),
                route_observation: ObjectValue.default({}),
                container: z.object({
                    mail: Step.nullable().default(null),
                    launch: z.string(),
                    workspace: Step.nullable(),
                    deploy: z.array(z.string()),
                    processes: z.string(),
                    seed: z.string(),
                    t3: z.string().default("skipped"),
                    register: z.string().default("skipped"),
                    server: z.string().nullable().default(null),
                    club_key: Step.nullable().default(null),
                    fleet: z.string().default("skipped"),
                }).nullable().default(null),
            }),
            execute: async (
                args: {
                    intent: Record<string, unknown>;
                    folder: string;
                    checkout: z.infer<typeof Step> | null;
                    database: string;
                    setup: string;
                    processes: string[];
                    route: string;
                    certificates?: string[];
                    t3_observation?: string;
                    sessions_observation?: string;
                    route_observation?: Record<string, unknown>;
                    container?: {
                        mail?: z.infer<typeof Step> | null;
                        launch: string;
                        workspace: z.infer<typeof Step> | null;
                        deploy: string[];
                        processes: string;
                        seed: string;
                        t3?: string;
                        register?: string;
                        server?: string | null;
                        club_key?: z.infer<typeof Step> | null;
                        fleet?: string;
                    } | null;
                },
                ctx: Context,
            ) => {
                const container = args.intent.container as {clone?: unknown; club_key?: unknown} | null | undefined;
                // A nested-fleet default's container phases leave in the result file what its start did with the Club key.
                const file = container?.club_key != null && container.clone == null ? await resultFile(args.folder) : null;
                // A failed clone T3 step leaves the part it was running in t3.json, which clone-session-copy brings to the run folder.
                const t3_part = args.container?.t3 === "failed" ? await t3Part(args.folder) : null;
                const value = createResult(args.intent, {...args, club_key_file: file, t3_part});
                const result = JSON.parse(value.stdout);
                if (!value.ready && ["container", "clone"].includes(result.failed_step)) {
                    // The phase owns redaction; preserve its public evidence in the Operation.
                    const failure = containerFailure(file ?? await resultFile(args.folder));
                    if (failure !== null) value.stdout = JSON.stringify({...result, ...failure});
                }
                if (!value.ready && result.failed_step === "fleet") {
                    // A clone's fleet phase writes its code and why into the run's result file; a failed step keeps no output.
                    const failure = fleetFailure(await resultFile(args.folder));
                    if (failure !== null) value.stdout = JSON.stringify({...result, ...failure});
                }
                if (!value.ready && result.failed_step === "register") {
                    try {
                        const failure = z.object({
                            error_code: z.literal("instance.container_process_inactive"),
                            process_failure: ObjectValue,
                        }).safeParse(JSON.parse(await Deno.readTextFile(args.folder + "/t3.json")));
                        if (failure.success) value.stdout = JSON.stringify({...result, ...failure.data});
                    } catch (error) {
                        if (!(error instanceof Deno.errors.NotFound || error instanceof SyntaxError)) throw error;
                    }
                }
                await ctx.writeResource("result", "create", value);
                return value;
            },
        },
        migrate_result: {
            description: "Map the steps of a move into a container to one public terminal receipt",
            arguments: z.object({
                intent: ObjectValue,
                folder: z.string().regex(/^\/tmp\/orbit-ansible-[A-Za-z0-9-]+$/),
                workspace: Step.nullable(),
                phases: MigratePhases,
                server: z.string().nullable().default(null),
            }),
            execute: async (
                args: {
                    intent: Record<string, unknown>;
                    folder: string;
                    workspace: z.infer<typeof Step> | null;
                    phases: z.infer<typeof MigratePhases>;
                    server?: string | null;
                },
                ctx: Context,
            ) => {
                const value = migrateResult(args.intent, args.phases, args.workspace, args.server ?? null, await writtenFailure(args.folder));
                await ctx.writeResource("result", "migrate", value);
                return value;
            },
        },
    },
};
