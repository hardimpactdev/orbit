import { assertEquals } from "jsr:@std/assert@1.0.19";

Deno.test("clone guest supports non-PHP readiness, preserves agent settings and redacts command failures", async () => {
    const result = await new Deno.Command("python3", {
        args: [new URL("../../../../tests/instance_clone_guest_test.py", import.meta.url).pathname],
    }).output();
    assertEquals(result.code, 0, new TextDecoder().decode(result.stderr));
});

Deno.test("clone Node preserves safe guest diagnostics and rejects private output", async () => {
    const result = await new Deno.Command("python3", {
        args: [new URL("../../../../tests/instance_clone_test.py", import.meta.url).pathname],
    }).output();
    assertEquals(result.code, 0, new TextDecoder().decode(result.stderr));
});
