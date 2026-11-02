import { assertEquals } from "jsr:@std/assert@1.0.19";

Deno.test("database:server:destroy removes every Process container and keeps the volume", async () => {
    const result = await new Deno.Command("python3", {
        args: ["-I", new URL("server_destroy_test.py", import.meta.url).pathname],
        stdout: "piped",
        stderr: "piped",
    }).output();
    assertEquals(result.success, true, new TextDecoder().decode(result.stderr));
});
