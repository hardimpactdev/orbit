import { assertEquals } from "jsr:@std/assert@1.0.14";
import { fromFileUrl } from "jsr:@std/path@1.1.2";

Deno.test("Schedule account and protected-stdin command validation", async () => {
    const reply = await new Deno.Command("python3", {
        args: ["-m", "unittest", "scripts_test.py"],
        cwd: fromFileUrl(new URL(".", import.meta.url)),
        stdout: "piped",
        stderr: "piped",
    }).output();
    assertEquals(reply.code, 0, new TextDecoder().decode(reply.stderr));
});
