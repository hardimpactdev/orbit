import { assertEquals, assertThrows } from "jsr:@std/assert@1.0.19";
import { publicKey } from "./assets.ts";
Deno.test("the decode reads one bare public key and refuses anything else", () => {
    const key = "A".repeat(43) + "=";
    assertEquals(publicKey(key + "\n"), { public_key: key });
    assertThrows(() => publicKey('{"public_key":"' + key + '"}'));
    assertThrows(() => publicKey(""));
});
