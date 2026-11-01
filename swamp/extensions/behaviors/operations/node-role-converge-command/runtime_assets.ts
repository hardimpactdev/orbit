import { z } from "npm:zod@4.4.3";
import { WebSocketOwnership } from "../integrations/websocket_runtime_system.ts";
import { webSocketUnit } from "../node/role_relocate_direct_local.ts";
const Path = z.string().regex(/^\/(?:[A-Za-z0-9_.-]+\/?)+$/).refine((p) =>
    !p.split("/").some((v) => v === "." || v === "..")
);
const Account = z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/);
const Args = z.discriminatedUnion("action", [
    z.object({
        action: z.literal("websocket_observe"),
        input: z.object({ install_path: Path, run_id: z.uuid() }).strict(),
    }),
    z.object({
        action: z.literal("websocket_directory"),
        input: z.object({ release: Path, user: Account, group: Account })
            .strict(),
    }),
    z.object({
        action: z.literal("websocket_link"),
        input: z.object({
            install_path: Path,
            release: Path,
            previous: Path.nullable(),
            restore: z.boolean(),
        }).strict(),
    }),
    z.object({
        action: z.literal("websocket_verify"),
        input: z.object({
            port: z.number().int().min(1).max(65535),
            install_path: Path.optional(),
            release: Path.optional(),
            unit: z.string().optional(),
        }).strict(),
    }),
]);
const Observation = z.object({
    previous: Path.nullable(),
    release: Path,
    active: z.boolean(),
    enabled: z.boolean(),
}).strict();
type Context = {
    extensionFile(name: string): string;
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
export const behavior = {
    type: "@nckrtl/node-role-runtime-assets",
    version: "2026.10.31.1",
    resources: {
        completion: {
            schema: z.object({
                node_id: z.number().int().positive(),
                assignment_id: z.number().int().positive(),
                completed: z.literal(true),
            }).strict(),
            lifetime: "1d",
            garbageCollection: 10,
        },
        assets: {
            schema: z.object({ script_body: z.string() }).strict(),
            lifetime: "1d",
            garbageCollection: 10,
        },
        observation: {
            schema: Observation,
            lifetime: "1d",
            garbageCollection: 10,
        },
        unit: {
            schema: z.object({ content: z.string() }).strict(),
            lifetime: "1d",
            garbageCollection: 10,
        },
    },
    methods: {
        complete: {
            description: "Record a completed native WebSocket contribution",
            arguments: z.object({
                node_id: z.number().int().positive(),
                assignment_id: z.number().int().positive(),
            }).strict(),
            execute: async (
                args: { node_id: number; assignment_id: number },
                ctx: Context,
            ) => {
                const result = { ...args, completed: true };
                await ctx.writeResource("completion", "completion", result);
                return result;
            },
        },
        read: {
            description: "Read one canonical role runtime action",
            arguments: z.object({
                action: z.enum([
                    "websocket_observe",
                    "websocket_directory",
                    "websocket_link",
                    "websocket_verify",
                ]),
                input: z.record(z.string(), z.unknown()),
            }).strict(),
            execute: async (
                raw: {
                    action:
                        | "websocket_observe"
                        | "websocket_directory"
                        | "websocket_link"
                        | "websocket_verify";
                    input: Record<string, unknown>;
                },
                ctx: Context,
            ) => {
                const args = Args.parse(raw);
                let source = await Deno.readTextFile(
                    ctx.extensionFile("runtime_scripts/" + args.action + ".py"),
                );
                source = source.replace(
                    "ROLE_INPUT = None",
                    "ROLE_INPUT = json.loads(" +
                        JSON.stringify(JSON.stringify(args.input)) + ")",
                );
                source = source.replace(
                    'def own_paths(install, releases):\n    raise RuntimeError("Canonical policy was not supplied")',
                    () => "import os,pwd,re\n" + WebSocketOwnership,
                );
                const result = { script_body: source };
                await ctx.writeResource("assets", "assets", result);
                return result;
            },
        },
        observe: {
            description: "Decode the public prior runtime snapshot",
            arguments: z.object({ stdout: z.string() }).strict(),
            execute: async (args: { stdout: string }, ctx: Context) => {
                const result = Observation.parse(JSON.parse(args.stdout));
                await ctx.writeResource("observation", "observation", result);
                return result;
            },
        },
        unit: {
            description: "Render the canonical Reverb service unit",
            arguments: z.object({
                path: Path,
                user: Account,
                group: Account,
                port: z.number().int(),
            }).strict(),
            execute: async (
                args: {
                    path: string;
                    user: string;
                    group: string;
                    port: number;
                },
                ctx: Context,
            ) => {
                const result = {
                    content: webSocketUnit(
                        args.path,
                        args.user,
                        args.group,
                        args.port,
                    ),
                };
                await ctx.writeResource("unit", "unit", result);
                return result;
            },
        },
    },
};
