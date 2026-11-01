import { z } from "npm:zod@4.4.3";
const Output = z.object({ script: z.string() });
/** Resolve the shipped public single-action script; no credential or HTTP access. */
export const behavior = {
    type: "@nckrtl/metrics-credentials-assets",
    version: "2026.11.01.1",
    resources: {
        assets: { schema: Output, lifetime: "infinite", garbageCollection: 10 },
    },
    methods: {
        read: {
            description: "Read the public Grafana HTTP action source",
            arguments: z.object({}),
            execute: async (_args: object, ctx: {
                extensionFile(name: string): string;
                writeResource(
                    spec: string,
                    name: string,
                    value: unknown,
                ): Promise<unknown>;
            }) => {
                const output = {
                    script: await Deno.readTextFile(
                        ctx.extensionFile("scripts/credential.py"),
                    ),
                };
                return {
                    dataHandles: [
                        await ctx.writeResource("assets", "assets", output),
                    ],
                };
            },
        },
    },
};
