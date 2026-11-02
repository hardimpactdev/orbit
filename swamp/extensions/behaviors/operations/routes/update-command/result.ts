import { z } from "npm:zod@4.4.3";
const Phase = z.object({
    ready: z.boolean(),
});
const States = z.record(
    z.string(),
    z.object({
        status: z.string(),
        outputs: z.object({ result: Phase.partial().optional() }).passthrough()
            .optional(),
    }).passthrough(),
);
const PhaseArgs = z.object({
    mode: z.enum(["forward", "restore", "withdraw"]),
    steps: States,
});
const succeeded = (steps: z.infer<typeof States>, name: string) =>
    steps[name]?.status === "succeeded";
export function phaseResult(raw: unknown) {
    const { mode, steps } = PhaseArgs.parse(raw);
    return {
        ready: succeeded(steps, `${mode}-0`),
    };
}
type Context = {
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
export const behavior = {
    type: "@nckrtl/route-update-result",
    version: "2026.11.02.1",
    resources: {
        phase: { schema: Phase, lifetime: "infinite", garbageCollection: 10 },
    },
    methods: {
        firewall: {
            description:
                "Return the canonical pinned tuple child's proven result",
            arguments: z.object({ ready: z.literal(true) }),
            execute: async (args: { ready: true }, ctx: Context) => {
                const result = { ...args };
                await ctx.writeResource("phase", "phase", result);
                return result;
            },
        },
        phase: {
            description:
                "Keep exact Route contribution completeness across native children",
            arguments: PhaseArgs,
            execute: async (args: z.infer<typeof PhaseArgs>, ctx: Context) => {
                const result = phaseResult(args);
                await ctx.writeResource("phase", "phase", result);
                return result;
            },
        },
    },
};
