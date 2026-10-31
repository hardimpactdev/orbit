import { z } from "npm:zod@4.4.3";
import { globToRegExp } from "jsr:@std/path@1/glob-to-regexp";
const Id = z.number().int().positive();
const Sha = z.string().regex(/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/);
const Deliverable = z.object({
    id: z.string(),
    type: z.enum(["file", "command", "review"]),
    path: z.string().default(""),
    change: z.string().default("any"),
    command: z.string().default(""),
    directory: z.string().default("."),
    fails_on_base: z.boolean().default(false),
    paths: z.array(z.string()).default([]),
});
export const CheckInput = z.object({
    group_id: Id,
    instance_id: Id,
    node_id: Id,
    subtask: Id,
    check_id: Id,
    checkout_path: z.string().startsWith("/"),
    ssr_port: z.number().int().min(1).max(65535).nullable(),
    phase: z.enum(["baseline", "handoff"]),
    command: z.string().nullable(),
    setup: z.array(
        z.object({
            name: z.string(),
            command: z.string(),
            timeout_seconds: z.number().int().positive(),
        }),
    ),
    deliverables: z.array(Deliverable),
    base: z.string(),
    message: z.string(),
    repository: z.string().nullable(),
    check_name: z.string().nullable(),
    branch: z.string().regex(/^task-[1-9][0-9]*-handoff$/),
});
const Evidence = z.object({
    start: z.string().nullable(),
    diff: z.array(z.object({ path: z.string(), status: z.string() }))
        .nullable(),
    commands: z.record(
        z.string(),
        z.object({
            exit_code: z.number().int(),
            output: z.string(),
            base_started: z.boolean().optional(),
            base_exit_code: z.number().int().optional(),
            base_timed_out: z.boolean().optional(),
        }).passthrough(),
    ),
});
export const Local = z.object({
    outcome: z.enum(["passed", "failed", "changed"]),
    exit_code: z.number().int(),
    output: z.string(),
    head_before: Sha,
    tree_before: Sha,
    head: Sha,
    tree: Sha,
    changed_paths: z.array(z.string()),
    failed_step: z.string().nullable(),
    evidence: Evidence.nullable(),
    subtask_start_commit: Sha,
});
export type CheckIntent = z.infer<typeof CheckInput>;
export type LocalCheck = z.infer<typeof Local>;

export function deliverableResults(input: CheckIntent, local: LocalCheck) {
    return input.deliverables.map((item) => {
        const failures: string[] = [];
        const checked = input.phase === "handoff" && item.type !== "review";
        if (checked && item.type === "file") {
            const pattern = globToRegExp(
                item.path.replace(/^(?:\.\/|\/)+/, ""),
                {
                    globstar: true,
                    extended: true,
                },
            );
            const wanted = item.change === "created"
                ? ["A"]
                : item.change === "modified"
                ? ["M"]
                : ["A", "M"];
            if (
                !local.evidence?.diff?.some((row) =>
                    pattern.test(row.path) && wanted.includes(row.status)
                )
            ) {
                failures.push(
                    `${item.id}: the checked diff does not contain the required file change.`,
                );
            }
        } else if (checked) {
            const run = local.evidence?.commands[item.id];
            if (!run || run.exit_code !== 0) {
                failures.push(`${item.id}: the required command did not pass.`);
            }
            if (
                item.fails_on_base &&
                (!run?.base_started || run.base_exit_code === undefined ||
                    [0, 126, 127].includes(run.base_exit_code))
            ) {
                failures.push(
                    `${item.id}: the command did not reproduce the failure on the start commit.`,
                );
            }
        }
        return {
            id: item.id,
            outcome: !checked
                ? "not_checked"
                : failures.length
                ? "failed"
                : "passed",
            failures,
        };
    });
}

type CiRead = {
    state: "passed" | "pending" | "missing" | "failed";
    fault: "change" | "configuration" | "infrastructure" | null;
    runs: unknown[];
    suites: unknown[];
};
export function classifyCi(
    runs: unknown[],
    suites: unknown[],
    commit: string,
    name: string,
): CiRead {
    const rows = runs.map((row) =>
        z.object({
            id: Id,
            name: z.string(),
            head_sha: z.string(),
            status: z.string(),
            conclusion: z.string().nullable(),
            app: z.object({ slug: z.string() }),
        }).parse(row)
    );
    const named = rows.filter((row) => row.name === name);
    const safeRuns = rows.map((row) => ({
        id: row.id,
        name: row.name.slice(0, 256),
        head_sha: row.head_sha,
        status: row.status,
        conclusion: row.conclusion,
        app_slug: row.app.slug,
    }));
    const suiteRows = suites.map((row) =>
        z.object({
            id: Id,
            status: z.string(),
            conclusion: z.string().nullable(),
            app: z.object({ slug: z.string() }),
        }).parse(row)
    ).filter((row) => row.app.slug === "github-actions");
    const safeSuites = suiteRows.map((row) => ({
        id: row.id,
        status: row.status,
        conclusion: row.conclusion,
        app_slug: row.app.slug,
    }));
    const reply = (state: CiRead["state"], fault: CiRead["fault"]): CiRead => ({
        state,
        fault,
        runs: safeRuns,
        suites: safeSuites,
    });
    const runFault = (): CiRead["fault"] =>
        rows.some((row) =>
                row.app.slug === "github-actions" && row.head_sha === commit &&
                ["failure", "timed_out", "action_required"].includes(
                    row.conclusion ?? "",
                )
            )
            ? "change"
            : rows.some((row) =>
                    ["cancelled", "startup_failure"].includes(
                        row.conclusion ?? "",
                    )
                )
            ? "infrastructure"
            : "configuration";
    const suiteFault = (): CiRead["fault"] =>
        suiteRows.some((row) =>
                ["failure", "startup_failure"].includes(row.conclusion ?? "")
            )
            ? "change"
            : suiteRows.some((row) => row.conclusion === "cancelled")
            ? "infrastructure"
            : "configuration";
    if (!named.length) {
        if (
            suiteRows.length &&
            suiteRows.every((row) => row.status === "completed")
        ) return reply("failed", suiteFault());
        return reply("missing", null);
    }
    if (
        named.some((row) =>
            row.app.slug !== "github-actions" || row.head_sha !== commit
        )
    ) return reply("failed", "configuration");
    if (named.some((row) => row.status !== "completed")) {
        return reply("pending", null);
    }
    if (named.every((row) => row.conclusion === "success")) {
        return reply("passed", null);
    }
    return reply("failed", runFault());
}
