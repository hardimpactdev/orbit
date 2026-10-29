import { z } from "npm:zod@4.4.3";
import { type Context, Operation } from "../../operation-runner/mod.ts";
import { Completion } from "./contract.ts";

const RecipeInput = z.object({
  node_id: z.number().int().positive(),
  automatic: z.boolean(),
  has_php: z.boolean(),
  vpn: z.boolean(),
  user: z.string(),
  identity: z.string(),
  agent_sha256: z.string(),
  cli_version: z.string(),
}).passthrough();
type Post = (suffix: string, body: Record<string, unknown>) => Promise<unknown>;
export type Outcome = z.infer<typeof Completion>;
export async function executeRecipe(
  operation: z.infer<typeof Operation>,
  ctx: Context,
  post: Post,
  readChild: (id: number) => Promise<{ status: string }>,
): Promise<Outcome | null> {
  const input = RecipeInput.parse(operation.input);
  if (input.node_id !== operation.node_id) throw new Error("Wrong recipe Node");
  const priorFailure = operation.steps.find((s) => s.status === "failed");
  if (priorFailure) {
    // The containing behavior reads the persisted failure code before entering here.
    throw new Error("Recipe has a failed step without a completion receipt");
  }
  const fields = (names: string[]) =>
    Object.fromEntries(names.map((n) => [n, input[n]]));
  const recipe = fields([
    "user",
    "snapshot",
    "git_packages",
    "artifact_mirror",
    "architecture",
    "tools",
    "vp_lock",
  ]);
  const fail = (code: string, result: Record<string, unknown>): Outcome => ({
    status: code === "operation.cancelled" ? "cancelled" : "failed",
    result,
    error_code: code,
  });
  const step = async (
    name: string,
    transport: string,
    command: string,
    commandInput: Record<string, unknown>,
    errorOf: (r: Record<string, unknown>) => string | null,
  ): Promise<{ result: Record<string, unknown>; error: string | null }> => {
    const saved = operation.step_results[name];
    if (saved) {
      return {
        result: z.record(z.string(), z.unknown()).parse(saved),
        error: null,
      };
    }
    await post("step", { key: `${name}:start`, name, status: "running" });
    const result = z.record(z.string(), z.unknown()).parse(
      await post("run", {
        key: name,
        transport,
        name: command,
        input: commandInput,
      }),
    );
    const error = typeof result.error_code === "string"
      ? result.error_code
      : errorOf(result);
    await post("step", {
      key: `${name}:finish`,
      name,
      status: error ? "failed" : "succeeded",
      result,
      error_code: error,
    });
    return { result, error };
  };
  if (!input.automatic) {
    const check = await step(
      "incus-preflight",
      "agent",
      "recipe.incus-preflight",
      recipe,
      (r) => r.ready === true ? null : "node.recipe_incus_busy",
    );
    if (check.error) return fail(check.error, check.result);
  }
  const begin = await step(
    "recipe-begin",
    "gateway",
    "fleet.recipe-begin",
    {},
    (r) => r.started === true ? null : "node.recipe_failed",
  );
  if (begin.error) return fail(begin.error, begin.result);
  const applied = await step(
    "recipe-apply",
    "agent",
    "recipe.apply",
    recipe,
    (r) => r.healthy === true ? null : "node.recipe_health_failed",
  );
  if (applied.error) return fail(applied.error, applied.result);
  if (input.has_php) {
    const php = await step(
      "php-build",
      "agent",
      "php.install-build",
      fields([
        "versions",
        "mirror",
        "pcov",
        "opentelemetry",
        "extras",
        "fpm",
        "repack",
        "release",
      ]),
      (r) =>
        ["current", "applied"].includes(String(r.outcome))
          ? null
          : "node.recipe_php_failed",
    );
    if (php.error) return fail(php.error, php.result);
    if (input.vpn && php.result.outcome === "applied") {
      const resource = `operation-${operation.id}-footprint`;
      let child = await ctx.readResource(resource);
      if (!child) {
        // Save the exact request before admission. The shared child boundary is keyed to its parent.
        child = { key: "footprint" };
        await ctx.writeResource("child", resource, child);
      }
      let id = typeof child.id === "number" ? child.id : null;
      if (id === null) {
        const admitted = z.object({ id: z.number().int().positive() }).parse(
          await post("children", { key: "footprint" }),
        );
        id = admitted.id;
        await ctx.writeResource("child", resource, { ...child, id });
      }
      const status = (await readChild(id)).status;
      if (!["succeeded", "failed", "cancelled"].includes(status)) return null;
      if (status !== "succeeded") return fail("node.recipe_dns_failed", {});
    }
  }
  const verified = await step("verify", "gateway", "fleet.verify", {
    mode: "recipe",
  }, (r) => r.passed === true ? null : "node.recipe_doctor_failed");
  if (verified.error) return fail(verified.error, verified.result);
  const delivered = await step(
    "recipe-delivery",
    "agent",
    "recipe.verify-delivery",
    fields(["user", "identity", "agent_sha256", "cli_version"]),
    (r) =>
      r.identity === input.identity && r.agent_sha256 === input.agent_sha256 &&
        r.cli_version === input.cli_version
        ? null
        : "node.recipe_delivery_failed",
  );
  if (delivered.error) return fail(delivered.error, delivered.result);

  return { status: "succeeded", result: delivered.result, error_code: null };
}
