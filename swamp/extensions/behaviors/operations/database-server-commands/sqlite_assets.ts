import { z } from "npm:zod@4.4.3";
import { SeedTarget, SnapshotSource } from "../database/sqlite_input.ts";
import { CleanupInput } from "../database/cleanup_contract.ts";
const Kind = z.enum([
    "database.sqlite_export",
    "database.sqlite_seed",
    "database.sqlite_seed_abandon",
    "database.sqlite_cleanup",
]);
const Action = z.enum([
    "check",
    "source_export",
    "source_cleanup",
    "target_prepare",
    "target_install",
    "target_cleanup",
    "target_recover",
    "cleanup",
]);
const Ssh = z.object({
    host: z.ipv4(),
    user: z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/),
    host_keys: z.array(z.string()).min(1),
}).strict();
const Plan = z.object({
    source: SnapshotSource,
    target: SeedTarget.optional(),
    source_ssh: Ssh,
    target_ssh: Ssh.optional(),
}).strict().refine((i) =>
    i.source.transport_user === i.source_ssh.user &&
    (!i.target ||
        i.source.variant === "seed" &&
            i.source.operation === i.target.operation &&
            i.source.target_instance_id === i.target.target_instance_id &&
            i.target.transport_user === i.target_ssh?.user)
);
const Cleanup = z.object({ cleanup: CleanupInput, source_ssh: Ssh }).strict()
    .refine((i) =>
        i.cleanup.variant === "clone" ||
        i.cleanup.transport_user === i.source_ssh.user
    );
const Snapshot = z.object({
    bytes: z.number().int().min(16).max(2 ** 40),
    sha256: z.string().regex(/^[a-f0-9]{64}$/),
    mode: z.number().int().min(0).max(0o7777).optional(),
}).strict();
const Prepared = z.union([
    z.object({
        state: z.literal("ready"),
        incoming: z.string().regex(
            /^\/tmp\/orbit-sqlite-[a-f0-9]{16}-[a-f0-9]{16}$/,
        ),
    }).strict(),
    z.object({ state: z.enum(["resume", "complete"]) }).strict(),
]);
const Refusal = z.object({
    error_code: z.string().regex(/^(?:database|instance)\.[a-z0-9_]{1,80}$/),
}).strict();
const CommandResult = z.object({
    stdout: z.string(),
    exitCode: z.number().int(),
});
const Steps = z.record(
    z.string(),
    z.object({
        status: z.string(),
        outputs: z.record(z.string(), z.unknown()).optional(),
    }).passthrough(),
);
type Context = {
    extensionFile(path: string): string;
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
    createFileWriter(
        spec: string,
        name: string,
    ): {
        writeStream(
            stream: ReadableStream<Uint8Array>,
        ): Promise<{ name: string; version: number }>;
    };
};
function decode(stdout: string) {
    return JSON.parse(stdout);
}
function output(step?: z.infer<typeof Steps>[string]) {
    const out = step?.outputs?.result ?? step?.outputs;
    return CommandResult.parse(out);
}
const Public = z.union([
    Snapshot,
    Prepared,
    z.object({ state: z.enum(["changed", "complete", "cleaned"]) }).strict(),
    z.object({
        backend: z.enum(["active", "absent"]),
        bytes: Snapshot.shape.bytes.optional(),
        sha256: Snapshot.shape.sha256.optional(),
        mode: Snapshot.shape.mode,
    }).strict(),
]);
const unknown = () => ({ error_code: "database.sqlite_outcome_unknown" });
export const behavior = {
    type: "@nckrtl/database-sqlite-command-assets",
    version: "2026.11.01.1",
    files: {
        snapshot: {
            description: "Private SQLite export",
            contentType: "application/vnd.sqlite3",
            lifetime: "workflow",
            garbageCollection: 1,
        },
    },
    resources: {
        assets: {
            schema: z.object({
                directory: z.string(),
                argv: z.array(z.string()),
                ssh: Ssh,
                source_path: z.string(),
            }),
            lifetime: "infinite",
            garbageCollection: 10,
        },
        snapshot: {
            schema: z.object({ ref: Snapshot, json: z.string() }),
            lifetime: "infinite",
            garbageCollection: 10,
        },
        prepared: {
            schema: z.object({
                state: z.enum(["ready", "resume", "complete"]),
                incoming: z.string(),
            }),
            lifetime: "infinite",
            garbageCollection: 10,
        },
        artifact: {
            schema: z.object({
                name: z.string(),
                version: z.number().int().positive(),
            }),
            lifetime: "infinite",
            garbageCollection: 10,
        },
        result: {
            schema: CommandResult,
            lifetime: "infinite",
            garbageCollection: 10,
        },
    },
    methods: {
        read: {
            description:
                "Validate public SQLite intent and resolve one shipped action",
            arguments: z.object({
                kind: Kind,
                intent: z.unknown(),
                action: Action.default("check"),
                run_id: z.string().uuid(),
                snapshot: Snapshot.nullish(),
            }).strict(),
            execute: async (
                raw: {
                    kind: z.infer<typeof Kind>;
                    intent: unknown;
                    action: z.infer<typeof Action>;
                    run_id: string;
                    snapshot?: z.infer<typeof Snapshot> | null;
                },
                ctx: Context,
            ) => {
                const a = Action.parse(raw.action),
                    run = z.string().uuid().parse(raw.run_id);
                let ssh: z.infer<typeof Ssh>, sudo = false, sourcePath = "";
                if (raw.kind === "database.sqlite_cleanup") {
                    const i = Cleanup.parse(raw.intent);
                    if (!["check", "cleanup"].includes(a)) {
                        throw Error("database.sqlite_intent_invalid");
                    }
                    ssh = i.source_ssh;
                    sudo = i.cleanup.variant === "seed";
                } else {
                    const i = Plan.parse(raw.intent);
                    if (raw.kind !== "database.sqlite_export" && !i.target) {
                        throw Error("database.sqlite_intent_invalid");
                    }
                    if (
                        raw.kind === "database.sqlite_export" &&
                        a.startsWith("target_")
                    ) throw Error("database.sqlite_intent_invalid");
                    if (
                        raw.kind === "database.sqlite_seed_abandon" &&
                        !["check", "target_cleanup", "source_cleanup"].includes(
                            a,
                        )
                    ) throw Error("database.sqlite_intent_invalid");
                    if (a === "cleanup") {
                        throw Error("database.sqlite_intent_invalid");
                    }
                    const target = a.startsWith("target_");
                    ssh = target ? Ssh.parse(i.target_ssh) : i.source_ssh;
                    sudo = target || i.source.variant === "seed";
                    sourcePath = i.source.variant === "clone"
                        ? i.source.directory + "/snapshot.sqlite"
                        : i.source.snapshot_path;
                }
                if (["target_prepare", "target_install"].includes(a)) {
                    Snapshot.parse(raw.snapshot);
                }
                const script = a === "check" ? "" : `sqlite_${a}.py`,
                    directory = ctx.extensionFile(
                        "scripts/sqlite_source_export.py",
                    ).replace(/\/[^/]+$/, "");
                const argv = a === "check" ? [] : [
                    ...(sudo ? ["/usr/bin/sudo", "-n", "--"] : []),
                    "/usr/bin/python3",
                    "-B",
                    `/tmp/orbit-sqlite-action-${run}/${script}`,
                    JSON.stringify(raw.intent),
                    JSON.stringify(raw.snapshot ?? {}),
                ];
                const value = { directory, argv, ssh, source_path: sourcePath };
                await ctx.writeResource("assets", "assets", value);
                return value;
            },
        },
        action_result: {
            description: "Project one bounded public script result",
            arguments: z.object({
                action: Action,
                stdout: z.string().max(8192),
                exit_code: z.number().int(),
            }).strict(),
            execute: async (
                args: {
                    action: z.infer<typeof Action>;
                    stdout: string;
                    exit_code: number;
                },
                ctx: Context,
            ) => {
                let result: unknown = unknown();
                try {
                    const raw = decode(args.stdout);
                    if (args.exit_code === 0) {
                        result = args.action === "source_export"
                            ? Snapshot.parse(raw)
                            : args.action === "target_prepare"
                            ? Prepared.parse(raw)
                            : args.action === "target_install"
                            ? z.object({
                                state: z.enum(["changed", "complete"]),
                            }).strict().parse(raw)
                            : args.action === "cleanup"
                            ? z.object({ backend: z.literal("absent") })
                                .strict().parse(raw)
                            : z.object({ state: z.literal("cleaned") }).strict()
                                .parse(raw);
                    } else result = Refusal.parse(raw);
                } catch {
                    /* Invalid or unbounded script output is never success. */
                }
                const value = {
                    stdout: JSON.stringify(result) + "\n",
                    exitCode: Refusal.safeParse(result).success ? 1 : 0,
                };
                await ctx.writeResource("result", "result", value);
                return value;
            },
        },
        snapshot: {
            description:
                "Read validated snapshot metadata from a completed native child",
            arguments: z.object({
                stdout: z.string(),
                exit_code: z.number().int(),
            }).strict(),
            execute: async (
                args: { stdout: string; exit_code: number },
                ctx: Context,
            ) => {
                if (args.exit_code !== 0) {
                    throw Error("database.sqlite_outcome_unknown");
                }
                const ref = Snapshot.parse(decode(args.stdout));
                const value = { ref, json: JSON.stringify(ref) };
                await ctx.writeResource("snapshot", "snapshot", value);
                return value;
            },
        },
        prepared: {
            description: "Read the target's bounded preparation result",
            arguments: z.object({
                stdout: z.string(),
                exit_code: z.number().int(),
            }).strict(),
            execute: async (
                args: { stdout: string; exit_code: number },
                ctx: Context,
            ) => {
                if (args.exit_code !== 0) {
                    throw Error("database.sqlite_outcome_unknown");
                }
                const raw = Prepared.parse(decode(args.stdout));
                const value = {
                    state: raw.state,
                    incoming: "incoming" in raw ? raw.incoming : "",
                };
                await ctx.writeResource("prepared", "prepared", value);
                return value;
            },
        },
        artifact: {
            description:
                "Project the placed download as a native private file result",
            arguments: z.object({
                path: z.string().regex(
                    /^\/tmp\/orbit-sqlite-transfer-[a-f0-9-]{36}\/snapshot.sqlite$/,
                ),
                run_id: z.string().uuid(),
            }).strict(),
            execute: async (
                args: { path: string; run_id: string },
                ctx: Context,
            ) => {
                const file = await Deno.open(args.path, { read: true });
                const handle = await ctx.createFileWriter(
                    "snapshot",
                    "snapshot-" + args.run_id,
                ).writeStream(file.readable);
                await ctx.writeResource("artifact", "artifact", handle);
                return handle;
            },
        },
        child_result: {
            description:
                "Return the typed action result after native script cleanup",
            arguments: z.object({ steps: Steps }).strict(),
            execute: async (
                args: { steps: z.infer<typeof Steps> },
                ctx: Context,
            ) => {
                let value = {
                    stdout: JSON.stringify(unknown()) + "\n",
                    exitCode: 1,
                };
                try {
                    if (
                        args.steps.outcome?.status !== "succeeded" ||
                        args.steps.cleanup_scripts?.status !== "succeeded" ||
                        output(args.steps.cleanup_scripts).exitCode !== 0
                    ) throw Error();
                    const out = output(args.steps.outcome);
                    if (out.exitCode === 0) Public.parse(decode(out.stdout));
                    else Refusal.parse(decode(out.stdout));
                    value = out;
                } catch { /* A missing action or cleanup is never success. */ }
                await ctx.writeResource("result", "result", value);
                return value;
            },
        },
        result: {
            description:
                "Require every chosen effect and independent cleanup before projection",
            arguments: z.object({
                kind: Kind,
                expected: z.array(z.string()),
                steps: Steps,
                export_step: z.string().optional(),
            }).strict(),
            execute: async (
                args: {
                    kind: z.infer<typeof Kind>;
                    expected: string[];
                    steps: z.infer<typeof Steps>;
                    export_step?: string;
                },
                ctx: Context,
            ) => {
                let result: Record<string, unknown> = {
                    backend: [
                            "database.sqlite_cleanup",
                            "database.sqlite_seed_abandon",
                        ].includes(args.kind)
                        ? "absent"
                        : "active",
                };
                for (const name of args.expected) {
                    try {
                        const step = args.steps[name];
                        if (step?.status !== "succeeded") throw Error();
                        if (name === "artifact") {
                            z.object({
                                name: z.string(),
                                version: z.number().int().positive(),
                            }).parse(step.outputs);
                            continue;
                        }
                        const out = output(step);
                        if (out.exitCode !== 0) {
                            result = Refusal.parse(decode(out.stdout));
                            break;
                        }
                        if (
                            name === "cleanup_transfer" && out.stdout === ""
                        ) continue;
                        Public.parse(decode(out.stdout));
                        if (
                            args.kind === "database.sqlite_export" &&
                            name === args.export_step
                        ) {
                            Object.assign(
                                result,
                                Snapshot.parse(
                                    Object.fromEntries(
                                        Object.entries(decode(out.stdout))
                                            .filter(([key]) =>
                                                ["bytes", "sha256", "mode"]
                                                    .includes(key)
                                            ),
                                    ),
                                ),
                            );
                        }
                    } catch {
                        result = unknown();
                        break;
                    }
                }
                const value = {
                    stdout: JSON.stringify(result) + "\n",
                    exitCode: "error_code" in result ? 1 : 0,
                };
                await ctx.writeResource("result", "result", value);
                return value;
            },
        },
    },
};
