import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import type { NodeCommand } from "../_lib/node-commands.ts";
import { NodeRelease, publishCli, publishObserver } from "./release_local.ts";
const CliInstallProgram = await Deno.readTextFile(
    new URL("../fleet-recipe/scripts/cli.py", import.meta.url),
);
const node = NodeRelease.parse({
    node_id: 2,
    user: "node",
    profile: "{}",
    skip_reason: null,
    plan_error: null,
    release: {
        version: "0.100.0",
        url: "https://example.com/cli",
        sha256: "a".repeat(64),
    },
    agent: {
        version: "0.5.0",
        url: "https://example.com/agent",
        sha256: "b".repeat(64),
    },
    daytime: true,
});
Deno.test("fleet CLI publication uses the canonical lower and observes as the managed user", async () => {
    const calls: Parameters<NodeCommand>[] = [];
    const command: NodeCommand = (...args) => {
        calls.push(args);
        return Promise.resolve({
            success: true,
            code: 0,
            stdout: new TextEncoder().encode(
                calls.length === 1
                    ? JSON.stringify({ installed: null, configured: false })
                    : "Orbit 0.101.0\n",
            ),
        });
    };
    assertEquals(
        await publishCli(node, command, undefined, CliInstallProgram),
        {
            installed: null,
            configured: false,
            version: "0.101.0",
        },
    );
    assertEquals(calls[0][0], [
        "/usr/bin/python3",
        "-I",
        "-c",
        CliInstallProgram,
    ]);
    assertEquals(JSON.parse(new TextDecoder().decode(calls[0][1])), {
        user: "node",
        release: node.release,
        profile: "{}",
    });
    assertEquals(calls[1][0], ["/usr/local/bin/orbit", "--version"]);
    assertEquals(calls[1][2], "node");
});
Deno.test("foreign CLI refusal runs no version or observer command", async () => {
    let count = 0;
    const command: NodeCommand = () => {
        count++;
        return Promise.resolve({
            success: true,
            code: 0,
            stdout: new TextEncoder().encode(
                '{"error_code":"cli.foreign_binary"}',
            ),
        });
    };
    assertEquals(
        await publishCli(node, command, undefined, CliInstallProgram),
        {
            error_code: "cli.foreign_binary",
        },
    );
    assertEquals(count, 1);
});
Deno.test("observer publication refuses cancellation before an effect and a different pin afterward", async () => {
    const cancelled = AbortSignal.abort();
    const command: NodeCommand = () => Promise.reject(new Error("effect ran"));
    await assertRejects(
        () => publishObserver(node, command, cancelled),
        DOMException,
    );
    await assertRejects(
        () =>
            publishObserver(node, () =>
                Promise.resolve({
                    success: true,
                    code: 0,
                    stdout: new TextEncoder().encode(
                        '{"changed":false,"version":"another"}',
                    ),
                })),
        Error,
        "differs from the admitted pin",
    );
});
