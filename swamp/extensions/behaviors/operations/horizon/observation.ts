import { z } from "npm:zod@4.4.3";

export const Observation = z.object({
    present: z.boolean(),
    active: z.boolean(),
    matches: z.boolean(),
}).strict();

/** Type the public observation from the preceding native command step. No execution or HTTP. */
export const behavior = {
    type: "@nckrtl/horizon-observation",
    version: "2026.10.31.2",
    resources: {
        assets: {
            description: "Shipped Horizon scripts on the placed control worker",
            schema: z.object({ scripts_directory: z.string() }).strict(),
            lifetime: "infinite",
            garbageCollection: 10,
        },
        observation: {
            description:
                "Owned Horizon unit state from the native inspect step",
            schema: Observation,
            lifetime: "infinite",
            garbageCollection: 10,
        },
    },
    methods: {
        assets: {
            description:
                "Resolve the shipped one-action scripts without execution",
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
                    scripts_directory: ctx.extensionFile("scripts/inspect.py")
                        .replace(/\/[^/]+$/, ""),
                };
                await ctx.writeResource("assets", "assets", assets);
                return assets;
            },
        },
        read: {
            description: "Shape a native script observation",
            arguments: z.object({ stdout: z.string().max(1024) }).strict(),
            execute: async (args: { stdout: string }, ctx: {
                writeResource(
                    schema: string,
                    name: string,
                    value: unknown,
                ): Promise<unknown>;
            }) => {
                const observation = Observation.parse(JSON.parse(args.stdout));
                await ctx.writeResource(
                    "observation",
                    "observation",
                    observation,
                );
                return observation;
            },
        },
    },
};
