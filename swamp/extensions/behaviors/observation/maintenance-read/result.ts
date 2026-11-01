import { z } from "npm:zod@4.4.3";

const Input = z.object({
    routine: z.enum(["maintenance", "retention"]),
    tasks_enabled: z.boolean(),
    evaluated_at: z.iso.datetime(),
    activity_seconds: z.number().int().positive(),
    batch_hours: z.number().int().nonnegative(),
    failed_hours: z.number().int().nonnegative(),
    horizon_unit: z.string().nullable(),
    activities: z.array(
        z.object({ id: z.number().int().positive(), created_at: z.string() }),
    ),
    batches: z.array(z.object({
        id: z.string(),
        created_at: z.number().int(),
        finished_at: z.number().int().nullable(),
        cancelled_at: z.number().int().nullable(),
    })),
    failed: z.array(
        z.object({ id: z.number().int().positive(), failed_at: z.string() }),
    ),
});
function timestamp(value: string): number {
    const ms = Date.parse(
        /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d+)?$/.test(value)
            ? value.replace(" ", "T") + "Z"
            : value,
    );
    if (!Number.isFinite(ms)) throw new Error("observation.timestamp_invalid");
    return ms;
}
/** Observe eligibility at the admitted instant. Never reset counters or delete records. */
export function eligible(input: z.infer<typeof Input>) {
    const now = Math.floor(timestamp(input.evaluated_at) / 1000);
    const cutoff = now - input.batch_hours * 3600;
    return {
        activities: input.routine === "maintenance"
            ? input.activities.filter((row) =>
                timestamp(row.created_at) <
                    (now - input.activity_seconds) * 1000
            ).slice(0, 500).length
            : 0,
        batches: input.routine === "retention" && input.tasks_enabled
            ? input.batches.filter((row) =>
                (row.finished_at !== null && row.finished_at < cutoff) ||
                (row.finished_at === null && row.created_at < cutoff) ||
                (row.cancelled_at !== null && row.created_at < cutoff)
            ).length
            : 0,
        failed: input.routine === "retention"
            ? input.failed.filter((row) =>
                timestamp(row.failed_at) <
                    (now - input.failed_hours * 3600) * 1000
            ).length
            : 0,
    };
}
const Step = z.object({ status: z.string(), outputs: z.unknown().optional() });
const Results = z.record(z.string(), Step);
const Output = z.object({
    stdout: z.string().max(1048576),
    exitCode: z.number().int(),
});
const Metric = z.number().nonnegative().nullable();
const Result = z.object({
    eligible: z.object({
        activities: z.number().int(),
        batches: z.number().int(),
        failed: z.number().int(),
    }),
    uptime_seconds: Metric,
    load: z.array(z.number().nonnegative()).length(3).nullable(),
    horizon_metrics: z.object({
        memory_bytes: Metric,
        cpu_nanoseconds: Metric,
        tasks: Metric,
    }).nullable(),
    unavailable: z.array(z.enum(["host_metrics", "horizon_metrics"])),
});
function stdout(results: z.infer<typeof Results>, name: string): string | null {
    const step = results[name], output = Output.safeParse(step?.outputs);
    return step?.status === "succeeded" && output.success &&
            output.data.exitCode === 0
        ? output.data.stdout
        : null;
}
/** Compare only public outputs from the named native reads; never return raw stderr. */
export function compare(
    input: z.infer<typeof Input>,
    results: z.infer<typeof Results>,
): z.infer<typeof Result> {
    const result: z.infer<typeof Result> = {
        eligible: eligible(input),
        uptime_seconds: null,
        load: null,
        horizon_metrics: null,
        unavailable: [],
    };
    const host = stdout(results, "host_metrics-0"),
        lines = host?.trim().split("\n");
    const up = lines?.[0]?.trim().split(/\s+/).map(Number);
    const load = lines?.[1]?.trim().split(/\s+/).slice(0, 3).map(Number);
    if (input.routine === "retention") {
        // Retention observes admitted database metadata and needs no remote reads.
    } else if (
        up?.length === 2 && load?.length === 3 &&
        [...up, ...load].every((v) => Number.isFinite(v) && v >= 0)
    ) {
        result.uptime_seconds = up[0];
        result.load = load;
    } else result.unavailable.push("host_metrics");
    if (input.routine === "maintenance" && input.horizon_unit !== null) {
        const raw = stdout(results, "horizon_metrics-0");
        const properties = Object.fromEntries(
            (raw ?? "").trim().split("\n").map((line) => line.split("=")),
        );
        const metric = (name: string) => {
            const value = properties[name];
            return typeof value === "string" && /^\d+$/.test(value) &&
                    Number.isSafeInteger(Number(value))
                ? Number(value)
                : null;
        };
        const metrics = {
            memory_bytes: metric("MemoryCurrent"),
            cpu_nanoseconds: metric("CPUUsageNSec"),
            tasks: metric("TasksCurrent"),
        };
        if (
            raw !== null &&
            Object.values(metrics).some((value) => value !== null)
        ) result.horizon_metrics = metrics;
        else result.unavailable.push("horizon_metrics");
    }
    return Result.parse(result);
}
export const behavior = {
    type: "@nckrtl/observation-maintenance",
    version: "2026.11.01.1",
    resources: {
        result: {
            schema: z.object({
                stdout: z.string(),
                exitCode: z.number().int(),
            }),
            lifetime: "1d",
            garbageCollection: 10,
        },
    },
    methods: {
        result: {
            description:
                "Record read-only host metrics and housekeeping eligibility from admitted metadata",
            arguments: z.object({ intent: Input, results: Results }),
            execute: async (
                args: {
                    intent: z.infer<typeof Input>;
                    results: z.infer<typeof Results>;
                },
                ctx: {
                    writeResource(
                        spec: string,
                        name: string,
                        value: unknown,
                    ): Promise<unknown>;
                },
            ) => {
                const value = {
                    stdout: JSON.stringify(compare(args.intent, args.results)) +
                        "\n",
                    exitCode: 0,
                };
                await ctx.writeResource("result", "result", value);
                return value;
            },
        },
    },
};
