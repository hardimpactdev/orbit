import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import {
  convergeParticipantNetwork,
  type ParticipantNetworkInput,
} from "./network_participant.ts";
import { fakeNetworkSystem, type SystemCall } from "./network_test_support.ts";
const snapshot: ParticipantNetworkInput = {
  schema_version: 1,
  node_id: 13,
  agent_sha256: "a".repeat(64),
  router_lan: { desired: [], expand: false, prune: true },
  peer_dns: {
    dns_server: "10.44.0.1",
    public_key: "A".repeat(43) + "=",
    domains: ["orbit"],
  },
};
Deno.test("participant directly applies scoped router and DNS programs without a peer restart", async () => {
  const calls: SystemCall[] = [];
  const result = await convergeParticipantNetwork(
    snapshot,
    fakeNetworkSystem((call) => {
      calls.push(call);
      return call.name === "firewall.router-lan" ? { matches: true } : {};
    }),
  );
  assertEquals(calls, [{
    name: "firewall.router-lan",
    input: snapshot.router_lan!,
  }, { name: "wireguard.repair-peer-dns", input: snapshot.peer_dns! }]);
  assertEquals(result, {
    schema_version: 1,
    node_id: 13,
    status: "succeeded",
    projected: true,
  });
});
Deno.test("nonapplicable participant invokes no native program", async () => {
  let calls = 0;
  const result = await convergeParticipantNetwork(
    { ...snapshot, router_lan: null, peer_dns: null },
    fakeNetworkSystem(() => {
      calls++;
      return {};
    }),
  );
  assertEquals(calls, 0);
  assertEquals(result.status, "succeeded");
});
Deno.test("known native refusal stops later participant effects", async () => {
  let calls = 0;
  const result = await convergeParticipantNetwork(
    snapshot,
    fakeNetworkSystem(() => {
      calls++;
      return {
        error_code: "firewall.rule_collision",
      };
    }),
  );
  assertEquals(calls, 1);
  assertEquals(result, {
    schema_version: 1,
    node_id: 13,
    status: "failed",
    error_code: "firewall.rule_collision",
  });
});
Deno.test("participant failed recovery remains uncertain", async () => {
  const error = await assertRejects(
    () =>
      convergeParticipantNetwork(
        snapshot,
        fakeNetworkSystem(() => ({
          error_code: "router.lan_ingress_recovery_failed",
          stderr: "sentinel",
        })),
      ),
    Error,
    "uncertain",
  );
  assertEquals(error.message.includes("sentinel"), false);
});
Deno.test("participant DNS reports a settled refusal only", async () => {
  const result = await convergeParticipantNetwork(
    { ...snapshot, router_lan: null },
    fakeNetworkSystem(() => ({
      error_code: "wireguard.peer_dns_uplink_missing",
    })),
  );
  assertEquals(result, {
    schema_version: 1,
    node_id: 13,
    status: "failed",
    error_code: "wireguard.peer_dns_uplink_missing",
  });
  await assertRejects(
    () =>
      convergeParticipantNetwork(
        snapshot,
        fakeNetworkSystem(() => ({ stdout: "raw" })),
      ),
    Error,
    "uncertain",
  );
});
Deno.test("participant cancellation before effects starts no native program", async () => {
  let calls = 0;
  const controller = new AbortController();
  controller.abort();
  await assertRejects(() =>
    convergeParticipantNetwork(
      snapshot,
      fakeNetworkSystem(() => {
        calls++;
        return {};
      }),
      controller.signal,
    )
  );
  assertEquals(calls, 0);
});
