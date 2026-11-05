import { z } from "npm:zod@4.4.3";

export const CliConvergePlan = z.object({
    user: z.string().min(1).max(64),
    release: z.object({
        version: z.string().min(1).max(128),
        url: z.url(),
        sha256: z.string().regex(/^[a-f0-9]{64}$/),
    }).strict().nullable(),
    profile: z.string().max(1024 * 1024),
    skip_reason: z.string().regex(/^[a-z][a-z0-9_]{0,63}$/).nullable(),
    plan_error: z.enum([
        "fleet.cli_plan_failed",
        "cli.architecture_unsupported",
        "cli.configuration_failed",
    ]).nullable(),
}).strict();
const Refusal = z.object({
    error_code: z.enum([
        "agent.version_mismatch",
        "cli.release_unavailable",
        "node.update_busy",
        "cli.download_failed",
        "cli.checksum_mismatch",
        "cli.candidate_invalid",
        "cli.install_failed",
        "operation.cancelled",
        ...CliConvergePlan.shape.plan_error.unwrap().options,
    ]),
}).strict();
export const CliConvergeResult = z.union([
    z.object({
        completed: z.literal(true),
        outcome: z.enum(["cli_installed", "cli_present", "skipped"]),
        reason: z.string().regex(/^[a-z][a-z0-9_]{0,63}$/).nullable(),
        version: z.string().min(1).max(128).nullable(),
        configured: z.boolean(),
    }).strict(),
    Refusal,
]);

export const CliNativeRun = z.object({
    status: z.string(),
    result: z.object({
        installed: z.string().nullable(),
        configured: z.boolean(),
        version: z.string(),
    }).strict().nullable(),
}).strict();

/** The outcome of the native `node-cli-install` child, in the public CLI result shape. */
export function nativeCliOutcome(
    raw: z.infer<typeof CliConvergePlan>,
    native: z.infer<typeof CliNativeRun>,
): z.infer<typeof CliConvergeResult> {
    const plan = CliConvergePlan.parse(raw), run = CliNativeRun.parse(native);
    if (plan.skip_reason !== null) {
        return {
            completed: true,
            outcome: "skipped",
            reason: plan.skip_reason,
            version: null,
            configured: false,
        };
    }
    if (plan.plan_error !== null) {
        return Refusal.parse({ error_code: plan.plan_error });
    }
    if (run.status !== "succeeded" || run.result === null) {
        return { error_code: "cli.install_failed" };
    }
    const { installed, configured } = run.result;
    if (installed !== null && installed !== plan.release?.version) {
        throw new Error("The CLI child installed a different version");
    }
    return CliConvergeResult.parse({
        completed: true,
        outcome: installed === null ? "cli_present" : "cli_installed",
        reason: null,
        version: installed,
        configured,
    });
}
