import { z } from "npm:zod@4.4.3";
import { phpProfileProgram } from "./profile_program.ts";
import {
  BoundedNames,
  LocalPhpRunner,
  PhpVersion,
  Release,
  runPhp,
} from "./local.ts";
export { PhpLocalError } from "./local.ts";
export type { LocalPhpCommand, LocalPhpRunner } from "./local.ts";
export const PhpRuntimeProfileInput = z.object({
  version: PhpVersion,
  runtime_ini: z.string().max(8192).refine((s) => !s.includes("\0")),
  release: Release,
}).strict();
export type ConfigurePhpRuntimeInput = z.infer<typeof PhpRuntimeProfileInput>;
const Result = z.object({
  changed: z.boolean(),
  reloaded: z.boolean(),
  deferred: BoundedNames,
}).strict();
export type ConfigurePhpRuntimeResult = z.infer<typeof Result>;
/** Publish the FPM-only runtime module and reload valid active masters. */
export function configurePhpRuntime(
  input: ConfigurePhpRuntimeInput,
  signal?: AbortSignal,
  runner?: LocalPhpRunner,
): Promise<ConfigurePhpRuntimeResult> {
  return runPhp(
    PhpRuntimeProfileInput.parse(input),
    phpProfileProgram,
    Result,
    "php.runtime_configuration_failed",
    signal,
    runner,
  );
}
