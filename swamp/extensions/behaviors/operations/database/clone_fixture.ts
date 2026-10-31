import type { DatabaseSqlCall, SqlOutcome } from "./local_sql.ts";
export const mysql = {
  repair: false,
  driver: "mysql",
  username: "target_owner",
  databases: ["target", "target_test"],
  test_prefix: "target_test",
  locale: null,
  host: "10.44.0.9",
  port: 3306,
  container: "orbit-process-42-proof",
  index: null,
  patterns: [],
  allow: [],
  source_database: "source_database",
};
export const use = (effect: (m: Record<string, unknown>) => Promise<void>) =>
  effect({
    password: "private-root-clone",
    payload: { password: "a".repeat(40), salt: "YWFhYWFhYWFhYWFhYWFhYQ==" },
  });
export function good(call: DatabaseSqlCall): Promise<SqlOutcome> {
  if (call.input.operation === "copy") return Promise.resolve({});
  return Promise.resolve({
    output: String(call.input.sql).includes("SCHEMA_NAME='source_database'")
      ? "source_database\n"
      : "",
  });
}
