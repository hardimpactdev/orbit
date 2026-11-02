import { z } from "npm:zod@4.4.3";
import { CreateInput, CreateResult } from "../database/create_input.ts";

const Failure = z.object({
    error_code: z.literal("database.create_outcome_unknown"),
}).strict();
// Each database phase module and the shared library it imports, sent over SSH in argv.
const Modules = {
    database_create: [
        "server_common",
        "create_common",
        "create_sql",
        "create_valkey",
        "database_create",
    ],
};
type Context = {
    extensionFile(name: string): string;
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
const Resource = z.object({ stdout: z.string(), exitCode: z.number().int() });

export const behavior = {
    type: "@nckrtl/database-create-command-assets",
    version: "2026.11.02.2",
    resources: {
        phase: {
            schema: z.object({
                intent_json: z.string(),
                shipped: z.string(),
                modules_json: z.string(),
            }),
            lifetime: "1d",
            garbageCollection: 10,
        },
        item: {
            schema: z.object({ json: z.string() }),
            lifetime: "infinite",
            garbageCollection: 10,
        },
        assets: {
            schema: z.object({
                directory: z.string(),
                intent_json: z.string(),
            }),
            lifetime: "infinite",
            garbageCollection: 10,
        },
        result: {
            schema: Resource,
            lifetime: "infinite",
            garbageCollection: 10,
        },
    },
    methods: {
        phase: {
            description:
                "Read one shipped database phase module and its library once for official SSH argv, with the public intent as JSON",
            arguments: z.object({
                intent: z.record(z.string(), z.unknown()),
                module: z.enum(["database_create"])
                    .default("database_create"),
            }).strict(),
            execute: async (
                args: {
                    intent: Record<string, unknown>;
                    module?: keyof typeof Modules;
                },
                ctx: Context,
            ) => {
                const read = (name: string) =>
                    Deno.readTextFile(ctx.extensionFile(`scripts/${name}.py`));
                const modules: Record<string, string> = {};
                for (const name of Modules[args.module ?? "database_create"]) {
                    modules[name] = await read(name);
                }
                const value = {
                    intent_json: JSON.stringify(args.intent),
                    shipped: await read("shipped"),
                    modules_json: JSON.stringify(modules),
                };
                await ctx.writeResource("phase", "phase", value);
                return value;
            },
        },
        item: {
            description: "Serialize the public native fan-out item",
            arguments: z.object({ item: z.record(z.string(), z.unknown()) })
                .strict(),
            execute: async (
                args: { item: Record<string, unknown> },
                ctx: Context,
            ) => {
                const value = { json: JSON.stringify(args.item) };
                await ctx.writeResource("item", "item", value);
                return value;
            },
        },
        read: {
            description:
                "Resolve shipped action files and serialize public intent",
            arguments: z.object({ intent: z.record(z.string(), z.unknown()) })
                .strict(),
            execute: async (
                args: { intent: Record<string, unknown> },
                ctx: Context,
            ) => {
                const { ssh, ...raw } = args.intent;
                const intent = CreateInput.parse(raw);
                const value = {
                    directory: ctx.extensionFile("scripts/create_common.py")
                        .replace(/\/[^/]+$/, ""),
                    intent_json: JSON.stringify({ ...intent, ssh }),
                };
                await ctx.writeResource("assets", "assets", value);
                return value;
            },
        },
        action_result: {
            description: "Decode one bounded public action result",
            arguments: z.object({
                stdout: z.string().max(4096),
                exit_code: z.number().int(),
            }).strict(),
            execute: async (
                args: { stdout: string; exit_code: number },
                ctx: Context,
            ) => {
                let result: Record<string, unknown> = {
                    error_code: "database.create_outcome_unknown",
                };
                try {
                    const raw = JSON.parse(args.stdout);
                    if (
                        args.exit_code === 0 &&
                        z.object({ ready: z.literal(true) }).strict().safeParse(
                            raw,
                        ).success
                    ) result = { backend: "active" };
                    else {
                        const parsed = z.union([CreateResult, Failure]).parse(
                            raw,
                        );
                        if ("error_code" in parsed) result = parsed;
                    }
                } catch {
                    /* Raw client diagnostics never become public results. */
                }
                const value = {
                    stdout: JSON.stringify(result) + "\n",
                    exitCode: "error_code" in result ? 1 : 0,
                };
                await ctx.writeResource("result", "result", value);
                return value;
            },
        },
        result: {
            description:
                "Shape the whole native creation outcome without a Gateway callback",
            arguments: z.object({
                completed: z.boolean(),
                steps: z.record(z.string(), z.unknown()),
            }).strict(),
            execute: async (
                args: { completed: boolean; steps: Record<string, unknown> },
                ctx: Context,
            ) => {
                let result: Record<string, unknown> = args.completed
                    ? { backend: "active" }
                    : { error_code: "database.create_outcome_unknown" };
                for (const raw of Object.values(args.steps)) {
                    const step = z.object({
                        status: z.string(),
                        outputs: z.record(z.string(), z.unknown()).optional(),
                    }).passthrough().safeParse(raw);
                    if (!step.success || step.data.status !== "failed") {
                        continue;
                    }
                    const output = step.data.outputs?.result;
                    try {
                        const parsed = z.union([CreateResult, Failure]).parse(
                            JSON.parse(
                                z.object({ stdout: z.string().max(4096) })
                                    .parse(output).stdout,
                            ),
                        );
                        if ("error_code" in parsed) {
                            result = parsed;
                            break;
                        }
                    } catch { /* Missing outcome remains unknown. */ }
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
