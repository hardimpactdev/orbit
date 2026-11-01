import { z } from "npm:zod@4.4.3";
import { WebSocketOwnership } from "../integrations/websocket_runtime_system.ts";
import { webSocketUnit } from "../node/role_relocate_direct_local.ts";
const Path = z.string().regex(/^\/(?:[A-Za-z0-9_.-]+\/?)+$/).refine((p) =>
    !p.split("/").some((v) => v === "." || v === "..")
);
const Runtime = z.object({
    node_id: z.number().int().positive(),
    assignment_id: z.number().int().positive(),
    user: z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/),
    install_path: Path,
    repository: z.string().regex(/^https:\/\/[^\s@]+\.git$/),
    ref: z.string().regex(/^[a-f0-9]{40}$/),
    port: z.number().int().min(1).max(65535),
}).passthrough();
const Status = z.enum(["succeeded", "failed", "skipped", "cancelled"]);
const phases = [
    "account",
    "checkout",
    "dependencies",
    "environment",
    "unit",
    "activation",
    "verify",
    "cleanup",
] as const;
const Statuses = z.object(
    Object.fromEntries([...phases, "restore"].map((key) => [key, Status])),
);
type Context = {
    extensionFile(name: string): string;
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
export const behavior = {
    type: "@nckrtl/node-role-runtime-assets",
    version: "2026.11.01.2",
    resources: {
        assets: {
            schema: z.object({
                scripts_directory: z.string(),
                input_json: z.string(),
            }),
            lifetime: "1d",
            garbageCollection: 10,
        },
        completion: {
            schema: z.object({
                node_id: z.number(),
                assignment_id: z.number(),
                completed: z.boolean(),
                failed_step: z.string().optional(),
                error_code: z.string().optional(),
            }),
            lifetime: "1d",
            garbageCollection: 10,
        },
    },
    methods: {
        bundle: {
            description:
                "Resolve all canonical WebSocket phase files once before effects",
            arguments: z.object({ runtime: Runtime, run_id: z.uuid() })
                .strict(),
            execute: async (
                raw: { runtime: z.infer<typeof Runtime>; run_id: string },
                ctx: Context,
            ) => {
                const runtime = Runtime.parse(raw.runtime);
                const directory = await Deno.makeTempDir({
                    prefix: "orbit-role-websocket-",
                });
                const files = [...phases, "restore", "common"].map((name) =>
                    "phase_" + name + ".py"
                );
                files.push(
                    "websocket_observe.py",
                    "websocket_directory.py",
                    "websocket_link.py",
                    "websocket_cleanup.py",
                );
                for (const file of files) {
                    await Deno.copyFile(
                        ctx.extensionFile("runtime_scripts/" + file),
                        directory + "/" + file,
                    );
                }
                for (
                    const [source, destination] of [
                        ["file.py", "role_file.py"],
                        ["file_snapshot.py", "file_snapshot.py"],
                        ["file_restore.py", "file_restore.py"],
                    ]
                ) {
                    await Deno.copyFile(
                        ctx.extensionFile("scripts/" + source),
                        directory + "/" + destination,
                    );
                }
                await Deno.writeTextFile(
                    directory + "/websocket_policy.py",
                    'import os,pathlib,pwd,re\nUNIT=pathlib.Path("/etc/systemd/system/orbit-websocket.service")\ndef regular(path):return path.is_file() and not path.is_symlink()\ndef refuse(code):raise ValueError(code)\n' +
                        WebSocketOwnership,
                );
                const result = {
                    scripts_directory: directory,
                    input_json: JSON.stringify({
                        runtime,
                        run_id: raw.run_id,
                        unit_template: webSocketUnit(
                            runtime.install_path,
                            "__ROLE_USER__",
                            "__ROLE_GROUP__",
                            runtime.port,
                        ),
                    }),
                };
                await ctx.writeResource("assets", "assets", result);
                return result;
            },
        },
        complete: {
            description:
                "Prove the runtime using only the named legacy phase statuses",
            arguments: z.object({
                node_id: z.number().int().positive(),
                assignment_id: z.number().int().positive(),
                statuses: Statuses,
            }).strict(),
            execute: async (
                args: {
                    node_id: number;
                    assignment_id: number;
                    statuses: Record<string, string>;
                },
                ctx: Context,
            ) => {
                const failed = phases.find((name) =>
                    args.statuses[name] !== "succeeded"
                );
                const result = {
                    node_id: args.node_id,
                    assignment_id: args.assignment_id,
                    completed: failed === undefined,
                    ...(failed
                        ? {
                            failed_step: failed,
                            error_code: "websocket.converge_failed",
                        }
                        : {}),
                };
                await ctx.writeResource("completion", "completion", result);
                return result;
            },
        },
    },
};
