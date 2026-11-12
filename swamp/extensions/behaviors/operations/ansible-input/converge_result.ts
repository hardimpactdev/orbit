import { z } from "npm:zod@4.4.3";

export const phases = ["input", "agent", "software", "roles", "tools", "network", "check"] as const;
const SafeText = z.string().min(1).max(120).regex(/^[\x20-\x7e]+$/).nullable();
const ReturnCode = z.number().int().min(-2147483648).max(2147483647).refine((rc) => rc !== 0 && rc !== 124);
const Failure = z.object({
    task: SafeText, host: z.enum(["target", "hub"]),
    reason: z.enum(["hidden", "unreachable", "timeout", "assertion", "command_failed", "module_failed"]),
    rc: ReturnCode.optional(),
}).strict().refine((failure) => (failure.reason === "command_failed") === (failure.rc !== undefined) &&
    (failure.reason !== "hidden" || failure.task === null));
export const Result = z.object({
    phases: z.array(z.object({ name: z.enum(phases), status: z.enum(["succeeded", "failed", "skipped"]), changed: z.number().int().nonnegative(), elapsed_seconds: z.number().finite().nonnegative() }).strict()).length(7),
    changed: z.number().int().nonnegative(), elapsed_seconds: z.number().finite().nonnegative(), cleanup_complete: z.boolean(),
    tools: z.array(z.object({ id: z.number().int().positive(), status: z.enum(["installed", "failed"]), installed_version: z.string().min(1).max(255).regex(/^[^\x00-\x20\x7f]+$/).nullable() }).strict().refine((tool) => tool.status === "failed" || tool.installed_version !== null)).max(10000),
    failure: Failure.optional(),
    failed_phase: z.enum(phases).optional(), error_code: z.literal("operation.native_failed").optional(),
}).strict();
export const ReportInput = z.object({ folder: z.string().regex(/^\/tmp\/orbit-ansible-[0-9a-f-]{36}$/) }).strict();
const Stats = z.object({ changed: z.number().int().nonnegative(), failures: z.number().int().nonnegative(), unreachable: z.number().int().nonnegative() });
const Report = z.object({ stats: z.record(z.string(), Stats), plays: z.array(z.unknown()).min(1) });
const Timing = z.object({ exit_code: z.number().int(), elapsed_seconds: z.number().finite().nonnegative() }).strict();

const Fields = z.record(z.string(), z.unknown());
const Play = z.object({ tasks: z.array(z.unknown()) });
const Task = z.object({ task: Fields, hosts: z.record(z.string(), Fields) });

function safeText(value: unknown): string | null {
    if (typeof value !== "string" || !value.length || /[\/=@]|[A-Za-z0-9+_-]{16,}/.test(value)) return null;
    return value.replace(/[^\x20-\x7e]/g, "?").slice(0, 120);
}

function hidden(value: Record<string, unknown>): boolean {
    return value.no_log === true || value._ansible_no_log === true || Object.hasOwn(value, "censored") ||
        (Array.isArray(value.results) && value.results.some((item) => {
            const parsed = Fields.safeParse(item);
            return parsed.success && hidden(parsed.data);
        }));
}

function fatalFailure(report: z.infer<typeof Report>): z.infer<typeof Failure> | undefined {
    type Candidate = { order: number; failure: z.infer<typeof Failure> };
    const failed = new Map<string, Candidate>();
    const censored = new Map<string, Candidate>();
    let order = 0;
    for (const rawPlay of report.plays) {
        const play = Play.safeParse(rawPlay);
        if (!play.success) continue;
        for (const rawTask of play.data.tasks) {
            const row = Task.safeParse(rawTask);
            if (!row.success) continue;
            for (const alias of ["hub", "target"] as const) {
                const position = order++;
                const stats = report.stats[alias];
                const result = row.data.hosts[alias];
                if (!stats || (stats.failures === 0 && stats.unreachable === 0) || !result) continue;
                const isHidden = hidden(row.data.task) || hidden(result);
                if (isHidden) censored.set(alias, { order: position, failure: { task: null, host: alias, reason: "hidden" } });
                if (result.failed !== true && result.unreachable !== true) continue;
                const rc = ReturnCode.safeParse(result.rc);
                const action = typeof result.action === "string" ? result.action.split(".").at(-1) : undefined;
                const reason = isHidden ? "hidden" : result.unreachable === true ? "unreachable"
                    : result.rc === 124 ? "timeout"
                    : action === "assert" || action === "fail" || Object.hasOwn(result, "assertion") || Object.hasOwn(result, "evaluated_to") ? "assertion"
                    : rc.success ? "command_failed" : "module_failed";
                failed.set(alias, { order: position, failure: { task: isHidden ? null : safeText(row.data.task.name), host: alias, reason,
                    ...(reason === "command_failed" && rc.success ? { rc: rc.data } : {}) } });
            }
        }
    }
    // A host stops at its fatal task. Earlier ignored/rescued failures do not explain its final stats.
    return ["hub", "target"].map((host) => failed.get(host) ?? censored.get(host))
        .filter((candidate): candidate is Candidate => candidate !== undefined)
        .sort((a, b) => a.order - b.order)[0]?.failure;
}

/** Private callbacks are untrusted. Export only counts, timing, bounded Tool observations and fixed-shape task failures. */
export async function convergeResult(folder: string) {
    const summaries: z.infer<typeof Result>["phases"] = [];
    let tools: z.infer<typeof Result>["tools"] = [];
    let failed: typeof phases[number] | undefined;
    let failure: z.infer<typeof Failure> | undefined;
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
                if (status === "failed") failure = fatalFailure(report);
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
        ...(failure ? { failure } : {}),
        ...(failed ? { failed_phase: failed, error_code: "operation.native_failed" } : {}) });
}
