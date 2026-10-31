import { assertEquals } from "jsr:@std/assert@1.0.13";
import { due, native } from "./rotation.ts";
const hour = 3600000;
Deno.test("old week-long access tokens and invalid timestamps are due for bounded replacement", () => {
    const now = Date.parse("2026-10-31T07:00:00Z");
    const token = {
        name: "orbit-node-209",
        state: "active",
        createdAt: new Date(now).toISOString(),
        expiresAt: new Date(now + 30 * 24 * hour).toISOString(),
    };
    assertEquals(due(token, now), true);
    assertEquals(due({ ...token, createdAt: "invalid" }, now), true);
});

Deno.test("native token reads use the monorepo root for both cwd and repo-dir", async () => {
    const descriptor = Object.getOwnPropertyDescriptor(Deno, "Command")!;
    const calls: Deno.CommandOptions[] = [];
    const output = {
        success: true,
        code: 0,
        signal: null,
        stdout: new TextEncoder().encode("[]"),
        stderr: new Uint8Array(),
    };
    class FixtureCommand {
        constructor(
            readonly command: string | URL,
            readonly options?: Deno.CommandOptions,
        ) {}
        outputSync() {
            assertEquals(this.command, "/usr/bin/timeout");
            return output;
        }
        output() {
            assertEquals(this.command, "/home/orbit/.local/bin/swamp");
            calls.push(this.options!);
            return Promise.resolve(output);
        }
    }
    Object.defineProperty(Deno, "Command", {
        value: FixtureCommand,
        configurable: true,
    });
    try {
        assertEquals(await native(["worker", "token", "list"]), []);
        assertEquals(calls.length, 1);
        const repository =
            "/home/orbit/apps/orbit-monorepo-staging/checkout/swamp";
        assertEquals(calls[0].cwd, repository);
        assertEquals(calls[0].args, [
            "worker",
            "token",
            "list",
            "--repo-dir",
            repository,
            "--json",
        ]);
    } finally {
        Object.defineProperty(Deno, "Command", descriptor);
    }
});
