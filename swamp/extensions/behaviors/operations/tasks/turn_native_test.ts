import { assertEquals } from "jsr:@std/assert@1";
import { fromFileUrl } from "jsr:@std/path@1";

Deno.test("turn action files preserve Git evidence and the existing Pi protocol", async () => {
    const result = await new Deno.Command("python3", {
        args: ["-m", "unittest", "actions_test.py", "-v"],
        cwd: fromFileUrl(new URL("../task-turn/", import.meta.url)),
        stdout: "piped",
        stderr: "piped",
    }).output();
    assertEquals(
        result.code,
        0,
        new TextDecoder().decode(result.stdout) +
            new TextDecoder().decode(result.stderr),
    );
});
