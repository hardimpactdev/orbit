import { assertEquals } from "jsr:@std/assert@1.0.14";
import { fromFileUrl } from "jsr:@std/path@1.1.2";

const root = fromFileUrl(new URL(".", import.meta.url));
for (
    const name of [
        "scripts_test.py",
        "installation_test.py",
        "material_delivery_test.py",
        "docker_test.py",
        "docker_effect_test.py",
    ]
) {
    Deno.test("shared Process script effects: " + name, async () => {
        const reply = await new Deno.Command("/usr/bin/python3", {
            args: ["-m", "unittest", name, "-v"],
            cwd: root,
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
}
