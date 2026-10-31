import { assertEquals, assertRejects } from "jsr:@std/assert@1";
import { certificateActions } from "../certificates/actions.ts";
import { analyticsRouteActions } from "./route-actions.ts";

const leaf = {
  node_id: 14,
  route_id: 19,
  user: "orbit",
  home: "/home/orbit",
  agent_sha256: "a".repeat(64),
  domain: "analytics.shop.example.com",
  scope: "route-19-router",
  version: "b".repeat(16),
  root_hash: "c".repeat(64),
};
const request = {
  node_id: 14,
  scope: leaf.scope,
  state: "request" as const,
  request_base64: btoa("-----BEGIN CERTIFICATE REQUEST-----\npublic\n"),
};
const cert = btoa("-----BEGIN CERTIFICATE-----\npublic\n");
const signed = {
  node_id: 14,
  scope: leaf.scope,
  certificate_base64: cert,
  root_certificate_base64: cert,
};
function fixture(refusal?: string) {
  const calls: { name: string; input: unknown }[] = [];
  let removed = false;
  const certificates = certificateActions((call) => {
    calls.push(call);
    if (refusal) return Promise.resolve({ error_code: refusal });
    if (call.name === "certificate.request-leaf") {
      return Promise.resolve({
        state: "request",
        request: atob(request.request_base64),
      });
    }
    if (call.name === "certificate.remove-leaf") removed = true;
    if (call.name === "certificate.inspect-leaf") {
      return Promise.resolve({ present: !removed });
    }
    return Promise.resolve({ applied: true });
  });
  const actions = analyticsRouteActions(certificates, (input) => {
    calls.push({ name: "lan", input });
    return Promise.resolve({ ready: true });
  });
  return { calls, actions, certificates };
}
Deno.test("tracking host preparation returns only its public CSR and exact Node identity", async () => {
  const f = fixture();
  assertEquals(await f.actions.hostPrepare(leaf), request);
  assertEquals(f.calls.map((call) => call.name), ["certificate.request-leaf"]);
  assertEquals(f.calls[0].input, {
    home: leaf.home,
    scope: leaf.scope,
    domain: leaf.domain,
    version: leaf.version,
    root_hash: leaf.root_hash,
    user: leaf.user,
  });
});
Deno.test("tracking publication uses its matching public request and signature then inspects", async () => {
  const f = fixture();
  assertEquals(await f.actions.publish(leaf, [request], [signed]), {
    ready: true,
  });
  assertEquals(f.calls.map((call) => call.name), [
    "certificate.publish-leaf",
    "certificate.inspect-leaf",
  ]);
});
Deno.test("foreign or duplicate request and signature identities refuse before publication", async () => {
  const f = fixture();
  for (
    const [requests, signatures] of [
      [[{ ...request, node_id: 209 }], [signed]],
      [[request, request], [signed]],
      [[request], [{ ...signed, scope: "route-20-router" }]],
      [[request], [signed, signed]],
      [[request], []],
    ]
  ) {
    await assertRejects(() => f.actions.publish(leaf, requests, signatures));
  }
  assertEquals(f.calls, []);
});
Deno.test("a current leaf needs no signature and is still inspected", async () => {
  const f = fixture();
  await f.actions.publish(leaf, [{
    node_id: 14,
    scope: leaf.scope,
    state: "current",
  }], []);
  assertEquals(f.calls.map((call) => call.name), ["certificate.inspect-leaf"]);
});
Deno.test("both tracking removal steps prove absence before removing the Route LAN allowance", async () => {
  for (const method of ["remove", "hostWithdraw"] as const) {
    const f = fixture();
    assertEquals(await f.actions[method]({ ...leaf, in_use: false }), {
      ready: true,
    });
    assertEquals(f.calls.map((call) => call.name), [
      "certificate.remove-leaf",
      "certificate.inspect-leaf",
      "lan",
    ]);
    assertEquals(f.calls[2].input, { route_id: 19 });
  }
});
Deno.test("an in-use or foreign-scope leaf never reaches tracking removal", async () => {
  const f = fixture();
  await assertRejects(() => f.actions.remove(leaf));
  await assertRejects(() => f.actions.hostWithdraw({ ...leaf, in_use: true }));
  await assertRejects(() =>
    f.actions.hostWithdraw({ ...leaf, in_use: false, scope: "route-20-router" })
  );
  assertEquals(f.calls, []);
});
Deno.test("a certificate refusal blocks subsequent inspection and LAN cleanup", async () => {
  const f = fixture("certificate.path_unsafe");
  await assertRejects(
    () => f.actions.remove({ ...leaf, in_use: false }),
    Error,
    "certificate.path_unsafe",
  );
  assertEquals(f.calls.map((call) => call.name), ["certificate.remove-leaf"]);
});
Deno.test("an aborted parent invokes no tracking host effect", async () => {
  const f = fixture();
  const signal = AbortSignal.abort();
  await assertRejects(() => f.actions.hostPrepare(leaf, signal));
  await assertRejects(() =>
    f.actions.publish(leaf, [request], [signed], signal)
  );
  await assertRejects(() =>
    f.actions.hostWithdraw({ ...leaf, in_use: false }, signal)
  );
  assertEquals(f.calls, []);
});

Deno.test("all tracking certificate steps accept an admitted user without a Gateway home", async () => {
  const f = fixture();
  const { home: _home, ...local } = leaf;
  const requested = await f.actions.hostPrepare(local);
  await f.actions.publish(local, [requested], [signed]);
  await f.actions.hostWithdraw({ ...local, in_use: false });
  for (
    const call of f.calls.filter((call) => call.name.startsWith("certificate."))
  ) {
    const input = call.input as Record<string, unknown>;
    assertEquals(input.user, "orbit");
    assertEquals(input.home, undefined);
  }
});
