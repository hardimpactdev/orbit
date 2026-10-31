import { fakeNetworkSystem } from "./network_test_support.ts";
import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { nodeNetworkRoot } from "./network_root.ts";
import {
  peerNetworkActions,
  type PeerNetworkOutcome,
} from "./network_parent.ts";
import type { Http } from "../../automatic-deploy/gateway.ts";
const key = "A".repeat(43) + "=";
const args = {
  operation_id: 7,
  kind: "node.network_converge" as const,
  node_id: 13,
  run_id: "ff3b7992-7376-4ac7-a49f-ed45bedc8de5",
};
const network = {
  schema_version: 1 as const,
  node_id: 13,
  agent_sha256: "a".repeat(64),
  hub_id: 1,
  existing_key: key,
  architecture: "x86_64",
  peer: {
    server_public_key: "B".repeat(43) + "=",
    endpoint: "vpn.example.test:51820",
    address: "10.44.0.13/24",
    subnet: "10.44.0.0/24",
    dns_server: "10.44.0.1",
    domains: ["orbit"],
    operator_dns: null,
    retain: true,
  },
  trust: { desired: [], remove: [], enable: false },
};
const hub = {
  schema_version: 1 as const,
  executor_node_id: 1,
  hub_id: 1,
  context_node_id: 13,
  excluded_node_id: null,
  agent_sha256: "a".repeat(64),
};
function fixture(cancel_requested = false) {
  const posts: Record<string, unknown>[] = [];
  const calls: string[] = [];
  const http: Http = (method, _path, body) => {
    if (method === "POST") posts.push(body as Record<string, unknown>);
    return Promise.resolve({
      status: 200,
      body: {
        data: {
          id: 7,
          node_id: 13,
          kind: args.kind,
          status: "queued",
          cancel_requested,
          input: { network, hub },
        },
      },
    });
  };
  const ctx = {
    globalArgs: { gatewayUrl: "https://gateway.orbit", caFile: "/tmp/test-ca" },
    writeResource: () => Promise.resolve({}),
  };
  const actions = peerNetworkActions(fakeNetworkSystem((call) => {
    calls.push(call.name);
    return Promise.resolve(
      call.name.includes("prepare") || call.name.includes("publish")
        ? { public_key: key }
        : {},
    );
  }));
  const behavior = nodeNetworkRoot(
    actions,
    () => http,
    fakeNetworkSystem((call) => {
      calls.push(call.name);
      return Promise.resolve({ matches: true });
    }),
  );
  return { posts, calls, http, ctx, behavior };
}
Deno.test("network root records public key before hub and commits after exact local publication", async () => {
  const f = fixture();
  const admission = await f.behavior.methods.admit.execute(args, f.ctx);
  const prepared = await f.behavior.methods.prepare.execute({
    ...args,
    admission,
  }, f.ctx);
  await f.behavior.methods.record.execute(
    { ...args, admission, prepared },
    f.ctx,
  );
  assertEquals((f.posts[1].result as { public_key: string }).public_key, key);
  await f.behavior.methods.hubResult.execute({
    ...args,
    admission,
    prepared,
    hub_outcome: {
      schema_version: 1,
      hub_id: 1,
      status: "succeeded",
      projected: true,
    },
  }, f.ctx);
  const published = await f.behavior.methods.publish.execute({
    ...args,
    admission,
    prepared,
  }, f.ctx);
  await f.behavior.methods.commit.execute({
    ...args,
    admission,
    prepared,
    published,
  }, f.ctx);
  assertEquals(f.calls, [
    "wireguard.prepare-peer-key",
    "wireguard.publish-peer",
    "firewall.rules",
    "wireguard.commit-peer",
  ]);
  assertEquals(f.posts.at(-1)?.status, "succeeded");
  assertEquals(f.posts.at(-1)?.result, {
    network_proven: true,
    public_key: key,
    retained: true,
  });
});
Deno.test("network root checks cancellation before preparing the peer", async () => {
  const f = fixture(true);
  const admission = await f.behavior.methods.admit.execute(args, f.ctx);
  assertEquals(admission.ready, false);
  assertEquals(f.calls, []);
  assertEquals(f.posts[0].status, "cancelled");
});
Deno.test("network root projects bounded hub failure without marking network proven", async () => {
  const f = fixture();
  const admission = await f.behavior.methods.admit.execute(args, f.ctx);
  const prepared: PeerNetworkOutcome = {
    schema_version: 1,
    node_id: 13,
    stage: "prepare",
    status: "succeeded",
    public_key: key,
  };
  const result = await f.behavior.methods.hubResult.execute({
    ...args,
    admission,
    prepared,
    hub_outcome: {
      schema_version: 1,
      hub_id: 1,
      status: "failed",
      error_code: "wireguard.hub_busy",
    },
  }, f.ctx);
  assertEquals(result.ok, false);
  assertEquals(f.posts.at(-1)?.result, {
    network_proven: false,
    error_code: "wireguard.hub_busy",
  });
  assertEquals(f.calls, []);
});
Deno.test("network root leaves malformed firewall result unresolved before commit", async () => {
  const f = fixture();
  const b = nodeNetworkRoot(
    undefined,
    () => f.http,
    fakeNetworkSystem(() =>
      Promise.resolve({ matches: false, stderr: "private-sentinel" })
    ),
  );
  const admission = await b.methods.admit.execute(args, f.ctx);
  const prepared: PeerNetworkOutcome = {
    schema_version: 1,
    node_id: 13,
    stage: "prepare",
    status: "succeeded",
    public_key: key,
  };
  const published: PeerNetworkOutcome = {
    schema_version: 1,
    node_id: 13,
    stage: "publish",
    status: "succeeded",
    public_key: key,
  };
  const error = await assertRejects(
    () =>
      b.methods.commit.execute(
        { ...args, admission, prepared, published },
        f.ctx,
      ),
    Error,
    "uncertain",
  );
  assertEquals(error.message.includes("sentinel"), false);
  assertEquals(f.posts.length, 1);
});
Deno.test("inactive peer admission cannot invoke a lower even if a stage is called directly", async () => {
  const f = fixture(true);
  const admission = await f.behavior.methods.admit.execute(args, f.ctx);
  const prepared: PeerNetworkOutcome = {
    schema_version: 1,
    node_id: 13,
    stage: "prepare",
    status: "succeeded",
    public_key: key,
  };
  const published: PeerNetworkOutcome = { ...prepared, stage: "publish" };
  await assertRejects(
    () => f.behavior.methods.prepare.execute({ ...args, admission }, f.ctx),
    Error,
    "not active",
  );
  await assertRejects(
    () =>
      f.behavior.methods.record.execute(
        { ...args, admission, prepared },
        f.ctx,
      ),
    Error,
    "not active",
  );
  await assertRejects(
    () =>
      f.behavior.methods.publish.execute(
        { ...args, admission, prepared },
        f.ctx,
      ),
    Error,
    "not active",
  );
  await assertRejects(
    () =>
      f.behavior.methods.commit.execute({
        ...args,
        admission,
        prepared,
        published,
      }, f.ctx),
    Error,
    "not active",
  );
  assertEquals(f.calls, []);
  assertEquals(f.posts.length, 1);
});

Deno.test("failed root restores admitted public key before the separately placed hub cleanup", async () => {
  const f = fixture();
  const actions = peerNetworkActions(fakeNetworkSystem((call) => {
    assertEquals(call.name, "wireguard.recover-peer");
    assertEquals(call.input, { peer: network.peer });
    return { restore_public_key: true };
  }));
  const b = nodeNetworkRoot(actions, () => f.http);
  const admission = await b.methods.admit.execute(args, f.ctx);
  const result = await b.methods.recover.execute({ ...args, admission }, f.ctx);
  assertEquals(result, { restore_hub: true });
  assertEquals(f.posts.at(-1)?.status, "running");
  assertEquals(f.posts.at(-1)?.result, {
    network_proven: false,
    restore_peer_key: true,
  });
});

Deno.test("lost commit acknowledgement cleanup preserves the applied key and does not infer success", async () => {
  const f = fixture();
  const actions = peerNetworkActions(
    fakeNetworkSystem(() => ({ restore_public_key: false })),
  );
  const b = nodeNetworkRoot(actions, () => f.http);
  const admission = await b.methods.admit.execute(args, f.ctx);
  const result = await b.methods.recover.execute({ ...args, admission }, f.ctx);
  assertEquals(result, { restore_hub: false });
  assertEquals(f.posts.length, 1);
  assertEquals(f.posts[0].status, "running");
});
