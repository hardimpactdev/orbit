import { assertEquals } from "jsr:@std/assert@1.0.19";

Deno.test("database:destroy refuses blockers before any drop and proves the names are gone", async () => {
    const result = await new Deno.Command("python3", {
        args: ["-I", new URL("database_destroy_test.py", import.meta.url).pathname],
        stdout: "piped",
        stderr: "piped",
    }).output();
    assertEquals(result.success, true, new TextDecoder().decode(result.stderr));
});
