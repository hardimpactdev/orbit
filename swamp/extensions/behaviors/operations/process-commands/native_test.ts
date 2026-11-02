import { assertEquals } from "jsr:@std/assert@1.0.14";
import { fromFileUrl } from "jsr:@std/path@1.1.2";

Deno.test("Process lifecycle on native Swamp with official cfgmgmt and SSH", async () => {
    const result = await new Deno.Command("python3", {
        args: ["-m", "unittest", "native_test", "-v"],
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
