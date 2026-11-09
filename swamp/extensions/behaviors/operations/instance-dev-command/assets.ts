import { z } from "npm:zod@4.4.3";
import { createResult, Step } from "./result.ts";
const ObjectValue = z.record(z.string(), z.unknown());
type Context = {
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
export const behavior = {
    type: "@nckrtl/instance-dev-command",
    version: "2026.11.06.2",
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
                container: z.object({
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
                    container?: {
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
    },
};
