import { z } from "npm:zod@4.4.3";
const Phase = z.object({
    ready: z.boolean(),
    // The refusal of a Node transaction that failed the phase: its WithdrawFailure code and message (route-withdraw `refusal`).
    error_code: z.string().optional(),
    detail: z.string().optional(),
});
const Failure = z.object({
    error_code: z.string().regex(/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/),
    detail: z.string(),
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
/** The refusal of a Node transaction that the `refusal` step printed (route-withdraw-node `failure`). */
function refusal(steps: z.infer<typeof States>) {
    const stdout = (steps.refusal?.outputs as { stdout?: unknown } | undefined)
        ?.stdout;
    if (
        steps.refusal?.status !== "succeeded" || typeof stdout !== "string" ||
        !stdout.trim()
    ) return {};
    try {
        const parsed = Failure.safeParse(JSON.parse(stdout));
        return parsed.success
            ? {
                error_code: parsed.data.error_code,
                detail: parsed.data.detail.slice(-1800),
            }
            : {};
    } catch {
        // A line that is not the refusal names nothing.
        return {};
    }
}
export function phaseResult(raw: unknown) {
    const { mode, steps } = PhaseArgs.parse(raw);
    const ready = succeeded(steps, `${mode}-0`);
    return { ready, ...(ready ? {} : refusal(steps)) };
}
type Context = {
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
export const behavior = {
    type: "@nckrtl/route-update-result",
    version: "2026.11.19.1",
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
