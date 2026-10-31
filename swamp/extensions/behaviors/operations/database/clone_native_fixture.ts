import type { DatabaseSqlCall, SqlOutcome } from "./local_sql.ts";
import { runSqliteLocal, type SqliteInvoke } from "./sqlite_local.ts";

/** Fake only the local SQL action; exercise real native checkpoints and private Gateway reads. */
export async function fixtureSqlInvoke(
  call: DatabaseSqlCall,
): Promise<SqlOutcome> {
  await Deno.writeTextFile(
    "effects",
    (
      String(call.input.sql).startsWith("DROP DATABASE")
        ? "reset"
        : String(call.input.operation)
    ) + "\n",
    { append: true },
  );
  if (Deno.env.get("CLONE_THROW") === "1") {
    throw new Error(JSON.stringify(call.input));
  }
  if (
    Deno.env.get("CLONE_FAIL_COPY_ONCE") === "1" &&
    call.input.operation === "copy"
  ) {
    try {
      await Deno.stat("copy-failed");
    } catch {
      await Deno.writeTextFile("copy-failed", "yes");
      throw new Error("Uncertain copy");
    }
  }
  if (call.input.operation === "copy") return {};
  return {
    output: String(call.input.sql).includes("SCHEMA_NAME='source_database'")
      ? "source_database\n"
      : "",
  };
}

/** MySQL native fixtures must never fall through to real SQLite process execution. */
export const fixtureSqliteInvoke: SqliteInvoke = async (call, signal) => {
  if (Deno.env.get("CLONE_SQLITE") !== "1") {
    throw new Error("Unexpected SQLite action in MySQL fixture");
  }
  await Deno.writeTextFile(
    "effects",
    "sqlite-" + String(call.input.operation) + "\n",
    { append: true },
  );
  return await runSqliteLocal(call, signal);
};
