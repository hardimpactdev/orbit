import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { readCopy } from "./store.ts";

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
