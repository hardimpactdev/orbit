import { assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";

Deno.test("Database SSH child names fit OpenSSH temporary control sockets with upstream defaults", async () => {
    for (
        const [file, prefixes] of [
            ["workflow-database-create-action.yaml", ["dbc-"]],
            ["workflow-database-owned-sqlite-remove.yaml", ["dbos-"]],
            ["workflow-database-clone-mysql.yaml", ["dbmq-"]],
            ["workflow-database-clone-sqlite.yaml", ["dbsq-", "dbtq-"]],
        ] as const
    ) {
        const workflow = parse(
            await Deno.readTextFile(
                new URL(
                    "../../../../workflows/" + file,
                    import.meta.url,
                ),
            ),
        ) as {
            jobs: {
                steps: {
                    task: {
                        behaviorType?: string;
                        behaviorName?: string;
                        globalArgs?: { name?: string };
                    };
                }[];
            }[];
        };
        const expressions = prefixes.map((prefix) =>
            '${{ "' + prefix + '" + run.id }}'
        );
        for (const job of workflow.jobs) {
            for (const { task } of job.steps) {
                if (task.behaviorType !== "@swamp/ssh") continue;
                assertEquals(
                    expressions.includes(task.behaviorName ?? ""),
                    true,
                );
                assertEquals(task.globalArgs?.name, task.behaviorName);
            }
        }
        // OpenSSH appends a random suffix while publishing its control socket.
        // Official safeFleetDir budgets the final path, so keep the full name short.
        for (const prefix of prefixes) {
            for (const base of ["/tmp/swamp-ssh", "/run/user/1000/swamp-ssh"]) {
                const directory = base + "/" + prefix + crypto.randomUUID();
                const temporary = directory +
                    "/afebf7b4834a.sock.HBR1rp5mk66BeRYl";
                assertEquals(
                    new TextEncoder().encode(temporary).length < 108,
                    true,
                );
            }
        }
    }
});
