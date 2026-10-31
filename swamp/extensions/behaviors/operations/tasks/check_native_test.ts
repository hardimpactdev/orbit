import { assertEquals } from "jsr:@std/assert@1";
Deno.test("native check actions retain candidate and deliverable contracts", async () => {
    const result = await new Deno.Command("python3", {
        args: ["-B", "-m", "unittest", "check_actions_test"],
        cwd: new URL("../task-repository", import.meta.url),
        stdout: "piped",
        stderr: "piped",
    }).output();
    assertEquals(
        result.success,
        true,
        new TextDecoder().decode(result.stdout) +
            new TextDecoder().decode(result.stderr),
    );
});
