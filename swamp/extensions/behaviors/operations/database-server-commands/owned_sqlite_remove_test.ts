import { assertEquals } from "jsr:@std/assert@1.0.19";
Deno.test("owned SQLite removal preserves checkout, symlink and owner guards on real files", async () => {
    const result = await new Deno.Command("python3", {
        args: [
            "-B",
            new URL("./owned_sqlite_remove_test.py", import.meta.url).pathname,
        ],
        stdout: "piped",
        stderr: "piped",
    }).output();
    assertEquals(result.success, true, new TextDecoder().decode(result.stderr));
});
