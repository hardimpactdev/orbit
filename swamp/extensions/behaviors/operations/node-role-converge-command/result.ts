import { z } from "npm:zod@4.4.3";

const Code = z.string().regex(/^[a-z][a-z0-9_-]*\.[a-z0-9_.-]{1,90}$/);
export const Stage = z.enum([
    "observer-install",
    "caddy-package",
    "storage",
    "prerequisites",
    "javascript",
    "hibernation",
    "manager-vp",
    "manager-composer",
    "runtime",
    "gateway-dns",
    "ci-host",
    "firewall",
    "router-lan",
]);
const Baseline = z.object({
    stage: Stage,
    ok: z.boolean(),
    error_code: Code.optional(),
}).strict();
const Args = z.object({
    assignment_id: z.number().int().positive(),
    created: z.boolean(),
    baseline: z.array(Baseline).max(Stage.options.length),
    dependencies_ready: z.boolean(),
    runtime_completed: z.boolean(),
    publication_phases: z.array(
        z.object({
            phase: z.enum(["certificates_published", "caddy_published"]),
            node_id: z.number().int().positive(),
        }).strict(),
    ),
    footprint: z.object({
        footprint_proven: z.boolean(),
        artifacts: z.array(z.record(z.string(), z.unknown())),
        error_code: Code.optional(),
    }).passthrough(),
    cli: z.record(z.string(), z.unknown()),
}).strict();

/** Assemble the native receipt. Host actions and their ordering remain visible in the root. */
export function roleConvergeResult(raw: unknown) {
    const args = Args.parse(raw);
    const seen = new Set(args.baseline.map((entry) => entry.stage));
    if (seen.size !== args.baseline.length) {
        throw new Error("Role convergence has duplicate baseline receipts");
    }
    const failure = args.baseline.find((entry) => !entry.ok);
    const complete = seen.size === Stage.options.length;
    const targetCompleted = !failure && complete && args.dependencies_ready;
    const common = {
        assignment_id: args.assignment_id,
        created: args.created,
        baseline: args.baseline,
        runtime_completed: args.runtime_completed,
        publication_phases: args.publication_phases,
        role_proven: targetCompleted,
        target_completed: targetCompleted,
    };
    if (!targetCompleted) {
        return {
            ...common,
            error_code: failure
                ? failure.error_code ?? "node_role.baseline_failed"
                : (complete
                    ? "node_role.publication_failed"
                    : "node_role.baseline_uncertain"),
            failed_step: failure?.stage ??
                (complete ? "publication" : "baseline"),
        };
    }
    const followUps: string[] = [];
    if (!args.footprint.footprint_proven) {
        followUps.push(
            "role-footprint:" +
                (args.footprint.error_code ?? "node.footprint_failed"),
        );
    }
    if (args.cli.error_code !== undefined) {
        followUps.push("role-cli:" + Code.parse(args.cli.error_code));
    }
    return {
        ...common,
        footprint: args.footprint,
        cli: args.cli,
        follow_up: followUps.length ? followUps.join(", ") : null,
    };
}

const Output = z.object({ stdout: z.string(), ready: z.boolean() }).strict();
export const behavior = {
    type: "@nckrtl/node-role-converge-result",
    version: "2026.10.31.1",
    resources: {
        result: { schema: Output, lifetime: "1d", garbageCollection: 10 },
    },
    methods: {
        summarize: {
            description: "Return one public terminal role convergence receipt",
            arguments: Args,
            execute: async (args: z.infer<typeof Args>, ctx: {
                writeResource(
                    spec: string,
                    name: string,
                    value: unknown,
                ): Promise<unknown>;
            }) => {
                const receipt = roleConvergeResult(args);
                const result = {
                    stdout: JSON.stringify(receipt),
                    ready: receipt.role_proven,
                };
                await ctx.writeResource("result", "result", result);
                return result;
            },
        },
    },
};
