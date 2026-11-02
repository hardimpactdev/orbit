import { assertEquals } from "jsr:@std/assert@1.0.19";
import { behavior } from "./assets.ts";

Deno.test("connection updates use one private official-client read and bounded errors", async () => {
    const result = await new Deno.Command("python3", {
        args: [
            "-B",
            new URL("./connection_update_scripts_test.py", import.meta.url)
                .pathname,
        ],
        stdout: "piped",
        stderr: "piped",
    }).output();
    assertEquals(result.success, true, new TextDecoder().decode(result.stderr));
});
Deno.test("connection update accepts typed outcomes and refuses missing or private output", async () => {
    const ctx = {
        extensionFile: (name: string) => name,
        writeResource: () => Promise.resolve(),
    };
    const decode = (stdout: string, exit_code = 0, record_only = false) =>
        behavior.methods.update_result.execute({
            stdout,
            exit_code,
            record_only,
        }, ctx);
    assertEquals(await decode('{"backend":"active"}'), {
        stdout: '{"backend":"active"}\n',
        exitCode: 0,
    });
    assertEquals(await decode('{"error_code":"database.index_invalid"}', 1), {
        stdout: '{"error_code":"database.index_invalid"}\n',
        exitCode: 1,
    });
    assertEquals(await decode("", -1, true), {
        stdout: '{"backend":"active"}\n',
        exitCode: 0,
    });
    for (
        const [stdout, code] of [
            ["", 0],
            ["private", 0],
            ['{"backend":"active","password":"private"}', 0],
            ['{"backend":"active"}', 1],
            ['{"error_code":"database.index_invalid"}', 0],
        ] as const
    ) {
        assertEquals(await decode(stdout, code), {
            stdout: '{"error_code":"database.server_command_failed"}\n',
            exitCode: 1,
        });
    }
});
