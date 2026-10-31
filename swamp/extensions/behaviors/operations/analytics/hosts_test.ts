import { assertEquals, assertRejects } from "jsr:@std/assert@1";
import {
  publishLeaf,
  readParticipant,
  removeLeaf,
  requestLeaf,
  verifyPublic,
} from "./hosts.ts";
import { Leaf } from "./lifecycle.ts";
const leaf = {
  node_id: 14,
  route_id: 1,
  user: "orbit",
  home: "/home/orbit",
  agent_sha256: "a".repeat(64),
  domain: "analytics.example.com",
  scope: "route-1-router",
  version: "b".repeat(16),
  root_hash: "c".repeat(64),
};
const args = {
  operation_id: 7,
  node_id: 17,
  target_node_id: 14,
  kind: "analytics.enable" as const,
  run_id: "a075eb23-7ac4-4487-80d1-57a038a92676",
  leaf,
};
const request = {
  node_id: 14,
  scope: leaf.scope,
  state: "request" as const,
  request_base64: btoa("public-request"),
};
const pem = btoa(
  "-----BEGIN CERTIFICATE-----\npublic\n-----END CERTIFICATE-----",
);
const signed = {
  node_id: 14,
  scope: leaf.scope,
  certificate_base64: pem,
  root_certificate_base64: pem,
};
function fixture(extra = {}, input = {}) {
  const calls: { name: string; input: unknown }[] = [];
  let present = true;
  const gateway = {
    read: () =>
      Promise.resolve({
        id: 7,
        node_id: 17,
        kind: args.kind,
        status: "running",
        cancel_requested: false,
        input: {
          participant_node_id: 14,
          certificates: [leaf],
          cleanup: [{ ...leaf, in_use: false }],
          ...input,
        },
        ...extra,
      }),
  };
  const actions = {
    request: (input: unknown) => {
      calls.push({ name: "request", input });
      return Promise.resolve({
        state: "request" as const,
        request_base64: request.request_base64,
      });
    },
    publish: (input: unknown, req: unknown, signed: unknown) => {
      calls.push({
        name: "publish",
        input: { leaf: input, request: req, signed },
      });
      return Promise.resolve({ applied: true as const });
    },
    inspect: (input: unknown) => {
      calls.push({ name: "inspect", input });
      return Promise.resolve({ present });
    },
    remove: (input: unknown) => {
      calls.push({ name: "remove", input });
      present = false;
      return Promise.resolve({ applied: true as const });
    },
  };
  const lan = (input: unknown) => {
    calls.push({ name: "lan", input });
    return Promise.resolve({ ready: true });
  };
  return { calls, gateway, actions, lan };
}
Deno.test("Router CSR uses the admitted home through the canonical certificate action", async () => {
  const f = fixture();
  assertEquals(await requestLeaf(args, f.gateway, f.actions), request);
  assertEquals(f.calls, [{
    name: "request",
    input: {
      node_id: 14,
      scope: leaf.scope,
      home: leaf.home,
      agent_sha256: leaf.agent_sha256,
      domain: leaf.domain,
      version: leaf.version,
      root_hash: leaf.root_hash,
      user: leaf.user,
    },
  }]);
});
Deno.test("foreign participants, homes, scopes and cancellation refuse before local effects", async () => {
  const f = fixture();
  for (
    const changed of [
      { ...args, target_node_id: 209 },
      { ...args, leaf: { ...leaf, home: "/home/other" } },
      { ...args, leaf: { ...leaf, scope: "route-9-router" } },
    ]
  ) await assertRejects(() => requestLeaf(changed, f.gateway, f.actions));
  await assertRejects(() =>
    readParticipant(args, fixture({ cancel_requested: true }).gateway)
  );
  await assertRejects(() =>
    requestLeaf(args, fixture({ status: "succeeded" }).gateway, f.actions)
  );
  assertEquals(f.calls, []);
});
Deno.test("Ingress leaves and unsafe account homes are refused; omitted homes resolve on the worker", () => {
  assertEquals(
    Leaf.safeParse({ ...leaf, scope: "route-1-ingress" }).success,
    false,
  );
  const { home: _home, ...missing } = leaf;
  assertEquals(Leaf.safeParse(missing).success, true);
  assertEquals(
    Leaf.safeParse({ ...leaf, home: "/home/../root" }).success,
    false,
  );
});
Deno.test("signed Router leaf carries its matching native request into canonical publication then inspection", async () => {
  const f = fixture();
  await publishLeaf(
    { ...args, signed: [signed], requests: [request] },
    f.gateway,
    f.actions,
  );
  assertEquals(f.calls.map((c) => c.name), ["publish", "inspect"]);
  assertEquals((f.calls[0].input as { request: unknown }).request, {
    state: "request",
    request_base64: request.request_base64,
  });
});
Deno.test("foreign and missing signatures or requests refuse before publication", async () => {
  const f = fixture();
  for (
    const changed of [
      { ...args, signed: [{ ...signed, node_id: 209 }], requests: [request] },
      { ...args, signed: [], requests: [request] },
      { ...args, signed: [signed], requests: [] },
      { ...args, signed: [signed], requests: [request, request] },
    ]
  ) await assertRejects(() => publishLeaf(changed, f.gateway, f.actions));
  assertEquals(f.calls, []);
});
Deno.test("current Router leaf is inspected through the canonical current-request path", async () => {
  const f = fixture();
  await publishLeaf(
    {
      ...args,
      signed: [],
      requests: [{ node_id: 14, scope: leaf.scope, state: "current" }],
    },
    f.gateway,
    f.actions,
  );
  assertEquals(f.calls.map((c) => c.name), ["publish", "inspect"]);
  assertEquals((f.calls[0].input as { request: unknown }).request, {
    state: "current",
  });
});
Deno.test("unused Router removal proves absence before canonical LAN cleanup", async () => {
  const f = fixture();
  await removeLeaf(
    { ...args, leaf: { ...leaf, in_use: false } },
    f.gateway,
    f.actions,
    undefined,
    f.lan,
  );
  assertEquals(f.calls.map((c) => c.name), ["remove", "inspect", "lan"]);
  assertEquals(f.calls[0].input, {
    node_id: 14,
    scope: leaf.scope,
    home: leaf.home,
    user: leaf.user,
    agent_sha256: leaf.agent_sha256,
    in_use: false,
  });
  assertEquals(f.calls[2].input, { route_id: leaf.route_id });
});
Deno.test("an unproven removal blocks LAN cleanup", async () => {
  const f = fixture();
  f.actions.inspect = () => Promise.resolve({ present: true });
  await assertRejects(() =>
    removeLeaf(
      { ...args, leaf: { ...leaf, in_use: false } },
      f.gateway,
      f.actions,
      undefined,
      f.lan,
    )
  );
  assertEquals(f.calls.map((c) => c.name), ["remove"]);
});
Deno.test("public verification uses every fresh admitted private hop and stops on failure", async () => {
  const probe = {
    protocol: "tls",
    agent_sha256: leaf.agent_sha256,
    probe: { address: "10.0.0.14", port: 443, domain: leaf.domain },
  };
  const f = fixture({}, {
    public_verification: [probe, {
      ...probe,
      probe: { ...probe.probe, domain: "second.example.com" },
    }],
  });
  const calls: unknown[] = [];
  assertEquals(
    await verifyPublic(args, f.gateway, (input) => {
      calls.push(input);
      return Promise.resolve({ ready: true });
    }),
    { ready: true },
  );
  assertEquals(calls.length, 2);
  await assertRejects(
    () =>
      verifyPublic(
        args,
        f.gateway,
        () => Promise.resolve({ error_code: "route.private_tls_failed" }),
      ),
    Error,
    "route.private_tls_failed",
  );
  await assertRejects(
    () => verifyPublic(args, fixture().gateway),
    Error,
    "analytics.verification_missing",
  );
});
