import { z } from "npm:zod@4.4.3";

export type NodeCall = {
  name: string;
  input: Record<string, unknown>;
  pin: string;
  sudo: boolean;
  mutates: boolean;
  deadlineMs: number;
  maxOutputBytes?: number;
};
const binary = "/usr/local/bin/orbit-agent";
const Reply = z.discriminatedUnion("ok", [
  z.object({ ok: z.literal(true), data: z.record(z.string(), z.unknown()) }),
  z.object({
    ok: z.literal(false),
    error: z.object({
      code: z.string().regex(/^[a-z0-9_.-]{1,100}$/),
      details: z.object({
        restored: z.boolean().optional(),
        stage: z.string().optional(),
      }).optional(),
    }),
  }),
]);

/** Fixed local argv and the admitted digest; never shell source or a broker. */
export function agentLaunch(call: NodeCall) {
  return {
    command: call.sudo ? "/usr/bin/sudo" : binary,
    args: [...(call.sudo ? ["-n", "--", binary] : []), "command", call.name],
    envelope: {
      agent_sha256: call.pin,
      deadline_ms: call.deadlineMs,
      input: call.input,
    },
  };
}

export function agentReply(bytes: Uint8Array, success: boolean) {
  let reply: z.infer<typeof Reply>;
  try {
    reply = Reply.parse(JSON.parse(new TextDecoder().decode(bytes)));
  } catch {
    throw new Error("The Node action returned no typed result");
  }
  if (!reply.ok) {
    const restored = reply.error.details?.restored;
    return {
      error_code: reply.error.code,
      ...(restored === undefined ? {} : { restored }),
      ...(reply.error.code === "caddy.publication_failed" &&
          reply.error.details?.stage === "validate"
        ? { stage: "validate" }
        : {}),
    };
  }
  if (!success) throw new Error("The Node action outcome is uncertain");
  return reply.data;
}

/** Agent supervision bounds the effect; native workflow recovery owns uncertainty. */
export async function runNodeAgent(call: NodeCall, signal?: AbortSignal) {
  signal?.throwIfAborted();
  for (const path of ["/usr", "/usr/local", "/usr/local/bin", binary]) {
    const file = await Deno.lstat(path);
    if (
      file.isSymlink || file.uid !== 0 || file.mode === null ||
      (file.mode & 0o022) !== 0 ||
      (path === binary ? !file.isFile : !file.isDirectory)
    ) throw new Error("The local agent path is not root owned");
  }
  const digest = new Uint8Array(
    await crypto.subtle.digest("SHA-256", await Deno.readFile(binary)),
  );
  const observed = Array.from(digest, (b) => b.toString(16).padStart(2, "0"))
    .join("");
  if (observed !== call.pin) return { error_code: "agent.version_mismatch" };
  signal?.throwIfAborted();
  const launch = agentLaunch(call);
  const timeout = AbortSignal.timeout(call.deadlineMs + 5000);
  const child = new Deno.Command(launch.command, {
    args: launch.args,
    stdin: "piped",
    stdout: "piped",
    stderr: "null",
    // Once an effect starts, let the supervised agent finish and project it.
    signal: !call.mutates && signal
      ? AbortSignal.any([signal, timeout])
      : timeout,
  }).spawn();
  let finished = false;
  try {
    const writer = child.stdin.getWriter();
    await writer.write(
      new TextEncoder().encode(JSON.stringify(launch.envelope) + "\n"),
    );
    await writer.close();
    const chunks: Uint8Array[] = [];
    let size = 0;
    for await (const chunk of child.stdout) {
      size += chunk.length;
      if (size > (call.maxOutputBytes ?? 4 * 1024 * 1024)) {
        throw new Error("The Node action output exceeded its bound");
      }
      chunks.push(chunk);
    }
    const status = await child.status;
    finished = true;
    const bytes = new Uint8Array(size);
    let offset = 0;
    for (const chunk of chunks) {
      bytes.set(chunk, offset);
      offset += chunk.length;
    }
    return agentReply(bytes, status.success);
  } finally {
    if (!finished) {
      try {
        child.kill("SIGTERM");
      } catch { /* The bounded agent may already have stopped. */ }
      await child.status.catch(() => {});
    }
  }
}
