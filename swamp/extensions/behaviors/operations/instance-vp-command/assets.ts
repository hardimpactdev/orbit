import { z } from "npm:zod@4.4.3";
type Context = {
    extensionFile(path: string): string;
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
export const behavior = {
    type: "@nckrtl/instance-vp-assets",
    version: "2026.11.01.1",
    resources: {
        assets: {
            schema: z.object({ script_body: z.string() }),
            lifetime: "1d",
            garbageCollection: 10,
        },
    },
    methods: {
        read: {
            description:
                "Bind the admitted application path to one private manifest capture action",
            arguments: z.object({
                action: z.literal("project_material"),
                input: z.object({
                    application: z.string().startsWith("/"),
                    destination: z.string().startsWith("/"),
                }),
            }),
            execute: async (
                args: { input: { application: string; destination: string } },
                ctx: Context,
            ) => {
                const source = await Deno.readTextFile(
                    ctx.extensionFile("scripts/project_material.py"),
                );
                const value = {
                    script_body: source.replace(
                        "json.load(sys.stdin)",
                        "json.loads(" +
                            JSON.stringify(JSON.stringify(args.input)) + ")",
                    ),
                };
                await ctx.writeResource("assets", "assets", value);
                return value;
            },
        },
    },
};
