import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import {
  prepareTaskVmNetwork,
  publishTaskVmNetwork,
} from "./enrollment_network.ts";
import type { SystemCommand, SystemTransport } from "../node/system_command.ts";
const key = "a".repeat(43) + "=";
const input = { task_vm_id: 184, guest_node_id: 212 };
const network = {
  server_public_key: "b".repeat(43) + "=",
  endpoint: "vpn.orbit:51820",
  address: "10.44.0.212/32",
  subnet: "10.44.0.0/16",
  dns_server: "10.44.0.1",
  domains: ["orbit"],
};
function fixture() {
  const calls: SystemCommand[] = [];
  const system: SystemTransport = (command) => {
    calls.push(command);
    const request = JSON.parse(command.stdin!);
    return Promise.resolve({
      success: true,
      stdout: JSON.stringify(
        request.name === "wireguard.commit-peer" ? {} : { public_key: key },
      ),
    });
  };
  return { calls, system };
}
Deno.test("TaskVM network uses canonical native program with admitted guest transport and commits its exact public key", async () => {
  const f = fixture();
  assertEquals(await prepareTaskVmNetwork(input, async () => {}, f.system), {
    wireguard_public_key: key,
  });
  assertEquals(
    await publishTaskVmNetwork(input, network, key, async () => {}, f.system),
    { guest_node_id: 212, network_configured: true },
  );
  assertEquals(f.calls.map((c) => JSON.parse(c.stdin!).name), [
    "wireguard.prepare-peer-key",
    "wireguard.publish-peer",
    "wireguard.commit-peer",
  ]);
  for (const call of f.calls) {
    assertEquals(call.command, "/usr/bin/sudo");
    assertEquals(call.args.slice(0, 4), ["-n", "--", "/usr/bin/python3", "-c"]);
    assertEquals(call.args.some((arg) => arg.includes("orbit-agent")), false);
  }
  assertEquals(JSON.parse(f.calls[1].stdin!).input, {
    ...network,
    peer_public_key: key,
    operator_dns: null,
    retain: false,
  });
});
Deno.test("network mismatch cannot commit and cancellation or supersession cannot mutate", async () => {
  const calls: string[] = [];
  await assertRejects(() =>
    publishTaskVmNetwork(input, network, key, async () => {}, (command) => {
      calls.push(JSON.parse(command.stdin!).name);
      return Promise.resolve({
        success: true,
        stdout: JSON.stringify({ public_key: network.server_public_key }),
      });
    })
  );
  assertEquals(calls, ["wireguard.publish-peer"]);
  const f = fixture();
  await assertRejects(() =>
    prepareTaskVmNetwork(
      input,
      () => Promise.reject(new Error("superseded")),
      f.system,
    )
  );
  await assertRejects(() =>
    prepareTaskVmNetwork(input, async () => {}, f.system, AbortSignal.abort())
  );
  await assertRejects(() =>
    publishTaskVmNetwork(
      input,
      { ...network, address: "invalid" },
      key,
      async () => {},
      f.system,
    )
  );
  assertEquals(f.calls, []);
});
