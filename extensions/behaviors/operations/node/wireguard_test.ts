import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { Fixture } from "./test-fixture.ts";

function fixture() {
  const f = new Fixture();
  f.operation.kind = "node.wireguard_hub_converge";
  f.operation.input = {
    context_node_id: 3,
    excluded_node_id: null,
    _wireguard: {
      configuration: { $secret: "node.vpn_configuration", id: 2 },
      excluded_node_id: null,
    },
  };
  f.replies.set("prepare-wireguard", { prepared: true });
  f.replies.set("hub-project", { projected: true });
  return f;
}
Deno.test("WireGuard projection sends only a protected reference and the admitted exclusion", async () => {
  const f = fixture();
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(f.runs(), ["node.prepare-wireguard", "wireguard.project-hub"]);
  const run = f.calls.find((c) =>
    c.path.endsWith("/run") && c.body.key === "hub-project"
  )!;
  assertEquals(run.body.transport, "node-wireguard");
  assertEquals(run.body.input, f.operation.input._wireguard);
});
Deno.test("WireGuard projection refuses a secret reference for another hub", async () => {
  const f = fixture();
  f.operation.node_id = 4;
  await assertRejects(() => f.execute());
  assertEquals(f.runs(), ["node.prepare-wireguard"]);
});
Deno.test("a lost hub reply recovers proof without replaying publication", async () => {
  const f = fixture();
  f.lostRun = "hub-project";
  await assertRejects(() => f.execute(), Error, "Lost run reply");
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(f.runs(), ["node.prepare-wireguard", "wireguard.project-hub"]);
});
Deno.test("a hub refusal keeps the admitted key and its stable failure code", async () => {
  const f = fixture();
  f.replies.set("hub-project", {
    error_code: "vpn.server_config_install_failed",
  });
  assertEquals((await f.execute()).status, "failed");
  assertEquals(f.completion()?.error_code, "vpn.server_config_install_failed");
});
