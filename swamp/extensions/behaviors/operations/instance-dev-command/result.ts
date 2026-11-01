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
    const source = success("source-result")
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
    if (
        !failure &&
        commands.some((_, index) => {
            const step = steps["finish-setup-" + index + "-0"];
            return !success("finish-setup-" + index + "-0") ||
                step.outputs?.exitCode !== 0;
        })
    ) failure = "setup";
    const result = {
        instance_id: intent.instance_id,
        ready: failure === undefined,
        name_converted_from: intent.name_converted_from ?? null,
        ...(source ? { source } : {}),
        ...(seeded ? { environment_seeded_keys: seeded.keys } : {}),
        ...(environment ? { environment_keys: environment.keys } : {}),
        ...(requirements && success("vp-result") &&
                steps["vp-result"].outputs?.ready === true
            ? { vp_environment: runtime }
            : {}),
        ...(failure
            ? {
                failed_step: failure,
                error_code: failure === "source" &&
                        steps["source-branch-available"]?.outputs
                                ?.error_code === "instance.branch_in_use"
                    ? "instance.branch_in_use"
                    : "instance.create_" + failure + "_failed",
            }
            : {}),
    };
    return { ready: !failure, stdout: JSON.stringify(result) };
}
