import { z } from "npm:zod@4.4.3";

const Code = z.string().regex(/^[a-z0-9_.-]{1,100}$/);
export const DiscardInput = z.object({
  agent_sha256: z.string().regex(/^[a-f0-9]{64}$/),
  destination: z.string().refine((path) => {
    const parts = path.split("/");
    return parts[0] === "" && parts.length >= 4 &&
      parts.slice(1).every((part) =>
        part.length > 0 && ![".", ".."].includes(part) && !/\p{Cc}/u.test(part)
      );
  }),
}).strict();
export type Discard = (input: z.infer<typeof DiscardInput>) => Promise<unknown>;

/** No shell, foreign Node relay, caller argv, receipt or retry protocol. */
export async function discardDestination(input: z.infer<typeof DiscardInput>) {
  DiscardInput.parse(input);
  // The primitive removes a tree; never traverse a replaced ancestor symlink.
  const parts = input.destination.split("/").slice(1);
  for (let i = 1; i <= parts.length; i++) {
    try {
      const info = await Deno.lstat("/" + parts.slice(0, i).join("/"));
      if (!info.isDirectory || info.isSymlink) {
        return {
          ok: false,
          error: { code: "instance.transfer_archive_invalid" },
        };
      }
    } catch (error) {
      if (error instanceof Deno.errors.NotFound) return { ok: true, data: {} };
      throw error;
    }
  }
  const child = new Deno.Command("/usr/bin/sudo", {
    args: [
      "-n",
      "/usr/local/bin/orbit-agent",
      "command",
      "instance.transfer-source",
    ],
    stdin: "piped",
    stdout: "piped",
    stderr: "null",
  }).spawn();
  try {
    const writer = child.stdin.getWriter();
    await writer.write(new TextEncoder().encode(
      JSON.stringify({
        agent_sha256: input.agent_sha256,
        deadline_ms: 600_000,
        input: { operation: "discard", destination: input.destination },
      }) + "\n",
    ));
    await writer.close();
    const chunks: Uint8Array[] = [];
    let length = 0;
    for await (const chunk of child.stdout) {
      length += chunk.length;
      if (length > 16_384) {
        throw new Error("Discard response exceeded its bound");
      }
      chunks.push(chunk);
    }
    const status = await child.status;
    const bytes = new Uint8Array(length);
    let offset = 0;
    for (const chunk of chunks) {
      bytes.set(chunk, offset);
      offset += chunk.length;
    }
    const envelope: unknown = JSON.parse(new TextDecoder().decode(bytes));
    if (
      !status.success &&
      z.object({ ok: z.literal(true) }).safeParse(envelope).success
    ) {
      throw new Error("Discard outcome is uncertain");
    }
    return envelope;
  } finally {
    try {
      child.kill("SIGTERM");
    } catch { /* already exited */ }
    await child.status;
  }
}

export async function discard(input: Record<string, unknown>, action: Discard) {
  const result = z.union([
    z.object({ ok: z.literal(true), data: z.object({}).strict() }),
    z.object({ ok: z.literal(false), error: z.object({ code: Code }) }),
  ]).parse(await action(DiscardInput.parse(input)));
  return result.ok
    ? { discarded: true as const }
    : { error_code: result.error.code };
}
