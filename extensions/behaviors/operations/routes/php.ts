import { z } from "npm:zod@4.4.3";
import { runLocalProgram } from "./local-program.ts";
import { PhpSharedProgram } from "./php-shared-program.ts";
export const PhpPublication = z.object({
  node_id: z.number().int().positive(),
  configurations: z.record(z.string(), z.string()),
});
/** Converge every stored shared pool, including the empty final set, on this exact worker. */
export async function publishPhp(
  raw: unknown,
  invoke = runLocalProgram,
  signal?: AbortSignal,
) {
  const input = PhpPublication.parse(raw);
  const result = await invoke(PhpSharedProgram, {
    configurations: input.configurations,
  }, signal);
  if (result.error_code !== undefined) {
    return {
      error_code: z.string().regex(/^[a-z0-9_.-]{1,100}$/).parse(
        result.error_code,
      ),
    };
  }
  const outcome = z.object({
    changed: z.boolean(),
    missing_pools: z.array(z.unknown()),
  }).parse(result);
  return outcome.missing_pools.length === 0
    ? { ready: true }
    : { error_code: "app-dev.php_pool_missing" };
}
