import { z } from "npm:zod@4.4.3";

/** Verify the admitted build before allowing the Node user's sudo action. */
export function assertAgent(
  file: Pick<Deno.FileInfo, "isFile" | "isSymlink" | "uid" | "mode">,
  digest: string,
  admitted: string,
) {
  if (
    !file.isFile || file.isSymlink || file.uid !== 0 || file.mode === null ||
    (file.mode & 0o022) !== 0 || digest !== admitted
  ) {
    throw new Error(
      "The local agent does not match its admitted root-owned build",
    );
  }
}

/** Fixed family commands on the local Node; never a Gateway transport. */
const Budgets = {
  "schedule.activate": 45000,
  "schedule.run": 15000,
  "schedule.remove": 60000,
  "process.control": 300000,
  "process.remove": 300000,
  "process.check-release": 10000,
  "process.status": 30000,
} as const;
export type LocalCommand = keyof typeof Budgets;
export async function localAgent(
  name: LocalCommand,
  input: Record<string, unknown>,
  admittedDigest: string,
  signal?: AbortSignal,
): Promise<Record<string, unknown>> {
  const binary = "/usr/local/bin/orbit-agent";
  const file = await Deno.lstat(binary);
  const digest = await crypto.subtle.digest(
    "SHA-256",
    await Deno.readFile(binary),
  );
  const pin = Array.from(
    new Uint8Array(digest),
    (byte) => byte.toString(16).padStart(2, "0"),
  ).join("");
  assertAgent(file, pin, admittedDigest);
  const deadline = Budgets[name];
  const bounded = AbortSignal.timeout(deadline);
  const process = new Deno.Command("/usr/bin/sudo", {
    args: ["-n", "--", binary, "command", name],
    stdin: "piped",
    stdout: "piped",
    stderr: "null",
    signal: signal ? AbortSignal.any([signal, bounded]) : bounded,
  }).spawn();
  try {
    const writer = process.stdin.getWriter();
    await writer.write(new TextEncoder().encode(
      JSON.stringify({
        agent_sha256: admittedDigest,
        deadline_ms: deadline,
        input,
      }) + "\n",
    ));
    await writer.close();
    const chunks: Uint8Array[] = [];
    let size = 0;
    for await (const chunk of process.stdout) {
      size += chunk.length;
      if (size > 16384) {
        throw new Error("Local Node action output is too large");
      }
      chunks.push(chunk);
    }
    const status = await process.status;
    const bytes = new Uint8Array(size);
    let offset = 0;
    for (const chunk of chunks) {
      bytes.set(chunk, offset);
      offset += chunk.length;
    }
    const reply = z.discriminatedUnion("ok", [
      z.object({
        ok: z.literal(true),
        data: z.record(z.string(), z.unknown()),
      }),
      z.object({
        ok: z.literal(false),
        error: z.object({ code: z.string().regex(/^[a-z0-9_.-]{1,100}$/) }),
      }),
    ]).parse(JSON.parse(new TextDecoder().decode(bytes)));
    if (!reply.ok) return { error_code: reply.error.code };
    if (!status.success) throw new Error("Local Node action failed");
    return reply.data;
  } catch {
    // Never include stdout, stderr, input or parser diagnostics in native history.
    throw new Error("Local Node action did not return a bounded result");
  } finally {
    try {
      process.kill("SIGKILL");
    } catch { /* already exited */ }
    await process.status;
  }
}
