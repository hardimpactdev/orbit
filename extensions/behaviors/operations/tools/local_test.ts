import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { aptReader } from "./local.ts";
import { ToolIntentInput } from "./direct-contract.ts";

const node = ToolIntentInput.parse({
  node_id: 209,
  manager: "apt",
  platform: "linux",
  architecture: "x86_64",
  user: "orbit",
  agent_sha256: "a".repeat(64),
});

Deno.test("APT account reads use only closed commands with the admitted agent pin", async () => {
  const calls: unknown[] = [];
  const read = aptReader((name, pin) => {
    calls.push([name, pin]);
    return Promise.resolve({ ok: true, data: { version: "apt 3.0.3" } });
  });
  for (const name of ["tool.apt-version", "tool.apt-inventory"]) {
    assertEquals(await read(name, {}, node), { version: "apt 3.0.3" });
  }
  assertEquals(calls, [["tool.apt-version", node.agent_sha256], [
    "tool.apt-inventory",
    node.agent_sha256,
  ]]);
});

Deno.test("mutations other managers platforms and caller input refuse before invoking the agent", async () => {
  let calls = 0;
  const read = aptReader(() => {
    calls++;
    return Promise.resolve({ ok: true, data: {} });
  });
  for (
    const name of [
      "tool.apt-install",
      "tool.apt-update",
      "tool.apt-remove",
      "tool.materialize-vp",
      "tool.vp-version",
      "arbitrary",
    ]
  ) {
    await assertRejects(() => read(name, {}, node));
  }
  await assertRejects(() =>
    read("tool.apt-version", { command: "other" }, node)
  );
  await assertRejects(() =>
    read("tool.apt-version", {}, { ...node, manager: "vp" })
  );
  await assertRejects(() =>
    read("tool.apt-version", {}, { ...node, platform: "macos" })
  );
  assertEquals(calls, 0);
});

Deno.test("agent refusal returns only its bounded code and malformed replies stay uncertain", async () => {
  const refused = aptReader(() =>
    Promise.resolve({
      ok: false,
      error: {
        code: "agent.version_mismatch",
        message: "discard",
        details: { raw: "discard" },
      },
    })
  );
  assertEquals(await refused("tool.apt-version", {}, node), {
    error_code: "agent.version_mismatch",
  });
  for (
    const reply of [{ ok: false, error: { code: "raw\noutput" } }, {
      ok: true,
      data: [],
    }, null]
  ) {
    await assertRejects(() =>
      aptReader(() => Promise.resolve(reply))("tool.apt-version", {}, node)
    );
  }
});
