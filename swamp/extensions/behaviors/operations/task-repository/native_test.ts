import { assertEquals } from "jsr:@std/assert@1";
import { fromFileUrl } from "jsr:@std/path@1";
Deno.test("repository workflows use official SSH and native resume", async () => {
    const reply = await new Deno.Command("python3", {
        args: ["-m", "unittest", "native_test.py", "-v"],
        cwd: fromFileUrl(new URL(".", import.meta.url)),
        stdout: "piped",
        stderr: "piped",
    }).output();
    assertEquals(
        reply.code,
        0,
        new TextDecoder().decode(reply.stdout) +
            new TextDecoder().decode(reply.stderr),
    );
});
