import { z } from "npm:zod@4.4.3";
import { environmentProgram } from "./local_runtime.ts";

const Input = z.object({
  base: z.string().startsWith("/").max(4096),
  user: z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/).refine((user) =>
    user !== "root"
  ),
  maximum: z.literal(1048576),
  require_home: z.boolean(),
  required_capacity: z.number().int().nonnegative(),
});
const WriteInput = Input.extend({
  mode: z.enum(["write", "merge"]),
  file: z.enum([".env", ".env.testing"]),
  managed_keys: z.array(z.string()).max(16384),
  content: z.string(),
}).strict();
export type EnvironmentActionName =
  | "instance.inspect-environment-sync"
  | "instance.write-environment";
export type EnvironmentInvoke = (
  action: {
    name: EnvironmentActionName;
    input: Record<string, unknown>;
  },
  signal?: AbortSignal,
) => Promise<Record<string, unknown>>;

/** The exact two environment filesystem operations; no command, binary or argv from input. */
export const invokeLocalEnvironment: EnvironmentInvoke = async (
  action,
  signal,
) => {
  const data = action.name === "instance.inspect-environment-sync"
    ? { ...Input.strict().parse(action.input), mode: "inspect" }
    : action.name === "instance.write-environment"
    ? WriteInput.parse(action.input)
    : undefined;
  if (data === undefined) throw new Error("env.configuration_invalid");
  signal?.throwIfAborted();
  const child = new Deno.Command("/usr/bin/sudo", {
    args: ["-n", "--", "/usr/bin/python3", "-I", "-c", environmentProgram],
    cwd: "/",
    clearEnv: true,
    env: { PATH: "/usr/bin:/bin", LANG: "C.UTF-8" },
    stdin: "piped",
    stdout: "piped",
    stderr: "null",
  }).spawn();
  const abort = () => {
    try {
      child.kill("SIGTERM");
    } catch { /* already exited */ }
  };
  signal?.addEventListener("abort", abort, { once: true });
  if (signal?.aborted) abort();
  const timer = setTimeout(abort, 60000);
  let size = 0;
  const parts: Uint8Array[] = [];
  try {
    const input = child.stdin.getWriter();
    await input.write(new TextEncoder().encode(JSON.stringify(data)));
    await input.close();
    for await (const part of child.stdout) {
      size += part.length;
      if (size > 2097152) throw new Error();
      parts.push(part);
    }
    if (!(await child.status).success) throw new Error();
    const bytes = new Uint8Array(size);
    let offset = 0;
    for (const part of parts) {
      bytes.set(part, offset);
      offset += part.length;
    }
    return z.record(z.string(), z.unknown()).parse(
      JSON.parse(new TextDecoder().decode(bytes)),
    );
  } catch {
    throw new Error("env.sync_unconfirmed");
  } finally {
    clearTimeout(timer);
    signal?.removeEventListener("abort", abort);
    abort();
    await child.status;
  }
};
