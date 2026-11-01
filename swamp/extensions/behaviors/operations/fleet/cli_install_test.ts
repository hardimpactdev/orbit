const source = await Deno.readTextFile(
    new URL("../fleet-recipe/scripts/cli.py", import.meta.url),
);
import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { installCli } from "./cli_install.ts";
import type { NodeCommand } from "../_lib/node-commands.ts";

const input = {
    user: "orbit",
    release: {
        version: "0.12.0",
        url: "https://example.invalid/orbit-0.12.0-linux-x86_64",
        sha256: "a".repeat(64),
    },
    profile: "admitted profile",
};
const reply = (data: unknown) => ({
    success: true,
    code: 0,
    stdout: new TextEncoder().encode(JSON.stringify(data)),
});
Deno.test("shared CLI lower uses one fixed system program with admitted stdin on the placed runner", async () => {
    const calls: Parameters<NodeCommand>[] = [];
    const result = await installCli(input, {
        source,
        command: (...args) => {
            calls.push(args);
            return Promise.resolve(
                reply({ installed: "0.12.0", configured: true }),
            );
        },
    });
    assertEquals(result, { installed: "0.12.0", configured: true });
    assertEquals(calls.length, 1);
    assertEquals(calls[0][0].slice(0, 3), ["/usr/bin/python3", "-I", "-c"]);
    assertEquals(calls[0][0][3].includes("orbit-agent"), false);
    assertEquals(JSON.parse(new TextDecoder().decode(calls[0][1])), input);
    assertEquals(calls[0][2], "root");
    assertEquals(calls[0][3], { deadlineMs: 720000, maxOutputBytes: 4096 });
});
Deno.test("CLI lower refuses cancellation before execution and preserves the bounded effect after it starts", async () => {
    const before = new AbortController();
    before.abort();
    let calls = 0;
    const command: NodeCommand = () => {
        calls++;
        return Promise.resolve(reply({ installed: null, configured: false }));
    };
    await assertRejects(() =>
        installCli(input, { source, signal: before.signal, command })
    );
    assertEquals(calls, 0);
    const after = new AbortController();
    assertEquals(
        await installCli(input, {
            source,
            signal: after.signal,
            command: () => {
                after.abort();
                return Promise.resolve(
                    reply({ installed: null, configured: false }),
                );
            },
        }),
        { installed: null, configured: false },
    );
});
Deno.test("CLI lower returns declared refusals and rejects uncertain or mismatched outcomes", async () => {
    assertEquals(
        await installCli(input, {
            source,
            command: () =>
                Promise.resolve(reply({ error_code: "cli.checksum_mismatch" })),
        }),
        { error_code: "cli.checksum_mismatch" },
    );
    await assertRejects(() =>
        installCli(input, {
            source,
            command: () =>
                Promise.resolve({
                    success: false,
                    code: 1,
                    stdout: new Uint8Array(),
                }),
        })
    );
    await assertRejects(() =>
        installCli(input, {
            source,
            command: () =>
                Promise.resolve(
                    reply({ installed: "0.13.0", configured: true }),
                ),
        })
    );
});
