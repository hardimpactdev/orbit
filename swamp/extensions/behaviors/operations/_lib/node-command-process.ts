export type CommandLaunch = { command: string; args: string[] };

/** Fixed reviewed processes only. Private stdin and stdout drain concurrently. */
export async function commandOutput(
  launch: CommandLaunch,
  source: Uint8Array | undefined,
  maximum: number,
  signal: AbortSignal,
) {
  signal.throwIfAborted();
  const child = new Deno.Command(launch.command, {
    args: launch.args,
    stdin: source === undefined ? "null" : "piped",
    stdout: "piped",
    stderr: "null",
    signal,
  }).spawn();
  let complete = false;
  try {
    const writing = (async () => {
      if (source === undefined) return;
      const writer = child.stdin.getWriter();
      try {
        await writer.write(source);
        await writer.close();
      } finally {
        writer.releaseLock();
      }
    })();
    const reading = (async () => {
      const chunks: Uint8Array[] = [];
      let size = 0;
      for await (const bytes of child.stdout) {
        size += bytes.length;
        if (size > maximum) throw new Error();
        chunks.push(bytes);
      }
      const bytes = new Uint8Array(size);
      let offset = 0;
      for (const chunk of chunks) {
        bytes.set(chunk, offset);
        offset += chunk.length;
      }
      return bytes;
    })();
    const [, bytes, status] = await Promise.all([
      writing,
      reading,
      child.status,
    ]);
    signal.throwIfAborted();
    complete = true;
    return { stdout: bytes, success: status.success, code: status.code };
  } catch {
    throw new Error("The Node command did not return a bounded result");
  } finally {
    if (!complete) {
      try {
        child.kill("SIGTERM");
      } catch { /* Already exited. */ }
      await child.status.catch(() => {});
    }
  }
}
