import { z } from "npm:zod@4.4.3";
const Args = z.object({}).strict();
const Assets = z.object({ script: z.string() });
export const behavior = {
    type: "@nckrtl/documents-cleanup-input",
    version: "2026.10.31.1",
    resources: {
        input: { schema: Assets, lifetime: "infinite", garbageCollection: 10 },
    },
    methods: {
        prepare: {
            description:
                "Resolve the shipped fixed Documents startup gate script",
            arguments: Args,
            execute: async (args: z.infer<typeof Args>, ctx: {
                extensionFile: (name: string) => string;
                writeResource: (
                    spec: string,
                    name: string,
                    value: unknown,
                ) => Promise<unknown>;
            }) => {
                Args.parse(args);
                const assets = Assets.parse({
                    script: ctx.extensionFile("scripts/prepare_cleanup.py"),
                });
                await ctx.writeResource("input", "cleanup", assets);
                return assets;
            },
        },
    },
};
