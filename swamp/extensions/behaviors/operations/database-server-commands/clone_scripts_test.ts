import { assertEquals } from "jsr:@std/assert@1.0.19";
Deno.test("clone scripts preserve real SQLite snapshots, cleanup and MySQL replay boundary", async () => {
    const result = await new Deno.Command("python3", {
        args: [
            "-B",
            new URL("./clone_scripts_test.py", import.meta.url).pathname,
        ],
        stdout: "piped",
        stderr: "piped",
    }).output();
    assertEquals(result.success, true, new TextDecoder().decode(result.stderr));
});
