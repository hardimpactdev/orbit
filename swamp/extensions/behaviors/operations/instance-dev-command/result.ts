import { z } from "npm:zod@4.4.3";
export const Public = z.record(z.string(), z.unknown());
export const Step = z.object({ status: z.string(), outputs: Public.optional() })
    .passthrough();
type Steps = Record<string, z.infer<typeof Step>>;
export function createResult(intent: Record<string, unknown>, steps: Steps) {
    if (intent.already_active === true) {
        return {
            ready: true,
            stdout: JSON.stringify({
                ready: true,
                instance_id: intent.instance_id,
                already_active: true,
            }),
        };
    }
    const success = (name: string) => steps[name]?.status === "succeeded";
    const source = success("source-result") &&
            steps["source-result"].outputs?.ready !== false
        ? steps["source-result"].outputs
        : undefined;
    const seeded = success("env-capture")
        ? steps["env-capture"].outputs
        : undefined;
    const environment = success("env-write") && success("env-capture")
        ? steps["env-capture"].outputs
        : undefined;
    const requirements = success("vp-requirements")
        ? steps["vp-requirements"].outputs
        : undefined;
    const runtime = requirements?.runtime;
    let failure = source
        ? !environment
            ? "environment"
            : !requirements || !success("vp-result") ||
                    steps["vp-result"].outputs?.ready !== true
            ? "vp"
            : undefined
        : "source";
    const commands = z.array(Public).parse(intent.setup_commands);
    let setupResult: Record<string, unknown> | undefined;
    if (
        success("finish-setup")
    ) {
        try {
            setupResult = Public.parse(
                JSON.parse(String(steps["finish-setup"].outputs?.stdout)),
            );
        } catch { /* No successful setup receipt. */ }
    }
    if (
        !failure &&
        (steps["finish-setup"]?.outputs?.exitCode !== 0 ||
            setupResult?.ready !== true ||
            JSON.stringify(setupResult.completed) !==
                JSON.stringify(commands.map((row) => row.name)))
    ) failure = "setup";
    const result = {
        instance_id: intent.instance_id,
        ready: failure === undefined,
        name_converted_from: intent.name_converted_from ?? null,
        ...(source ? { source } : {}),
        ...(source ? { environment_template: source.template } : {}),
        ...(seeded ? { environment_seeded_keys: seeded.keys } : {}),
        ...(environment ? { environment_keys: environment.keys } : {}),
        ...(requirements && success("vp-result") &&
                steps["vp-result"].outputs?.ready === true
            ? { vp_environment: runtime }
            : {}),
        ...(failure
            ? {
                failed_step: failure,
                ...(failure === "setup" &&
                        typeof setupResult?.failed_command === "string"
                    ? { failed_command: setupResult.failed_command }
                    : {}),
                error_code: failure === "source"
                    ? sourceError(steps)
                    : failure === "setup" &&
                            [
                                "operation.timed_out",
                                "instance.setup_step_failed",
                            ].includes(String(setupResult?.error_code))
                    ? setupResult?.error_code
                    : "instance.create_" + failure + "_failed",
            }
            : {}),
    };
    return { ready: !failure, stdout: JSON.stringify(result) };
}

function sourceError(steps: Steps): string {
    if (
        steps["source-result"]?.status === "succeeded" &&
        steps["source-result"].outputs?.ready === false
    ) {
        return String(steps["source-result"].outputs?.error_code);
    }
    const prepare = steps["create-prepare"];
    if (prepare?.status === "succeeded" && prepare.outputs?.exitCode !== 0) {
        try {
            const result = JSON.parse(String(prepare.outputs?.stdout));
            if (/^(?:instance|env|app-dev)\.[a-z_]+$/.test(result.error_code)) {
                return result.error_code;
            }
        } catch { /* No structured refusal was retained. */ }
    }
    return "instance.create_source_failed";
}
