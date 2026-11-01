import { z } from "npm:zod@4.4.3";

const Result = z.union([
    z.object({ backend: z.literal("active") }).strict(),
    z.object({
        error_code: z.enum([
            "database.server_in_use",
            "database.server_command_failed",
        ]),
    }).strict(),
    z.object({
        error_code: z.literal("database.server_restore_failed"),
        default_open: z.literal("unknown"),
    }).strict(),
]);
const Probe = z.object({ ready: z.literal(true), was_open: z.boolean() })
    .strict();
const Step = z.object({
    status: z.string(),
    outputs: z.record(z.string(), z.unknown()).optional(),
}).passthrough();
type Steps = Record<string, z.infer<typeof Step>>;
type Context = {
    extensionFile(name: string): string;
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
const Output = z.object({ stdout: z.string(), exitCode: z.number().int() });
function ready(step: z.infer<typeof Step> | undefined): boolean {
    try {
        return step?.status === "succeeded" && step.outputs?.exitCode === 0 &&
            z.object({ ready: z.literal(true) }).strict().safeParse(
                JSON.parse(String(step.outputs?.stdout)),
            ).success;
    } catch {
        return false;
    }
}
async function output(result: z.infer<typeof Result>, ctx: Context) {
    const value = {
        stdout: JSON.stringify(result) + "\n",
        exitCode: "backend" in result ? 0 : 1,
    };
    await ctx.writeResource("result", "result", value);
    return value;
}
export const behavior = {
    type: "@nckrtl/database-close-command-assets",
    version: "2026.11.01.1",
    resources: {
        plan: {
            schema: z.object({ ready: z.boolean(), was_open: z.boolean() }),
            lifetime: "infinite",
            garbageCollection: 10,
        },
        result: { schema: Output, lifetime: "infinite", garbageCollection: 10 },
    },
    methods: {
        probe: {
            description: "Decode the bounded public default-user observation",
            arguments: z.object({
                stdout: z.string(),
                exit_code: z.number().int(),
            }).strict(),
            execute: async (
                args: { stdout: string; exit_code: number },
                ctx: Context,
            ) => {
                let value = { ready: false, was_open: false };
                try {
                    if (args.exit_code === 0) {
                        value = Probe.parse(JSON.parse(args.stdout));
                    }
                } catch { /* Missing output refuses closure. */ }
                await ctx.writeResource("plan", "plan", value);
                return value;
            },
        },
        result: {
            description: "Shape typed closure and rollback outcomes",
            arguments: z.object({
                default_open: z.boolean(),
                steps: z.record(z.string(), Step),
            }).strict(),
            execute: async (
                args: { default_open: boolean; steps: Steps },
                ctx: Context,
            ) => {
                const s = args.steps;
                if (
                    s.saved?.status === "succeeded" &&
                    (!args.default_open || ready(s.disconnect)) &&
                    s.cleanup_files?.status === "succeeded"
                ) return output({ backend: "active" }, ctx);
                if (
                    s.restore && s.restore.status !== "skipped" &&
                    !ready(s.restore)
                ) {
                    return output({
                        error_code: "database.server_restore_failed",
                        default_open: "unknown",
                    }, ctx);
                }
                for (const name of ["probe", "close"]) {
                    try {
                        const raw = JSON.parse(
                            String(s[name]?.outputs?.stdout),
                        );
                        if (raw.error_code === "database.server_in_use") {
                            return output({
                                error_code: "database.server_in_use",
                            }, ctx);
                        }
                    } catch {
                        /* Only a typed refusal enters the public result. */
                    }
                }
                return output(
                    { error_code: "database.server_command_failed" },
                    ctx,
                );
            },
        },
    },
};
