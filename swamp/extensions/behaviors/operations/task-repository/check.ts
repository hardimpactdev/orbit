import { z } from "npm:zod@4.4.3";
import {
    CheckInput,
    classifyCi,
    deliverableResults,
    Local,
} from "../tasks/check.ts";

const ObjectValue = z.record(z.string(), z.unknown());
type Context = {
    extensionFile(name: string): string;
    writeResource(
        spec: string,
        name: string,
        value: unknown,
        options?: { tags: Record<string, string> },
    ): Promise<unknown>;
};
const output = async (ctx: Context, value: unknown) => ({
    dataHandles: [await ctx.writeResource("public", "output", value)],
});
export function observation(raw: unknown): Record<string, unknown> {
    const row = ObjectValue.parse(raw);
    const outputs = ObjectValue.parse(row.outputs ?? row);
    return typeof outputs.stdout === "string"
        ? ObjectValue.parse(JSON.parse(outputs.stdout))
        : {};
}
export function setupResult(
    input: z.infer<typeof CheckInput>,
    raw: Record<string, unknown>,
) {
    const observations: (Record<string, unknown> & { name: string })[] = input
        .setup.map((item, index) => ({
            name: item.name,
            ...observation(raw["setup-" + index] ?? {}),
        }));
    const failed = observations.find((item) => item.exit_code !== 0);
    return {
        passed: !failed,
        exit_code: failed?.exit_code ?? 0,
        failed_step: failed?.name ?? null,
        output: observations.map((item) => item.output ?? "").join("").slice(
            -16384,
        ),
    };
}
export function localResult(
    input: z.infer<typeof CheckInput>,
    raw: Record<string, unknown>,
) {
    const get = (name: string) => observation(raw[name] ?? {});
    const original = get("original"),
        before = get("before"),
        after = get("after"),
        setup = setupResult(input, raw);
    const project: Record<string, unknown> = input.command && setup.passed
        ? get("project")
        : { exit_code: setup.exit_code, output: "" };
    const all = [
        get("validate"),
        original,
        before,
        after,
        project,
        ...input.setup.map((_, i) => get("setup-" + i)),
    ];
    const error = all.find((item) => typeof item.error_code === "string");
    if (error) return { ...error, check_id: input.check_id };
    const commands = Object.fromEntries(
        input.deliverables.filter((item) => item.type === "command").map(
            (item, index) => {
                const current = get("deliverable-" + index),
                    base = get("base-" + index);
                return [item.id, {
                    ...current,
                    ...(item.fails_on_base
                        ? {
                            base_started: typeof base.exit_code === "number",
                            base_exit_code: base.exit_code,
                            base_output: base.output,
                        }
                        : {}),
                }];
            },
        ),
    );
    const changed = before.head !== after.head || before.tree !== after.tree;
    const local = Local.parse({
        outcome: changed
            ? "changed"
            : project.exit_code === 0
            ? "passed"
            : "failed",
        exit_code: project.exit_code,
        output: (setup.output + String(project.output ?? "")).slice(-16384),
        head_before: before.head,
        tree_before: before.tree,
        head: after.head,
        tree: after.tree,
        changed_paths: get("changed").diff
            ? (get("changed").diff as { path: string }[]).map((row) => row.path)
            : [],
        failed_step: setup.failed_step,
        evidence: input.deliverables.length
            ? {
                start: input.base || null,
                diff: get("diff").diff ?? null,
                commands,
            }
            : null,
        subtask_start_commit: original.head,
    });
    const deliverables = deliverableResults(input, local);
    return {
        ...local,
        outcome: local.outcome === "passed" &&
                deliverables.some((item) => item.outcome === "failed")
            ? "failed"
            : local.outcome,
        check_id: input.check_id,
        group_id: input.group_id,
        subtask: input.subtask,
        phase: input.phase,
        deliverables,
        commit: null,
        ci: null,
    };
}
export function candidateResult(
    local: Record<string, unknown>,
    raw: Record<string, unknown>,
) {
    if (local.outcome !== "passed" || !Object.keys(raw).length) return local;
    if (raw.error_code) return { ...local, error_code: raw.error_code };
    if (
        raw.changed || raw.tree !== local.tree || typeof raw.commit !== "string"
    ) return { ...local, outcome: "changed" };
    return { ...local, commit: raw.commit };
}
export function ciResult(
    pending: Record<string, unknown>,
    reading: Record<string, unknown>,
    observedAt: string,
) {
    const inputs = ObjectValue.parse(pending.inputs),
        input = CheckInput.parse(inputs.intent),
        local = ObjectValue.parse(pending.result);
    const commit = z.string().parse(local.commit);
    const read = reading.error_code
        ? { state: "unreadable", fault: "infrastructure", runs: [], suites: [] }
        : classifyCi(
            z.array(z.unknown()).parse(reading.runs),
            [],
            commit,
            input.check_name!,
        );
    const ci = {
        ...read,
        repository: input.repository,
        branch: input.branch,
        commit,
        check_name: input.check_name,
        pushed_at: pending.pushed_at,
        observed_at: observedAt,
    };
    const missingExpired = read.state === "missing" &&
        Date.parse(observedAt) - Date.parse(String(pending.pushed_at)) >=
            600000;
    const expired =
        Date.parse(observedAt) - Date.parse(String(pending.admitted_at)) >=
            55 * 60 * 1000;
    const terminal = read.state === "passed" || read.fault !== null ||
        missingExpired || expired;
    const error_code = reading.error_code ??
        (expired
            ? "tasks.handoff_ci_timeout"
            : missingExpired || (read.fault && read.fault !== "change")
            ? "tasks.handoff_ci_unavailable"
            : null);
    const result = {
        ...local,
        pending: !terminal,
        ci,
        outcome: read.fault === "change" ? "failed" : "passed",
        failed_step: read.fault === "change" ? "ci" : local.failed_step,
        ...(error_code ? { error_code } : {}),
    };
    return {
        ...pending,
        pending: !terminal,
        result,
        status: error_code ? "failed" : "succeeded",
    };
}

export function finish(args: Record<string, unknown>) {
    const input = CheckInput.parse(args.intent),
        local = ObjectValue.parse(args.local);
    const push = ObjectValue.parse(JSON.parse(String(args.push))),
        imported = ObjectValue.parse(JSON.parse(String(args.imported)));
    const after = ObjectValue.parse(JSON.parse(String(args.after)));
    if (
        typeof local.error_code === "string" || local.outcome !== "passed" ||
        input.phase !== "handoff" || input.check_name === null
    ) {
        return {
            result: local,
            pending: false,
            result_json: JSON.stringify(local),
        };
    }
    let error_code = push.error_code ?? imported.error_code;
    if (
        typeof local.commit !== "string" || after.head !== local.commit ||
        after.tree !== local.tree
    ) error_code = "tasks.head_moved";
    if (!error_code && push.pushed !== true) error_code = "tasks.push_failed";
    const pushed_at = new Date().toISOString();
    const result = {
        ...local,
        ...(error_code ? { error_code } : {}),
        ci: {
            repository: input.repository,
            state: "pending",
            fault: null,
            branch: input.branch,
            commit: local.commit,
            check_name: input.check_name,
            pushed_at,
        },
    };
    return {
        result,
        pending: !error_code,
        pushed_at,
        result_json: JSON.stringify({ ...result, pending: !error_code }),
    };
}

/** Pure public check data and native asset paths. Commands and HTTP are placed steps. */
export const behavior = {
    type: "@nckrtl/task-check-data",
    version: "2026.10.31.1",
    resources: {
        public: {
            description: "Public check observations",
            schema: ObjectValue,
            lifetime: "infinite",
            garbageCollection: 10,
        },
        pending: {
            description:
                "Admitted CI check state for native scheduled observations",
            schema: ObjectValue,
            lifetime: "infinite",
            garbageCollection: 10,
        },
    },
    methods: {
        assets: {
            description: "Resolve the bounded CI read action",
            arguments: ObjectValue,
            execute: (_args: unknown, ctx: Context) =>
                output(ctx, {
                    ci_script: ctx.extensionFile("scripts/check_ci_read.py"),
                    directory: ctx.extensionFile("scripts/snapshot.py").replace(
                        /\/[^/]+$/,
                        "",
                    ),
                }),
        },
        collect: {
            description: "Collect this tick's public terminal results",
            arguments: ObjectValue,
            execute: (args: Record<string, unknown>, ctx: Context) => {
                const checks = Object.entries(ObjectValue.parse(args.steps))
                    .filter(([name]) => name.startsWith("observed-")).map((
                        [, step],
                    ) => ObjectValue.parse(ObjectValue.parse(step).outputs))
                    .map((item) => {
                        const inputs = ObjectValue.parse(item.inputs);
                        return {
                            operation_id: inputs.operation_id,
                            node_id: inputs.node_id,
                            kind: inputs.kind,
                            root_run_id: item.root_run_id,
                            status: item.status,
                            result: item.result,
                        };
                    });
                return output(ctx, { result_json: JSON.stringify({ checks }) });
            },
        },
        prepare: {
            description:
                "Validate public check intent and resolve native action assets",
            arguments: ObjectValue,
            execute: (args: Record<string, unknown>, ctx: Context) => {
                const input = CheckInput.parse(args.intent);
                if (
                    input.node_id !== args.node_id ||
                    input.branch !== `task-${input.group_id}-handoff`
                ) throw new Error("Wrong check target");
                return output(ctx, {
                    input_json: JSON.stringify({
                        ...input,
                        operation_id: args.operation_id,
                    }),
                    directory: ctx.extensionFile("scripts/check_validate.py")
                        .replace(/\/[^/]+$/, ""),
                    commands: input.deliverables.filter((item) =>
                        item.type === "command"
                    ),
                });
            },
        },
        decode: {
            description: "Decode public native action output",
            arguments: z.object({ value: z.string() }),
            execute: (args: { value: string }, ctx: Context) =>
                output(ctx, ObjectValue.parse(JSON.parse(args.value))),
        },
        setup: {
            description: "Summarize ordered setup observations",
            arguments: ObjectValue,
            execute: (args: Record<string, unknown>, ctx: Context) =>
                output(
                    ctx,
                    setupResult(
                        CheckInput.parse(args.intent),
                        ObjectValue.parse(args.steps),
                    ),
                ),
        },
        local: {
            description: "Shape local check and deliverable evidence",
            arguments: ObjectValue,
            execute: (args: Record<string, unknown>, ctx: Context) =>
                output(
                    ctx,
                    (() => {
                        const result = localResult(
                            CheckInput.parse(args.intent),
                            ObjectValue.parse(args.steps),
                        );
                        return { result, result_json: JSON.stringify(result) };
                    })(),
                ),
        },
        candidate: {
            description:
                "Attach the exact committed candidate to the checked evidence",
            arguments: ObjectValue,
            execute: (args: Record<string, unknown>, ctx: Context) =>
                output(
                    ctx,
                    (() => {
                        const result = candidateResult(
                            ObjectValue.parse(args.local),
                            ObjectValue.parse(JSON.parse(String(args.commit))),
                        );
                        const input = CheckInput.parse(args.intent);
                        return {
                            result,
                            result_json: JSON.stringify(result),
                            publication_json: JSON.stringify({
                                commit: result.commit,
                                tree: result.tree,
                                repository: input.repository,
                                branch: input.branch,
                            }),
                        };
                    })(),
                ),
        },
        finish: {
            description: "Shape local completion or pending CI admission",
            arguments: ObjectValue,
            execute: (args: Record<string, unknown>, ctx: Context) =>
                output(ctx, finish(args)),
        },
        pending: {
            description:
                "Record a public pending check in native data for the scheduled workflow",
            arguments: ObjectValue,
            execute: async (args: Record<string, unknown>, ctx: Context) => ({
                dataHandles: [
                    await ctx.writeResource("pending", "pending", {
                        ...args,
                        input_json: JSON.stringify(args),
                        inputs_json: JSON.stringify(
                            ObjectValue.parse(args.inputs).intent,
                        ),
                    }, { tags: { "task_check": "pending" } }),
                ],
            }),
        },
        observed: {
            description: "Compare terminal CI against the current VM candidate",
            arguments: ObjectValue,
            execute: (args: Record<string, unknown>, ctx: Context) => {
                const value = ObjectValue.parse(args.value),
                    result = ObjectValue.parse(value.result);
                if (value.pending || value.status !== "succeeded") {
                    return output(ctx, value);
                }
                const current = ObjectValue.parse(
                    JSON.parse(String(args.snapshot)),
                );
                if (
                    typeof current.head !== "string" ||
                    typeof current.tree !== "string"
                ) {
                    return output(ctx, {
                        ...value,
                        status: "failed",
                        result: {
                            ...result,
                            error_code: "tasks.check_execution_unknown",
                        },
                    });
                }
                return output(
                    ctx,
                    current.head === result.commit &&
                        current.tree === result.tree
                        ? value
                        : {
                            ...value,
                            result: { ...result, outcome: "changed" },
                        },
                );
            },
        },
        ci: {
            description: "Classify one bounded exact-SHA CI observation",
            arguments: ObjectValue,
            execute: (args: Record<string, unknown>, ctx: Context) =>
                output(
                    ctx,
                    ciResult(
                        ObjectValue.parse(args.pending),
                        ObjectValue.parse(JSON.parse(String(args.reading))),
                        new Date().toISOString(),
                    ),
                ),
        },
        settle: {
            description: "Retain terminal check evidence in native data",
            arguments: ObjectValue,
            execute: async (args: Record<string, unknown>, ctx: Context) => ({
                dataHandles: [
                    await ctx.writeResource(
                        "pending",
                        "pending",
                        (() => {
                            const value = ObjectValue.parse(args.value);
                            const { input_json: _, ...publicValue } = value;
                            return {
                                ...publicValue,
                                input_json: JSON.stringify(publicValue),
                            };
                        })(),
                        {
                            tags: {
                                "task_check":
                                    ObjectValue.parse(args.value).pending
                                        ? "pending"
                                        : "settled",
                            },
                        },
                    ),
                ],
            }),
        },
    },
};
