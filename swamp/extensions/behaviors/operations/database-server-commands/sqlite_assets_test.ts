import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { behavior } from "./sqlite_assets.ts";
const ctx = {
    extensionFile: (path: string) => "/bundle/" + path,
    writeResource: () => Promise.resolve(),
    createFileWriter: () => ({
        writeStream: () => Promise.resolve({ name: "snapshot", version: 1 }),
    }),
};
const output = (value: unknown) => ({
    status: "succeeded",
    outputs: { stdout: JSON.stringify(value), exitCode: 0 },
});
Deno.test("SQLite result projection requires every effect and cleanup, with no raw diagnostics", async () => {
    const result = behavior.methods.result.execute;
    assertEquals(
        await result({
            kind: "database.sqlite_cleanup",
            expected: ["cleanup"],
            steps: { cleanup: output({ backend: "absent" }) },
        }, ctx),
        { stdout: '{"backend":"absent"}\n', exitCode: 0 },
    );
    for (
        const steps of [{}, {
            cleanup: {
                status: "failed",
                outputs: { stdout: "private diagnostic", exitCode: 1 },
            },
        }, { cleanup: output({ backend: "absent", password: "private" }) }]
    ) {
        assertEquals(
            (await result({
                kind: "database.sqlite_cleanup",
                expected: ["cleanup"],
                steps: steps as Parameters<typeof result>[0]["steps"],
            }, ctx)).exitCode,
            1,
        );
    }
    assertEquals(
        (await result({
            kind: "database.sqlite_seed",
            expected: ["install", "cleanup"],
            steps: { install: output({ state: "changed" }) },
        }, ctx)).exitCode,
        1,
    );
});
Deno.test("SQLite action output retains bounded failures and rejects missing or excessive snapshot metadata", async () => {
    const action = behavior.methods.action_result.execute;
    assertEquals(
        await action({
            action: "target_install",
            stdout: '{"error_code":"instance.sqlite_seed_refused"}',
            exit_code: 1,
        }, ctx),
        {
            stdout: '{"error_code":"instance.sqlite_seed_refused"}\n',
            exitCode: 1,
        },
    );
    for (
        const stdout of [
            '{"state":"changed","sql":"private"}',
            "{}",
            "private",
            '{"state":"changed"}',
        ]
    ) {
        assertEquals(
            (await action(
                { action: "target_install", stdout, exit_code: 1 },
                ctx,
            )).exitCode,
            1,
        );
    }
    await assertRejects(() =>
        behavior.methods.snapshot.execute({
            stdout: JSON.stringify({
                bytes: 2 ** 40 + 1,
                sha256: "a".repeat(64),
            }),
            exit_code: 0,
        }, ctx)
    );
    const snapshot = { bytes: 4096, sha256: "a".repeat(64) };
    assertEquals(
        await behavior.methods.snapshot.execute({
            stdout: JSON.stringify(snapshot),
            exit_code: 0,
        }, ctx),
        { ref: snapshot, json: JSON.stringify(snapshot) },
    );
});
