// Local native integration: requires the pinned Swamp CLI and official SSH extension.
import { assertEquals } from "jsr:@std/assert@1.0.14";
import { fromFileUrl } from "jsr:@std/path@1.1.2";

Deno.test("development deployment native ordering, failure guards and private stdin", async () => {
    const result = await new Deno.Command("python3", {
        args: ["-m", "unittest", "development_deploy_native_test", "-q"],
        cwd: fromFileUrl(new URL(".", import.meta.url)),
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
