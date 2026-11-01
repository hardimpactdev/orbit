import { localCommand } from "../_lib/local-worker.ts";
import { z } from "npm:zod@4.4.3";

/** Fixed database action envelope. No agent binary, pin, request or receipt is used. */
export type DatabaseSqlCall = {
  name: string;
  input: Record<string, unknown>;
  sudo?: boolean;
  mutates?: boolean;
  deadlineMs: number;
  maxOutputBytes?: number;
};
export type SqlOutcome = { output: string } | Record<string, never> | {
  error_code:
    | "database.server_admin_invalid"
    | "database.server_admin_failed"
    | "database.server_not_ready";
};
const Container = z.string().min(1).max(255).regex(
  /^[A-Za-z0-9][A-Za-z0-9_.-]*$/,
);
const Password = z.string().max(16384).refine((p) => !p.includes("\0"));
const Name = z.string().regex(/^[A-Za-z0-9_]{1,64}$/);
const Client = z.array(z.string().max(128)).min(1).max(16).refine((args) => {
  if (!["mysql", "psql"].includes(args[0])) return false;
  const pg = args[0] === "psql";
  const fixed = pg
    ? new Set([
      "--no-psqlrc",
      "--host=127.0.0.1",
      "--dbname=postgres",
      "--no-align",
      "--tuples-only",
      "--quiet",
      "--set=ON_ERROR_STOP=1",
    ])
    : new Set([
      "--protocol=TCP",
      "--host=127.0.0.1",
      "--batch",
      "--skip-column-names",
    ]);
  return args.slice(1).every((a) =>
    fixed.has(a) ||
    (pg ? /^--username=[A-Za-z0-9_]{1,63}$/ : /^--user=[A-Za-z0-9_]{1,32}$/)
      .test(a)
  );
});
const Common = z.object({
  container: Container,
  password: Password,
  password_variable: z.enum(["MYSQL_PWD", "PGPASSWORD"]),
  client: Client,
});
export const SqlInput = z.discriminatedUnion("operation", [
  Common.extend({
    operation: z.literal("execute"),
    sql: z.string().max(16 * 1024 * 1024).refine((s) => !s.includes("\0")),
  }).strict(),
  Common.extend({
    operation: z.literal("ready"),
    timeout_seconds: z.number().int().min(0).max(900),
  }).strict(),
  z.object({
    operation: z.literal("copy"),
    container: Container,
    password: Password,
    source: Name,
    target: Name,
  }).strict(),
]).refine((i) =>
  i.operation === "copy" ||
  (i.password_variable === "PGPASSWORD") === (i.client[0] === "psql")
);
type Input = z.infer<typeof SqlInput>;
export type SqlProcess = {
  command: "/usr/bin/sudo";
  args: string[];
  stdin: Uint8Array;
  timeoutMs: number;
  maxOutputBytes: number;
  signal?: AbortSignal;
};
export type SqlProcessResult = { success: boolean; output: string };
export type SqlRunner = (process: SqlProcess) => Promise<SqlProcessResult>;

// Read one private frame. The sentinel preserves trailing newlines in a password.
const credential = (variable: "MYSQL_PWD" | "PGPASSWORD") =>
  `IFS= read -r orbit_database_credential\n${variable}="$(printf '%s' "$orbit_database_credential" | base64 -d; printf x)"\n${variable}="\${${variable}%x}"\nexport ${variable}\nunset orbit_database_credential\n`;
function encoded(password: string) {
  const bytes = new TextEncoder().encode(password);
  let value = "";
  for (const byte of bytes) value += String.fromCharCode(byte);
  return btoa(value);
}

/** Only reviewed fixed shell source reaches the container; secrets remain stdin bytes. */
export function sqlProcess(
  input: Input,
  timeoutMs: number,
  maxOutputBytes = 1024 * 1024,
): SqlProcess {
  const copy = input.operation === "copy";
  const variable = copy ? "MYSQL_PWD" : input.password_variable;
  const script = credential(variable) +
    (copy
      ? 'mysqldump --user=root --single-transaction --routines --triggers --no-tablespaces --set-gtid-purged=OFF "$1" | mysql --user=root "$2"'
      : 'exec "$@"');
  const client = copy ? [input.source, input.target] : input.client;
  const sql = input.operation === "execute"
    ? input.sql
    : input.operation === "ready"
    ? "SELECT 1;\n"
    : "";
  return {
    command: "/usr/bin/sudo",
    args: [
      "-n",
      "--",
      "/usr/bin/docker",
      "container",
      "exec",
      "-i",
      "--",
      input.container,
      "timeout",
      "-s",
      "TERM",
      "-k",
      "5",
      `${Math.max(1, Math.ceil(timeoutMs / 1000))}s`,
      ...(copy ? ["bash", "-euo", "pipefail", "-c"] : ["sh", "-eu", "-c"]),
      script,
      "orbit-database-admin",
      ...client,
    ],
    stdin: new TextEncoder().encode(encoded(input.password) + "\n" + sql),
    timeoutMs,
    maxOutputBytes,
  };
}

/** Drain output while writing SQL, so scripts larger than a pipe buffer cannot deadlock. */
export async function runSqlProcess(
  process: SqlProcess,
): Promise<SqlProcessResult> {
  const deadline = AbortSignal.timeout(Math.max(1, process.timeoutMs) + 6000);
  const signal = process.signal
    ? AbortSignal.any([process.signal, deadline])
    : deadline;
  const child = localCommand(process.command, {
    args: process.args,
    stdin: "piped",
    stdout: "piped",
    stderr: "null",
    clearEnv: true,
    env: {
      PATH: "/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin",
      LANG: "C.UTF-8",
    },
    signal,
  }).spawn();
  let finished = false;
  const write = (async () => {
    const writer = child.stdin.getWriter();
    try {
      await writer.write(process.stdin);
      await writer.close();
    } finally {
      writer.releaseLock();
    }
  })();
  const read = (async () => {
    const chunks: Uint8Array[] = [];
    let length = 0;
    for await (const chunk of child.stdout) {
      length += chunk.length;
      if (length > process.maxOutputBytes) {
        throw new Error("database.server_admin_invalid");
      }
      chunks.push(chunk);
    }
    const bytes = new Uint8Array(length);
    let offset = 0;
    for (const chunk of chunks) {
      bytes.set(chunk, offset);
      offset += chunk.length;
    }
    return new TextDecoder().decode(bytes);
  })();
  try {
    const [_, output, status] = await Promise.all([
      write,
      read,
      child.status,
    ]);
    finished = true;
    return { success: status.success, output };
  } finally {
    if (!finished) {
      try {
        child.kill("SIGTERM");
      } catch { /* already exited */ }
      await Promise.allSettled([write, read, child.status]);
    }
  }
}
/** One SQL client observation or effect; native workflows own retry and resume. */
export async function invokeSql(
  call: DatabaseSqlCall,
  signal?: AbortSignal,
  runner: SqlRunner = runSqlProcess,
): Promise<SqlOutcome> {
  const parsed = SqlInput.safeParse(call.input);
  if (
    call.name !== "database.server-admin" || !parsed.success ||
    call.sudo === false ||
    !Number.isSafeInteger(call.deadlineMs) || call.deadlineMs < 1 ||
    call.deadlineMs > 900000
  ) {
    return { error_code: "database.server_admin_invalid" };
  }
  const input = parsed.data;
  const bound = Math.min(1024 * 1024, call.maxOutputBytes ?? 1024 * 1024);
  if (!Number.isSafeInteger(bound) || bound < 1) {
    return { error_code: "database.server_admin_invalid" };
  }
  if (signal?.aborted) return { error_code: "database.server_admin_failed" };
  try {
    const deadline = input.operation === "ready"
      ? Math.min(call.deadlineMs, input.timeout_seconds * 1000)
      : call.deadlineMs;
    const process = sqlProcess(input, deadline, bound);
    if (!call.mutates || input.operation === "ready") {
      process.signal = signal;
    }
    const result = await runner(process);
    if (new TextEncoder().encode(result.output).length > bound) {
      return { error_code: "database.server_admin_invalid" };
    }
    if (result.success) {
      return input.operation === "execute" ? { output: result.output } : {};
    }
    return {
      error_code: input.operation === "ready"
        ? "database.server_not_ready"
        : "database.server_admin_failed",
    };
  } catch {
    return { error_code: "database.server_admin_failed" };
  }
}
