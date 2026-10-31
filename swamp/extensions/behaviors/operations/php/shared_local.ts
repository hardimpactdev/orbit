import { z } from "npm:zod@4.4.3";
import { phpSharedProgram } from "./shared_program.ts";
import { LocalPhpRunner, PhpVersion, runPhp } from "./local.ts";
export { PhpLocalError } from "./local.ts";
export type { LocalPhpCommand, LocalPhpRunner } from "./local.ts";
const Input = z.object({
  configurations: z.record(
    PhpVersion,
    z.string().max(1048576).refine((s) => !s.includes("\0")),
  ),
}).strict();
export type ConvergeSharedPoolsInput = z.infer<typeof Input>;
const Result = z.object({
  changed: z.boolean(),
  missing_pools: z.array(
    z.object({
      pool: z.string().max(256),
      version: PhpVersion,
      directory: z.string().max(4096),
      installed: z.boolean(),
    }).strict(),
  ).max(16384),
}).strict();
export type ConvergeSharedPoolsResult = z.infer<typeof Result>;
/** Validate the complete shared-pool transition before ordered publication and recovery. */
export function convergeSharedPools(
  input: ConvergeSharedPoolsInput,
  signal?: AbortSignal,
  runner?: LocalPhpRunner,
): Promise<ConvergeSharedPoolsResult> {
  return runPhp(
    Input.parse(input),
    phpSharedProgram,
    Result,
    "php.shared_pool_failed",
    signal,
    runner,
  );
}
