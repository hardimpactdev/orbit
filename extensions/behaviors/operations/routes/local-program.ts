import { type NodeCommand, run } from "../_lib/node-commands.ts";
import { z } from "npm:zod@4.4.3";
/** Fixed Node-local program. The canonical worker transport selects direct or SSH execution. */
export async function runLocalProgram(
  program: string,
  input: Record<string, unknown>,
  signal?: AbortSignal,
  command: NodeCommand = run,
): Promise<Record<string, unknown>> {
  signal?.throwIfAborted();
  let result: Awaited<ReturnType<NodeCommand>>;
  try {
    result = await command(
      [
        "/usr/bin/timeout",
        "--signal=TERM",
        "--kill-after=5s",
        "120s",
        "/usr/bin/python3",
        "-I",
        "-c",
        program,
      ],
      new TextEncoder().encode(JSON.stringify(input)),
      "root",
      { deadlineMs: 130000, maxOutputBytes: 65536 },
    );
  } catch {
    throw new Error("Local publication outcome is uncertain");
  }
  if (result.stdout.length > 65536) {
    throw new Error("Local publication returned no bounded outcome");
  }
  if (!result.success) {
    throw new Error("Local publication outcome is uncertain");
  }
  try {
    return z.record(z.string(), z.unknown()).parse(
      JSON.parse(new TextDecoder().decode(result.stdout)),
    );
  } catch {
    throw new Error("Local publication returned no typed outcome");
  }
}
