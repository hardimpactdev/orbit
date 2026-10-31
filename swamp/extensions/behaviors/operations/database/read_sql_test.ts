import { assertEquals, assertStringIncludes } from "jsr:@std/assert@1.0.19";
import { fileURLToPath } from "node:url";

const policy = fileURLToPath(
    new URL(
        "../database-read-commands/scripts/read_policy.php",
        import.meta.url,
    ),
);

async function run(program: string, input: unknown) {
    const child = new Deno.Command("/usr/bin/php", {
        args: [
            "-d",
            "display_errors=0",
            "-d",
            "log_errors=0",
            "-r",
            program,
            policy,
        ],
        stdin: "piped",
        stdout: "piped",
        stderr: "piped",
    }).spawn();
    const writer = child.stdin.getWriter();
    await writer.write(new TextEncoder().encode(JSON.stringify(input)));
    await writer.close();
    return await child.output();
}

Deno.test("native network reads allow ordinary queries and refuse external effects before a client starts", async () => {
    const program =
        "require $argv[1];$p=json_decode(stream_get_contents(STDIN),true);try {assertReadSql($p['sql'],$p['driver']);}catch(Throwable){exit(1);}";
    for (const driver of ["mysql", "pgsql"]) {
        for (
            const sql of [
                "SELECT id, upper(name), count(*) FROM users GROUP BY id,name",
                "SELECT 'INTO OUTFILE lo_export()' AS safe_literal",
                "WITH summary AS (SELECT count(*) AS n FROM users) SELECT n FROM summary;",
            ]
        ) {
            assertEquals(
                (await run(program, { sql, driver })).success,
                true,
                sql,
            );
        }
        for (
            const sql of [
                "SELECT 'proof' INTO OUTFILE '/tmp/proof'",
                "SELECT 'proof' INTO DUMPFILE '/tmp/proof'",
                "SELECT 1 /*! INTO OUTFILE '/tmp/proof' */",
                "SELECT lo_export(48012,'/tmp/proof')",
                "SELECT pg_catalog.lo_export(48012,'/tmp/proof')",
                'SELECT "pg_catalog"."lo_export"(48012,\'/tmp/proof\')',
                "SELECT unreviewed_function()",
                "SELECT public.lower('name')",
                "COMMIT",
                "SELECT 1; DELETE FROM users",
            ]
        ) {
            assertEquals(
                (await run(program, { sql, driver })).success,
                false,
                sql,
            );
        }
    }
    assertEquals(
        (await run(program, {
            sql: "SELECT pg_catalog.lower('value')",
            driver: "pgsql",
        })).success,
        true,
    );
});

Deno.test("PDO cells and keys redact credentials before public native output", async () => {
    const password = "private-password-credential";
    const result = await run(
        "require $argv[1];$p=json_decode(stream_get_contents(STDIN),true);echo json_encode(redact($p['value'],$p['password']));",
        {
            password,
            value: {
                columns: [password, "value"],
                rows: [{
                    [password]: `password=${password}`,
                    value:
                        "postgres://user:unsafe@db.local Bearer abcdefghijklmn",
                    pem: "-----BEGIN PRIVATE KEY-----\\nprivate-key-sentinel\\n-----END PRIVATE KEY-----",
                    pat: "github_pat_abcdefghijklmnopqrstuvw",
                }],
            },
        },
    );
    assertEquals(result.success, true);
    const output = new TextDecoder().decode(result.stdout);
    for (
        const secret of [
            password,
            "unsafe",
            "abcdefghijklmn",
            "private-key-sentinel",
            "github_pat_abcdefghijklmnopqrstuvw",
        ]
    ) assertEquals(output.includes(secret), false);
    assertStringIncludes(output, "[REDACTED]");
});
