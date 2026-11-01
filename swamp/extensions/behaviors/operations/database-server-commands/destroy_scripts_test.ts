import { assertEquals } from "jsr:@std/assert@1.0.19";
Deno.test("database destruction preserves exact scopes, fences and ACL rollback material", async () => {
    const output = await new Deno.Command("python3", {
        args: [
            "-B",
            "-m",
            "unittest",
            "discover",
            "-s",
            new URL(".", import.meta.url).pathname,
            "-p",
            "destroy_scripts_test.py",
        ],
        stdout: "piped",
        stderr: "piped",
    }).output();
    assertEquals(output.success, true, new TextDecoder().decode(output.stderr));
});
