import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { toolAgent } from "./local.ts";
import { ToolIntentInput } from "./direct-contract.ts";
import type { NodeCall } from "../_lib/node-agent.ts";

const node = ToolIntentInput.parse({
  node_id: 209,
  manager: "apt",
  platform: "linux",
  architecture: "x86_64",
  user: "orbit",
  agent_sha256: "a".repeat(64),
});
Deno.test("APT reads stay unprivileged and carry the admitted digest", async () => {
  const calls: NodeCall[] = [];
  const run = toolAgent((call) => {
    calls.push(call);
    return Promise.resolve({ version: "apt 3.2.0" });
  });
  for (
    const name of [
      "tool.apt-version",
      "tool.apt-inventory",
      "tool.apt-candidate",
      "tool.apt-removal-plan",
    ]
  ) {
    assertEquals(await run(name, {}, node), { version: "apt 3.2.0" });
  }
  assertEquals(
    calls.map((c) => [c.sudo, c.mutates, c.pin]),
    Array(4).fill([false, false, node.agent_sha256]),
  );
});
Deno.test("all Linux manager mutations use sudo while retaining typed package input", async () => {
  const calls: NodeCall[] = [];
  const run = toolAgent((call) => {
    calls.push(call);
    return Promise.resolve({});
  });
  for (
    const manager of ["apt", "composer", "vp", "brew", "brew-cask"] as const
  ) {
    const prefix = manager === "brew"
      ? "tool.homebrew-formula-"
      : manager === "brew-cask"
      ? "tool.homebrew-cask-"
      : `tool.${manager}-`;
    for (const verb of ["install", "update", "remove"]) {
      await run(
        prefix + verb,
        { package: "canary" },
        { ...node, manager },
      );
    }
  }
  assertEquals(calls.length, 15);
  assertEquals(
    calls.every((c) =>
      c.sudo && c.mutates && c.pin === node.agent_sha256 &&
      c.input.package === "canary" && c.deadlineMs === 900000
    ),
    true,
  );
});
Deno.test("materialization uses sudo and macOS account commands stay in the worker account", async () => {
  const calls: NodeCall[] = [];
  const run = toolAgent((call) => {
    calls.push(call);
    return Promise.resolve({});
  });
  for (const manager of ["composer", "vp", "brew"] as const) {
    await run(`tool.materialize-${manager === "brew" ? "homebrew" : manager}`, {
      user: "orbit",
    }, { ...node, manager });
  }
  await run("tool.homebrew-formula-install", { package: "canary" }, {
    ...node,
    platform: "macos",
    manager: "brew",
  });
  assertEquals(calls.map((c) => [c.sudo, c.mutates]), [
    [true, true],
    [true, true],
    [true, true],
    [false, true],
  ]);
});
Deno.test("foreign manager commands and unsupported platform refuse before launch", async () => {
  let calls = 0;
  const run = toolAgent(() => {
    calls++;
    return Promise.resolve({});
  });
  for (
    const name of [
      "arbitrary",
      "tool.vp-version",
      "tool.materialize-vp",
      "tool.homebrew-version",
    ]
  ) await assertRejects(() => run(name, {}, node));
  await assertRejects(() =>
    run("tool.apt-install", {}, { ...node, platform: "macos" })
  );
  await assertRejects(() =>
    run("tool.materialize-vp", {}, {
      ...node,
      platform: "macos",
      manager: "vp",
    })
  );
  assertEquals(calls, 0);
});
