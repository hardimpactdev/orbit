import { assertEquals } from "jsr:@std/assert@1.0.19";

const source = await Deno.readTextFile(
    new URL("../scripts/gateway-release-snapshot.sh", import.meta.url),
);
const decoder = new TextDecoder();

for (const paused of [undefined, "pre-old-1.sqlite", "pre-old-4.sqlite"]) {
    Deno.test(`snapshots keep the newest two with pause ${paused ?? "absent"}`, async () => {
        const folder = await Deno.makeTempDir();
        try {
            const backups = `${folder}/.orbit/backups`;
            await Deno.mkdir(backups, { recursive: true });
            for (let i = 1; i <= 4; i++) {
                const path = `${backups}/pre-old-${i}.sqlite`;
                await Deno.writeTextFile(path, `old snapshot ${i}`);
                const time = new Date(Date.UTC(2020, 0, i));
                await Deno.utime(path, time, time);
            }
            await Deno.writeTextFile(
                `${backups}/gateway-manual.sqlite`,
                "manual",
            );
            await Deno.writeTextFile(
                `${backups}/pre-interrupted.sqlite.partial`,
                "partial",
            );
            if (paused) {
                await Deno.writeTextFile(
                    `${folder}/.orbit/gateway-release.paused`,
                    JSON.stringify({ snapshot: `${backups}/${paused}` }),
                );
            }
            const created = await snapshot(folder);
            const names = await Array.fromAsync(
                Deno.readDir(backups),
                (entry) => entry.name,
            );

            assertEquals(
                names.sort(),
                [
                    "gateway-manual.sqlite",
                    "pre-interrupted.sqlite.partial",
                    "pre-old-4.sqlite",
                    ...(paused === "pre-old-1.sqlite" ? [paused] : []),
                    created.slice(backups.length + 1),
                ].sort(),
            );
        } finally {
            await Deno.remove(folder, { recursive: true });
        }
    });
}

Deno.test("the first release creates a private, readable database snapshot", async () => {
    const folder = await Deno.makeTempDir();
    try {
        const created = await snapshot(folder);
        const contents = await new Deno.Command("php8.5", {
            args: [
                "-r",
                '$db = new PDO("sqlite:".$argv[1]); echo $db->query("SELECT value FROM proof")->fetchColumn();',
                created,
            ],
            stdout: "piped",
            stderr: "piped",
        }).output();

        assertEquals(contents.code, 0, decoder.decode(contents.stderr));
        assertEquals(decoder.decode(contents.stdout), "retained");
        assertEquals((await Deno.stat(created)).mode! & 0o777, 0o600);
        assertEquals(
            (await Deno.stat(`${folder}/.orbit/backups`)).mode! & 0o777,
            0o700,
        );
    } finally {
        await Deno.remove(folder, { recursive: true });
    }
});

async function snapshot(folder: string): Promise<string> {
    const cache =
        `${folder}/releases/aaaaaaaaaaaa/apps/gateway/bootstrap/cache`;
    await Deno.mkdir(cache, { recursive: true });
    await Deno.writeTextFile(
        `${cache}/config.php`,
        "<?php return ['database' => ['connections' => ['sqlite' => ['database' => __DIR__.'/database.sqlite']]]];",
    );
    const database = await new Deno.Command("php8.5", {
        args: [
            "-r",
            '$db = new PDO("sqlite:".$argv[1]); $db->exec("CREATE TABLE proof (value TEXT); INSERT INTO proof VALUES (\'retained\')");',
            `${cache}/database.sqlite`,
        ],
        stdout: "piped",
        stderr: "piped",
    }).output();
    assertEquals(database.code, 0, decoder.decode(database.stderr));

    const result = await new Deno.Command("bash", {
        args: [
            "-c",
            source.replaceAll("/home/orbit", folder),
            "snapshot",
            "aaaaaaaaaaaa",
        ],
        stdout: "piped",
        stderr: "piped",
    }).output();
    assertEquals(result.code, 0, decoder.decode(result.stderr));
    return decoder.decode(result.stdout).trim();
}
