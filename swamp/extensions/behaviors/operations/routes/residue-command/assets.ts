import { z } from "npm:zod@4.4.3";
import remove from "../../certificates/scripts/remove.py" with { type: "text" };
import local from "../../certificates/scripts/local.py" with { type: "text" };
import { PhpSharedProgram } from "../php-shared-program.ts";
import { FirewallProgram } from "../firewall-program.ts";
const ObjectInput = z.record(z.string(), z.unknown());
export const Residue = z.object({
    rows: z.array(
        z.object({
            id: z.number().int().positive(),
            route_id: z.number().int().positive(),
            domain: z.string(),
            steps: z.array(z.string()),
            attempts: z.number().int().nonnegative(),
        }).strict(),
    ).max(10000),
    caddy: ObjectInput,
    user: z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/),
    php: z.record(z.string(), z.string()).nullable(),
    certificates: z.array(
        z.string().regex(
            /^route-[1-9][0-9]*(?:-router|-router-hostname-change|-ingress)?$/,
        ),
    ),
    firewall: z.array(
        z.object({
            operation: z.literal("remove"),
            route_id: z.number().int().positive(),
        }).strict(),
    ),
    ingress: ObjectInput.nullable(),
    dns: ObjectInput.nullable(),
}).strict();
export function outcome(status: string, stdout: string) {
    const value = status === "succeeded"
        ? ObjectInput.safeParse((() => {
            try {
                return JSON.parse(stdout);
            } catch {
                return null;
            }
        })())
        : { success: false as const };
    const ok = value.success && value.data.ready === true;
    return {
        stage: "route-residue",
        ok: true,
        entry: {
            name: "route-residue",
            status: ok
                ? (value.data.changed ? "applied" : "unchanged")
                : "skipped",
            ...(!ok ? { reason: "route_residue_cleanup_failed" } : {}),
        },
    };
}
/** Typed public asset and result boundary; all host work runs in the native child. */
export const behavior = {
    type: "@nckrtl/route-residue-command",
    version: "2026.11.01.1",
    resources: {
        result: { schema: ObjectInput, lifetime: "1d", garbageCollection: 10 },
    },
    methods: {
        bundle: {
            description:
                "Resolve the sole PHP, firewall and certificate policies once",
            arguments: z.object({ residue: Residue }).strict(),
            execute: async (
                args: { residue: z.infer<typeof Residue> },
                ctx: {
                    extensionFile(name: string): string;
                    writeResource(
                        s: string,
                        n: string,
                        v: unknown,
                    ): Promise<unknown>;
                },
            ) => {
                const directory = await Deno.makeTempDir({
                    prefix: "orbit-route-residue-",
                });
                for (
                    const [name, source] of Object.entries({
                        "canonical_php.py": PhpSharedProgram,
                        "canonical_firewall.py": FirewallProgram,
                        "canonical_certificate.py": remove,
                        "local.py": local,
                    })
                ) await Deno.writeTextFile(directory + "/" + name, source);
                await Deno.copyFile(
                    ctx.extensionFile("scripts/residue.py"),
                    directory + "/residue.py",
                );
                const value = {
                    scripts_directory: directory,
                    input_json: JSON.stringify(Residue.parse(args.residue)),
                };
                await ctx.writeResource("result", "assets", value);
                return value;
            },
        },
        complete: {
            description:
                "Read named native statuses and only successful phase output",
            arguments: z.object({
                action_status: z.string(),
                exit_code: z.number(),
                stdout: z.string(),
                caddy_statuses: z.array(z.string()),
                cleanup_status: z.string(),
            }).strict(),
            execute: async (
                args: {
                    action_status: string;
                    exit_code: number;
                    stdout: string;
                    caddy_statuses: string[];
                    cleanup_status: string;
                },
                ctx: {
                    writeResource(
                        s: string,
                        n: string,
                        v: unknown,
                    ): Promise<unknown>;
                },
            ) => {
                let body: Record<string, unknown> = {};
                if (
                    args.action_status === "succeeded"
                ) {
                    try {
                        body = ObjectInput.parse(JSON.parse(args.stdout));
                    } catch {}
                }
                const ready = args.exit_code === 0 &&
                    typeof body.changed === "boolean" &&
                    args.caddy_statuses.every((s) => s === "succeeded") &&
                    args.cleanup_status === "succeeded";
                const result = {
                    ready,
                    changed: ready && body.changed === true,
                    ...(!ready
                        ? {
                            error_code: typeof body.error_code === "string"
                                ? body.error_code
                                : "route.residue_cleanup_failed",
                        }
                        : {}),
                };
                await ctx.writeResource("result", "result", result);
                return { ...result, stdout: JSON.stringify(result) };
            },
        },
        outcome: {
            description:
                "Keep failed residue retryable without failing the Node footprint",
            arguments: z.object({
                status: z.string(),
                stdout: z.string(),
                artifact_present: z.boolean().default(true),
                required: z.boolean().default(true),
            })
                .strict(),
            execute: async (
                args: {
                    status: string;
                    stdout: string;
                    artifact_present: boolean;
                    required: boolean;
                },
                ctx: {
                    writeResource(
                        s: string,
                        n: string,
                        v: unknown,
                    ): Promise<unknown>;
                },
            ) => {
                const result = !args.artifact_present
                    ? { stage: "route-residue", ok: true }
                    : !args.required
                    ? {
                        stage: "route-residue",
                        ok: true,
                        entry: { name: "route-residue", status: "unchanged" },
                    }
                    : outcome(args.status, args.stdout);
                await ctx.writeResource("result", "outcome", result);
                return result;
            },
        },
    },
};
