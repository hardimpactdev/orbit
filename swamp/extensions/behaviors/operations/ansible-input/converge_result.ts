import { z } from "npm:zod@4.4.3";

export const phases = ["input", "agent", "software", "roles", "tools", "network", "check"] as const;
export const Result = z.object({
    phases: z.array(z.object({ name: z.enum(phases), status: z.enum(["succeeded", "failed", "skipped"]), changed: z.number().int().nonnegative(), elapsed_seconds: z.number().finite().nonnegative() }).strict()).length(7),
    changed: z.number().int().nonnegative(), elapsed_seconds: z.number().finite().nonnegative(), cleanup_complete: z.boolean(),
    tools: z.array(z.object({ id: z.number().int().positive(), status: z.enum(["installed", "failed"]), installed_version: z.string().min(1).max(255).regex(/^[^\x00-\x20\x7f]+$/).nullable() }).strict().refine((tool) => tool.status === "failed" || tool.installed_version !== null)).max(10000),
    failed_phase: z.enum(phases).optional(), error_code: z.literal("operation.native_failed").optional(),
}).strict();
export const ReportInput = z.object({ folder: z.string().regex(/^\/tmp\/orbit-ansible-[0-9a-f-]{36}$/) }).strict();
const Stats = z.object({ changed: z.number().int().nonnegative(), failures: z.number().int().nonnegative(), unreachable: z.number().int().nonnegative() });
const Report = z.object({ stats: z.record(z.string(), Stats), plays: z.array(z.unknown()).min(1) });
const Timing = z.object({ exit_code: z.number().int(), elapsed_seconds: z.number().finite().nonnegative() }).strict();

/** Private callback reports are untrusted. Only target counts, timing and bounded Tool observations leave this folder. */
export async function convergeResult(folder: string) {
    const summaries: z.infer<typeof Result>["phases"] = [];
    let tools: z.infer<typeof Result>["tools"] = [];
    let failed: typeof phases[number] | undefined;
    for (const name of phases) {
        let status: "succeeded" | "failed" | "skipped" = failed ? "skipped" : "failed";
        let changed = 0;
        let elapsed = 0;
        if (!failed) {
            try {
                const timing = Timing.parse(JSON.parse(await Deno.readTextFile(`${folder}/${name}-meta.json`)));
                elapsed = timing.elapsed_seconds;
                const report = Report.parse(JSON.parse(await Deno.readTextFile(`${folder}/${name}.json`)));
                // Delegating hub work to target would corrupt this metric; the hub is a separate inventory host/play.
                changed = report.stats.target?.changed ?? 0;
                status = timing.exit_code === 0 && report.stats.target !== undefined &&
                        Object.values(report.stats).every((stat) => stat.failures === 0 && stat.unreachable === 0)
                    ? "succeeded" : "failed";
                if (name === "tools") {
                    tools = Result.shape.tools.parse(JSON.parse(await Deno.readTextFile(`${folder}/tools-result.json`)));
                    if (new Set(tools.map((tool) => tool.id)).size !== tools.length) status = "failed";
                }
            } catch {
                // A missing, truncated or malformed callback is a phase failure, never a claimed no-op.
                status = "failed";
            }
        }
        if (status === "failed") failed = name;
        summaries.push({ name, status, changed, elapsed_seconds: elapsed });
    }
    return Result.parse({ phases: summaries, changed: summaries.reduce((sum, phase) => sum + phase.changed, 0),
        elapsed_seconds: summaries.reduce((sum, phase) => sum + phase.elapsed_seconds, 0), tools, cleanup_complete: false,
        ...(failed ? { failed_phase: failed, error_code: "operation.native_failed" } : {}) });
}
