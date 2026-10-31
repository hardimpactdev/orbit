import { assertEquals } from "jsr:@std/assert@1.0.19";
import { Globals } from "./mod.ts";

Deno.test("Gateway TLS requires a nonempty explicit or default CA path", () => {
  assertEquals(Globals.safeParse({ caFile: "" }).success, false);
  assertEquals(Globals.parse({}).caFile.length > 0, true);
  assertEquals(
    Globals.parse({ caFile: "/usr/local/share/ca-certificates/orbit-root.crt" })
      .caFile,
    "/usr/local/share/ca-certificates/orbit-root.crt",
  );
});
