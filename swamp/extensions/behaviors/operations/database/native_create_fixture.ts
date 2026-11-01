/** Test fixture exercises the shipped Python actions rather than retired TS executors. */
import { CreateInput } from "./create_input.ts";
export async function scriptFixture(
    name: string,
    intent: unknown,
    material: unknown,
    item: unknown = {},
) {
    const process = new Deno.Command("/usr/bin/sudo", {
        args: [
            "-n",
            "--",
            "/usr/bin/python3",
            "-B",
            new URL(
                "../database-server-commands/scripts/" + name + ".py",
                import.meta.url,
            ).pathname,
            JSON.stringify(intent),
            JSON.stringify(item),
        ],
        stdin: "piped",
        stdout: "piped",
        stderr: "null",
    }).spawn();
    const writer = process.stdin.getWriter();
    await writer.write(new TextEncoder().encode(JSON.stringify(material)));
    await writer.close();
    const result = await process.output();
    const value = JSON.parse(new TextDecoder().decode(result.stdout));
    return result.success ? { ready: true } : value as { error_code: string };
}
export async function createDatabase(
    raw: unknown,
    use: (f: (m: Record<string, unknown>) => Promise<void>) => Promise<void>,
) {
    const i = CreateInput.parse(raw);
    let outcome: Record<string, unknown> = { backend: "active" };
    await use(async (m) => {
        const actions: [string, unknown][] = [
            ["sql_probe_user", {}],
            ["sql_probe_databases", {}],
            ["sql_create_user", {}],
            ["sql_authenticate", {}],
        ];
        if (i.driver === "mysql") {
            for (const name of i.databases) {
                actions.push(["mysql_grant", { name, wildcard: false }]);
            }
            if (i.test_prefix) {
                actions.push(["mysql_grant", {
                    name: i.test_prefix,
                    wildcard: true,
                }]);
            }
        }
        for (const database of i.databases) {
            actions.push(["sql_create_database", { database }]);
            if (i.driver === "pgsql") {
                for (
                    const name of [
                        "postgres_revoke_database",
                        "postgres_revoke_schema",
                        "postgres_grant_schema",
                    ]
                ) actions.push([name, { database }]);
            }
        }
        for (const [name, item] of actions) {
            const r = await scriptFixture(name, i, m, item);
            if ("error_code" in r) {
                outcome = r;
                return;
            }
        }
    });
    return outcome;
}
export async function copyMysqlClone(
    raw: Record<string, unknown>,
    use: (f: (m: Record<string, unknown>) => Promise<void>) => Promise<void>,
) {
    const { source_database: _, ...create } = raw;
    const prepared = await createDatabase(create, use);
    if ("error_code" in prepared) return prepared;
    let result: Record<string, unknown> = { backend: "active" };
    await use(async (m) => {
        const reply = await scriptFixture("mysql_clone_copy", {
            variant: "mysql",
            mysql: raw,
        }, m);
        if ("error_code" in reply) result = reply;
    });
    return result;
}
