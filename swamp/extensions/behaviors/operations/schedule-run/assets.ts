import { z } from "npm:zod@4.4.3";

/** Public native file metadata only; no intent reads or execution. */
export const behavior = {
    type: "@nckrtl/schedule-run-assets",
    version: "2026.10.31.1",
    resources: {
        assets: {
            schema: z.object({ scripts_directory: z.string() }).strict(),
            lifetime: "infinite",
            garbageCollection: 10,
        },
    },
    methods: {
        read: {
            description: "Resolve the shipped Schedule run validation scripts",
            arguments: z.object({}).strict(),
            execute: async (_args: Record<string, never>, ctx: {
                extensionFile(name: string): string;
                writeResource(
                    schema: string,
                    name: string,
                    value: unknown,
                ): Promise<unknown>;
            }) => {
                const assets = {
                    scripts_directory: ctx.extensionFile("scripts/account.py")
                        .replace(/\/[^/]+$/, ""),
                };
                await ctx.writeResource("assets", "assets", assets);
                return assets;
            },
        },
    },
};
