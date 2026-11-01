import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { integrations } from "./mod.ts";

Deno.test("retired WebSocket role callback kinds cannot claim work or read Gateway intent", async () => {
  let reads = 0;
  const root = integrations(() => {
    reads++;
    throw new Error("No Gateway access is permitted");
  });
  for (const kind of ["websocket.role_converge", "websocket.role_remove"]) {
    await assertRejects(() => root.methods.execute.execute({ kind, worker: "old-worker" }, {
      globalArgs: { gatewayUrl: "https://gateway.test", caFile: "/tmp/public-ca.pem" },
      readResource: () => Promise.resolve(null),
      writeResource: () => Promise.resolve({ name: "unused" }),
    }), Error, "integration.kind_invalid");
  }
  assertEquals(reads, 0);
});
