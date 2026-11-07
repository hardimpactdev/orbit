import { z } from "npm:zod@4.4.3";
const Id = z.number().int().positive();
const LanRule = z.object({
    model_name: z.string().regex(/^[a-z0-9-]+$/),
    port: z.number().int().min(1).max(65535),
    protocol: z.enum(["tcp", "udp"]),
    source: z.union([z.ipv4(), z.literal("any")]),
    ensure: z.enum(["present", "absent"]),
}).strict();
const LanPlan = z.object({
    node_id: Id,
    rules: z.array(LanRule),
}).strict();
function tupleKeys(status: string): Set<string> {
    if (!/^Status: active$/m.test(status)) throw new Error("firewall.inactive");
    return new Set(
        status.split("\n").flatMap((line) => {
            const row = line.trim().match(
                /^(\d+)\/(tcp|udp)(?:\s+\(v6\))?\s+ALLOW(?:\s+IN)?\s+(Anywhere|[0-9.]+)(?:\s+\(v6\))?(?:\s+#.*)?\s*$/,
            );
            return row ? [`${Number(row[1])}/${row[2]}/${row[3]}`] : [];
        }),
    );
}
const tupleKey = (rule: z.infer<typeof LanRule>) =>
    `${rule.port}/${rule.protocol}/${
        rule.source === "any" ? "Anywhere" : rule.source
    }`;
export function neededLanRules(plan: z.infer<typeof LanPlan>, status: string) {
    const current = tupleKeys(status);
    return plan.rules.filter((rule) =>
        current.has(tupleKey(rule)) !== (rule.ensure === "present")
    )
        .map((rule) => rule.model_name);
}
const LanOutcomes = z.object({
    plan: LanPlan,
    outcomes: z.record(z.string(), z.unknown()),
});
type LanOutcomes = z.infer<typeof LanOutcomes>;
function verifyLanRules(
    plan: z.infer<typeof LanPlan>,
    outcomes: Record<string, unknown>,
): void {
    const observe = (name: string) =>
        z.object({
            status: z.literal("succeeded"),
            outputs: z.object({ stdout: z.string() }),
        }).parse(outcomes[name]).outputs.stdout;
    const needed = neededLanRules(plan, observe("observe-before"));
    const current = tupleKeys(observe("observe-after"));
    plan.rules.forEach((rule, index) => {
        if (needed.includes(rule.model_name)) {
            z.object({ status: z.literal("succeeded") }).parse(
                outcomes[`apply-${index}`],
            );
        }
        if (current.has(tupleKey(rule)) !== (rule.ensure === "present")) {
            throw new Error("firewall.tuple_unproven");
        }
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
type Context = {
    extensionFile(path: string): string;
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
export const behavior = {
    type: "@nckrtl/route-mutation-command",
    version: "2026.11.02.1",
    resources: {
        lan_changes: {
            schema: z.object({
                model_names: z.array(z.string()),
            }),
            lifetime: "infinite",
            garbageCollection: 10,
        },
        ready: {
            lifetime: "infinite",
            garbageCollection: 10,
            schema: z.object({
                ready: z.literal(true),
                result_json: z.string().optional(),
            }),
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
        lanChanges: {
            description:
                "Shape visible tuple commands from the pinned UFW observation",
            arguments: z.object({ plan: LanPlan, current: z.string() }),
            execute: async (
                args: { plan: z.infer<typeof LanPlan>; current: string },
                ctx: Context,
            ) => ({
                dataHandles: [
                    await ctx.writeResource("lan_changes", "changes", {
                        model_names: neededLanRules(args.plan, args.current),
                    }),
                ],
            }),
        },
        lanApplied: {
            description:
                "Prove official tuple state before legacy rules can be removed",
            arguments: LanOutcomes,
            execute: async (
                args: LanOutcomes,
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
            description: "Verify every official firewall tuple",
            arguments: LanOutcomes,
            execute: async (
                args: LanOutcomes,
                ctx: Context,
            ) => {
                verifyLanRules(args.plan, args.outcomes);
                const result = { ready: true as const };
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
    },
};
