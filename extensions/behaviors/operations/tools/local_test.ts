import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { toolProgram, toolSystem, type ToolSystemCall } from "./local.ts";
import { ToolIntentInput } from "./direct-contract.ts";

const node = ToolIntentInput.parse({
  node_id: 209,
  manager: "apt",
  platform: "linux",
  architecture: "x86_64",
  user: "candidate",
});
Deno.test("lower adapter preserves the exact admitted candidate and typed package", async () => {
  const calls: ToolSystemCall[] = [];
  const run = toolSystem((call) => {
    calls.push(call);
    return Promise.resolve({ version: "apt 3.2.0" });
  });
  assertEquals(await run("tool.apt-install", { package: "hello" }, node), {
    version: "apt 3.2.0",
  });
  assertEquals(calls, [{
    name: "tool.apt-install",
    input: { package: "hello" },
    node,
  }]);
  assertEquals("agent_sha256" in node, false);
});
Deno.test("cancelled native admission starts no system program", async () => {
  let calls = 0;
  const run = toolSystem(() => {
    calls++;
    return Promise.resolve({});
  });
  await assertRejects(() =>
    run("tool.apt-install", { package: "hello" }, node, AbortSignal.abort())
  );
  assertEquals(calls, 0);
});
Deno.test("native implementation contains no agent or shell dispatch", () => {
  assertEquals(
    /orbit-agent|shell\s*=\s*True|os\.system\(/.test(toolProgram),
    false,
  );
});
