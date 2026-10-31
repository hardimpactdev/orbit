import { assertEquals } from "jsr:@std/assert@1.0.19";
import { Globals } from "./mod.ts";

Deno.test("Gateway calls use the public CA installed for every Node worker", () => {
  assertEquals(Globals.parse({}), {
    gatewayUrl: "https://gateway.orbit",
    caFile: "/usr/local/share/ca-certificates/orbit-root.crt",
  });
});

Deno.test("an explicit model CA setting remains an explicit override", () => {
  const caFile = "/usr/local/share/ca-certificates/orbit-managed-root-ca.crt";
  assertEquals(Globals.parse({ caFile }).caFile, caFile);
});

Deno.test("Gateway TLS requires a nonempty explicit or default CA path", () => {
  assertEquals(Globals.safeParse({ caFile: "" }).success, false);
  assertEquals(Globals.parse({}).caFile.length > 0, true);
  assertEquals(
    Globals.parse({ caFile: "/usr/local/share/ca-certificates/orbit-root.crt" })
      .caFile,
    "/usr/local/share/ca-certificates/orbit-root.crt",
  );
});
