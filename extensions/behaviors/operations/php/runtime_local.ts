import { z } from "npm:zod@4.4.3";
import { productionRuntimeProgram } from "./runtime_program.ts";

const Name = z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/);
const Version = z.string().regex(/^[0-9]+\.[0-9]+$/);
const Identity = z.object({
  user: Name,
  version: Version,
  marker: z.string().min(1).max(256),
}).strict();
const Path = z.string().min(1).max(4096).startsWith("/").refine((p) =>
  !/[\0\r\n]/.test(p) && !p.split("/").includes("..")
);
const Text = z.string().max(1048576).refine((s) => !s.includes("\0"));
const Configuration = z.object({
  main: Text,
  pool: Text,
  local_defaults: Text,
  master_ini: Text,
  unit: Text,
  initial_pool: Text,
}).strict();
const ConvergeInput = Identity.extend({
  mode: z.enum(["converge", "monitor"]),
  home: Path,
  configuration: Configuration,
  application: Path,
  initial_application: Path,
}).strict();
const RefreshInput = Identity.extend({
  home: Path,
  deadline_seconds: z.number().int().min(1).max(30),
}).strict();
export type ProductionRuntimeInput = z.infer<typeof ConvergeInput>;
export type ProductionRuntimeIdentity = z.infer<typeof Identity>;
export type ProductionCacheInput = z.infer<typeof RefreshInput>;
export type LocalPhpRuntimeCommand = {
  executable: "sudo";
  args: string[];
  stdin: string;
};
export type LocalPhpRuntimeRunner = (
  command: LocalPhpRuntimeCommand,
  signal?: AbortSignal,
) => Promise<{ code: number; stdout: string }>;
export class PhpRuntimeError extends Error {
  constructor(readonly code: string) {
    super(code);
    this.name = "PhpRuntimeError";
  }
}
const local: LocalPhpRuntimeRunner = async (command, signal) => {
  const child = new Deno.Command(command.executable, {
    args: command.args,
    stdin: "piped",
    stdout: "piped",
    stderr: "null",
    signal,
  }).spawn();
  const writer = child.stdin.getWriter();
  try {
    await writer.write(new TextEncoder().encode(command.stdin));
    await writer.close();
    const result = await child.output();
    return { code: result.code, stdout: new TextDecoder().decode(result.stdout) };
  } finally {
    writer.releaseLock();
  }
};
async function run<T>(
  action: "converge" | "inspect" | "remove" | "refresh",
  input: Record<string, unknown>,
  result: z.ZodType<T>,
  signal?: AbortSignal,
  runner: LocalPhpRuntimeRunner = local,
): Promise<T> {
  try {
    const output = await runner({
      executable: "sudo",
      args: ["-n", "--", "python3", "-c", productionRuntimeProgram],
      stdin: JSON.stringify({ action, input }),
    }, signal);
    if (output.code !== 0 || output.stdout.length > 2097152) {
      throw new PhpRuntimeError("php.runtime_execution_failed");
    }
    const parsed = JSON.parse(output.stdout);
    const refusal = z.object({ error_code: z.string().regex(/^php\.[a-z_]+$/) })
      .strict().safeParse(parsed);
    if (refusal.success) throw new PhpRuntimeError(refusal.data.error_code);
    return result.parse(parsed);
  } catch (error) {
    if (error instanceof PhpRuntimeError) throw error;
    throw new PhpRuntimeError("php.runtime_execution_failed");
  }
}
/** Node-local FPM publication. Monitor mode changes only the pool with a journaled reload. */
export function convergeProductionRuntime(
  input: ProductionRuntimeInput,
  signal?: AbortSignal,
  runner?: LocalPhpRuntimeRunner,
): Promise<{ published: boolean }> {
  return run("converge", ConvergeInput.parse(input), z.object({
    published: z.boolean(),
  }).strict(), signal, runner);
}
/** Read the owned generated pool and its main+pool+local fingerprint. No service changes. */
export function inspectProductionPool(
  input: ProductionRuntimeIdentity,
  signal?: AbortSignal,
  runner?: LocalPhpRuntimeRunner,
): Promise<{ pool: string; fingerprint: string }> {
  return run("inspect", Identity.parse(input), z.object({
    pool: Text,
    fingerprint: z.string().regex(/^[a-f0-9]{64}$/),
  }).strict(), signal, runner);
}
/** Keep the marker and operator tuning so a later recreation cannot adopt foreign files. */
export function removeProductionRuntime(
  input: ProductionRuntimeIdentity,
  signal?: AbortSignal,
  runner?: LocalPhpRuntimeRunner,
): Promise<{ removed: boolean }> {
  return run("remove", Identity.parse(input), z.object({
    removed: z.boolean(),
  }).strict(), signal, runner);
}
/** Reset only this service's OPcache; validate FastCGI identity and observe completion. */
export function refreshProductionCache(
  input: ProductionCacheInput,
  signal?: AbortSignal,
  runner?: LocalPhpRuntimeRunner,
): Promise<{ complete: true }> {
  return run("refresh", RefreshInput.parse(input), z.object({
    complete: z.literal(true),
  }).strict(), signal, runner);
}
