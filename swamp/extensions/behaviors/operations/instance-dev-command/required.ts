import { Step } from "./result.ts";
import { z } from "npm:zod@4.4.3";
type Steps = Record<string, z.infer<typeof Step>>;
export function requireActions(
    names: string[],
    counts: number[],
    steps: Steps,
): void {
    for (let index = 0; index < names.length; index++) {
        const base = names[index], count = counts[index];
        if (!Number.isSafeInteger(count) || count < 0) {
            throw new Error("instance.result_invalid");
        }
        const actual = Object.keys(steps).filter((key) =>
            key === base ||
            key.startsWith(base + "-") &&
                /^\d+$/.test(key.slice(base.length + 1))
        );
        if (
            actual.length !== count ||
            actual.some((key) => steps[key].status !== "succeeded")
        ) throw new Error("instance.action_incomplete");
    }
}
export function destroyResult(intent: Record<string, unknown>, steps: Steps) {
    const inspection = steps["destroy-inspect"]?.status === "succeeded"
        ? steps["destroy-inspect"].outputs
        : undefined;
    let failure: string | undefined;
    try {
        requireActions(
            [
                "destroy-verified",
                "destroy-runtime-ready",
                "destroy-teardown-ready",
                "destroy-database-ready",
                "withdraw",
                "remove-worktree-ready",
                "remove-checkout-ready",
            ],
            [1, 1, 1, 1, 1, 1, 1],
            steps,
        );
        if (steps["withdraw-0"]?.outputs?.ready !== true) throw new Error();
    } catch {
        failure = Object.entries(steps).find(([_, step]) =>
            step.status === "failed"
        )?.[0] ?? "source";
    }
    const result = {
        removed: failure === undefined,
        ...(inspection ? { source_inspection: inspection } : {}),
        ...(failure
            ? { failed_step: failure, error_code: "instance.remove_refused" }
            : {}),
    };
    return { ready: failure === undefined, stdout: JSON.stringify(result) };
}
