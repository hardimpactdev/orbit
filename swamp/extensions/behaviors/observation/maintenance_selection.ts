import { z } from "npm:zod@4.4.3";

const Redis = z.object({
    host: z.string().min(1),
    port: z.number().int().positive(),
    database: z.number().int().nonnegative(),
    prefix: z.string().min(1),
    queue_prefix: z.string(),
    trim_job: z.number().int().nonnegative(),
    trim_queue: z.number().int().nonnegative(),
});
export const MaintenanceInput = z.object({
    routine: z.enum(["maintenance", "retention"]),
    tasks_enabled: z.boolean(),
    activity_seconds: z.number().int().positive(),
    batch_hours: z.number().int().nonnegative(),
    failed_hours: z.number().int().nonnegative(),
    redis: Redis.nullable(),
    activities: z.array(
        z.object({ id: z.number().int().positive(), created_at: z.string() }),
    ).default([]),
    batches: z.array(
        z.object({
            id: z.string().min(1),
            created_at: z.number().int(),
            finished_at: z.number().int().nullable(),
            cancelled_at: z.number().int().nullable(),
        }),
    ).default([]),
    failed: z.array(
        z.object({ id: z.number().int().positive(), failed_at: z.string() }),
    ).default([]),
});
export const MaintenanceResult = z.object({
    activity_ids: z.array(z.number().int().positive()),
    batch_ids: z.array(z.string()),
    failed_ids: z.array(z.number().int().positive()),
    snapshot_jobs: z.number().int().nonnegative(),
    snapshot_queues: z.number().int().nonnegative(),
    error_code: z.literal("observation.metrics_unavailable").optional(),
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
/** Cutoffs, strict comparisons and the interrupted-Activity cap live only in Swamp. */
export function selectMaintenance(
    raw: unknown,
    now: Date = new Date(),
): z.infer<typeof MaintenanceResult> {
    const input = MaintenanceInput.parse(raw),
        result: z.infer<typeof MaintenanceResult> = {
            activity_ids: [],
            batch_ids: [],
            failed_ids: [],
            snapshot_jobs: 0,
            snapshot_queues: 0,
        };
    if (input.routine === "maintenance") {
        result.activity_ids = input.activities.filter((row) =>
            timestamp(row.created_at) <
                (Math.floor(now.getTime() / 1000) - input.activity_seconds) *
                    1000
        ).sort((a, b) => a.id - b.id).slice(0, 500).map((row) => row.id);
    } else {
        const cutoff = Math.floor(now.getTime() / 1000) -
            input.batch_hours * 3600;
        if (input.tasks_enabled) {
            result.batch_ids = input.batches.filter((row) =>
                (row.finished_at !== null && row.finished_at < cutoff) ||
                (row.finished_at === null && row.created_at < cutoff) ||
                (row.cancelled_at !== null && row.created_at < cutoff)
            ).map((row) => row.id);
        }
        result.failed_ids = input.failed.filter((row) =>
            timestamp(row.failed_at) <
                (Math.floor(now.getTime() / 1000) - input.failed_hours * 3600) *
                    1000
        ).map((row) => row.id);
    }
    return result;
}
