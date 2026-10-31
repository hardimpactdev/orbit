import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { peerNetworkActions } from "./network_parent.ts";
import { fakeNetworkSystem, type SystemCall } from "./network_test_support.ts";
const key = "A".repeat(43) + "=", other = "B".repeat(43) + "=";
const identity = {
  schema_version: 1 as const,
  node_id: 13,
  agent_sha256: "a".repeat(64),
};
const publication = {
  ...identity,
  existing_public_key: key,
  peer: {
    server_public_key: other,
    peer_public_key: key,
    endpoint: "vpn.example.test:51820",
    address: "10.44.0.13/24",
    subnet: "10.44.0.0/24",
    dns_server: "10.44.0.1",
    domains: ["orbit"],
    operator_dns: null,
    retain: true,
  },
};
Deno.test("peer actions invoke four fixed native programs with typed stdin", async () => {
  const calls: SystemCall[] = [];
  const actions = peerNetworkActions(fakeNetworkSystem((call) => {
    calls.push(call);
    return call.name.includes("prepare") || call.name.includes("publish")
      ? { public_key: key }
      : {};
  }));
  await actions.prepare({ ...identity, existing_public_key: key });
  await actions.publish(publication);
  await actions.commit({ ...identity, public_key: key });
  await actions.rollback(identity);
  assertEquals(calls, [
    { name: "wireguard.prepare-peer-key", input: { existing: true } },
    { name: "wireguard.publish-peer", input: publication.peer },
    { name: "wireguard.commit-peer", input: { public_key: key } },
    { name: "wireguard.rollback-peer", input: {} },
  ]);
});
Deno.test("new peer preparation retains generated key ownership", async () => {
  const actions = peerNetworkActions(fakeNetworkSystem((call) => {
    assertEquals(call.input, { existing: false });
    return { public_key: key };
  }));
  assertEquals(
    (await actions.prepare({ ...identity, existing_public_key: null })).status,
    "succeeded",
  );
});
Deno.test("peer preparation refuses a different existing key without exposing diagnostics", async () => {
  const actions = peerNetworkActions(
    fakeNetworkSystem(() => ({ public_key: other })),
  );
  assertEquals(
    await actions.prepare({ ...identity, existing_public_key: key }),
    {
      schema_version: 1,
      node_id: 13,
      stage: "prepare",
      status: "failed",
      error_code: "vpn.peer_key_mismatch",
    },
  );
});
Deno.test("peer public admission rejects replacement and malformed values before commands", async () => {
  let calls = 0;
  const actions = peerNetworkActions(fakeNetworkSystem(() => {
    calls++;
    return {};
  }));
  await assertRejects(
    () => actions.publish({ ...publication, existing_public_key: other }),
    Error,
    "Invalid parent peer intent",
  );
  await assertRejects(
    () =>
      actions.publish({
        ...publication,
        peer: { ...publication.peer, retain: false },
      }),
    Error,
    "Invalid parent peer intent",
  );
  assertEquals(calls, 0);
});
Deno.test("native bounded refusal strips diagnostic fields", async () => {
  const actions = peerNetworkActions(
    fakeNetworkSystem(() => ({
      error_code: "wireguard.peer_recovery_pending",
      diagnostic: "private-sentinel",
    })),
  );
  assertEquals(await actions.publish(publication), {
    schema_version: 1,
    node_id: 13,
    stage: "publish",
    status: "failed",
    error_code: "wireguard.peer_recovery_pending",
  });
});
Deno.test("native peer unknown outputs and process failures stay uncertain", async () => {
  for (
    const reply of [{ error_code: "unknown" }, {
      public_key: key,
      private_key: "sentinel",
    }, null]
  ) {
    const actions = peerNetworkActions(fakeNetworkSystem(() => reply));
    await assertRejects(
      () => actions.prepare({ ...identity, existing_public_key: key }),
      Error,
      "uncertain",
    );
  }
  const actions = peerNetworkActions(() =>
    Promise.resolve({ success: false, stdout: "sentinel" })
  );
  const error = await assertRejects(
    () => actions.publish(publication),
    Error,
    "uncertain",
  );
  assertEquals(error.message.includes("sentinel"), false);
});
Deno.test("cancellation before native peer program invokes nothing", async () => {
  const controller = new AbortController();
  controller.abort();
  let calls = 0;
  const actions = peerNetworkActions(fakeNetworkSystem(() => {
    calls++;
    return {};
  }));
  await assertRejects(() =>
    actions.prepare(
      { ...identity, existing_public_key: null },
      controller.signal,
    )
  );
  assertEquals(calls, 0);
});
