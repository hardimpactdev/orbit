import { z } from "npm:zod@4.4.3";
import { installCli } from "./cli_install.ts";
import { cliInstallError } from "./contract.ts";

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
export const CliConvergeLocalInput = CliConvergePlan.extend({
    agent_sha256: z.string().regex(/^[a-f0-9]{64}$/),
});
const Refusal = z.object({
    error_code: z.enum([
        "agent.version_mismatch",
        "cli.foreign_binary",
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

/** One local action in its original placed root; no Gateway or child protocol. */
export async function convergeCli(
    raw: z.infer<typeof CliConvergeLocalInput>,
    { signal, beforeEffect, invoke = installCli, source }: {
        signal?: AbortSignal;
        source?: string;
        beforeEffect?: () => Promise<void>;
        invoke?: (
            input: Parameters<typeof installCli>[0],
            options?: Parameters<typeof installCli>[1],
        ) => Promise<unknown>;
    } = {},
): Promise<z.infer<typeof CliConvergeResult>> {
    const input = CliConvergeLocalInput.parse(raw);
    signal?.throwIfAborted();
    if (input.skip_reason !== null) {
        return {
            completed: true,
            outcome: "skipped",
            reason: input.skip_reason,
            version: null,
            configured: false,
        };
    }
    if (input.plan_error !== null) {
        return Refusal.parse({ error_code: input.plan_error });
    }
    await beforeEffect?.();
    signal?.throwIfAborted();
    const result = z.record(z.string(), z.unknown()).parse(
        await invoke({
            user: input.user,
            release: input.release,
            profile: input.profile,
        }, { signal, source }),
    );
    const code = cliInstallError(result);
    if (code !== null) {
        const refusal = Refusal.safeParse({ error_code: code });
        if (!refusal.success) {
            throw new Error("The CLI action returned an unknown refusal");
        }
        return refusal.data;
    }
    if (
        result.installed !== null && result.installed !== input.release?.version
    ) {
        throw new Error(
            "The CLI action returned a different installed version",
        );
    }
    return CliConvergeResult.parse({
        completed: true,
        outcome: result.installed === null ? "cli_present" : "cli_installed",
        reason: null,
        version: result.installed,
        configured: result.configured,
    });
}
