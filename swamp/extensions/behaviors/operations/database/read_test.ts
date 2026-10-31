import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { userInfo } from "node:os";
import { readDatabase } from "../../../../tests/_support/database_read_command.ts";

const owner = userInfo().username;
const privateUse =
    (value: Record<string, unknown>) =>
    async (callback: (value: Record<string, unknown>) => Promise<void>) => {
        await callback(value);
    };
const material = {
    password: "",
    dsn: "",
    username: null,
    sql: "SELECT id,value FROM proof ORDER BY id",
};
async function fixture() {
    const dir = await Deno.makeTempDir({ prefix: "database-read-test-" });
    const path = dir + "/proof.sqlite";
    const result = await new Deno.Command("/usr/bin/python3", {
        args: [
            "-I",
            "-c",
            "import sqlite3,sys; d=sqlite3.connect(sys.argv[1]); d.execute('CREATE TABLE proof(id INTEGER PRIMARY KEY,value TEXT NOT NULL)');d.execute(\"INSERT INTO proof VALUES(7,'proof-value')\");d.commit();d.close()",
            path,
        ],
        stdout: "null",
        stderr: "null",
    }).output();
    assertEquals(result.success, true);
    return { dir, path };
}
Deno.test("native SQLite query/tables/schema/describe preserve values and column facts", async () => {
    const { dir, path } = await fixture();
    try {
        const base = {
            driver: "sqlite",
            owner,
            path,
            mode: "query",
            table: null,
        };
        assertEquals(await readDatabase(base, privateUse(material)), {
            columns: ["id", "value"],
            rows: [{ id: 7, value: "proof-value" }],
            row_count: 1,
            truncated: false,
        });
        assertEquals(
            await readDatabase(
                { ...base, mode: "tables" },
                privateUse({ ...material, sql: "" }),
            ),
            { tables: ["proof"] },
        );
        const columns = [{
            name: "id",
            type: "INTEGER",
            nullable: true,
            default: null,
            primary: true,
        }, {
            name: "value",
            type: "TEXT",
            nullable: false,
            default: null,
            primary: false,
        }];
        assertEquals(
            await readDatabase(
                { ...base, mode: "describe", table: "proof" },
                privateUse({ ...material, sql: "" }),
            ),
            { table: "proof", columns },
        );
        assertEquals(
            await readDatabase(
                { ...base, mode: "schema" },
                privateUse({ ...material, sql: "" }),
            ),
            { tables: [{ name: "proof", columns }] },
        );
        assertEquals(
            await readDatabase(
                { ...base, mode: "describe", table: "missing" },
                privateUse({ ...material, sql: "" }),
            ),
            { error_code: "database.table_missing" },
        );
    } finally {
        await Deno.remove(dir, { recursive: true });
    }
});
Deno.test("SQLite read refuses mutations, stacked SQL, attached files and extension functions without effects", async () => {
    const { dir, path } = await fixture();
    try {
        const base = {
            driver: "sqlite",
            owner,
            path,
            mode: "query",
            table: null,
        };
        for (
            const sql of [
                "DELETE FROM proof",
                "PRAGMA user_version=17",
                "ATTACH DATABASE ':memory:' AS foreign_db",
                "VACUUM INTO '/tmp/forbidden-db-read'",
                "SELECT load_extension('missing')",
                "SELECT 1; DROP TABLE proof",
            ]
        ) {
            assertEquals(
                await readDatabase(base, privateUse({ ...material, sql })),
                {
                    error_code: "database.query_failed",
                },
            );
        }
        assertEquals(
            await readDatabase(
                base,
                privateUse({
                    ...material,
                    sql: "SELECT count(*) AS n FROM proof",
                }),
            ),
            {
                columns: ["n"],
                rows: [{ n: 1 }],
                row_count: 1,
                truncated: false,
            },
        );
        assertEquals(
            await readDatabase(
                { ...base, path: dir + "/missing.sqlite" },
                privateUse(material),
            ),
            { error_code: "database.sqlite_unreadable" },
        );
        await assertRejects(
            () => Deno.stat(dir + "/missing.sqlite"),
            Deno.errors.NotFound,
        );
        await Deno.symlink(path, dir + "/link.sqlite");
        assertEquals(
            await readDatabase(
                { ...base, path: dir + "/link.sqlite" },
                privateUse(material),
            ),
            { error_code: "database.sqlite_unreadable" },
        );
    } finally {
        await Deno.remove(dir, { recursive: true });
    }
});
Deno.test("SQLite read caps rows and bytes, preserves null/numbers/blob text and bounds errors", async () => {
    const { dir, path } = await fixture();
    try {
        const base = {
            driver: "sqlite",
            owner,
            path,
            mode: "query",
            table: null,
        };
        const result = await readDatabase(
            base,
            privateUse({
                ...material,
                sql: "WITH RECURSIVE n(x) AS (VALUES(1) UNION ALL SELECT x+1 FROM n WHERE x<700) SELECT x FROM n",
            }),
        );
        assertEquals("row_count" in result && result.row_count, 500);
        assertEquals("truncated" in result && result.truncated, true);
        assertEquals(
            await readDatabase(
                base,
                privateUse({
                    ...material,
                    sql: "SELECT NULL AS n,1.5 AS f,CAST('ok' AS BLOB) AS b",
                }),
            ),
            {
                columns: ["n", "f", "b"],
                rows: [{ n: null, f: 1.5, b: "ok" }],
                row_count: 1,
                truncated: false,
            },
        );
        assertEquals(
            await readDatabase(
                base,
                privateUse({
                    ...material,
                    sql: "SELECT private_read_sentinel FROM absent",
                }),
            ),
            { error_code: "database.query_failed" },
        );
        const bounded = await readDatabase(
            base,
            privateUse({
                ...material,
                sql: "SELECT hex(zeroblob(10000)) AS oversized",
            }),
        );
        assertEquals("truncated" in bounded && bounded.truncated, true);
        assertEquals(JSON.stringify(bounded).length < 16000, true);
    } finally {
        await Deno.remove(dir, { recursive: true });
    }
});
Deno.test("SQLite cells and column names are redacted before command output", async () => {
    const { dir, path } = await fixture();
    try {
        const result = await readDatabase(
            { driver: "sqlite", owner, path, mode: "query", table: null },
            privateUse({
                ...material,
                sql: "SELECT 'postgres://user:unsafe@db.local Bearer abcdefghijklmn' AS value, 'password=private-cell' AS credential",
            }),
        );
        assertEquals(JSON.stringify(result).includes("unsafe"), false);
        assertEquals(JSON.stringify(result).includes("abcdefghijklmn"), false);
        assertEquals(JSON.stringify(result).includes("private-cell"), false);
        assertEquals(JSON.stringify(result).includes("[REDACTED]"), true);
    } finally {
        await Deno.remove(dir, { recursive: true });
    }
});
Deno.test("invalid PDO material and process errors remain bounded", async () => {
    for (const dsn of ["pgsql:wrong", "mysql:host=127.0.0.1;port=1"]) {
        assertEquals(
            await readDatabase(
                { driver: "mysql", mode: "query", table: null },
                privateUse({
                    ...material,
                    dsn,
                    password: "private-process-sentinel",
                }),
            ),
            { error_code: "database.query_failed" },
        );
    }
});
