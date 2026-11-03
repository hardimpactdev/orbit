import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { createModelTestContext } from "jsr:@swamp-club/swamp-testing@0.20261016.58";
import { behavior, readCopy } from "./store.ts";

Deno.test("store scopes private content to the operation and returns only size and hash", async () => {
    const path = await Deno.makeTempFile();
    const content = 'APP_KEY="private-import"\n';
    await Deno.writeTextFile(path, content);
    try {
        const { context, getWrittenResources } = createModelTestContext();
        const result = await behavior.methods.store.execute({ path, operation: 41 }, context);
        const sha256 = "1c84ebc52edf0087f0bc52e780e4b1d0ed33f58d073692cd33810b3abf0ca252";
        assertEquals(result, { size: content.length, sha256 });
        assertEquals(getWrittenResources().map(({ specName, name, data }) => ({ specName, name, data })), [{
            specName: "file", name: "file",
            data: { size: content.length, sha256, content: { "41": content } },
        }]);
        await assertRejects(() => Deno.lstat(path), Deno.errors.NotFound);
    } finally {
        await Deno.remove(path).catch((error) => {
            if (!(error instanceof Deno.errors.NotFound)) throw error;
        });
    }
});

Deno.test("the copy is read once, hashed and deleted", async () => {
    const path = await Deno.makeTempFile();
    await Deno.writeTextFile(path, "APP_KEY=secret\n");
    const file = await readCopy(path);
    assertEquals(file.content, "APP_KEY=secret\n");
    assertEquals(file.size, 15);
    assertEquals(
        file.sha256,
        "745140411ae46162b1f701f8a802f3d87d25d51dba411b97011fba272c511007",
    );
    await assertRejects(() => Deno.lstat(path));
});

Deno.test("a copy over 1 MiB is refused", async () => {
    const path = await Deno.makeTempFile();
    await Deno.writeFile(path, new Uint8Array(1048577));
    await assertRejects(
        () => readCopy(path),
        Error,
        "env.import_source_missing",
    );
});
