import { localCommand } from "../_lib/local-worker.ts";
import { z } from "npm:zod@4.4.3";
/** Fixed repository-owned local program, bounded by native execution. Public input is data only. */
export async function runLocalProgram(
  program: string,
  input: Record<string, unknown>,
  signal?: AbortSignal,
): Promise<Record<string, unknown>> {
  signal?.throwIfAborted();
  const child = localCommand("/usr/bin/sudo", {
    args: [
      "-n",
      "--",
      "/usr/bin/timeout",
      "--signal=TERM",
      "--kill-after=5s",
      "120s",
      "/usr/bin/python3",
      "-I",
      "-c",
      program,
    ],
    stdin: "piped",
    stdout: "piped",
    stderr: "null",
    signal: AbortSignal.timeout(130000),
  }).spawn();
  const writer = child.stdin.getWriter();
  await writer.write(new TextEncoder().encode(JSON.stringify(input)));
  await writer.close();
  const chunks: Uint8Array[] = [];
  let size = 0;
  for await (const chunk of child.stdout) {
    size += chunk.length;
    if (size > 65536) {
      child.kill("SIGTERM");
      throw new Error("Local publication returned no bounded outcome");
    }
    chunks.push(chunk);
  }
  const status = await child.status;
  if (!status.success) {
    throw new Error("Local publication outcome is uncertain");
  }
  const bytes = new Uint8Array(size);
  let offset = 0;
  for (const chunk of chunks) {
    bytes.set(chunk, offset);
    offset += chunk.length;
  }
  try {
    return z.record(z.string(), z.unknown()).parse(
      JSON.parse(new TextDecoder().decode(bytes)),
    );
  } catch {
    throw new Error("Local publication returned no typed outcome");
  }
}
