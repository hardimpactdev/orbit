import { assertEquals, assertRejects } from "jsr:@std/assert@1";
import { behavior } from "./cleanup_input.ts";

Deno.test("cleanup metadata resolves the fixed shipped gate without performing effects", async () => {
    const writes: unknown[] = [];
    const result = await behavior.methods.prepare.execute({}, {
        extensionFile: (name) => `/fixture/cache/${name}`,
        writeResource: (spec, name, value) => {
            writes.push({ spec, name, value });
            return Promise.resolve();
        },
    });
    assertEquals(result, {
        script: "/fixture/cache/scripts/prepare_cleanup.py",
    });
    assertEquals(writes, [{ spec: "input", name: "cleanup", value: result }]);
});

Deno.test("cleanup metadata rejects caller-controlled paths before resolving any asset", async () => {
    await assertRejects(() =>
        behavior.methods.prepare.execute({ path: "/foreign" } as never, {
            extensionFile: () => {
                throw new Error("must not resolve");
            },
            writeResource: () => {
                throw new Error("must not write");
            },
        })
    );
});
