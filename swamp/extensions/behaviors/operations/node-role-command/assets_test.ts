import { assertEquals, assertRejects } from "jsr:@std/assert@1";
import { behavior } from "./assets.ts";

Deno.test("public role asset delivery reads only a fixed action and native result resource", async () => {
    const writes: unknown[] = [];
    const result = await behavior.methods.read.execute({
        action: "account",
        input: { user: "orbit" },
    }, {
        extensionFile: (file) => new URL(file, import.meta.url).pathname,
        writeResource: (...args) => Promise.resolve(writes.push(args)),
    });
    assertEquals(
        result.script_body.includes('ROLE_INPUT = json.loads("{'),
        true,
    );
    assertEquals(writes, [["assets", "assets", result]]);
    await assertRejects(() =>
        behavior.methods.read.execute(
            {
                action: "account",
                input: { user: "orbit", private: "must-not-read" },
            } as never,
            {
                extensionFile: () => {
                    throw new Error("Invalid input read the asset");
                },
                writeResource: () => Promise.resolve(),
            },
        )
    );
});
Deno.test("only typed account or Caddy completion leaves the role decoder", async () => {
    const writes: unknown[] = [];
    const ctx = {
        writeResource: (...args: unknown[]) =>
            Promise.resolve(writes.push(args)),
    };
    assertEquals(
        await behavior.methods.decode.execute({
            stdout: '{"user":"orbit","group":"orbit","home":"/home/orbit"}',
        }, ctx),
        { user: "orbit", group: "orbit", home: "/home/orbit" },
    );
    assertEquals(
        await behavior.methods.decode.execute({
            stdout: '{"caddy_installed":true}',
        }, ctx),
        { caddy_installed: true },
    );
    await assertRejects(() =>
        behavior.methods.decode.execute(
            { stdout: '{"caddy_installed":false}' },
            ctx,
        )
    );
    assertEquals(writes.length, 2);
});
