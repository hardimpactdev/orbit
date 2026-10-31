import { z } from "npm:zod@4.4.3";

const Value = z.union([z.string(), z.number().finite(), z.boolean(), z.null()]);
const Column = z.object({
    name: z.string(),
    type: z.string(),
    nullable: z.boolean(),
    default: z.string().nullable(),
    primary: z.boolean(),
}).strict();
export const ReadResult = z.union([
    z.object({
        columns: z.array(z.string()).max(500),
        rows: z.array(z.record(z.string(), Value)).max(500),
        row_count: z.number().int().min(0).max(500),
        truncated: z.boolean(),
    }).strict(),
    z.object({ tables: z.array(z.string()).max(500) }).strict(),
    z.object({
        tables: z.array(
            z.object({ name: z.string(), columns: z.array(Column).max(500) })
                .strict(),
        ).max(500),
    }).strict(),
    z.object({ table: z.string(), columns: z.array(Column).max(500) }).strict(),
    z.object({
        error_code: z.enum([
            "database.query_failed",
            "database.table_missing",
            "database.sqlite_unreadable",
        ]),
    }).strict(),
]);

type Context = {
    extensionFile(name: string): string;
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};

export const behavior = {
    type: "@nckrtl/database-read-command-assets",
    version: "2026.10.31.1",
    resources: {
        assets: {
            schema: z.object({
                directory: z.string(),
                intent_json: z.string(),
            }),
            lifetime: "infinite",
            garbageCollection: 10,
        },
        result: {
            schema: z.object({
                stdout: z.string(),
                exitCode: z.number().int(),
            }),
            lifetime: "infinite",
            garbageCollection: 10,
        },
    },
    methods: {
        read: {
            description:
                "Resolve read action files and serialize public intent",
            arguments: z.object({ intent: z.record(z.string(), z.unknown()) })
                .strict(),
            execute: async (
                args: { intent: Record<string, unknown> },
                ctx: Context,
            ) => {
                const value = {
                    directory: ctx.extensionFile("scripts/read_pdo.php")
                        .replace(/\/[^/]+$/, ""),
                    intent_json: JSON.stringify(args.intent),
                };
                await ctx.writeResource("assets", "assets", value);
                return value;
            },
        },
        result: {
            description:
                "Decode a bounded public read observation before terminal projection",
            arguments: z.object({
                stdout: z.string().max(16000),
                exit_code: z.number().int(),
            }).strict(),
            execute: async (
                args: { stdout: string; exit_code: number },
                ctx: Context,
            ) => {
                let result: z.infer<typeof ReadResult> = {
                    error_code: "database.query_failed",
                };
                try {
                    if (new TextEncoder().encode(args.stdout).length <= 15000) {
                        const parsed = ReadResult.parse(
                            JSON.parse(args.stdout),
                        );
                        if ("error_code" in parsed || args.exit_code === 0) {
                            result = parsed;
                        }
                    }
                } catch {
                    /* Missing or invalid observations are a failed read. */
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
