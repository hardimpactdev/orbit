import { assertEquals } from "jsr:@std/assert@1.0.19";

Deno.test("database:create stages the owner secret root-only and runs each driver in chart order", async () => {
    const result = await new Deno.Command("python3", {
        args: ["-I", new URL("database_create_test.py", import.meta.url).pathname],
        stdout: "piped",
        stderr: "piped",
    }).output();
    assertEquals(result.success, true, new TextDecoder().decode(result.stderr));
});
