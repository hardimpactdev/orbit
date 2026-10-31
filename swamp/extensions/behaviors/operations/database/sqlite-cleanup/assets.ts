import { z } from "npm:zod@4.4.3";

const Args = z.object({ action: z.enum(["source", "target"]) }).strict();
const Assets = z.object({ script: z.string() }).strict();

/** Metadata only. Native additionalFiles owns delivery and fingerprinting. */
export const behavior = {
    type: "@nckrtl/database-sqlite-cleanup-assets",
    version: "2026.10.31.1",
    resources: {
        assets: { schema: Assets, lifetime: "workflow", garbageCollection: 1 },
    },
    methods: {
        read: {
            description: "Resolve one native cleanup script asset",
            arguments: Args,
            execute: async (args: z.infer<typeof Args>, ctx: {
                extensionFile: (name: string) => string;
                writeResource: (
                    spec: string,
                    name: string,
                    value: unknown,
                ) => Promise<unknown>;
            }) => {
                const value = {
                    script: ctx.extensionFile(
                        args.action === "source"
                            ? "scripts/source_cleanup.py"
                            : "scripts/target_abandon.py",
                    ),
                };
                await ctx.writeResource("assets", "assets", value);
                return value;
            },
        },
    },
};
