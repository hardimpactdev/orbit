import { z } from "npm:zod@4.4.3";
import { type DatabaseSqlCall as NodeCall, invokeSql } from "./local_sql.ts";

export const SqlRefusal = z.object({
  error_code: z.enum([
    "database.server_admin_invalid",
    "database.server_admin_failed",
    "database.server_not_ready",
  ]),
}).strict();
export const SqlRows = z.object({ output: z.string().max(1024 * 1024) })
  .strict();

/** Fixed container admin clients; SQL, password and output stay callback-local. */
export function sqlClient(
  driver: "mysql" | "pgsql",
  container: string,
  password: string,
  signal?: AbortSignal,
  invoke: (call: NodeCall, signal?: AbortSignal) => Promise<unknown> =
    invokeSql,
) {
  return async (sql: string, mutates: boolean) => {
    const postgres = driver === "pgsql";
    const raw = await invoke({
      name: "database.server-admin",
      sudo: true,
      mutates,
      deadlineMs: 900000,
      maxOutputBytes: 2 * 1024 * 1024,
      input: {
        operation: "execute",
        container,
        password,
        password_variable: postgres ? "PGPASSWORD" : "MYSQL_PWD",
        client: postgres
          ? [
            "psql",
            "--no-psqlrc",
            "--host=127.0.0.1",
            "--username=postgres",
            "--dbname=postgres",
            "--no-align",
            "--tuples-only",
            "--quiet",
            "--set=ON_ERROR_STOP=1",
          ]
          : ["mysql", "--user=root", "--batch", "--skip-column-names"],
        sql,
      },
    }, signal);
    const refused = SqlRefusal.safeParse(raw);
    return refused.success ? refused.data : SqlRows.parse(raw);
  };
}
