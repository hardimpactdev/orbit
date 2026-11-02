import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import type { NodeCommand } from "../_lib/node-commands.ts";
import { NodeRelease, publishObserver } from "./release_local.ts";
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
    ssh: null,
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
