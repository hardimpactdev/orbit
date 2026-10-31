import { assertEquals } from "jsr:@std/assert@1.0.14";
import { localAgent, type LocalCommand } from "./agent.ts";

Deno.test("local Process and Schedule preflight refuses a different admitted build without sudo", async () => {
  for (
    const command of ["process.remove", "schedule.remove"] as LocalCommand[]
  ) {
    assertEquals(await localAgent(command, {}, "0".repeat(64)), {
      error_code: "operation.agent_invalid",
    });
  }
});
