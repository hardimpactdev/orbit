import { assertEquals } from "jsr:@std/assert@1.0.19";
Deno.test("disposable Valkey connection update validates indexes without changing configuration", async () => {
    const result = await new Deno.Command("python3", {
        args: [
            "-B",
            new URL("./connection_update_effect_fixture.py", import.meta.url)
                .pathname,
        ],
        stdout: "piped",
        stderr: "piped",
    }).output();
    assertEquals(result.success, true, new TextDecoder().decode(result.stderr));
    assertEquals(JSON.parse(new TextDecoder().decode(result.stdout)), {
        valid: true,
        out_of_range: true,
        wrong_password_refused: true,
        configuration_unchanged: true,
    });
});
