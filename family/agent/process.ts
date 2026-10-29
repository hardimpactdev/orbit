export class AgentCallError extends Error {
  constructor(readonly code: string) {
    super(code);
  }
}

export type ProcessRequest = {
  args: string[];
  input: string;
  signal: AbortSignal;
};
export type ProcessResult = { code: number; stdout: string };
export type AgentProcess = (request: ProcessRequest) => Promise<ProcessResult>;
export const STDOUT_LIMIT = 1024 * 1024;
const STDERR_LIMIT = 64 * 1024;

/** Capture only bounded stdout. Stderr is counted and discarded, never logged. */
export const agentProcess: AgentProcess = (request) =>
  captureProcess("/usr/bin/ssh", request);

/** Internal executor seam for subprocess tests; never a behavior argument. */
export async function captureProcess(
  binary: string,
  { args, input, signal }: ProcessRequest,
): Promise<ProcessResult> {
  if (signal.aborted) throw new AgentCallError("agent.transport_uncertain");
  const child = new Deno.Command(binary, {
    args,
    clearEnv: true,
    env: { PATH: "/usr/bin:/bin", LANG: "C" },
    stdin: "piped",
    stdout: "piped",
    stderr: "piped",
  }).spawn();
  const kill = () => {
    try {
      child.kill("SIGKILL");
    } catch { /* Already exited. */ }
  };
  let overflow = false;
  const drain = async (
    stream: ReadableStream<Uint8Array>,
    limit: number,
    capture: boolean,
  ) => {
    const chunks: Uint8Array[] = [];
    let bytes = 0;
    for await (const chunk of stream) {
      bytes += chunk.length;
      if (bytes > limit) {
        overflow = true;
        kill();
      } else if (capture && !overflow) chunks.push(chunk);
    }
    const output = new Uint8Array(capture ? Math.min(bytes, limit) : 0);
    let offset = 0;
    for (const chunk of chunks) {
      output.set(chunk, offset);
      offset += chunk.length;
    }
    return output.subarray(0, offset);
  };
  signal.addEventListener("abort", kill, { once: true });
  if (signal.aborted) kill();
  try {
    // Start both readers before writing: a full stderr pipe cannot block stdin.
    const stdout = drain(child.stdout, STDOUT_LIMIT, true);
    const stderr = drain(child.stderr, STDERR_LIMIT, false);
    const send = (async () => {
      const writer = child.stdin.getWriter();
      try {
        await writer.write(new TextEncoder().encode(input));
        await writer.close();
      } catch {
        kill();
        throw new AgentCallError("agent.transport_uncertain");
      } finally {
        writer.releaseLock();
      }
    })();
    const results = await Promise.allSettled([
      stdout,
      stderr,
      send,
      child.status,
    ]);
    if (overflow) throw new AgentCallError("agent.output_limit");
    if (
      signal.aborted || results.some((result) => result.status === "rejected")
    ) {
      throw new AgentCallError("agent.transport_uncertain");
    }
    const out = results[0];
    const status = results[3];
    if (out.status !== "fulfilled" || status.status !== "fulfilled") {
      throw new AgentCallError("agent.transport_uncertain");
    }
    return {
      code: status.value.code,
      stdout: new TextDecoder("utf-8", { fatal: true }).decode(out.value),
    };
  } catch (error) {
    kill();
    throw error instanceof AgentCallError
      ? error
      : new AgentCallError("agent.transport_uncertain");
  } finally {
    signal.removeEventListener("abort", kill);
  }
}
