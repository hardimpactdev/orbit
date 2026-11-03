import { z } from "npm:zod@4.4.3";
import { createResult, Step } from "./result.ts";
const ObjectValue = z.record(z.string(), z.unknown());
type Context = {
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
export const behavior = {
    type: "@nckrtl/instance-dev-command",
    version: "2026.11.02.2",
    resources: {
        result: { schema: ObjectValue, lifetime: "1d", garbageCollection: 10 },
    },
    methods: {
        create_result: {
            description:
                "Map the create phase steps to one public terminal receipt",
            arguments: z.object({
                intent: ObjectValue,
                prepare: z.string(),
                fetch: z.string(),
                source: Step.nullable(),
                environment: z.string(),
                setup: z.array(z.string()),
                processes: z.array(z.string()),
                route: z.string(),
            }),
            execute: async (
                args: {
                    intent: Record<string, unknown>;
                    prepare: string;
                    fetch: string;
                    source: z.infer<typeof Step> | null;
                    environment: string;
                    setup: string[];
                    processes: string[];
                    route: string;
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
