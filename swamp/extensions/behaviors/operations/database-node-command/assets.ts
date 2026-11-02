import { z } from "npm:zod@4.4.3";
const ObjectValue = z.record(z.string(), z.unknown());
type Context = {
    extensionFile(path: string): string;
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
export const behavior = {
    type: "@nckrtl/database-node-command",
    version: "2026.11.02.3",
    resources: {
        result: { schema: ObjectValue, lifetime: "1d", garbageCollection: 10 },
    },
    methods: {
        server_create: {
            description:
                "Read the shipped database:server:create phase script once for official SSH argv, with the public intent as JSON",
            arguments: z.object({ intent: ObjectValue }),
            execute: async (
                args: { intent: Record<string, unknown> },
                ctx: Context,
            ) => {
                const value = {
                    intent_json: JSON.stringify(args.intent),
                    create: await Deno.readTextFile(
                        ctx.extensionFile("server/create.py"),
                    ),
                };
                await ctx.writeResource("result", "server-create", value);
                return value;
            },
        },
    },
};
