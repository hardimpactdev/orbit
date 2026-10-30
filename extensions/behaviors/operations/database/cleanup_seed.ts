import { z } from "npm:zod@4.4.3";
import { Seed } from "./cleanup_contract.ts";

const Reply = z.discriminatedUnion("ok", [
  z.object({
    ok: z.literal(true),
    data: z.object({}).strict(),
  }),
  z.object({
    ok: z.literal(false),
    error: z.object({ code: z.string().regex(/^[a-z0-9_.-]{1,100}$/) }),
  }),
]);
type Input = Omit<z.infer<typeof Seed>, "agent_sha256" | "variant"> & {
  variant: "seed";
};
type Invoke = (pin: string, input: Record<string, unknown>) => Promise<unknown>;

/** Existing root primitive, cleanup mode only; no caller-selected command or argv. */
export function seedCleaner(invoke: Invoke = invokeAgent) {
  return async (
    pin: string,
    input: Input,
  ): Promise<Record<string, unknown>> => {
    const { target_instance_id: _, variant: __, ...safe } = Seed.parse({
      ...input,
      agent_sha256: pin,
    });
    const { agent_sha256: ___, ...commandInput } = safe;
    const raw = await invoke(pin, { ...commandInput, mode: "cleanup" });
    const parsed = Reply.safeParse(raw);
    if (!parsed.success) throw new Error("database.cleanup_reply_invalid");
    const reply = parsed.data;
    return reply.ok ? { backend: "absent" } : { error_code: reply.error.code };
  };
}

async function invokeAgent(
  pin: string,
  input: Record<string, unknown>,
): Promise<unknown> {
  const binary = "/usr/local/bin/orbit-agent";
  const file = await Deno.lstat(binary);
  if (
    !file.isFile || file.isSymlink || file.uid !== 0 || file.mode === null ||
    (file.mode & 0o022) !== 0
  ) {
    throw new Error("database.cleanup_agent_invalid");
  }
  const hash = Array.from(
    new Uint8Array(
      await crypto.subtle.digest("SHA-256", await Deno.readFile(binary)),
    ),
    (byte) => byte.toString(16).padStart(2, "0"),
  ).join("");
  if (hash !== pin) throw new Error("agent.version_mismatch");
  const child = new Deno.Command("/usr/bin/sudo", {
    args: ["-n", "--", binary, "command", "instance.prepare-sqlite-seed"],
    stdin: "piped",
    stdout: "piped",
    stderr: "null",
    signal: AbortSignal.timeout(120000),
  }).spawn();
  try {
    const writer = child.stdin.getWriter();
    await writer.write(
      new TextEncoder().encode(
        JSON.stringify({ agent_sha256: pin, deadline_ms: 120000, input }) +
          "\n",
      ),
    );
    await writer.close();
    const chunks: Uint8Array[] = [];
    let size = 0;
    for await (const chunk of child.stdout) {
      size += chunk.length;
      if (size > 16384) throw new Error("oversize");
      chunks.push(chunk);
    }
    await child.status;
    const bytes = new Uint8Array(size);
    let offset = 0;
    for (const chunk of chunks) {
      bytes.set(chunk, offset);
      offset += chunk.length;
    }
    return JSON.parse(new TextDecoder().decode(bytes));
  } catch {
    try {
      child.kill("SIGKILL");
    } catch { /* Already exited. */ }
    await child.status.catch(() => undefined);
    throw new Error("database.cleanup_outcome_unknown");
  }
}

export const cleanupSeed = seedCleaner();
