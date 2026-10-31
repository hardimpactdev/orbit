import {
  assertEquals,
  assertRejects,
  assertThrows,
} from "jsr:@std/assert@1.0.19";
import { reconcilePeerDns } from "../fleet/peer_dns_local.ts";
import type { NodeCall } from "../_lib/node-agent.ts";
import {
  nodeNetwork,
  NodeNetworkInputV1,
  NodeNetworkResultV1,
} from "./network_direct.ts";

const input: NodeNetworkInputV1 = {
  schema_version: 1,
  node_id: 9,
  agent_sha256: "a".repeat(64),
  peer_dns: {
    dns_server: "10.44.0.1",
    public_key: "A".repeat(43) + "=",
    domains: ["orbit", "test"],
  },
};
const healthy = {
  state: "orbit\n10.44.0.1\norbit\ntest",
  hooks:
    "PostUp = resolvectl dns %i 10.44.0.1; resolvectl domain %i \\~orbit \\~test",
  dns: "Link 2 (orbit): 10.44.0.1",
  domains: "Link 2 (orbit): ~orbit ~test",
  default_route: "Link 2 (orbit): no",
  uplink: true,
};
const success = (changed: boolean): NodeNetworkResultV1 => ({
  schema_version: 1,
  name: "peer-dns",
  status: changed ? "applied" : "unchanged",
  policy: true,
  uplink: true,
});

Deno.test("Node network v1 forwards only the admitted public policy and signal", async () => {
  for (const changed of [false, true]) {
    const signal = new AbortController().signal;
    let calls = 0;
    const action = nodeNetwork((policy, options) => {
      ++calls;
      assertEquals(policy, {
        ...input.peer_dns,
        node_id: input.node_id,
        agent_sha256: input.agent_sha256,
        server: input.peer_dns.dns_server,
      });
      assertEquals(options, { signal });
      return Promise.resolve({ result: { ...healthy }, changed });
    });
    const result = await action(input, signal);
    assertEquals(result, success(changed));
    assertEquals(calls, 1);
    assertEquals(JSON.stringify(result).length < 256, true);
  }
});

Deno.test("Node network v1 rejects malformed immutable input before reconciliation", async () => {
  const invalid = [
    null,
    { ...input, schema_version: 2 },
    { ...input, node_id: 0 },
    { ...input, node_id: "9" },
    { ...input, agent_sha256: "A".repeat(64) },
    { ...input, agent_sha256: "a".repeat(64) + "\n" },
    { ...input, private_key: "not-admitted" },
    { ...input, peer_dns: { ...input.peer_dns, extra: true } },
    { ...input, peer_dns: { ...input.peer_dns, dns_server: "::1" } },
    { ...input, peer_dns: { ...input.peer_dns, public_key: "invalid" } },
    {
      ...input,
      peer_dns: { ...input.peer_dns, public_key: "A".repeat(42) + "B=" },
    },
    ...[
      [],
      ["Orbit"],
      ["orbit."],
      ["orbit\n"],
      ["a".repeat(64)],
      ["orbit", "orbit"],
      ["test", "orbit"],
      ["app.test", "test"],
      ["test", "x.test"],
      Array.from({ length: 513 }, (_, i) => `d${String(i).padStart(3, "0")}`),
    ].map((domains) => ({
      ...input,
      peer_dns: { ...input.peer_dns, domains },
    })),
  ];
  let calls = 0;
  const action = nodeNetwork(() => {
    ++calls;
    throw new Error("Malformed intent reached reconciliation");
  });
  for (const value of invalid) {
    await assertRejects(() => action(value as NodeNetworkInputV1));
  }
  assertEquals(calls, 0);
});

Deno.test("Node network v1 accepts bounded canonical domain sets without normalization", () => {
  const large = {
    ...input,
    peer_dns: {
      ...input.peer_dns,
      domains: Array.from(
        { length: 512 },
        (_, i) => `d${String(i).padStart(3, "0")}`,
      ),
    },
  };
  assertEquals(NodeNetworkInputV1.parse(large), large);
});

Deno.test("Node network v1 returns only declared terminal refusal codes", async () => {
  for (
    const error_code of [
      "agent.version_mismatch",
      "vpn.peer_dns_busy",
      "vpn.peer_dns_unreadable",
      "vpn.peer_recovery_pending",
      "vpn.peer_dns_state_unsupported",
      "vpn.peer_dns_candidate_invalid",
      "vpn.peer_dns_apply_failed",
      "vpn.peer_dns_recovery_failed",
      "vpn.peer_dns_uplink_missing",
      "vpn.peer_dns_repair_failed",
      "vpn.peer_dns_reconcile_failed",
    ] as const
  ) {
    const result = await nodeNetwork(() =>
      Promise.resolve({ result: { error_code }, changed: false })
    )(input);
    assertEquals(result, {
      schema_version: 1,
      name: "peer-dns",
      status: "failed",
      error_code,
    });
    assertEquals(JSON.stringify(result).length < 256, true);
  }
});

Deno.test("Node network uncertainty throws without a terminal artifact result", async () => {
  const uncertain = new Error("Native effect outcome is uncertain");
  let caught: unknown;
  try {
    await nodeNetwork(() => {
      throw uncertain;
    })(input);
  } catch (error) {
    caught = error;
  }
  assertEquals(caught, uncertain);
});

Deno.test("Node network never calls a false healthy policy successful", async () => {
  for (
    const result of [{ ...healthy, state: "drift" }, {
      ...healthy,
      uplink: false,
    }]
  ) {
    await assertRejects(() =>
      nodeNetwork(() => Promise.resolve({ result, changed: true }))(input)
    );
  }
});

Deno.test("Node network closed result schema refuses unknown failures or diagnostic fields", () => {
  for (
    const raw of [
      { ...success(false), diagnostic: "not-public" },
      { ...success(false), status: "skipped" },
      { ...success(false), uplink: false },
      {
        schema_version: 1,
        name: "peer-dns",
        status: "failed",
        error_code: "vpn.unknown",
      },
    ]
  ) {
    assertThrows(() => NodeNetworkResultV1.parse(raw));
  }
});

Deno.test("Node network composes the real Fleet flow locally with fixed pinned commands", async () => {
  for (const drift of [false, true]) {
    const calls: NodeCall[] = [];
    let observed = { ...healthy, state: drift ? "drift" : healthy.state };
    const action = nodeNetwork((policy, options) =>
      reconcilePeerDns(policy, {
        ...options,
        invoke: (call) => {
          calls.push(call);
          if (call.mutates) {
            observed = { ...healthy };
            return Promise.resolve({});
          }
          return Promise.resolve({ ...observed });
        },
      })
    );
    assertEquals(await action(input), success(drift));
    assertEquals(
      calls.map((call) => call.name),
      drift
        ? [
          "wireguard.inspect-peer-dns",
          "wireguard.repair-peer-dns",
          "wireguard.inspect-peer-dns",
        ]
        : ["wireguard.inspect-peer-dns"],
    );
    assertEquals(calls.every((call) => call.pin === input.agent_sha256), true);
    assertEquals(calls.every((call) => call.sudo), true);
  }
});

Deno.test("Node network real Fleet flow leaves a lost repair reply uncertain", async () => {
  const calls: NodeCall[] = [];
  const action = nodeNetwork((policy, options) =>
    reconcilePeerDns(policy, {
      ...options,
      invoke: (call) => {
        calls.push(call);
        if (call.mutates) throw new Error("Lost reply");
        return Promise.resolve({ ...healthy, state: "drift" });
      },
    })
  );
  await assertRejects(() => action(input), Error, "Lost reply");
  assertEquals(calls.length, 2);
});

Deno.test("Node network real Fleet flow checks abort before mutation", async () => {
  const controller = new AbortController();
  const calls: NodeCall[] = [];
  const action = nodeNetwork((policy, options) =>
    reconcilePeerDns(policy, {
      ...options,
      invoke: (call) => {
        calls.push(call);
        controller.abort();
        return Promise.resolve({ ...healthy, state: "drift" });
      },
    })
  );
  await assertRejects(() => action(input, controller.signal));
  assertEquals(calls.filter((call) => call.mutates).length, 0);
});

Deno.test("Node network verifies a completed repair after a late abort", async () => {
  const controller = new AbortController();
  const calls: NodeCall[] = [];
  let repaired = false;
  const action = nodeNetwork((policy, options) =>
    reconcilePeerDns(policy, {
      ...options,
      invoke: (call) => {
        calls.push(call);
        if (call.mutates) {
          repaired = true;
          controller.abort();
          return Promise.resolve({});
        }
        return Promise.resolve({
          ...healthy,
          state: repaired ? healthy.state : "drift",
        });
      },
    })
  );
  assertEquals(await action(input, controller.signal), success(true));
  assertEquals(calls.length, 3);
});
