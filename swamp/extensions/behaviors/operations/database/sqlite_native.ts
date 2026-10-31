import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import { Globals } from "../_lib/direct.ts";
import { DirectArgs, GatewayOperation } from "../_lib/direct.ts";
import {
    cleanupSnapshot,
    exportSnapshot,
    type FileContext,
    type Invoke,
    receiveSnapshot,
    SeedSource,
    SeedTarget,
    seedTarget,
    snapshotFile,
    SnapshotRef,
    SnapshotSource,
} from "./sqlite_actions.ts";
import { runSqliteLocal } from "./sqlite_local.ts";

const Kind = z.enum([
    "database.sqlite_export",
    "database.sqlite_seed",
]);
const Args = DirectArgs.extend({
    kind: Kind,
    node_id: z.number().int().positive(),
});
const Intent = z.object({
    source: SnapshotSource,
    target: SeedTarget.optional(),
    started_at: z.string().datetime().optional(),
    cancel_requested: z.boolean().default(false),
    binding: Args.optional(),
    work_started: z.boolean().default(false),
}).strict().refine((v) =>
    !v.target ||
    (v.source.variant === "seed" && v.source.operation === v.target.operation &&
        v.source.target_instance_id === v.target.target_instance_id)
);
const Outcome = z.object({
    ok: z.boolean(),
    error_code: z.string().regex(/^[a-z0-9_.-]+$/).optional(),
    snapshot: SnapshotRef.optional(),
    state: z.enum([
        "ready",
        "resume",
        "complete",
        "changed",
        "cleaned",
        "cancelled",
    ])
        .optional(),
    incoming: z.string().optional(),
}).strict();
type Result = z.infer<typeof Outcome>;
const Selected = z.object({
    run_id: z.string().uuid(),
    selected_node_id: z.number().int().positive(),
});
type Selection = z.infer<typeof Selected>;
type Placed = Selection & ({ source: unknown } | { target: unknown });
type Context = FileContext & {
    globalArgs: z.infer<typeof Globals>;
    signal?: AbortSignal;
    readResource(name: string): Promise<Record<string, unknown> | null>;
    writeResource(
        spec: string,
        name: string,
        value: Record<string, unknown>,
    ): Promise<{ name: string }>;
};

/** One native type owns both source and target file access within each run. */
export function sqliteNative(
    makeHttp = (ctx: Context) =>
        gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
    invoke: Invoke = runSqliteLocal,
    actions = { exportSnapshot, receiveSnapshot, cleanupSnapshot, seedTarget },
) {
    const save = async (
        ctx: Context,
        name: string,
        action: () => Promise<Result>,
        selected: Placed,
        cleanup = false,
    ) => {
        let result: Result;
        try {
            const intent = Intent.parse(await ctx.readResource("intent"));
            const binding = Args.parse(intent.binding);
            const source = "source" in selected;
            const descriptor = source
                ? SnapshotSource.parse(selected.source)
                : SeedTarget.parse(selected.target);
            if (
                selected.run_id !== binding.run_id ||
                selected.selected_node_id !== descriptor.node_id ||
                JSON.stringify(descriptor) !==
                    JSON.stringify(source ? intent.source : intent.target)
            ) throw new Error("SQLite step differs from admitted placement");
            if (!cleanup) {
                const args = Args.parse(intent.binding);
                const current = z.object({
                    id: z.literal(args.operation_id),
                    kind: z.literal(args.kind),
                    node_id: z.literal(args.node_id),
                    status: z.enum(["queued", "running"]),
                    cancel_requested: z.boolean(),
                }).parse(
                    await new GatewayOperation(makeHttp(ctx), args).read(),
                );
                intent.cancel_requested ||= current.cancel_requested ||
                    ctx.signal?.aborted === true;
                if (
                    !intent.cancel_requested &&
                    (name === "export" || name === "cleanup-target")
                ) intent.work_started = true;
                await ctx.writeResource("intent", "intent", intent);
            }
            result =
                intent.cancel_requested && (!cleanup || !intent.work_started)
                    ? { ok: true, state: "cancelled" }
                    : cleanup && !intent.work_started
                    ? { ok: true, state: "cleaned" }
                    : Outcome.parse(await action());
        } catch {
            result = {
                ok: false,
                error_code: `database.sqlite_${
                    name.replaceAll("-", "_")
                }_failed`,
            };
        }
        return {
            dataHandles: [await ctx.writeResource("outcome", name, result)],
        };
    };
    return {
        type: "@nckrtl/database-sqlite",
        version: "2026.10.31.1",
        globalArguments: Globals,
        files: { snapshot: snapshotFile },
        resources: {
            intent: {
                schema: Intent,
                lifetime: "workflow",
                garbageCollection: 1,
            },
            outcome: {
                schema: Outcome,
                lifetime: "workflow",
                garbageCollection: 1,
            },
        },
        methods: {
            read: {
                description:
                    "Read safe recorded SQLite descriptors on the Operation Node",
                arguments: Args,
                execute: async (raw: z.infer<typeof Args>, ctx: Context) => {
                    try {
                        const args = Args.parse(raw);
                        const gateway = new GatewayOperation(
                            makeHttp(ctx),
                            args,
                        );
                        const current = z.object({
                            id: z.literal(args.operation_id),
                            kind: z.literal(args.kind),
                            node_id: z.literal(args.node_id),
                            input: Intent,
                            status: z.enum(["queued", "running"]),
                            cancel_requested: z.boolean(),
                        }).parse(
                            await gateway.read(ctx.signal),
                        );
                        if (
                            args.kind !== "database.sqlite_export" &&
                            (!current.input.target ||
                                current.input.target.node_id !== args.node_id ||
                                !SeedSource.safeParse(current.input.source)
                                    .success)
                        ) {
                            throw new Error("seed target unavailable");
                        }
                        if (
                            args.kind === "database.sqlite_export" &&
                            current.input.source.node_id !== args.node_id
                        ) {
                            throw new Error("wrong export placement");
                        }
                        const started_at = new Date().toISOString();
                        if (!current.cancel_requested) {
                            await gateway.project({
                                status: "running",
                                started_at,
                            });
                        }
                        return {
                            dataHandles: [
                                await ctx.writeResource("intent", "intent", {
                                    ...current.input,
                                    started_at,
                                    cancel_requested: current.cancel_requested,
                                    binding: args,
                                    work_started: false,
                                }),
                            ],
                        };
                    } catch {
                        throw new Error("database.sqlite_intent_invalid");
                    }
                },
            },
            export: {
                description: "Export an admitted snapshot as a native file",
                arguments: Selected.extend({ source: SnapshotSource }),
                execute: async (
                    args: Selection & { source: unknown },
                    ctx: Context,
                ) => await save(
                    ctx,
                    "export",
                    async () => ({
                        ok: true,
                        snapshot: await actions.exportSnapshot(
                            args.source,
                            ctx,
                            `snapshot-${args.run_id}`,
                            invoke,
                            ctx.signal,
                        ),
                    }),
                    args,
                ),
            },
            prepare: {
                description:
                    "Prepare the admitted target inode with the native seed primitive",
                arguments: Selected.extend({
                    target: SeedTarget,
                    exported: Outcome,
                }),
                execute: async (
                    args: Selection & { target: unknown; exported: Result },
                    ctx: Context,
                ) => await save(
                    ctx,
                    "prepare",
                    async () =>
                        args.exported.ok
                            ? {
                                ok: true,
                                ...await actions.seedTarget(
                                    args.target,
                                    "prepare",
                                    args.exported.snapshot,
                                    invoke,
                                    ctx.signal,
                                ),
                            }
                            : args.exported,
                    args,
                ),
            },
            receive: {
                description:
                    "Stream the native snapshot into the prepared private inode",
                arguments: Selected.extend({
                    target: SeedTarget,
                    exported: Outcome,
                    prepared: Outcome,
                }),
                execute: async (
                    args: Selection & {
                        target: z.infer<typeof SeedTarget>;
                        exported: Result;
                        prepared: Result;
                    },
                    ctx: Context,
                ) => await save(ctx, "receive", async () => {
                    if (!args.prepared.ok) return args.prepared;
                    if (args.prepared.state === "ready") {
                        await actions.receiveSnapshot(
                            {
                                transport_user: args.target.transport_user,
                                base: args.target.base,
                                path: z.string().parse(args.prepared.incoming),
                            },
                            args.exported.snapshot,
                            ctx,
                        );
                    }
                    return { ok: true, state: args.prepared.state };
                }, args),
            },
            install: {
                description: "Install or resume the admitted target seed",
                arguments: Selected.extend({
                    target: SeedTarget,
                    exported: Outcome,
                    received: Outcome,
                }),
                execute: async (
                    args: Selection & {
                        target: unknown;
                        exported: Result;
                        received: Result;
                    },
                    ctx: Context,
                ) => await save(ctx, "install", async () => {
                    if (!args.received.ok) return args.received;
                    if (args.received.state === "complete") {
                        return { ok: true, state: "complete" };
                    }
                    const result = await actions.seedTarget(
                        args.target,
                        "install",
                        args.exported.snapshot,
                        invoke,
                        ctx.signal,
                    );
                    if (
                        result.state !== "changed" &&
                        result.state !== "complete"
                    ) {
                        throw new Error("install incomplete");
                    }
                    return { ok: true, state: result.state };
                }, args),
            },
            cleanupSource: {
                description:
                    "Clean started source work even after cancellation",
                arguments: Selected.extend({ source: SnapshotSource }),
                execute: async (
                    args: Selection & { source: unknown },
                    ctx: Context,
                ) => await save(
                    ctx,
                    "cleanup-source",
                    async () => {
                        await actions.cleanupSnapshot(args.source, invoke);
                        return { ok: true, state: "cleaned" };
                    },
                    args,
                    true,
                ),
            },
            cleanupTarget: {
                description: "Abandon only the admitted target seed attempt",
                arguments: Selected.extend({
                    target: SeedTarget,
                    cancel_only: z.boolean().default(false),
                }),
                execute: async (
                    args: Selection & {
                        target: unknown;
                        cancel_only?: boolean;
                    },
                    ctx: Context,
                ) => await save(
                    ctx,
                    "cleanup-target",
                    async () => {
                        if (args.cancel_only) {
                            const intent = Intent.parse(
                                await ctx.readResource("intent"),
                            );
                            const prepared = await ctx.readResource("prepare");
                            if (
                                !intent.cancel_requested || !prepared ||
                                Outcome.parse(prepared).state === "cancelled"
                            ) return { ok: true, state: "cleaned" };
                        }
                        const result = await actions.seedTarget(
                            args.target,
                            "cleanup",
                            undefined,
                            invoke,
                        );
                        if (result.state !== "cleaned") {
                            throw new Error("cleanup incomplete");
                        }
                        return { ok: true, state: "cleaned" };
                    },
                    args,
                    args.cancel_only === true,
                ),
            },
            project: {
                description: "Project the saved bounded native outcome",
                arguments: Args,
                execute: async (
                    raw: z.infer<typeof Args>,
                    ctx: Context,
                ) => {
                    const args = Args.parse(raw);
                    const intent = Intent.parse(
                        await ctx.readResource("intent"),
                    );
                    if (
                        JSON.stringify(Args.parse(intent.binding)) !==
                            JSON.stringify(args)
                    ) {
                        throw new Error(
                            "database.sqlite_projection_binding_invalid",
                        );
                    }
                    const started_at = z.string().datetime().parse(
                        intent.started_at,
                    );
                    const readOutcome = async (name: string) => {
                        const value = await ctx.readResource(name);
                        return value === null ? null : Outcome.parse(value);
                    };
                    const effect = await readOutcome(
                        args.kind === "database.sqlite_export"
                            ? "export"
                            : "install",
                    );
                    const cleanup = await readOutcome("cleanup-source");
                    const targetCleanup = args.kind === "database.sqlite_seed"
                        ? await readOutcome("cleanup-target")
                        : cleanup;
                    const error =
                        [effect, targetCleanup, cleanup].find((value) =>
                            value && !value.ok
                        )
                            ?.error_code ??
                            (!effect || !cleanup || !targetCleanup
                                ? "database.sqlite_native_failed"
                                : null);
                    const cancelled = intent.cancel_requested && !error;
                    const result = error ? { error_code: error } : {
                        backend: "active",
                        ...(args.kind === "database.sqlite_export" &&
                                effect?.snapshot
                            ? {
                                bytes: effect.snapshot.bytes,
                                sha256: effect.snapshot.sha256,
                                ...(effect.snapshot.mode === undefined
                                    ? {}
                                    : { mode: effect.snapshot.mode }),
                            }
                            : {}),
                    };
                    await new GatewayOperation(makeHttp(ctx), args).project({
                        status: cancelled
                            ? "cancelled"
                            : error
                            ? "failed"
                            : "succeeded",
                        result: cancelled ? {} : result,
                        error_code: error,
                        started_at,
                        finished_at: new Date().toISOString(),
                    });
                    if (error) throw new Error(error);
                    return {
                        status: cancelled ? "cancelled" : "succeeded",
                    };
                },
            },
        },
    };
}
export const behavior = sqliteNative();
