import { z } from "npm:zod@4.4.3";
import { CloneInput } from "../database/clone_input.ts";
const Result = z.object({ stdout: z.string(), exitCode: z.number().int() });
const Snapshot = z.object({
    bytes: z.number().int().min(16).max(2 ** 40),
    sha256: z.string().regex(/^[a-f0-9]{64}$/),
    mode: z.number().int().min(0).max(0o7777),
    copy: z.enum(["reflink", "snapshot"]),
}).strict();
type Context = {
    extensionFile(path: string): string;
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
export const behavior = {
    type: "@nckrtl/database-clone-command-assets",
    version: "2026.11.01.1",
    resources: {
        assets: {
            schema: z.object({
                directory: z.string(),
                intent_json: z.string(),
                create_intent: z.record(z.string(), z.unknown()),
            }),
            lifetime: "infinite",
            garbageCollection: 10,
        },
        snapshot: {
            schema: z.object({ json: z.string() }),
            lifetime: "infinite",
            garbageCollection: 10,
        },
        result: { schema: Result, lifetime: "infinite", garbageCollection: 10 },
    },
    methods: {
        read: {
            description:
                "Validate captured clone intent and resolve shipped scripts",
            arguments: z.object({ intent: CloneInput }).strict(),
            execute: async (
                args: { intent: z.infer<typeof CloneInput> },
                ctx: Context,
            ) => {
                const i = CloneInput.parse(args.intent);
                let create: Record<string, unknown> = {};
                if (i.variant === "mysql") {
                    const { source_database: _, ...raw } = i.mysql;
                    create = { ...raw, ssh: i.target_ssh };
                }
                const value = {
                    directory: ctx.extensionFile("scripts/mysql_clone_probe.py")
                        .replace(/\/[^/]+$/, ""),
                    intent_json: JSON.stringify(i),
                    create_intent: create,
                };
                await ctx.writeResource("assets", "assets", value);
                return value;
            },
        },
        snapshot: {
            description: "Validate public snapshot size, digest and mode",
            arguments: z.object({
                stdout: z.string().max(4096),
                exit_code: z.number().int(),
            }).strict(),
            execute: async (
                args: { stdout: string; exit_code: number },
                ctx: Context,
            ) => {
                if (args.exit_code !== 0) {
                    throw new Error("database.clone_snapshot_unknown");
                }
                const value = {
                    json: JSON.stringify(
                        Snapshot.parse(JSON.parse(args.stdout)),
                    ),
                };
                await ctx.writeResource("snapshot", "snapshot", value);
                return value;
            },
        },
        result: {
            description: "Require every selected clone and cleanup outcome",
            arguments: z.object({
                expected: z.array(z.string()),
                steps: z.record(
                    z.string(),
                    z.object({
                        status: z.string(),
                        outputs: z.record(z.string(), z.unknown()).optional(),
                    }).passthrough(),
                ),
            }).strict(),
            execute: async (
                args: {
                    expected: string[];
                    steps: Record<
                        string,
                        { status: string; outputs?: Record<string, unknown> }
                    >;
                },
                ctx: Context,
            ) => {
                let result: Record<string, unknown> = { backend: "active" };
                for (const name of args.expected) {
                    const step = args.steps[name];
                    const out = (step?.outputs?.result ?? step?.outputs ??
                        {}) as Record<string, unknown>;
                    let good = step?.status === "succeeded" &&
                        (out.exitCode ?? -1) === 0;
                    try {
                        const raw = JSON.parse(String(out.stdout));
                        good &&= z.union([
                            z.object({ ready: z.literal(true) }).strict(),
                            z.object({ backend: z.literal("active") })
                                .strict(),
                        ]).safeParse(raw).success;
                        if (
                            !good && typeof raw.error_code === "string" &&
                            /^(?:database|instance)\.[a-z0-9_]{1,80}$/.test(
                                raw.error_code,
                            )
                        ) {
                            result = { error_code: raw.error_code };
                            break;
                        }
                    } catch {
                        good &&= name.startsWith("cleanup_") &&
                            out.stdout === "";
                    }
                    if (!good) {
                        result = {
                            error_code: "database.clone_outcome_unknown",
                        };
                    }
                }
                const value = {
                    stdout: JSON.stringify(result) + "\n",
                    exitCode: "backend" in result ? 0 : 1,
                };
                await ctx.writeResource("result", "result", value);
                return value;
            },
        },
    },
};
