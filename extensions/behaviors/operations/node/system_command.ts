/** Internal transport for code-owned programs. Never accept command source from an intent. */
export type SystemCommand = {
  command: string;
  args: readonly string[];
  stdin?: string;
  timeoutMs: number;
};
export type SystemOutput = { success: boolean; stdout: string };
export type SystemTransport = (
  command: SystemCommand,
  signal?: AbortSignal,
) => Promise<SystemOutput>;

/** Once a mutation starts, the caller reconciles its outcome before observing cancellation. */
export const localSystemCommand: SystemTransport = async (input, signal) => {
  signal?.throwIfAborted();
  const child = new Deno.Command(input.command, {
    args: [...input.args],
    stdin: input.stdin === undefined ? "null" : "piped",
    stdout: "piped",
    stderr: "null",
    signal: AbortSignal.timeout(input.timeoutMs),
  }).spawn();
  let finished = false;
  try {
    if (input.stdin !== undefined) {
      const writer = child.stdin.getWriter();
      await writer.write(new TextEncoder().encode(input.stdin));
      await writer.close();
    }
    const chunks: Uint8Array[] = [];
    let length = 0;
    for await (const chunk of child.stdout) {
      length += chunk.length;
      if (length > 65536) {
        throw new Error("System command output exceeded its bound");
      }
      chunks.push(chunk);
    }
    const status = await child.status;
    finished = true;
    const bytes = new Uint8Array(length);
    let offset = 0;
    for (const chunk of chunks) {
      bytes.set(chunk, offset);
      offset += chunk.length;
    }
    return { success: status.success, stdout: new TextDecoder().decode(bytes) };
  } catch {
    throw new Error("The system command has no confirmed outcome");
  } finally {
    if (!finished) {
      try {
        child.kill("SIGTERM");
      } catch { /* Already exited. */ }
      await child.status.catch(() => {});
    }
  }
};

/** Adapter shared with fixed network/bootstrap programs; private input stays on stdin. */
export function systemArguments(
  transport: SystemTransport = localSystemCommand,
) {
  return (argv: readonly string[], stdin?: string, signal?: AbortSignal) => {
    if (argv.length === 0) throw new Error("The system command is missing");
    return transport({
      command: argv[0],
      args: argv.slice(1),
      stdin,
      timeoutMs: 420000,
    }, signal);
  };
}
