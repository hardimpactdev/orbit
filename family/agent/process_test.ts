import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { AgentCallError, captureProcess, STDOUT_LIMIT } from "./process.ts";

function run(source: string, input = "", signal = AbortSignal.timeout(5000)) {
  return captureProcess(Deno.execPath(), {
    args: ["eval", source],
    input,
    signal,
  });
}
Deno.test("bounded reader drains stderr and stdout while writing a large stdin", async () => {
  const result = await run(
    `await Deno.stderr.write(new Uint8Array(60000)); let n=0;for await (const c of Deno.stdin.readable)n+=c.length;console.log(JSON.stringify({n}));`,
    "x".repeat(1024 * 1024),
  );
  assertEquals(result.code, 0);
  assertEquals(JSON.parse(result.stdout), { n: 1024 * 1024 });
  assertEquals(Object.keys(result).sort(), ["code", "stdout"]);
});
Deno.test("stdout overflow kills a writer without retaining its full output", async () => {
  await assertRejects(
    () => run(`for(;;)await Deno.stdout.write(new Uint8Array(65536));`),
    AgentCallError,
    "agent.output_limit",
  );
});
Deno.test("stderr overflow kills a writer without exposing stderr", async () => {
  await assertRejects(
    () => run(`for(;;)await Deno.stderr.write(new Uint8Array(65536));`),
    AgentCallError,
    "agent.output_limit",
  );
});
Deno.test("exact output limit is accepted", async () => {
  const result = await run(
    `await Deno.stdout.write(new Uint8Array(${STDOUT_LIMIT}));`,
  );
  assertEquals(result.stdout.length, STDOUT_LIMIT);
});
Deno.test("timeout/cancel kills a child with open pipes", async () => {
  await assertRejects(
    () => run(`setInterval(()=>{},1000);`, "", AbortSignal.timeout(100)),
    AgentCallError,
    "agent.transport_uncertain",
  );
});
Deno.test("invalid UTF-8 and binary output do not become a public result", async () => {
  await assertRejects(
    () => run(`await Deno.stdout.write(new Uint8Array([255]));`),
    AgentCallError,
    "agent.transport_uncertain",
  );
});
Deno.test("child environment does not inherit dispatch secrets", async () => {
  Deno.env.set("ORBIT_TEST_DISPATCH_SECRET", "synthetic");
  try {
    const result = await run(
      `console.log(Deno.env.has("ORBIT_TEST_DISPATCH_SECRET"));`,
    );
    assertEquals(result.stdout.trim(), "false");
  } finally {
    Deno.env.delete("ORBIT_TEST_DISPATCH_SECRET");
  }
});
