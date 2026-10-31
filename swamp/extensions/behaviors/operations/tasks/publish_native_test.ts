import { assertEquals } from "jsr:@std/assert@1";
import { fromFileUrl } from "jsr:@std/path@1";

Deno.test("native publication actions retain exact Git/PR identity and private token handling", async () => {
    const result = await new Deno.Command("python3", {
        args: ["-m", "unittest", "publication_actions_test.py", "-v"],
        cwd: fromFileUrl(new URL("../task-repository/", import.meta.url)),
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
