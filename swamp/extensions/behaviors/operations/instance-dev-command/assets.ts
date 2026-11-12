import { z } from "npm:zod@4.4.3";
import { MigratePhases, migrateResult, writtenFailure } from "./migrate_result.ts";
import { createResult, Step } from "./result.ts";
const ObjectValue = z.record(z.string(), z.unknown());
type Context = {
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
export const behavior = {
    type: "@nckrtl/instance-dev-command",
    version: "2026.11.10.1",
    resources: {
        result: { schema: ObjectValue, lifetime: "1d", garbageCollection: 10 },
    },
    methods: {
        create_result: {
            description:
                "Map the create phase steps to one public terminal receipt",
            arguments: z.object({
                intent: ObjectValue,
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
                    agent: z.string().default("skipped"),
                    t3: z.string().default("skipped"),
                    register: z.string().default("skipped"),
                    server: z.string().nullable().default(null),
                }).nullable().default(null),
            }),
            execute: async (
                args: {
                    intent: Record<string, unknown>;
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
                        agent?: string;
                        t3?: string;
                        register?: string;
                        server?: string | null;
                    } | null;
                },
                ctx: Context,
            ) => {
                const value = createResult(args.intent, args);
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
