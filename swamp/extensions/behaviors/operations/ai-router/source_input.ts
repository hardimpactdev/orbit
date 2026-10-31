import { z } from "npm:zod@4.4.3";

export const SourceIntent = z.object({ retired: z.boolean() }).strict();
const Output = z.object({ script: z.string(), intent_json: z.string() });
export const behavior = {
    type: "@nckrtl/ai-router-source-input",
    version: "2026.10.31.1",
    resources: {
        input: { schema: Output, lifetime: "infinite", garbageCollection: 10 },
    },
    methods: {
        prepare: {
            description:
                "Shape only the fixed source selector and resolve its shipped script",
            arguments: SourceIntent,
            execute: async (
                args: z.infer<typeof SourceIntent>,
                ctx: {
                    extensionFile: (name: string) => string;
                    writeResource: (
                        spec: string,
                        name: string,
                        value: unknown,
                    ) => Promise<unknown>;
                },
            ) => {
                const result = Output.parse({
                    script: ctx.extensionFile("scripts/remove_source.py"),
                    intent_json: JSON.stringify(SourceIntent.parse(args)),
                });
                await ctx.writeResource("input", "source", result);
                return result;
            },
        },
    },
};
