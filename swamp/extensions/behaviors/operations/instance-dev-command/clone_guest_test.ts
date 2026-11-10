import { assertEquals } from "jsr:@std/assert@1.0.19";

Deno.test("clone agent files preserve runtime settings, reject corrupt JSON and repeat without changes", async () => {
    const result = await new Deno.Command("python3", {
        args: [new URL("../../../../tests/instance_clone_guest_test.py", import.meta.url).pathname],
    }).output();
    assertEquals(result.code, 0, new TextDecoder().decode(result.stderr));
});
