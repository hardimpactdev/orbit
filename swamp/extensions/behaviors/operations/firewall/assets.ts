import { z } from "npm:zod@4.4.3";

export const behavior = {
    type: "@nckrtl/firewall-assets",
    version: "2026.11.02.1",
    resources: {
        assets: {
            schema: z.object({ script: z.string(), input: z.string() }),
            lifetime: "1d",
            garbageCollection: 10,
        },
    },
    methods: {
        read: {
            description:
                "Resolve the shipped firewall rule script and public intent",
            arguments: z.object({ intent: z.record(z.string(), z.unknown()) }),
            execute: async (args: { intent: Record<string, unknown> }, ctx: {
                extensionFile(name: string): string;
                writeResource(
                    spec: string,
                    name: string,
                    value: unknown,
                ): Promise<unknown>;
            }) => {
                const value = {
                    script: await Deno.readTextFile(
                        ctx.extensionFile("scripts/firewall.py"),
                    ),
                    input: JSON.stringify(args.intent),
                };
                await ctx.writeResource("assets", "assets", value);
                return value;
            },
        },
    },
};
