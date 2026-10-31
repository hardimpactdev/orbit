import { z } from "npm:zod@4.4.3";
const Summary = z.object({
    ready: z.boolean(),
    selected: z.boolean(),
    migrated_node_ids: z.array(z.number().int().positive()),
    failed_step: z.string().optional(),
});
const State = z.object({
    status: z.string(),
    outputs: z.object({
        result: z.object({
            ready: z.boolean().optional(),
            selected: z.boolean().optional(),
            migrated_node_ids: z.array(z.number().int().positive()).optional(),
            failed_step: z.string().optional(),
        }).passthrough().optional(),
    }).passthrough().optional(),
}).passthrough();
const States = z.record(z.string(), State);
const Args = z.object({
    noop: z.boolean(),
    old_count: z.number().int().nonnegative(),
    steps: States,
});
const PhaseArgs = z.object({
    mode: z.enum(["forward", "restore"]),
    dns_count: z.number().int().nonnegative(),
    steps: States,
});
type Steps = z.infer<typeof States>;
const succeeded = (steps: Steps, name: string) =>
    steps[name]?.status === "succeeded";
function migrations(steps: Steps) {
    return [
        ...new Set(
            Object.values(steps).flatMap((step) =>
                step.outputs?.result?.migrated_node_ids ?? []
            ),
        ),
    ];
}
export function phaseResult(raw: unknown) {
    const { mode, dns_count, steps } = PhaseArgs.parse(raw);
    const ready = succeeded(steps, `${mode}-0`);
    const failure = Object.entries(steps).find(([, step]) =>
        step.status === "failed"
    );
    return {
        ready,
        selected: mode === "forward" &&
            (ready ||
                (dns_count > 0 &&
                    Array.from(
                        { length: dns_count },
                        (_, i) => succeeded(steps, `forward_prepare_dns-${i}`),
                    ).every(Boolean))),
        migrated_node_ids: migrations(steps),
        ...(!ready
            ? { failed_step: failure?.[0] ?? `${mode}-incomplete` }
            : {}),
    };
}
export function routerResult(raw: unknown) {
    const { noop, old_count, steps } = Args.parse(raw);
    const forward = succeeded(steps, "forward")
        ? Summary.parse(steps.forward.outputs?.result)
        : undefined;
    const restore = succeeded(steps, "restore")
        ? Summary.parse(steps.restore.outputs?.result)
        : undefined;
    const restored = restore?.ready === true;
    const cleaned = Array.from(
        { length: old_count },
        (_, i) => succeeded(steps, `retire-${i}`),
    ).every(Boolean);
    const ready = noop || (forward?.ready === true && cleaned);
    const failure = Object.entries(steps).find(([, step]) =>
        step.status === "failed"
    );
    return {
        stdout: JSON.stringify({
            ready,
            selected: !restored && forward?.selected === true,
            restored,
            cleanup_complete: ready,
            migrated_node_ids: migrations(steps),
            ...(!ready
                ? {
                    failed_step: restore?.failed_step ?? forward?.failed_step ??
                        failure?.[0] ?? "router-preparation",
                    error_code: "cluster.router_failed",
                }
                : {}),
        }),
    };
}
const Output = z.object({ stdout: z.string() });
type Context = {
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
export const behavior = {
    type: "@nckrtl/cluster-router-result",
    version: "2026.10.31.1",
    resources: {
        result: { schema: Output, lifetime: "infinite", garbageCollection: 10 },
        phase: { schema: Summary, lifetime: "infinite", garbageCollection: 10 },
    },
    methods: {
        read: {
            description:
                "Shape native Router outcomes for terminal Gateway projection",
            arguments: Args,
            execute: async (args: z.infer<typeof Args>, ctx: Context) => {
                const result = routerResult(args);
                await ctx.writeResource("result", "result", result);
                return result;
            },
        },
        phase: {
            description:
                "Keep partial selection and exact contribution proof across native child boundaries",
            arguments: PhaseArgs,
            execute: async (args: z.infer<typeof PhaseArgs>, ctx: Context) => {
                const result = phaseResult(args);
                await ctx.writeResource("phase", "phase", result);
                return result;
            },
        },
        complete: {
            description:
                "Fail the native root after its terminal result records incomplete work",
            arguments: Output,
            execute: async (args: z.infer<typeof Output>, ctx: Context) => {
                if (
                    !z.object({ ready: z.boolean() }).parse(
                        JSON.parse(args.stdout),
                    ).ready
                ) throw new Error("cluster.router_failed");
                await ctx.writeResource("result", "result", args);
                return args;
            },
        },
    },
};
