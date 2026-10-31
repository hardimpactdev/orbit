import { assertEquals, assertRejects } from "jsr:@std/assert@1";
import { removalActions } from "./removal-local.ts";
const node = {
  node_id: 18,
  user: "orbit",
  home: "/home/orbit",
  agent_sha256: "a".repeat(64),
  certificate_scopes: ["route-7", "route-7-router-hostname-change"],
  lan_route_ids: [7, 8],
  shared_php: true,
  production_instance_ids: [],
  url_instance_ids: [],
  ingress_firewall: false,
};
Deno.test("withdrawal removes and inspects all owned scopes then exact LAN rules", async () => {
  const names: string[] = [];
  const calls: unknown[] = [];
  const actions = removalActions((_program, payload) => {
    const call = payload as { name: string; input: Record<string, unknown> };
    names.push(call.name);
    calls.push(call.input ?? payload);
    return Promise.resolve(
      call.name === "certificate.inspect-leaf"
        ? { present: false }
        : payload.mode === "route"
        ? { matches: true }
        : {},
    );
  });
  assertEquals(await actions.certificates(node), { ready: true });
  assertEquals(names, [
    "certificate.remove-leaf",
    "certificate.inspect-leaf",
    "certificate.remove-leaf",
    "certificate.inspect-leaf",
  ]);
  assertEquals(await actions.lan(node), { ready: true });
  assertEquals(calls.slice(-2), [{
    mode: "route",
    operation: "remove",
    route_id: 7,
  }, {
    mode: "route",
    operation: "remove",
    route_id: 8,
  }]);
});
Deno.test("withdrawal stops certificate cleanup on refusal or remaining leaf", async () => {
  let count = 0;
  await assertRejects(() =>
    removalActions(() => {
      count++;
      return Promise.resolve({ error_code: "app-dev.certificate_in_use" });
    }).certificates(node)
  );
  assertEquals(count, 1);
  await assertRejects(() =>
    removalActions((_program, call) =>
      Promise.resolve(
        call.name === "certificate.inspect-leaf" ? { present: true } : {},
      )
    ).certificates(node)
  );
});
