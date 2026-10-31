import { z } from "npm:zod@4.4.3";
const Id = z.number().int().positive();
const Phase = z.object({
    ready: z.boolean(),
    migrated_node_ids: z.array(Id),
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
    mode: z.enum(["forward", "restore"]),
    steps: States,
});
const Args = z.object({
    effectful: z.boolean(),
    workload_count: z.number().int().nonnegative().default(0),
    route_id: Id,
    result_route_id: Id,
    steps: States,
});
const succeeded = (steps: z.infer<typeof States>, name: string) =>
    steps[name]?.status === "succeeded";
export function phaseResult(raw: unknown) {
    const { mode, steps } = PhaseArgs.parse(raw);
    return {
        ready: succeeded(steps, `${mode}-0`),
        migrated_node_ids: [
            ...new Set(
                Object.values(steps).flatMap((step) =>
                    step.status === "succeeded"
                        ? step.outputs?.result?.migrated_node_ids ?? []
                        : []
                ),
            ),
        ],
    };
}
export function updateResult(raw: unknown) {
    const { effectful, workload_count, route_id, result_route_id, steps } = Args
        .parse(raw);
    const forward = succeeded(steps, "forward")
        ? Phase.parse(steps.forward.outputs?.result)
        : undefined;
    const restore = succeeded(steps, "restore")
        ? Phase.parse(steps.restore.outputs?.result)
        : undefined;
    const all = (name: string) =>
        Array.from(
            { length: workload_count },
            (_, i) => succeeded(steps, `${name}-${i}`),
        ).every(Boolean);
    const ready = !effectful ||
        forward?.ready === true && all("prepare") && all("retire");
    const restored = !ready && restore?.ready === true &&
        all("restore-workload");
    return {
        stdout: JSON.stringify({
            ready,
            restored,
            route_id,
            result_route_id,
            selected: ready ? "after" : restored ? "before" : "unknown",
            migrated_node_ids: ready
                ? forward?.migrated_node_ids ?? []
                : restored
                ? restore.migrated_node_ids
                : [],
            ...(!ready ? { error_code: "route.update_failed" } : {}),
        }),
    };
}
const Output = z.object({ stdout: z.string() });
type Context = {
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
export const behavior = {
    type: "@nckrtl/route-update-result",
    version: "2026.10.31.1",
    resources: {
        result: { schema: Output, lifetime: "infinite", garbageCollection: 10 },
        phase: { schema: Phase, lifetime: "infinite", garbageCollection: 10 },
    },
    methods: {
        firewall: {
            description:
                "Prove every official public ingress rule state before completing publication",
            arguments: z.object({
                rules: z.array(
                    z.object({
                        port: z.number().int(),
                        ensure: z.enum(["present", "absent"]),
                    }),
                ),
                steps: z.record(
                    z.string(),
                    z.object({
                        status: z.string(),
                        outputs: z.object({
                            current: z.object({ ruleExists: z.boolean() })
                                .optional(),
                        }).optional(),
                    }).passthrough(),
                ),
            }),
            execute: async (
                args: {
                    rules: { port: number; ensure: string }[];
                    steps: Record<
                        string,
                        {
                            status: string;
                            outputs?: { current: { ruleExists: boolean } };
                        }
                    >;
                },
                ctx: Context,
            ) => {
                if (args.steps.pins?.status !== "succeeded") {
                    throw new Error("route.ingress_unproven");
                }
                args.rules.forEach((rule, i) => {
                    const step = args.steps[`apply-${i}`];
                    if (
                        step?.status !== "succeeded" ||
                        step.outputs?.current?.ruleExists !==
                            (rule.ensure === "present")
                    ) throw new Error("route.ingress_unproven");
                });
                const result = { ready: true };
                await ctx.writeResource("phase", "phase", {
                    ...result,
                    migrated_node_ids: [],
                });
                return result;
            },
        },
        read: {
            description:
                "Report the native update outcome and complete restoration only",
            arguments: Args,
            execute: async (args: z.infer<typeof Args>, ctx: Context) => {
                const result = updateResult(args);
                await ctx.writeResource("result", "result", result);
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
        complete: {
            description:
                "Fail the run after its public terminal result records incomplete work",
            arguments: Output,
            execute: async (args: z.infer<typeof Output>, ctx: Context) => {
                if (
                    !z.object({ ready: z.boolean() }).parse(
                        JSON.parse(args.stdout),
                    ).ready
                ) throw new Error("route.update_failed");
                await ctx.writeResource("result", "result", args);
                return args;
            },
        },
    },
};
