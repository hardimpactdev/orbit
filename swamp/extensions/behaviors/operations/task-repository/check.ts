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
type ActiveContext = Context & {
    readResource(name: string): Promise<Record<string, unknown> | null>;
};
const MAX_ACTIVE_CHECKS = 128, MAX_ACTIVE_BYTES = 8 * 1024 * 1024;
const Active = z.object({
    schema: z.literal(1),
    entries: z.array(ObjectValue).max(MAX_ACTIVE_CHECKS),
});
function overCapacity(state: { entries: unknown[] }) {
    return state.entries.length > MAX_ACTIVE_CHECKS ||
        new TextEncoder().encode(JSON.stringify(state)).length >
            MAX_ACTIVE_BYTES;
}
function activeRecord(value: unknown) {
    if (value === null) throw new Error("tasks.check_active_uninitialized");
    const state = Active.parse(value);
    const ids = state.entries.map((row) => {
        z.literal(true).parse(row.pending);
        return handoff(row).operation_id;
    });
    if (new Set(ids).size !== ids.length) {
        throw new Error("tasks.check_active_duplicate");
    }
    if (overCapacity(state)) {
        throw new Error("tasks.check_active_capacity");
    }
    return state;
}
function handoff(value: Record<string, unknown>) {
    const inputs = ObjectValue.parse(value.inputs),
        result = ObjectValue.parse(value.result);
    z.literal("tasks.check").parse(inputs.kind);
    return {
        operation_id: z.number().int().positive().parse(inputs.operation_id),
        node_id: z.number().int().positive().parse(inputs.node_id),
        root_run_id: z.uuid().parse(value.root_run_id),
        commit: z.string().regex(/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/).parse(
            result.commit,
        ),
    };
}
function sameHandoff(
    left: Record<string, unknown>,
    right: Record<string, unknown>,
) {
    return JSON.stringify(handoff(left)) === JSON.stringify(handoff(right));
}
function pendingValue(value: Record<string, unknown>) {
    z.literal(true).parse(value.pending);
    handoff(value);
    const { input_json: _input, inputs_json: _intent, ...row } = value;
    return {
        ...row,
        input_json: JSON.stringify(row),
        inputs_json: JSON.stringify(ObjectValue.parse(row.inputs).intent),
    };
}
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
        exit_code: failed ? (failed.exit_code ?? 255) : 0,
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
        get("diff"),
        get("changed"),
        ...input.deliverables.filter((item) => item.type === "command").flatMap(
            (
                _,
                i,
            ) => [
                get("base_prepare-" + i),
                get("base-" + i),
                get("deliverable-" + i),
            ],
        ),
    ];
    const error = all.find((item) => typeof item.error_code === "string");
    if (error) return { ...error, check_id: input.check_id };
    const commands = Object.fromEntries(
        input.deliverables.filter((item) => item.type === "command").flatMap(
            (item, index) => {
                const current = get("deliverable-" + index),
                    base = get("base-" + index);
                if (Object.keys(current).length === 0) return [];
                return [[item.id, {
                    ...current,
                    ...(item.fails_on_base
                        ? {
                            base_started: typeof base.exit_code === "number",
                            base_exit_code: base.exit_code,
                            base_output: base.output,
                        }
                        : {}),
                }]];
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
        Date.parse(observedAt) - Date.parse(String(pending.pushed_at)) >=
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
            minted: false,
            result_json: JSON.stringify(local),
        };
    }
    const minted = ObjectValue.parse(JSON.parse(String(args.mint)));
    let error_code = minted.error_code ?? push.error_code ??
        imported.error_code;
    if (
        !error_code &&
        (typeof local.commit !== "string" || after.head !== local.commit ||
            after.tree !== local.tree)
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
        minted: minted.minted === true,
        result_json: JSON.stringify({ ...result, pending: !error_code }),
    };
}

/** Pure public check data and native asset paths. Commands and HTTP are placed steps. */
export const behavior = {
    type: "@nckrtl/task-check-data",
    version: "2026.11.01.3",
    resources: {
        public: {
            description: "Public check observations",
            schema: ObjectValue,
            lifetime: "infinite",
            garbageCollection: 10,
        },
        active: {
            description:
                "Bounded current active handoffs, read and merged under the native model lock",
            schema: Active,
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
        initialize: {
            description:
                "One-time load transition from freshly read native active handoffs",
            arguments: z.object({
                checks: z.array(ObjectValue).max(MAX_ACTIVE_CHECKS),
            }),
            execute: async (
                args: { checks: Record<string, unknown>[] },
                ctx: ActiveContext,
            ) => {
                if (await ctx.readResource("active") !== null) {
                    throw new Error("tasks.check_active_initialized");
                }
                const state = activeRecord({
                    schema: 1,
                    entries: args.checks.map(pendingValue),
                });
                return {
                    dataHandles: [
                        await ctx.writeResource("active", "active", state),
                    ],
                };
            },
        },
        snapshot: {
            description:
                "Snapshot the bounded current active handoffs before fan-out",
            arguments: z.object({}),
            execute: async (_args: unknown, ctx: ActiveContext) => {
                const state = activeRecord(await ctx.readResource("active"));
                return output(ctx, {
                    checks: state.entries.map((attributes) => ({ attributes })),
                });
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
                    ObjectValue.parse(args.validation).valid === true
                        ? setupResult(
                            CheckInput.parse(args.intent),
                            ObjectValue.parse(args.steps),
                        )
                        : {
                            passed: false,
                            exit_code: 255,
                            failed_step: "validation",
                            output: "",
                        },
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
                "Merge one admitted handoff into the current native active record",
            arguments: ObjectValue,
            execute: async (
                args: Record<string, unknown>,
                ctx: ActiveContext,
            ) => {
                const row = pendingValue(args), identity = handoff(row);
                const state = activeRecord(await ctx.readResource("active"));
                const prior = state.entries.find((item) =>
                    handoff(item).operation_id === identity.operation_id
                );
                if (prior) {
                    if (!sameHandoff(prior, row)) {
                        throw new Error("tasks.check_handoff_changed");
                    }
                    return output(ctx, { admitted: true });
                }
                const candidate = {
                    schema: 1,
                    entries: [...state.entries, row],
                };
                if (overCapacity(candidate)) {
                    return output(ctx, {
                        admitted: false,
                        error_code: "tasks.check_active_capacity",
                    });
                }
                const current = activeRecord(candidate);
                const active = await ctx.writeResource(
                    "active",
                    "active",
                    current,
                );
                const accepted = await output(ctx, { admitted: true });
                return { dataHandles: [active, ...accepted.dataHandles] };
            },
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
            description:
                "Merge observed handoffs into the latest current record and retain public terminal evidence",
            arguments: z.object({
                values: z.array(ObjectValue).max(MAX_ACTIVE_CHECKS),
            }),
            execute: async (
                args: { values: Record<string, unknown>[] },
                ctx: ActiveContext,
            ) => {
                const state = activeRecord(await ctx.readResource("active"));
                const values = args.values.map((value) => {
                    handoff(value);
                    z.boolean().parse(value.pending);
                    z.enum(["succeeded", "failed"]).parse(value.status);
                    return value;
                }).filter((value) =>
                    state.entries.some((row) => sameHandoff(row, value))
                );
                const ids = values.map((row) => handoff(row).operation_id);
                if (new Set(ids).size !== ids.length) {
                    throw new Error("tasks.check_active_duplicate");
                }
                const entries = state.entries.flatMap((row) => {
                    const value = values.find((item) => sameHandoff(row, item));
                    return value
                        ? value.pending ? [pendingValue(value)] : []
                        : [row];
                });
                const current = activeRecord({ schema: 1, entries });
                const checks = values.map((item) => {
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
                // Keep terminal evidence before dropping an exact active handoff.
                const result = await output(ctx, {
                    result_json: JSON.stringify({ checks }),
                });
                return {
                    dataHandles: [
                        ...result.dataHandles,
                        await ctx.writeResource("active", "active", current),
                    ],
                };
            },
        },
    },
};
