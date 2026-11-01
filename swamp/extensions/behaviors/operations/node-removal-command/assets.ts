import rolePaths from "../certificates/scripts/role_paths.py" with {
    type: "text",
};
import { z } from "npm:zod@4.4.3";

const Id = z.number().int().positive();
const User = z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/);
const Inputs = {
    stop_unit: z.object({ unit: z.string().regex(/^[a-z0-9.-]+\.service$/) })
        .strict(),
    remove_unit: z.object({ unit: z.literal("orbit-websocket.service") })
        .strict(),
    reload_units: z.object({}).strict(),
    remove_footprint: z.object({
        node_id: Id,
        group: z.enum(["worker", "observer", "exporter"]),
    }).strict(),
    revoke_keys: z.object({
        user: User,
        keys: z.array(z.string().min(1)).min(1),
    }).strict(),
    firewall: z.object({ argv: z.array(z.string()).min(2) }).strict(),
    remove_container: z.object({
        name: z.string(),
        action: z.enum(["stop", "rm"]),
    }).strict(),
    hub_peer: z.object({ public_key: z.string().regex(/^[A-Za-z0-9+/]{43}=$/) })
        .strict(),
    remove_certificate: z.object({
        scope: z.enum(["role-metrics", "role-websocket", "role-analytics"]),
    }).strict(),
    ci_project: z.object({}).strict(),
};
const Action = z.enum(
    Object.keys(Inputs) as [keyof typeof Inputs, ...(keyof typeof Inputs)[]],
);
export const RoleResult = z.object({
    schema_version: z.literal(1),
    node_id: Id,
    assignment_id: Id,
    role: z.string(),
    role_removed: z.literal(true),
    offline: z.boolean(),
}).strict();
export const RemovalResult = z.object({
    schema_version: z.literal(1),
    node_id: Id,
    name: z.string(),
    removed: z.literal(true),
    offline: z.boolean(),
    roles_shed: z.array(z.string()),
    retained_on_node: z.array(z.string()),
    follow_up: z.string().nullable(),
}).strict();
type Context = {
    extensionFile(name: string): string;
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
const save = async (ctx: Context, spec: string, value: unknown) => ({
    dataHandles: [await ctx.writeResource(spec, spec, value)],
});

/** Public asset shaping and typed results only. This behavior performs no operation or transport. */
export const behavior = {
    type: "@nckrtl/node-removal-command",
    version: "2026.11.01.2",
    resources: {
        assets: {
            schema: z.object({ script_body: z.string() }).strict(),
            lifetime: "1d",
            garbageCollection: 10,
        },
        paths: {
            schema: z.object({ probe: z.string(), revoke_token: z.string() })
                .strict(),
            lifetime: "1d",
            garbageCollection: 10,
        },
        preflight: {
            schema: z.object({ offline: z.boolean() }).strict(),
            lifetime: "1d",
            garbageCollection: 10,
        },
        role_result: {
            schema: RoleResult,
            lifetime: "1d",
            garbageCollection: 10,
        },
        result: {
            schema: RemovalResult.extend({ result_json: z.string() }),
            lifetime: "1d",
            garbageCollection: 10,
        },
    },
    methods: {
        read: {
            description: "Bind public input to one shipped removal script",
            arguments: z.object({
                action: Action,
                input: z.record(z.string(), z.unknown()),
            }).strict(),
            execute: async (
                args: { action: keyof typeof Inputs; input: unknown },
                ctx: Context,
            ) => {
                const input = Inputs[args.action].parse(args.input);
                let source = await Deno.readTextFile(
                    ctx.extensionFile("scripts/" + args.action + ".py"),
                );
                if (args.action === "remove_certificate") {
                    source = rolePaths + "\n" +
                        source.replace(
                            "from role_paths import role_directory\n",
                            "",
                        );
                }
                return await save(ctx, "assets", {
                    script_body: source.replace(
                        "REMOVAL_INPUT = None",
                        "REMOVAL_INPUT = json.loads(" +
                            JSON.stringify(JSON.stringify(input)) + ")",
                    ),
                });
            },
        },
        paths: {
            description: "Resolve the public local reachability asset",
            arguments: z.object({}).strict(),
            execute: async (_args: unknown, ctx: Context) =>
                await save(ctx, "paths", {
                    probe: ctx.extensionFile("scripts/probe.py"),
                    revoke_token: ctx.extensionFile("scripts/revoke_token.py"),
                }),
        },
        preflight: {
            description:
                "Offline permission applies only to an actually unreachable target",
            arguments: z.object({
                stdout: z.string(),
                offline: z.boolean(),
                has_ssh: z.boolean(),
            }).strict(),
            execute: async (
                args: { stdout: string; offline: boolean; has_ssh: boolean },
                ctx: Context,
            ) => {
                const result = z.object({ reachable: z.boolean() }).strict()
                    .parse(JSON.parse(args.stdout));
                if (!result.reachable && !args.offline) {
                    throw new Error("node.unreachable");
                }
                if (result.reachable && !args.has_ssh) {
                    throw new Error("swamp.ssh_target_unavailable");
                }
                return await save(ctx, "preflight", {
                    offline: args.offline && !result.reachable,
                });
            },
        },
        roleResult: {
            description:
                "Record completion of the admitted role child after every native dependency",
            arguments: RoleResult.omit({
                schema_version: true,
                role_removed: true,
            }),
            execute: async (
                args: {
                    node_id: number;
                    assignment_id: number;
                    role: string;
                    offline: boolean;
                },
                ctx: Context,
            ) => await save(
                ctx,
                "role_result",
                RoleResult.parse({
                    ...args,
                    schema_version: 1,
                    role_removed: true,
                }),
            ),
        },
        result: {
            description: "Return the one terminal Node deletion result",
            arguments: RemovalResult.omit({
                schema_version: true,
                removed: true,
                follow_up: true,
            }),
            execute: async (
                args: {
                    node_id: number;
                    name: string;
                    offline: boolean;
                    roles_shed: string[];
                    retained_on_node: string[];
                },
                ctx: Context,
            ) => await save(
                ctx,
                "result",
                (() => {
                    const result = RemovalResult.parse({
                        ...args,
                        schema_version: 1,
                        removed: true,
                        follow_up: args.offline
                            ? "Discard the Node or clear the reported retained artifacts when it is reachable."
                            : null,
                    });
                    return { ...result, result_json: JSON.stringify(result) };
                })(),
            ),
        },
    },
};
