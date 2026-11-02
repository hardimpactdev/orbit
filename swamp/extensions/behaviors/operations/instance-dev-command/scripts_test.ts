import { assertEquals } from "jsr:@std/assert@1.0.19";
import { fromFileUrl } from "jsr:@std/path@1.1.2";
const root = fromFileUrl(new URL(".", import.meta.url));
for (
    const name of [
        "source_actions_test.py",
        "project_step_test.py",
        "create_phases_test.py",
    ]
) {
    Deno.test(
        "Instance source and Project command contract: " + name,
        async () => {
            const reply = await new Deno.Command("/usr/bin/python3", {
                args: ["-B", name],
                cwd: root,
                stdout: "piped",
                stderr: "piped",
            }).output();
            assertEquals(reply.code, 0, new TextDecoder().decode(reply.stderr));
        },
    );
}
