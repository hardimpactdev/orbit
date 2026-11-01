import { assertEquals } from "jsr:@std/assert@1.0.19";
Deno.test("shipped SQLite files preserve seed ownership, integrity, replay and cleanup", async () => {
    const result = await new Deno.Command("/usr/bin/sudo", {
        args: [
            "-n",
            "--",
            "/usr/bin/python3",
            "-B",
            new URL("./sqlite_scripts_test.py", import.meta.url).pathname,
        ],
        stdout: "piped",
        stderr: "piped",
    }).output();
    assertEquals(result.success, true, new TextDecoder().decode(result.stderr));
});
