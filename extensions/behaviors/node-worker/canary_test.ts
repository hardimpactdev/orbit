import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.14";
import { inspect } from "./canary.ts";
import type { NodeCommand } from "../operations/_lib/node-commands.ts";
const proxy = {
  node_id: 1 as const,
  host: "10.44.0.1",
  user: "orbit" as const,
};
function runner(host = "10.44.0.1", mask = "masked") {
  const calls: string[][] = [];
  const invoke: NodeCommand = (command) => {
    calls.push([...command]);
    const text = command[0] === "/usr/sbin/ip"
      ? JSON.stringify([{ addr_info: [{ local: host }] }])
      : command[0] === "/usr/bin/hostname"
      ? "vpn"
      : command.includes("stat") || command.includes("/usr/bin/stat")
      ? "0:644:regular file"
      : command[0] === "/usr/bin/systemctl"
      ? mask
      : command.includes("-un")
      ? "orbit"
      : "0";
    return Promise.resolve({
      success: command[0] !== "/usr/bin/systemctl",
      code: command[0] === "/usr/bin/systemctl" ? 1 : 0,
      stdout: new TextEncoder().encode(text),
    });
  };
  return { calls, invoke };
}
Deno.test("proxy readiness uses commands on its exact remote Node and preserves local mask", async () => {
  const f = runner();
  const proof = await inspect({ node_id: 1, user: "orbit" }, f.invoke, proxy);
  assertEquals(proof.hostname, "vpn");
  assertEquals(proof.node_id, 1);
  assertEquals(proof.sudo_ready, true);
  assertEquals(f.calls.some((c) => c.includes("/usr/bin/cat")), false);
});
Deno.test("wrong proxy placement refuses before any command; wrong remote host or local worker enable refuses", async () => {
  const f = runner();
  await assertRejects(() =>
    inspect({ node_id: 18, user: "orbit" }, f.invoke, proxy)
  );
  assertEquals(f.calls.length, 0);
  await assertRejects(() =>
    inspect({ node_id: 1, user: "orbit" }, runner("10.44.0.15").invoke, proxy)
  );
  await assertRejects(() =>
    inspect(
      { node_id: 1, user: "orbit" },
      runner("10.44.0.1", "enabled").invoke,
      proxy,
    )
  );
});
