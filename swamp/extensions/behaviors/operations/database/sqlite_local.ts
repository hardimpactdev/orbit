import { localCommand } from "../_lib/local-worker.ts";
import { z } from "npm:zod@4.4.3";
import { userInfo } from "node:os";
import { sqliteProgram } from "./sqlite_program.ts";

const User = z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/);
const Reply = z.discriminatedUnion("ok", [
  z.object({ ok: z.literal(true), data: z.record(z.string(), z.unknown()) })
    .strict(),
  z.object({
    ok: z.literal(false),
    error_code: z.string().regex(/^[a-z0-9_.-]{1,100}$/),
  }).strict(),
]);
/** Only the four SQLite operations are accepted; no executable, source or arbitrary argv is input. */
export type SqliteCall = {
  action: "copy" | "seedSource" | "seedTarget" | "read";
  input: Record<string, unknown>;
};
export type SqliteInvoke = (
  call: SqliteCall,
  signal?: AbortSignal,
) => Promise<Record<string, unknown>>;
export function sqliteLaunch(call: SqliteCall) {
  const seconds = call.action === "copy"
    ? 600
    : call.action === "read"
    ? 30
    : 120;
  let sudo: string[] = [];
  if (call.action === "seedSource" || call.action === "seedTarget") {
    sudo = ["-n", "--", "/usr/bin/python3"];
  }
  if (call.action === "read") {
    const owner = User.parse(call.input.owner);
    if (owner !== userInfo().username) {
      sudo = ["-n", "-u", owner, "--", "/usr/bin/python3"];
    }
  }
  return {
    command: sudo.length ? "/usr/bin/sudo" : "/usr/bin/python3",
    args: [...sudo, "-I", "-c", sqliteProgram, call.action, String(seconds)],
    timeoutMs: (seconds + 5) * 1000,
  };
}

/** A fixed domain program bundled in every native method; private inputs stay on stdin. */
export const runSqliteLocal: SqliteInvoke = async (call, signal) => {
  signal?.throwIfAborted();
  if (
    !["copy", "seedSource", "seedTarget", "read"].includes(call.action)
  ) {
    throw new Error("database.sqlite_action_invalid");
  }
  const launch = sqliteLaunch(call);
  const bytes = new TextEncoder().encode(JSON.stringify(call.input));
  if (bytes.length > 65536) throw new Error("database.sqlite_input_invalid");
  const child = localCommand(launch.command, {
    args: launch.args,
    clearEnv: true,
    env: { PATH: "/usr/bin:/bin", LANG: "C.UTF-8" },
    stdin: "piped",
    stdout: "piped",
    stderr: "null",
    // Once a mutation starts, the fixed per-action deadline owns its completion.
    signal: AbortSignal.timeout(launch.timeoutMs),
  }).spawn();
  let finished = false;
  try {
    const input = child.stdin.getWriter();
    await input.write(bytes);
    await input.close();
    const chunks: Uint8Array[] = [];
    let size = 0;
    for await (const chunk of child.stdout) {
      size += chunk.length;
      if (size > 16384) throw new Error("database.sqlite_output_invalid");
      chunks.push(chunk);
    }
    const status = await child.status;
    finished = true;
    if (!status.success) throw new Error("database.sqlite_outcome_unknown");
    const output = new Uint8Array(size);
    let offset = 0;
    for (const chunk of chunks) {
      output.set(chunk, offset);
      offset += chunk.length;
    }
    const reply = Reply.parse(JSON.parse(new TextDecoder().decode(output)));
    return reply.ok ? reply.data : { error_code: reply.error_code };
  } catch {
    throw new Error("database.sqlite_outcome_unknown");
  } finally {
    if (!finished) {
      try {
        child.kill("SIGKILL");
      } catch { /* Exited already. */ }
      await child.status.catch(() => {});
    }
  }
};

export async function copySqlite(
  input: Record<string, unknown>,
  signal?: AbortSignal,
  invoke: SqliteInvoke = runSqliteLocal,
) {
  return await invoke({ action: "copy", input }, signal);
}
