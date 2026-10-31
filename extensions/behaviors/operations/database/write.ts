import { z } from "npm:zod@4.4.3";
import { type NodeCall, runNodeAgent } from "../_lib/node-agent.ts";
import { nativeEffect } from "./native_effect.ts";
import type { PrivateMaterialUse } from "./user_create_action.ts";

const Pin = z.string().regex(/^[a-f0-9]{64}$/);
const Input = z.discriminatedUnion("driver", [
  z.object({
    driver: z.literal("sqlite"),
    agent_sha256: Pin,
    owner: z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/),
    path: z.string().startsWith("/").refine((p) =>
      !/[\0\r\n]|(?:^|\/)\.{1,2}(?:\/|$)|\/\//.test(p)
    ),
  }).strict(),
  z.object({ driver: z.enum(["mysql", "pgsql"]) }).strict(),
]);
const Material = z.object({
  password: z.string().max(16384),
  sql: z.string().min(1).max(16384),
  dsn: z.string().max(4096),
  username: z.string().max(128).nullable(),
}).strict();
const Refusal = z.object({
  error_code: z.enum([
    "agent.version_mismatch",
    "database.sqlite_unreadable",
    "database.query_failed",
    "database.write_outcome_unknown",
  ]),
}).strict();
const Count = z.object({
  row_count: z.number().int().nonnegative(),
  truncated: z.boolean(),
});
export const WriteResult = z.union([
  Count.extend({ backend: z.literal("active") }).strict(),
  Refusal,
]);

/** Fixed reviewed program; SQL/credentials go only through stdin, never argv or output. */
const php = String.raw`
$p = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
try {
  $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10, PDO::ATTR_EMULATE_PREPARES => false];
  if (str_starts_with($p['dsn'], 'mysql:')) $options[PDO::MYSQL_ATTR_MULTI_STATEMENTS] = false;
  $pdo = new PDO($p['dsn'], $p['username'], $p['password'], $options);
  $statement = $pdo->query($p['sql']);
  echo json_encode(['row_count' => max(0, $statement->rowCount()), 'truncated' => false]);
} catch (Throwable $error) { exit(1); }
`;
export async function pdoWrite(input: z.infer<typeof Material>) {
  const child = new Deno.Command("/usr/bin/php", {
    args: ["-r", php],
    stdin: "piped",
    stdout: "piped",
    stderr: "null",
    signal: AbortSignal.timeout(45000),
  }).spawn();
  let finished = false;
  try {
    const writer = child.stdin.getWriter();
    await writer.write(new TextEncoder().encode(JSON.stringify(input)));
    await writer.close();
    const chunks: Uint8Array[] = [];
    let size = 0;
    for await (const chunk of child.stdout) {
      size += chunk.length;
      if (size > 16384) throw new Error("Write output bound");
      chunks.push(chunk);
    }
    const status = await child.status;
    finished = true;
    if (!status.success) return { error_code: "database.query_failed" };
    const bytes = new Uint8Array(size);
    let offset = 0;
    for (const chunk of chunks) {
      bytes.set(chunk, offset);
      offset += chunk.length;
    }
    return Count.parse(JSON.parse(new TextDecoder().decode(bytes)));
  } finally {
    if (!finished) {
      try {
        child.kill("SIGTERM");
      } catch { /* exited */ }
      await child.status.catch(() => {});
    }
  }
}

export async function writeDatabase(
  raw: unknown,
  use: PrivateMaterialUse,
  signal?: AbortSignal,
  invoke = runNodeAgent,
  pdo = pdoWrite,
) {
  let invoked = false;
  try {
    const input = Input.parse(raw);
    signal?.throwIfAborted();
    let result: z.infer<typeof WriteResult> | undefined;
    await use(async (rawMaterial) => {
      const material = Material.parse(rawMaterial);
      signal?.throwIfAborted();
      if (material.sql.includes("\0") || material.password.includes("\0")) {
        throw new Error("Invalid private write");
      }
      let reply: unknown;
      if (input.driver === "sqlite") {
        if (
          material.dsn !== "" || material.username !== null ||
          material.password !== ""
        ) throw new Error("Foreign SQLite material");
        const call: NodeCall = {
          name: "database.write-sqlite",
          pin: input.agent_sha256,
          sudo: true,
          mutates: true,
          deadlineMs: 30000,
          input: { owner: input.owner, path: input.path, sql: material.sql },
        };
        invoked = true;
        reply = await invoke(call, signal);
      } else {
        if (
          !material.dsn.startsWith(`${input.driver}:`) ||
          material.dsn.includes("\0")
        ) throw new Error("Foreign SQL material");
        invoked = true;
        reply = await pdo(material);
      }
      const refused = Refusal.safeParse(reply);
      result = refused.success
        ? refused.data
        : { backend: "active", ...Count.parse(reply) };
    });
    if (!result) throw new Error("No completed write");
    return result;
  } catch {
    if (!invoked) {
      return { error_code: "database.write_outcome_unknown" as const };
    }
    throw new Error("database.write_outcome_unknown");
  }
}
export function databaseWrite(
  makeHttp?: Parameters<typeof nativeEffect>[4],
  invoke = runNodeAgent,
  pdo = pdoWrite,
) {
  return nativeEffect(
    "database.write",
    "@nckrtl/database-write",
    WriteResult,
    (input, gateway, signal) =>
      writeDatabase(
        input,
        (use) => gateway.withPrivateMaterial(use),
        signal,
        invoke,
        pdo,
      ),
    makeHttp,
    false,
  );
}
export const behavior = { ...databaseWrite(), type: "@nckrtl/database-write" };
