import { assertEquals } from "jsr:@std/assert@1.0.19";
import {
    pdoRead,
    readDatabase,
} from "../../../../tests/_support/database_read_command.ts";
import { pdoWrite } from "../../../../tests/_support/database_write_command.ts";

export function registerReadEffect(driver: "mysql" | "pgsql") {
    Deno.test({
        name:
            `native read-only ${driver} queries and metadata preserve row values and refuse mutation`,
        fn: async () => {
            const name = `orbit-database-read-${crypto.randomUUID()}`;
            const dir = await Deno.makeTempDir({ prefix: "database-read-" });
            const password = `private-read-${crypto.randomUUID()}`;
            const docker = async (...args: string[]) => {
                const result = await new Deno.Command("docker", {
                    args,
                    stdout: "piped",
                    stderr: "null",
                    signal: AbortSignal.timeout(30000),
                }).output();
                if (!result.success) {
                    throw new Error("Disposable read fixture failed");
                }
                return new TextDecoder().decode(result.stdout).trim();
            };
            try {
                const pg = driver === "pgsql";
                await Deno.writeTextFile(
                    `${dir}/env`,
                    `${
                        pg
                            ? "POSTGRES_PASSWORD"
                            : "MYSQL_ROOT_PASSWORD"
                    }=${password}\n${
                        pg ? "POSTGRES_DB" : "MYSQL_DATABASE"
                    }=proof\n`,
                    { mode: 0o600 },
                );
                await docker(
                    "run",
                    "--detach",
                    "--rm",
                    "--name",
                    name,
                    "--label",
                    "orbit.test=database-read",
                    "--env-file",
                    `${dir}/env`,
                    "--publish",
                    `127.0.0.1::${pg ? 5432 : 3306}`,
                    pg ? "postgres:18" : "mysql:8.4",
                );
                const port = Number(
                    (await docker("port", name, pg ? "5432/tcp" : "3306/tcp"))
                        .split(":")
                        .at(-1),
                );
                const material = {
                    dsn: `${driver}:host=127.0.0.1;port=${port};dbname=proof`,
                    username: pg ? "postgres" : "root",
                    password,
                    sql: "SELECT 1",
                };
                let ready = false;
                const until = Date.now() + 45000;
                while (Date.now() < until) {
                    if ("row_count" in await pdoWrite(material)) {
                        ready = true;
                        break;
                    }
                    await new Promise((resolve) => setTimeout(resolve, 100));
                }
                assertEquals(ready, true);
                assertEquals(
                    await pdoWrite({
                        ...material,
                        sql: "CREATE TABLE proof_rows(id integer PRIMARY KEY, value varchar(200) NOT NULL)",
                    }),
                    { row_count: 0, truncated: false },
                );
                assertEquals(
                    await pdoWrite({
                        ...material,
                        sql: "INSERT INTO proof_rows VALUES(7,'proof-row')",
                    }),
                    { row_count: 1, truncated: false },
                );
                const use = (sql: string) =>
                async (
                    callback: (v: Record<string, unknown>) => Promise<void>,
                ) => {
                    await callback({ ...material, sql });
                };
                const base = { driver, mode: "query", table: null };
                assertEquals(
                    await readDatabase(
                        base,
                        use("SELECT id,value FROM proof_rows"),
                    ),
                    {
                        columns: ["id", "value"],
                        rows: [{ id: 7, value: "proof-row" }],
                        row_count: 1,
                        truncated: false,
                    },
                );
                assertEquals(
                    await readDatabase({ ...base, mode: "tables" }, use("")),
                    {
                        tables: ["proof_rows"],
                    },
                );
                const description = await readDatabase({
                    ...base,
                    mode: "describe",
                    table: "proof_rows",
                }, use(""));
                assertEquals("columns" in description, true);
                if ("columns" in description) {
                    assertEquals(description.columns[0], {
                        name: "id",
                        type: pg ? "integer" : "int",
                        nullable: false,
                        default: null,
                        primary: true,
                    });
                }
                const schema = await readDatabase(
                    { ...base, mode: "schema" },
                    use(""),
                );
                assertEquals("tables" in schema && schema.tables.length, 1);
                assertEquals(
                    await readDatabase(
                        { ...base, mode: "describe", table: "missing" },
                        use(""),
                    ),
                    { error_code: "database.table_missing" },
                );
                for (
                    const sql of [
                        "DELETE FROM proof_rows",
                        "SELECT 1; DELETE FROM proof_rows",
                    ]
                ) {
                    assertEquals(await readDatabase(base, use(sql)), {
                        error_code: "database.query_failed",
                    });
                }
                const deniedPath = pg
                    ? "/tmp/native-read-denied.txt"
                    : "/var/lib/mysql-files/native-read-denied.txt";
                if (pg) {
                    await pdoWrite({
                        ...material,
                        sql: "SELECT lo_from_bytea(48012, decode('70726f6f66','hex'))",
                    });
                }
                const attacks = pg
                    ? [
                        "SELECT lo_export(48012, '/tmp/native-read-denied.txt')",
                        `SELECT pg_catalog."lo_export"(48012, '/tmp/native-read-denied.txt')`,
                    ]
                    : [
                        "SELECT 'proof' INTO OUTFILE '/var/lib/mysql-files/native-read-denied.txt'",
                        "SELECT 1 /*! INTO OUTFILE '/var/lib/mysql-files/native-read-denied.txt' */",
                    ];
                for (const sql of attacks) {
                    assertEquals(
                        await pdoRead({
                            ...material,
                            sql,
                            mode: "query",
                            table: null,
                        }),
                        { error_code: "database.query_failed" },
                    );
                    await docker(
                        "exec",
                        name,
                        "sh",
                        "-c",
                        "test ! -e " + deniedPath,
                    );
                }
                assertEquals(
                    await readDatabase(
                        base,
                        use("SELECT count(*) AS n FROM proof_rows"),
                    ),
                    {
                        columns: ["n"],
                        rows: [{ n: 1 }],
                        row_count: 1,
                        truncated: false,
                    },
                );
                assertEquals(
                    await pdoRead({
                        ...material,
                        password: "wrong",
                        mode: "query",
                        table: null,
                    }),
                    { error_code: "database.query_failed" },
                );
            } finally {
                await docker("rm", "--force", name).catch(() => {});
                await Deno.remove(dir, { recursive: true });
            }
        },
    });
}
