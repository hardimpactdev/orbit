import { z } from "npm:zod@4.4.3";
import { Operation } from "../../operation-runner/mod.ts";
import type { Outcome } from "./recipe.ts";

const Input = z.object({
  user: z.string(),
  release: z.object({
    version: z.string(),
    url: z.string(),
    sha256: z.string(),
  }).nullable(),
  profile: z.string(),
  skip_reason: z.string().nullable(),
  plan_error: z.string().nullable(),
});
const Result = z.record(z.string(), z.unknown());
type Post = (suffix: string, body: Record<string, unknown>) => Promise<unknown>;

/** Optional Node child: install the admitted release or prove a Gateway-selected skip. */
export async function executeCliConverge(
  operation: z.infer<typeof Operation>,
  post: Post,
): Promise<Outcome> {
  const input = Input.parse(operation.input);
  const run = async (
    key: string,
    transport: string,
    name: string,
    body: Record<string, unknown>,
  ) => {
    const saved = operation.step_results[key];
    if (saved) return { result: Result.parse(saved), code: null };
    await post("step", { key: `${key}:start`, name: key, status: "running" });
    const result = Result.parse(
      await post("run", { key, transport, name, input: body }),
    );
    const code = result.error_code === "cli.update_busy"
      ? "node.update_busy"
      : result.error_code === "cli.release_needed"
      ? "cli.release_unavailable"
      : typeof result.error_code === "string"
      ? result.error_code
      : name === "cli.install" &&
          (!Object.hasOwn(result, "installed") ||
            typeof result.configured !== "boolean")
      ? "cli.install_failed"
      : name === "fleet.cli-converge-result" && result.completed !== true
      ? "fleet.cli_converge_failed"
      : null;
    await post("step", {
      key: `${key}:finish`,
      name: key,
      status: code ? "failed" : "succeeded",
      result,
      error_code: code,
    });
    return { result, code };
  };
  if (input.skip_reason === null && input.plan_error === null) {
    const install = await run("cli-install", "agent", "cli.install", {
      user: input.user,
      release: input.release,
      profile: input.profile,
    });
    if (install.code) {
      return {
        status: install.code === "operation.cancelled" ? "cancelled" : "failed",
        result: install.result,
        error_code: install.code,
      };
    }
  }
  const result = await run(
    "cli-converge",
    "gateway",
    "fleet.cli-converge-result",
    {},
  );
  return {
    status: result.code === "operation.cancelled"
      ? "cancelled"
      : result.code
      ? "failed"
      : "succeeded",
    result: result.result,
    error_code: result.code,
  };
}
