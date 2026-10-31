import {
  type NodeCommand,
  run as runNodeCommand,
} from "../_lib/node-commands.ts";
import { z } from "npm:zod@4.4.3";

export const PhpVersion = z.string().regex(/^[0-9]+\.[0-9]+$/);
export const PackageName = z.string().regex(/^[a-z0-9][a-z0-9+.-]*$/);
export const PackageVersion = z.string().regex(/^[0-9][0-9A-Za-z.+:~_-]*$/);
export const Release = z.object({
  id: PackageName,
  codenames: z.array(PackageName).min(1),
}).strict();
export const Https = z.string().url().refine((s) => {
  const url = new URL(s);
  return url.protocol === "https:" && !!url.hostname && !url.username &&
    !url.password && !s.includes("#") &&
    !s.split("//", 2)[1]?.split(/[/?]/, 1)[0].includes("@") &&
    !/[\0\r\n]/.test(s);
});
export const BoundedNames = z.array(z.string().max(4096)).max(16384);
export type LocalPhpCommand = {
  executable: "sudo";
  args: string[];
  stdin: string;
};
export type LocalPhpRunner = (
  command: LocalPhpCommand,
  signal?: AbortSignal,
) => Promise<{ code: number; stdout: string }>;
export class PhpLocalError extends Error {
  constructor(readonly code: string, readonly restored?: boolean) {
    super(code);
    this.name = "PhpLocalError";
  }
}
/** Canonical placed transport; a cancelled proxy command has an uncertain outcome. */
export function nodePhpRunner(
  command: NodeCommand = runNodeCommand,
): LocalPhpRunner {
  return async (input, signal) => {
    signal?.throwIfAborted();
    if (
      input.executable !== "sudo" || input.args.length !== 6 ||
      input.args.slice(0, 5).join("\0") !==
        ["-n", "--", "python3", "-I", "-c"].join("\0")
    ) {
      throw new Error("php.local_command_invalid");
    }
    const output = await command(
      ["/usr/bin/python3", "-I", "-c", input.args[5]],
      new TextEncoder().encode(input.stdin),
      "root",
      // The program permits 880s for effects and another 120s for restoration.
      { signal, deadlineMs: 1020000, maxOutputBytes: 2097152 },
    );
    if (output.stdout.byteLength > 2097152) {
      throw new Error("php.local_output_exceeded");
    }
    return {
      code: output.code,
      stdout: new TextDecoder().decode(output.stdout),
    };
  };
}
const local = nodePhpRunner();
export async function runPhp<T>(
  input: unknown,
  program: string,
  result: z.ZodType<T>,
  errorCode: string,
  signal?: AbortSignal,
  runner: LocalPhpRunner = local,
): Promise<T> {
  try {
    signal?.throwIfAborted();
    const output = await runner({
      executable: "sudo",
      args: ["-n", "--", "python3", "-I", "-c", program],
      stdin: JSON.stringify({ input }),
    }, signal);
    if (output.code !== 0 || output.stdout.length > 2097152) {
      throw new PhpLocalError(errorCode);
    }
    const parsed = JSON.parse(output.stdout);
    const refusal = z.object({
      error_code: z.string().regex(
        /^php\.(build_[a-z_]+|runtime_[a-z_]+|shared_pool_[a-z_]+)$/,
      ),
      restored: z.boolean().optional(),
    }).strict().safeParse(parsed);
    if (refusal.success) {
      throw new PhpLocalError(refusal.data.error_code, refusal.data.restored);
    }
    return result.parse(parsed);
  } catch (error) {
    if (error instanceof PhpLocalError) throw error;
    throw new PhpLocalError(errorCode);
  }
}
