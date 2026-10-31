import { z } from "npm:zod@4.4.3";

export const RouteLanRemoval = z.object({
    route_id: z.number().int().positive().safe(),
}).strict();
const Assets = z.object({ remove_script: z.string(), input_json: z.string() });
type Context = {
    extensionFile(name: string): string;
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};

/** Public metadata only; native additionalFiles ships the one-action script. */
export const behavior = {
    type: "@nckrtl/route-lan-commands",
    version: "2026.10.31.1",
    resources: {
        assets: { schema: Assets, lifetime: "infinite", garbageCollection: 10 },
    },
    methods: {
        assets: {
            description:
                "Resolve a Route's public withdrawal input and shipped script",
            arguments: RouteLanRemoval,
            execute: async (raw: unknown, ctx: Context) => {
                const input = RouteLanRemoval.parse(raw);
                const value = Assets.parse({
                    remove_script: ctx.extensionFile(
                        "scripts/remove_route_lan.py",
                    ),
                    input_json: JSON.stringify(input),
                });
                await ctx.writeResource("assets", "assets", value);
                return value;
            },
        },
    },
};
