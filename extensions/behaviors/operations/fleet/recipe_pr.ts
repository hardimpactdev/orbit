import { z } from "npm:zod@4.4.3";
import { Operation } from "../../operation-runner/mod.ts";
import type { Outcome } from "./recipe.ts";
const Input = z.object({
  action: z.enum(["open", "update", "merge", "retire"]),
  variant: z.enum(["snapshot", "artifacts"]),
  date: z.string(),
  pull_number: z.number().int().nonnegative(),
  head_sha: z.string(),
  token: z.object({
    $secret: z.literal("github.app"),
    id: z.number().int().positive(),
  }),
});
export async function executeRecipePr(
  operation: z.infer<typeof Operation>,
  post: (suffix: string, body: Record<string, unknown>) => Promise<unknown>,
): Promise<Outcome> {
  const input = Input.parse(operation.input);
  const saved = operation.step_results["recipe-pr"];
  if (saved) {
    return {
      status: "succeeded",
      result: z.record(z.string(), z.unknown()).parse(saved),
      error_code: null,
    };
  }
  await post("step", {
    key: "recipe-pr:start",
    name: "recipe-pr",
    status: "running",
  });
  const result = z.record(z.string(), z.unknown()).parse(
    await post("run", {
      key: "recipe-pr",
      transport: "gateway",
      name: "github.recipe-pr",
      input,
    }),
  );
  const error = typeof result.error_code === "string"
    ? result.error_code
    : result.completed === true
    ? null
    : "fleet.recipe_pr_failed";
  await post("step", {
    key: "recipe-pr:finish",
    name: "recipe-pr",
    status: error ? "failed" : "succeeded",
    result,
    error_code: error,
  });
  return {
    status: error === "operation.cancelled"
      ? "cancelled"
      : error
      ? "failed"
      : "succeeded",
    result,
    error_code: error,
  };
}
