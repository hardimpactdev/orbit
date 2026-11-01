import { assertEquals } from "jsr:@std/assert@1.0.19";
import { behavior } from "../database-server-commands/close_assets.ts";
const ctx = {
    extensionFile: (path: string) => "/cache/" + path,
    writeResource: () => Promise.resolve(),
};
const ready = {
    status: "succeeded",
    outputs: { exitCode: 0, stdout: '{"ready":true}' },
};
const skipped = { status: "skipped" };
Deno.test("closure decoder refuses missing, malformed and nonzero probe outcomes", async () => {
    for (
        const stdout of [
            "",
            "{}",
            '{"ready":true}',
            '{"ready":false,"was_open":true}',
        ]
    ) {
        assertEquals(
            await behavior.methods.probe.execute({ stdout, exit_code: 0 }, ctx),
            { ready: false, was_open: false },
        );
    }
    assertEquals(
        await behavior.methods.probe.execute({
            stdout: '{"ready":true,"was_open":true}',
            exit_code: 1,
        }, ctx),
        { ready: false, was_open: false },
    );
});
Deno.test("closure result requires persistence, disconnect and scratch cleanup", async () => {
    const steps = {
        saved: { status: "succeeded" },
        disconnect: ready,
        cleanup_files: { status: "succeeded" },
        restore: skipped,
    };
    assertEquals(
        JSON.parse(
            (await behavior.methods.result.execute({
                default_open: true,
                steps,
            }, ctx)).stdout,
        ),
        { backend: "active" },
    );
    for (const name of ["saved", "disconnect", "cleanup_files"]) {
        const result = await behavior.methods.result.execute({
            default_open: true,
            steps: { ...steps, [name]: skipped },
        }, ctx);
        assertEquals(JSON.parse(result.stdout), {
            error_code: "database.server_command_failed",
        });
    }
});
Deno.test("closure restore failure overrides earlier refusal and preserves unknown access", async () => {
    const result = await behavior.methods.result.execute({
        default_open: true,
        steps: {
            restore: { status: "failed" },
            probe: {
                status: "succeeded",
                outputs: { stdout: '{"error_code":"database.server_in_use"}' },
            },
        },
    }, ctx);
    assertEquals(JSON.parse(result.stdout), {
        error_code: "database.server_restore_failed",
        default_open: "unknown",
    });
});
Deno.test("closed intent needs only its password probe and cleanup", async () => {
    const result = await behavior.methods.result.execute({
        default_open: false,
        steps: {
            saved: { status: "succeeded" },
            disconnect: skipped,
            restore: skipped,
            cleanup_files: { status: "succeeded" },
        },
    }, ctx);
    assertEquals(JSON.parse(result.stdout), { backend: "active" });
});

Deno.test("closure scripts preserve client refusal, password delivery and bounded errors", async () => {
    const result = await new Deno.Command("python3", {
        args: [
            "-B",
            new URL(
                "../database-server-commands/server_close_scripts_test.py",
                import.meta.url,
            ).pathname,
        ],
        stdout: "piped",
        stderr: "piped",
    }).output();
    assertEquals(result.success, true, new TextDecoder().decode(result.stderr));
});
