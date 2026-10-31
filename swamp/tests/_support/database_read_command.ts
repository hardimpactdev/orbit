import { fileURLToPath } from "node:url";
import { ReadResult } from "../../extensions/behaviors/operations/database-read-commands/assets.ts";
type Result = ReturnType<typeof ReadResult.parse>;

const directory = fileURLToPath(
    new URL(
        "../../extensions/behaviors/operations/database-read-commands/scripts/",
        import.meta.url,
    ),
);

type Intent = {
    driver: string;
    mode: string;
    table: string | null;
    owner?: string;
    path?: string;
};
type Material = {
    sql: string;
    dsn: string;
    username: string | null;
    password: string;
};

/** Test the shipped scripts directly; no replacement behavior or action implementation. */
export async function readCommand(
    intent: Intent,
    material: Material,
): Promise<Result> {
    const argv = intent.driver === "sqlite"
        ? [
            "/usr/bin/sudo",
            "-n",
            "-u",
            intent.owner ?? "",
            "--",
            "/usr/bin/python3",
            "-I",
            directory + "read_sqlite.py",
            JSON.stringify(intent),
        ]
        : [
            "/usr/bin/php",
            "-d",
            "display_errors=0",
            "-d",
            "log_errors=0",
            directory + "read_pdo.php",
            JSON.stringify(intent),
        ];
    const child = new Deno.Command(argv[0], {
        args: argv.slice(1),
        stdin: "piped",
        stdout: "piped",
        stderr: "piped",
        signal: AbortSignal.timeout(45000),
    }).spawn();
    const writer = child.stdin.getWriter();
    await writer.write(new TextEncoder().encode(JSON.stringify(material)));
    await writer.close();
    const output = await child.output();
    if (output.stderr.length) {
        throw new Error("Unexpected read action diagnostic");
    }
    return ReadResult.parse(
        JSON.parse(new TextDecoder().decode(output.stdout)),
    );
}

export async function readDatabase(
    intent: Intent,
    use: (
        consume: (material: Record<string, unknown>) => Promise<void>,
    ) => Promise<void>,
): Promise<Result> {
    let result: Result = { error_code: "database.query_failed" };
    await use(async (material) => {
        result = await readCommand(intent, material as Material);
    });
    return result;
}

export async function pdoRead(
    material: Material & { mode: string; table: string | null },
) {
    return await readCommand({
        driver: material.dsn.split(":")[0],
        mode: material.mode,
        table: material.table,
    }, {
        sql: material.sql,
        dsn: material.dsn,
        username: material.username,
        password: material.password,
    });
}
