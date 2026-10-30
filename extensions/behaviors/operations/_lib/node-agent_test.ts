import { assertEquals, assertThrows } from "jsr:@std/assert@1.0.19";
import { agentLaunch, agentReply, type NodeCall } from "./node-agent.ts";
import { firewallAgent } from "../firewall/local.ts";

const call: NodeCall = {
  name: "firewall.rule",
  input: { name: "canary", operation: "converge" },
  pin: "a".repeat(64),
  sudo: true,
  mutates: true,
  deadlineMs: 120000,
};
Deno.test("sudo launch is fixed argv with a frozen admitted envelope", () => {
  assertEquals(agentLaunch(call), {
    command: "/usr/bin/sudo",
    args: [
      "-n",
      "--",
      "/usr/local/bin/orbit-agent",
      "command",
      "firewall.rule",
    ],
    envelope: {
      agent_sha256: call.pin,
      deadline_ms: 120000,
      input: call.input,
    },
  });
  assertEquals(
    agentLaunch({ ...call, sudo: false }).command,
    "/usr/local/bin/orbit-agent",
  );
});
Deno.test("Firewall strips the pin from rule input and uses a bounded sudo action", async () => {
  const calls: NodeCall[] = [];
  await firewallAgent((c) => {
    calls.push(c);
    return Promise.resolve({ backend: "active" });
  })({ ...call.input, agent_sha256: call.pin });
  assertEquals(calls, [{ ...call, maxOutputBytes: 16384 }]);
});
Deno.test("refusal diagnostics and malformed output never escape the local step", () => {
  const bytes = (value: unknown) =>
    new TextEncoder().encode(JSON.stringify(value));
  assertEquals(
    agentReply(
      bytes({
        ok: false,
        error: {
          code: "agent.version_mismatch",
          message: "SECRET",
          details: { stderr: "SECRET" },
        },
      }),
      false,
    ),
    { error_code: "agent.version_mismatch" },
  );
  for (
    const value of [{ ok: false, error: { code: "SECRET\n" } }, {
      ok: true,
      data: [],
    }, null]
  ) {
    assertThrows(
      () => agentReply(bytes(value), true),
      Error,
      "The Node action returned no typed result",
    );
  }
  assertThrows(
    () => agentReply(new TextEncoder().encode("SECRET invalid output"), false),
    Error,
    "The Node action returned no typed result",
  );
  assertThrows(
    () => agentReply(bytes({ ok: true, data: {} }), false),
    Error,
    "The Node action outcome is uncertain",
  );
});
