import { z } from "npm:zod@4.4.3";
const Ready = z.object({ ready: z.literal(true) });
type Context = {
    extensionFile(path: string): string;
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
export const behavior = {
    type: "@nckrtl/instance-route-workload",
    version: "2026.11.02.1",
    resources: {
        result: {
            schema: Ready.passthrough(),
            lifetime: "infinite",
            garbageCollection: 10,
        },
    },
    methods: {
        verifyPool: {
            description:
                "Verify the official file action observed the captured pool",
            arguments: z.object({
                expected: z.string(),
                state: z.object({
                    status: z.enum(["applied", "compliant"]),
                    current: z.object({
                        exists: z.literal(true),
                        contentSha256: z.string(),
                    }),
                }).passthrough(),
            }),
            execute: async (
                args: {
                    expected: string;
                    state: { current: { contentSha256: string } };
                },
                ctx: Context,
            ) => {
                if (args.state.current.contentSha256 !== args.expected) {
                    throw new Error("php.pool_unproven");
                }
                const result = { ready: true };
                await ctx.writeResource("result", "result", result);
                return result;
            },
        },
        prepared: {
            description:
                "Require every admitted workload contribution, including empty branches",
            arguments: z.object({
                required: z.record(z.string(), z.number().int().nonnegative()),
                outcomes: z.record(
                    z.string(),
                    z.object({ status: z.string() }).passthrough(),
                ),
            }),
            execute: async (
                args: {
                    required: Record<string, number>;
                    outcomes: Record<string, { status: string }>;
                },
                ctx: Context,
            ) => {
                for (const [name, count] of Object.entries(args.required)) {
                    for (let i = 0; i < count; i++) {
                        if (
                            args.outcomes[`${name}-${i}`]?.status !==
                                "succeeded"
                        ) {
                            throw new Error("route.workload_incomplete");
                        }
                    }
                }
                const result = { ready: true };
                await ctx.writeResource("result", "result", result);
                return result;
            },
        },
    },
};
