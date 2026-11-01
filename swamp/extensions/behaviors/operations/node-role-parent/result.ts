import { z } from "npm:zod@4.4.3";
import { RoleConvergeContribution } from "../node-role-converge-command/result.ts";
const Step = z.object({
    status: z.string(),
    outputs: z.record(z.string(), z.unknown()).optional(),
}).passthrough();
const Args = z.object({
    kind: z.enum(["node.role_add", "node.role_relocate"]),
    steps: z.record(z.string(), Step),
}).strict();
export function parentResult(raw: unknown) {
    const { kind, steps } = Args.parse(raw);
    const parsed = RoleConvergeContribution.safeParse(
        steps.role_result?.status === "succeeded"
            ? steps.role_result.outputs
            : undefined,
    );
    type Contribution = z.infer<typeof RoleConvergeContribution>;
    type ParentArtifact = {
        name: string;
        status: "applied" | "unchanged" | "skipped";
        reason?: string;
    };
    const target:
        | (Omit<Contribution, "footprint"> & {
            footprint: Omit<Contribution["footprint"], "artifacts"> & {
                artifacts: ParentArtifact[];
            };
        })
        | null = parsed.success ? parsed.data : null;
    if (
        target !== null && steps.route_residue &&
        steps.route_residue.status !== "skipped"
    ) {
        const receipt = steps.route_residue.status === "succeeded"
            ? steps.route_residue.outputs?.result as
                | Record<string, unknown>
                | undefined
            : undefined;
        const completed = receipt?.ready === true;
        target.footprint.artifacts.push({
            name: "route-residue",
            status: completed
                ? (receipt.changed ? "applied" : "unchanged")
                : "skipped",
            ...(!completed ? { reason: "route_residue_cleanup_failed" } : {}),
        });
    }
    const target_completed = target !== null && target.role_proven &&
        target.runtime_proven && target.certificates_proven &&
        target.publication_proven;
    const source_completed = kind === "node.role_relocate" &&
        steps.source_cleanup?.status === "succeeded" &&
        z.object({ role_removed: z.literal(true) }).passthrough().safeParse(
            steps.source_cleanup.outputs?.result,
        ).success;
    const target_rollback_completed =
        steps.target_rollback?.status === "succeeded" &&
        z.object({ role_removed: z.literal(true) }).passthrough().safeParse(
            steps.target_rollback.outputs?.result,
        ).success;
    const rollback_failed = ["failed", "cancelled"].includes(
        steps.target_rollback?.status ?? "skipped",
    );
    const ready = !rollback_failed && target_completed &&
        (kind === "node.role_add" || source_completed);
    const failure = Object.entries(steps).find(([, step]) =>
        ["failed", "cancelled"].includes(step.status)
    );
    const error_code = ready
        ? undefined
        : rollback_failed
        ? "websocket.rollback_failed"
        : !target_completed
        ? target?.error_code ?? "node_role.convergence_failed"
        : "node_role.source_cleanup_failed";
    const result = {
        ready,
        target_completed,
        source_completed,
        target_rollback_completed,
        target,
        ...(!ready
            ? {
                error_code,
                failed_step: failure?.[0] ??
                    (target_completed ? "source" : "target"),
            }
            : {}),
    };
    return { ...result, stdout: JSON.stringify(result) };
}
export const behavior = {
    type: "@nckrtl/node-role-parent-result",
    version: "2026.11.01.3",
    resources: {
        result: {
            schema: z.record(z.string(), z.unknown()),
            lifetime: "1d",
            garbageCollection: 10,
        },
    },
    methods: {
        assemble: {
            description:
                "Keep completed target facts when later source cleanup fails",
            arguments: Args,
            execute: async (
                args: z.infer<typeof Args>,
                ctx: {
                    writeResource(
                        spec: string,
                        name: string,
                        value: unknown,
                    ): Promise<unknown>;
                },
            ) => {
                const result = parentResult(args);
                await ctx.writeResource("result", "result", result);
                return result;
            },
        },
    },
};
