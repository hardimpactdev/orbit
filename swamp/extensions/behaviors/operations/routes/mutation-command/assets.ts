import { z } from "npm:zod@4.4.3";
import { mergeDns } from "./dns.ts";
const Id = z.number().int().positive();
const LanRule = z.object({
    model_name: z.string().regex(/^[a-z0-9-]+$/),
    port: z.number().int().min(1).max(65535),
    protocol: z.enum(["tcp", "udp"]),
    source: z.ipv4(),
    ensure: z.enum(["present", "absent"]),
}).strict();
const LanPlan = z.object({
    node_id: Id,
    rules: z.array(LanRule),
    migrate: z.boolean(),
}).strict();
function verifyLanRules(
    plan: z.infer<typeof LanPlan>,
    outcomes: Record<string, unknown>,
): void {
    plan.rules.forEach((rule, index) => {
        const step = z.object({
            status: z.literal("succeeded"),
            outputs: z.object({
                current: z.object({ ruleExists: z.boolean() }),
            }),
        }).parse(outcomes[`apply-${index}`]);
        if (
            step.outputs.current.ruleExists !==
                (rule.ensure === "present")
        ) throw new Error("firewall.tuple_unproven");
    });
}
const RouteObservation = z.object({
    route_id: Id,
    domain: z.string(),
    node_ids: z.array(Id),
    certificate_scopes: z.array(
        z.object({ node_id: Id, scope: z.string() }).strict(),
    ),
}).strict();
const Write = z.object({
    index: z.number().int().nonnegative(),
    route_id: Id,
    node_id: Id,
    instance_id: Id,
    name: z.enum([
        "instance.write-environment",
        "instance.write-directory-url",
        "instance.write-development-url",
    ]),
    input: z.record(z.string(), z.unknown()),
    secret_field: z.string(),
}).strict();
type Context = {
    extensionFile(path: string): string;
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
export const behavior = {
    type: "@nckrtl/route-mutation-command",
    version: "2026.10.31.3",
    resources: {
        lan: { schema: LanPlan, lifetime: "infinite", garbageCollection: 10 },
        ready: {
            lifetime: "infinite",
            garbageCollection: 10,
            schema: z.object({
                ready: z.literal(true),
                migrated_node_ids: z.array(Id).optional(),
                result_json: z.string().optional(),
            }),
        },
        dns: {
            lifetime: "infinite",
            garbageCollection: 10,
            schema: z.object({ records: z.string(), catalog: z.string() }),
        },
        contribution: {
            lifetime: "infinite",
            garbageCollection: 10,
            schema: z.object({
                ready: z.literal(true),
                node_id: Id,
                scope: z.string(),
            }),
        },
        completion: {
            lifetime: "infinite",
            garbageCollection: 10,
            schema: z.object({
                ready: z.literal(true),
                restored: z.boolean(),
                routes: z.array(
                    RouteObservation.extend({ ready: z.literal(true) }),
                ),
                contributions: z.record(z.string(), z.number()),
                migrated_node_ids: z.array(Id),
            }),
        },
        assets: {
            lifetime: "infinite",
            garbageCollection: 10,
            schema: z.object({
                script: z.string(),
                scripts_directory: z.string(),
                input_json: z.string(),
            }),
        },
        result: {
            lifetime: "infinite",
            garbageCollection: 10,
            schema: z.object({
                ready: z.boolean(),
                route_id: Id,
                instance_id: Id,
            }),
        },
    },
    methods: {
        lanApplied: {
            description:
                "Prove official tuple state before legacy rules can be removed",
            arguments: z.object({
                plan: LanPlan,
                outcomes: z.record(z.string(), z.unknown()),
            }),
            execute: async (
                args: {
                    plan: z.infer<typeof LanPlan>;
                    outcomes: Record<string, unknown>;
                },
                ctx: Context,
            ) => {
                verifyLanRules(args.plan, args.outcomes);
                return {
                    dataHandles: [
                        await ctx.writeResource("ready", "ready", {
                            ready: true,
                        }),
                    ],
                };
            },
        },
        lanResult: {
            description:
                "Verify every official firewall tuple and the optional one-time migration",
            arguments: z.object({
                plan: LanPlan,
                outcomes: z.record(z.string(), z.unknown()),
            }),
            execute: async (
                args: {
                    plan: z.infer<typeof LanPlan>;
                    outcomes: Record<string, unknown>;
                },
                ctx: Context,
            ) => {
                verifyLanRules(args.plan, args.outcomes);
                if (args.plan.migrate) {
                    z.object({ status: z.literal("succeeded") }).parse(
                        args.outcomes["migrate-0"],
                    );
                }
                const result = {
                    ready: true as const,
                    migrated_node_ids: args.plan.migrate
                        ? [args.plan.node_id]
                        : [],
                };
                return {
                    dataHandles: [
                        await ctx.writeResource("ready", "ready", {
                            ...result,
                            result_json: JSON.stringify(result),
                        }),
                    ],
                };
            },
        },
        lanMigrationResult: {
            description: "Decode the one-time legacy Route rule cleanup",
            arguments: z.object({ stdout: z.string() }),
            execute: async (args: { stdout: string }, ctx: Context) => {
                const result = z.object({ ready: z.literal(true) }).strict()
                    .parse(JSON.parse(args.stdout));
                return {
                    dataHandles: [
                        await ctx.writeResource("ready", "ready", result),
                    ],
                };
            },
        },
        dns: {
            description:
                "Compose captured DNS changes with current public files",
            arguments: z.object({
                before: z.object({ records: z.string(), catalog: z.string() })
                    .passthrough(),
                after: z.object({ records: z.string(), catalog: z.string() })
                    .passthrough(),
                current: z.object({ records: z.string(), catalog: z.string() }),
                alternatives: z.array(
                    z.object({ records: z.string(), catalog: z.string() })
                        .passthrough(),
                ),
            }),
            execute: async (
                args: {
                    before: { records: string; catalog: string };
                    after: { records: string; catalog: string };
                    current: { records: string; catalog: string };
                    alternatives: { records: string; catalog: string }[];
                },
                ctx: Context,
            ) => ({
                dataHandles: [
                    await ctx.writeResource(
                        "dns",
                        "dns",
                        mergeDns(
                            args.before,
                            args.after,
                            args.current,
                            args.alternatives,
                        ),
                    ),
                ],
            }),
        },
        lan: {
            description: "Read the complete public Node tuple plan",
            arguments: z.object({ intent: LanPlan }),
            execute: async (
                args: { intent: z.infer<typeof LanPlan> },
                ctx: Context,
            ) => ({
                dataHandles: [
                    await ctx.writeResource("lan", "lan", args.intent),
                ],
            }),
        },
        lanMigration: {
            description:
                "Resolve the temporary approved old-rule cleanup asset",
            arguments: z.object({}),
            execute: async (_args: unknown, ctx: Context) => ({
                dataHandles: [
                    await ctx.writeResource("assets", "assets", {
                        script: ctx.extensionFile("scripts/route_lan.py"),
                        scripts_directory: ctx.extensionFile("scripts"),
                        input_json: "{}",
                    }),
                ],
            }),
        },
        certificateResult: {
            description:
                "Decode the canonical leaf inspection after publication",
            arguments: z.object({
                leaf: z.object({ node_id: Id, scope: z.string() })
                    .passthrough(),
                stdout: z.string(),
            }),
            execute: async (
                args: {
                    leaf: { node_id: number; scope: string };
                    stdout: string;
                },
                ctx: Context,
            ) => {
                z.object({ present: z.literal(true) }).parse(
                    JSON.parse(args.stdout),
                );
                return {
                    dataHandles: [
                        await ctx.writeResource(
                            "contribution",
                            "contribution",
                            {
                                ready: true,
                                node_id: args.leaf.node_id,
                                scope: args.leaf.scope,
                            },
                        ),
                    ],
                };
            },
        },
        certificateRemoved: {
            description:
                "Decode certificate absence after fresh loaded-scope protection",
            arguments: z.object({
                leaf: z.object({ node_id: Id, scope: z.string() })
                    .passthrough(),
                stdout: z.string(),
            }),
            execute: async (
                args: {
                    leaf: { node_id: number; scope: string };
                    stdout: string;
                },
                ctx: Context,
            ) => {
                z.object({ present: z.literal(false) }).parse(
                    JSON.parse(args.stdout),
                );
                return {
                    dataHandles: [
                        await ctx.writeResource(
                            "contribution",
                            "contribution",
                            {
                                ready: true,
                                node_id: args.leaf.node_id,
                                scope: args.leaf.scope,
                            },
                        ),
                    ],
                };
            },
        },
        result: {
            description:
                "Report every required native contribution for the parent terminal result",
            arguments: z.object({
                stage: z.object({
                    routes: z.array(RouteObservation),
                }).passthrough(),
                mode: z.enum(["forward", "restore"]),
                required: z.record(z.string(), z.number().int().nonnegative()),
                outcomes: z.record(
                    z.string(),
                    z.object({ status: z.string() }).passthrough(),
                ),
            }),
            execute: async (
                args: {
                    stage: { routes: z.infer<typeof RouteObservation>[] };
                    mode: string;
                    required: Record<string, number>;
                    outcomes: Record<
                        string,
                        {
                            status: string;
                            outputs?: {
                                result?: { migrated_node_ids?: number[] };
                            };
                        }
                    >;
                },
                ctx: Context,
            ) => {
                for (const [name, count] of Object.entries(args.required)) {
                    for (let index = 0; index < count; index++) {
                        if (
                            args.outcomes[`${name}-${index}`]?.status !==
                                "succeeded"
                        ) throw new Error("route.contribution_incomplete");
                    }
                }
                const result = {
                    ready: true as const,
                    restored: args.mode === "restore",
                    routes: args.stage.routes.map((route) => ({
                        ...route,
                        ready: true,
                    })),
                    contributions: args.required,
                    migrated_node_ids: [
                        ...new Set(
                            Object.entries(args.outcomes).filter(([name]) =>
                                Object.keys(args.required).some((prefix) =>
                                    name.startsWith(prefix + "-")
                                )
                            ).flatMap(([, step]) =>
                                step.outputs?.result?.migrated_node_ids ?? []
                            ),
                        ),
                    ],
                };
                return {
                    dataHandles: [
                        await ctx.writeResource(
                            "completion",
                            "completion",
                            result,
                        ),
                    ],
                };
            },
        },
        environment: {
            description:
                "Resolve the script for one public admitted environment write",
            arguments: z.object({ intent: Write }),
            execute: async (args: { intent: unknown }, ctx: Context) => {
                const value = Write.parse(args.intent);
                const output = {
                    script: ctx.extensionFile("scripts/write_environment.py"),
                    scripts_directory: ctx.extensionFile("scripts"),
                    input_json: JSON.stringify(value),
                };
                return {
                    dataHandles: [
                        await ctx.writeResource("assets", "assets", output),
                    ],
                };
            },
        },
        environmentResult: {
            description: "Decode the public result of one environment write",
            arguments: z.object({ stdout: z.string() }),
            execute: async (args: { stdout: string }, ctx: Context) => {
                const result = z.object({
                    ready: z.literal(true),
                    route_id: Id,
                    instance_id: Id,
                }).strict().parse(JSON.parse(args.stdout));
                return {
                    dataHandles: [
                        await ctx.writeResource("result", "result", result),
                    ],
                };
            },
        },
    },
};
