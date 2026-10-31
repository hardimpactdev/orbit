import { assertEquals } from "jsr:@std/assert@1.0.19";

Deno.test("server clients keep credentials in removed private files and refuse unsafe adoption", async () => {
    const result = await new Deno.Command("python3", {
        args: [
            "-m",
            "unittest",
            "discover",
            "-s",
            new URL(".", import.meta.url).pathname,
            "-p",
            "scripts_test.py",
        ],
        stdout: "piped",
        stderr: "piped",
    }).output();
    assertEquals(result.success, true, new TextDecoder().decode(result.stderr));
});
