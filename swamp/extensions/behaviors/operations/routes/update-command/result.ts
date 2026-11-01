import { Snapshot } from "../../firewall/inspection-result.ts";
import { z } from "npm:zod@4.4.3";
const Id = z.number().int().positive();
const Phase = z.object({
    ready: z.boolean(),
    migrated_node_ids: z.array(Id),
});
const States = z.record(
    z.string(),
    z.object({
        status: z.string(),
        outputs: z.object({ result: Phase.partial().optional() }).passthrough()
            .optional(),
    }).passthrough(),
);
const PhaseArgs = z.object({
    mode: z.enum(["forward", "restore", "withdraw"]),
    steps: States,
});
const FailureCode = z.enum([
    "route.update_failed",
    "route.target_set_failed",
    "route.create_failed",
    "route.withdrawal_failed",
]);
const Args = z.object({
    admitted: z.boolean().default(true),
    retained_on_nodes: z.array(
        z.object({ node_id: Id, steps: z.array(z.string()) }),
    ).default([]),
    failure_code: FailureCode.default("route.update_failed"),
    preparation_counts: z.record(z.string(), z.number().int().nonnegative())
        .default({}),
    effectful: z.boolean(),
    workload_count: z.number().int().nonnegative().default(0),
    route_id: Id,
    result_route_id: Id,
    steps: States,
});
const succeeded = (steps: z.infer<typeof States>, name: string) =>
    steps[name]?.status === "succeeded";
export function phaseResult(raw: unknown) {
    const { mode, steps } = PhaseArgs.parse(raw);
    return {
        ready: succeeded(steps, `${mode}-0`),
        migrated_node_ids: [
            ...new Set(
                Object.values(steps).flatMap((step) =>
                    step.status === "succeeded"
                        ? step.outputs?.result?.migrated_node_ids ?? []
                        : []
                ),
            ),
        ],
    };
}
export function updateResult(raw: unknown) {
    const {
        admitted,
        retained_on_nodes,
        effectful,
        workload_count,
        route_id,
        result_route_id,
        steps,
        failure_code,
        preparation_counts,
    } = Args
        .parse(raw);
    const forward = succeeded(steps, "forward")
        ? Phase.parse(steps.forward.outputs?.result)
        : undefined;
    const restore = succeeded(steps, "restore")
        ? Phase.parse(steps.restore.outputs?.result)
        : undefined;
    const all = (name: string, count = workload_count) =>
        Array.from(
            { length: count },
            (_, i) => succeeded(steps, `${name}-${i}`),
        ).every(Boolean);
    const ready = admitted && (!effectful ||
        forward?.ready === true && all("prepare") && all("retire") &&
            Object.entries(preparation_counts).every(([name, count]) =>
                all(name, count)
            ));
    const restored = !ready && restore?.ready === true &&
        all("restore-workload");
    const preparedMigrations = Object.entries(steps).filter(([name, step]) =>
        name.startsWith("prepare-lan-") && step.status === "succeeded"
    ).flatMap(([, step]) => step.outputs?.result?.migrated_node_ids ?? []);
    return {
        stdout: JSON.stringify({
            ready,
            restored,
            retained_on_nodes,
            route_id,
            result_route_id,
            selected: ready ? "after" : restored ? "before" : "unknown",
            migrated_node_ids: ready || restored
                ? [
                    ...new Set([
                        ...(ready
                            ? forward?.migrated_node_ids ?? []
                            : restore?.migrated_node_ids ?? []),
                        ...preparedMigrations,
                    ]),
                ]
                : [],
            ...(!ready ? { error_code: failure_code } : {}),
        }),
    };
}
const Output = z.object({ stdout: z.string() });
type Context = {
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
const Selection = z.object({
    intent: z.record(z.string(), z.unknown()),
    admitted: z.boolean(),
    retained_on_nodes: z.array(
        z.object({ node_id: Id, steps: z.array(z.string()) }),
    ),
});
type RemovalIntent = Record<string, unknown> & {
    effectful: boolean;
    offline: boolean;
    workloads: { node_id: number }[];
    route_mutations: {
        forward: Record<string, unknown> & {
            publications: { node_id: number; before: { dns: unknown } }[];
        };
        restore: Record<string, unknown>;
    };
};
export function selectRemoval(
    raw: { intent: Record<string, unknown>; steps: z.infer<typeof States> },
) {
    const intent = structuredClone(raw.intent) as RemovalIntent;
    const publications = intent.route_mutations.forward.publications;
    const missing = publications.filter((_part, i) =>
        !succeeded(raw.steps, `reachable-${i}`)
    );
    const admitted = missing.length === 0 ||
        intent.offline && missing.every((part) => part.before.dns === null);
    const excluded = missing.map((part) => part.node_id);
    for (const side of ["forward", "restore"] as const) {
        for (
            const [key, rows] of Object.entries(intent.route_mutations[side])
        ) {
            if (Array.isArray(rows)) {
                intent.route_mutations[side][key] = rows.filter((
                    row: { node_id?: number },
                ) => !excluded.includes(row.node_id ?? 0));
            }
        }
    }
    intent.workloads = intent.workloads.filter((row) =>
        !excluded.includes(row.node_id)
    );
    if (!admitted) {
        intent.effectful = false;
        intent.workloads = [];
    }
    return {
        intent,
        admitted: Boolean(admitted),
        retained_on_nodes: admitted
            ? missing.map((part) => ({
                node_id: part.node_id,
                steps: ["caddy", "php", "certificates", "firewall"],
            }))
            : [],
    };
}
export const behavior = {
    type: "@nckrtl/route-update-result",
    version: "2026.11.01.3",
    resources: {
        selection: {
            schema: Selection,
            lifetime: "infinite",
            garbageCollection: 10,
        },
        result: { schema: Output, lifetime: "infinite", garbageCollection: 10 },
        phase: { schema: Phase, lifetime: "infinite", garbageCollection: 10 },
    },
    methods: {
        select: {
            description:
                "Select admitted offline residue after native SSH readiness",
            arguments: z.object({
                intent: z.record(z.string(), z.unknown()),
                steps: States,
            }),
            execute: async (
                args: Parameters<typeof selectRemoval>[0],
                ctx: Context,
            ) => {
                const result = selectRemoval(args);
                await ctx.writeResource("selection", "selection", result);
                return result;
            },
        },
        upstream: {
            description:
                "Require an exact admitted allowance from the canonical firewall snapshot",
            arguments: z.object({ stdout: z.string() }),
            execute: async (args: { stdout: string }, ctx: Context) => {
                const snapshot = Snapshot.parse(JSON.parse(args.stdout));
                if (
                    snapshot.backend !== "active" ||
                    !snapshot.comparison.live.some((row) =>
                        row.match === "exact"
                    )
                ) {
                    throw new Error("route.upstream_firewall_mismatch");
                }
                const result = { ready: true, migrated_node_ids: [] };
                await ctx.writeResource("phase", "phase", result);
                return result;
            },
        },
        firewall: {
            description:
                "Return the canonical pinned tuple child's proven result",
            arguments: z.object({ ready: z.literal(true) }),
            execute: async (args: { ready: true }, ctx: Context) => {
                const result = { ...args, migrated_node_ids: [] };
                await ctx.writeResource("phase", "phase", result);
                return result;
            },
        },
        read: {
            description:
                "Report the native update outcome and complete restoration only",
            arguments: Args,
            execute: async (args: z.infer<typeof Args>, ctx: Context) => {
                const result = updateResult(args);
                await ctx.writeResource("result", "result", result);
                return result;
            },
        },
        phase: {
            description:
                "Keep exact Route contribution completeness across native children",
            arguments: PhaseArgs,
            execute: async (args: z.infer<typeof PhaseArgs>, ctx: Context) => {
                const result = phaseResult(args);
                await ctx.writeResource("phase", "phase", result);
                return result;
            },
        },
        complete: {
            description:
                "Fail the run after its public terminal result records incomplete work",
            arguments: Output,
            execute: async (args: z.infer<typeof Output>, ctx: Context) => {
                const result = z.object({
                    ready: z.boolean(),
                    error_code: FailureCode.optional(),
                }).parse(JSON.parse(args.stdout));
                if (!result.ready) {
                    throw new Error(result.error_code ?? "route.update_failed");
                }
                await ctx.writeResource("result", "result", args);
                return args;
            },
        },
    },
};
