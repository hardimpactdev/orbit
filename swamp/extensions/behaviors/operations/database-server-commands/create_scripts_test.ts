import { assertEquals } from "jsr:@std/assert@1.0.19";

Deno.test("database creation scripts preserve ownership, SQL policy and private client boundaries", async () => {
    const result = await new Deno.Command("python3", {
        args: [
            "-B",
            "-m",
            "unittest",
            "discover",
            "-s",
            new URL(".", import.meta.url).pathname,
            "-p",
            "create_scripts_test.py",
        ],
        stdout: "piped",
        stderr: "piped",
    }).output();
    assertEquals(result.success, true, new TextDecoder().decode(result.stderr));
});
