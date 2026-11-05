import { assertEquals, assertRejects, assertThrows } from "jsr:@std/assert@1";
import { parse } from "jsr:@std/yaml@1.0.12";
import { account, behavior } from "./assets.ts";

Deno.test("the account comes from its passwd line and primary group", () => {
    assertEquals(
        account({
            passwd: "orbit:x:1000:1000:Orbit:/home/orbit:/bin/bash\n",
            group: "orbit\n",
        }),
        { user: "orbit", group: "orbit", home: "/home/orbit" },
    );
});

Deno.test("a malformed passwd line or group fails the step", () => {
    assertThrows(
        () => account({ passwd: "", group: "orbit" }),
        Error,
        "account.passwd_invalid",
    );
    assertThrows(() =>
        account({
            passwd: "orbit:x:1000:1000::relative:/bin/sh",
            group: "orbit",
        })
    );
    assertThrows(() =>
        account({ passwd: "orbit:x:1000:1000::/home/orbit:/bin/sh", group: "" })
    );
});

Deno.test("the account is written as a native resource and the manifest version matches", async () => {
    const writes: unknown[] = [];
    const ctx = {
        writeResource: (...args: unknown[]) =>
            Promise.resolve(writes.push(args)),
    };
    const result = await behavior.methods.account.execute(
        { passwd: "orbit:x:1000:1000::/home/orbit:/bin/sh", group: "orbit" },
        ctx,
    );
    assertEquals(writes, [["result", "result", result]]);
    await assertRejects(() =>
        behavior.methods.account.execute(
            { passwd: "x", group: "orbit", extra: 1 } as never,
            ctx,
        )
    );
    const manifest = parse(
        await Deno.readTextFile(new URL("manifest.yaml", import.meta.url)),
    ) as { version: string };
    assertEquals(manifest.version, behavior.version);
});
