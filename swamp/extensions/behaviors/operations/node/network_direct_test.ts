import {
  assertEquals,
  assertRejects,
  assertThrows,
} from "jsr:@std/assert@1.0.19";
import {
  nodeNetwork,
  NodeNetworkInputV1,
  NodeNetworkResultV1,
} from "./network_direct.ts";
import { fakeNetworkSystem } from "./network_test_support.ts";

const input: NodeNetworkInputV1 = {
  schema_version: 1,
  node_id: 13,
  agent_sha256: "a".repeat(64),
  peer_dns: {
    dns_server: "10.44.0.1",
    public_key: btoa("a".repeat(32)),
    domains: ["orbit", "test"],
  },
};

Deno.test("converge network uses only the native DNS system program and public stdin", async () => {
  const calls: unknown[] = [];
  const result = await nodeNetwork(fakeNetworkSystem((call) => {
    calls.push(call);
    return {};
  }))(input);
  assertEquals(calls, [{
    name: "wireguard.repair-peer-dns",
    input: input.peer_dns,
  }]);
  assertEquals(result, {
    schema_version: 1,
    name: "peer-dns",
    status: "applied",
    policy: true,
    uplink: true,
  });
});

Deno.test("converge network rejects malformed admitted intent before effects", async () => {
  let calls = 0;
  const action = nodeNetwork(fakeNetworkSystem(() => {
    calls++;
    return {};
  }));
  const invalid: unknown[] = [
    null,
    { ...input, node_id: "13" },
    { ...input, schema_version: 2 },
    { ...input, private_key: "forbidden" },
    { ...input, agent_sha256: "a".repeat(64) + "\n" },
  ];
  for (
    const domains of [
      [],
      ["Orbit"],
      ["orbit."],
      ["orbit\n"],
      ["orbit", "orbit"],
      ["test", "orbit"],
      ["app.test", "test"],
      ["test", "x.test"],
    ]
  ) {
    invalid.push({ ...input, peer_dns: { ...input.peer_dns, domains } });
  }
  for (const value of invalid) {
    await assertRejects(() => action(value as NodeNetworkInputV1));
  }
  assertEquals(calls, 0);
});

Deno.test("converge network accepts the bounded canonical suffix set", () => {
  const snapshot = {
    ...input,
    peer_dns: {
      ...input.peer_dns,
      domains: Array.from(
        { length: 512 },
        (_, i) => `d${String(i).padStart(3, "0")}`,
      ),
    },
  };
  assertEquals(NodeNetworkInputV1.parse(snapshot), snapshot);
});

Deno.test("native DNS refusals stay bounded and do not become successful artifacts", async () => {
  const result = await nodeNetwork(
    fakeNetworkSystem(() => ({
      error_code: "wireguard.peer_dns_uplink_missing",
      diagnostic: "private-sentinel",
    })),
  )(input);
  assertEquals(result, {
    schema_version: 1,
    name: "peer-dns",
    status: "failed",
    error_code: "wireguard.peer_dns_uplink_missing",
  });
  assertEquals(JSON.stringify(result).length < 256, true);
});

Deno.test("unknown native DNS outcomes remain uncertain with bounded diagnostics", async () => {
  for (
    const result of [{
      error_code: "unknown.failure",
      diagnostic: "private-sentinel",
    }, { matches: true }]
  ) {
    const error = await assertRejects(
      () => nodeNetwork(fakeNetworkSystem(() => result))(input),
      Error,
      "uncertain",
    );
    assertEquals(error.message.includes("sentinel"), false);
  }
});

Deno.test("converge network starts no process after cancellation", async () => {
  const controller = new AbortController();
  controller.abort();
  let calls = 0;
  await assertRejects(() =>
    nodeNetwork(fakeNetworkSystem(() => {
      calls++;
      return {};
    }))(input, controller.signal)
  );
  assertEquals(calls, 0);
});

Deno.test("converge network observes a settled mutation after late cancellation", async () => {
  const controller = new AbortController();
  const result = await nodeNetwork(fakeNetworkSystem(() => {
    controller.abort();
    return {};
  }))(input, controller.signal);
  assertEquals(result.status, "applied");
});

Deno.test("network artifact result refuses arbitrary proof or diagnostics", () => {
  for (
    const value of [
      { schema_version: 1, name: "peer-dns", status: "skipped" },
      {
        schema_version: 1,
        name: "peer-dns",
        status: "applied",
        policy: false,
        uplink: true,
      },
      {
        schema_version: 1,
        name: "peer-dns",
        status: "failed",
        error_code: "unknown.failure",
      },
    ]
  ) {
    assertThrows(() => NodeNetworkResultV1.parse(value));
  }
});
