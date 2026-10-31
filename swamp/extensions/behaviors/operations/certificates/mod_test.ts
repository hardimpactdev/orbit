import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { certificateActions } from "./actions.ts";
import { certificates } from "./mod.ts";
import { type CertificateSystemCall } from "./system.ts";

const leaf = {
  node_id: 2,
  scope: "route-8-router",
  home: "/home/orbit",
  agent_sha256: "a".repeat(64),
  domain: "private.test",
  version: "0123456789abcdef",
  root_hash: "b".repeat(64),
  user: "orbit",
};
const scope = {
  node_id: leaf.node_id,
  scope: leaf.scope,
  home: leaf.home,
  user: leaf.user,
  agent_sha256: leaf.agent_sha256,
};
const csr =
  "-----BEGIN CERTIFICATE REQUEST-----\ncHVibGlj\n-----END CERTIFICATE REQUEST-----\n";
const pem =
  "-----BEGIN CERTIFICATE-----\ncHVibGlj\n-----END CERTIFICATE-----\n";
const signed = {
  certificate_base64: btoa(pem),
  root_certificate_base64: btoa(pem),
};

Deno.test("request, publish and inspect use only local system actions and public outputs", async () => {
  const calls: CertificateSystemCall[] = [];
  const actions = certificateActions((call) =>
    Promise.resolve().then(() => {
      calls.push(call);
      return call.name === "certificate.request-leaf"
        ? { state: "request", request: csr }
        : call.name === "certificate.inspect-leaf"
        ? { present: true }
        : {};
    })
  );
  const request = await actions.request(leaf);
  assertEquals(request, { state: "request", request_base64: btoa(csr) });
  const applied = await actions.publish(leaf, request, signed);
  assertEquals(await actions.inspect(scope, applied), { present: true });
  assertEquals(calls.map((c) => c.name), [
    "certificate.request-leaf",
    "certificate.publish-leaf",
    "certificate.inspect-leaf",
  ]);
  assertEquals(calls.every((c) => c.name.startsWith("certificate.")), true);
  assertEquals(calls[0].input, {
    scope: leaf.scope,
    home: leaf.home,
    domain: leaf.domain,
    version: leaf.version,
    root_hash: leaf.root_hash,
    user: leaf.user,
  });
  assertEquals(calls[1].input.certificate, pem);
});
Deno.test("current leaf skips signing and publication", async () => {
  let called = 0;
  const actions = certificateActions(() =>
    Promise.resolve().then(() => {
      called++;
      return { state: "current" };
    })
  );
  const request = await actions.request(leaf);
  assertEquals(await actions.publish(leaf, request, { skipped: true }), {
    applied: true,
  });
  assertEquals(called, 1);
});
Deno.test("refusals stop publication and inspection and malformed public signatures fail", async () => {
  let calls = 0;
  const actions = certificateActions(() =>
    Promise.resolve().then(() => {
      calls++;
      return {};
    })
  );
  const error = { error_code: "certificate.leaf_failed" };
  assertEquals(await actions.publish(leaf, error, signed), error);
  assertEquals(await actions.inspect(scope, error), error);
  assertEquals(await actions.remove({ ...scope, in_use: true }), {
    error_code: "app-dev.certificate_in_use",
  });
  assertEquals(calls, 0);
  await assertRejects(() =>
    actions.publish(leaf, { state: "request", request_base64: btoa(csr) }, {
      ...signed,
      certificate_base64: btoa("PRIVATE KEY"),
    })
  );
  assertEquals(calls, 0);
});
Deno.test("removal verifies absence and never trusts a failed effect", async () => {
  const calls: CertificateSystemCall[] = [];
  const actions = certificateActions((call) =>
    Promise.resolve().then(() => {
      calls.push(call);
      return call.name === "certificate.inspect-leaf" ? { present: false } : {};
    })
  );
  const removed = await actions.remove({ ...scope, in_use: false });
  assertEquals(await actions.inspect(scope, removed), { present: false });
  assertEquals(calls.map((v) => v.name), [
    "certificate.remove-leaf",
    "certificate.inspect-leaf",
  ]);
});
Deno.test("admission binds the worker Node before a local effect", async () => {
  const definition = certificates(() => () =>
    Promise.resolve({
      status: 200,
      body: {
        data: {
          id: 19,
          kind: "certificate.converge",
          node_id: 9,
          input: leaf,
          cancel_requested: false,
        },
      },
    })
  );
  await assertRejects(() =>
    definition.methods.admit.execute({
      operation_id: 19,
      kind: "certificate.converge",
      node_id: 9,
      run_id: "a075eb23-7ac4-4487-80d1-57a038a92676",
    }, {
      globalArgs: { gatewayUrl: "https://gateway.test", caFile: "/fixture" },
      writeResource: () => Promise.resolve({}),
    })
  );
});

Deno.test("parent requests effect Nodes, signs once on control209, then publishes on effect Nodes", async () => {
  const resources: { spec: string; value: unknown }[] = [];
  const calls: CertificateSystemCall[] = [];
  const leaves = [leaf, { ...leaf, node_id: 3, scope: "route-8" }];
  let signatures = 0;
  const definition = certificates(
    () => () => {
      throw new Error("Signing must not call the Gateway");
    },
    certificateActions((call) => {
      calls.push(call);
      return Promise.resolve(
        call.name === "certificate.request-leaf"
          ? { state: "request", request: csr }
          : call.name === "certificate.inspect-leaf"
          ? { present: true }
          : {},
      );
    }),
    () => {
      signatures++;
      return Promise.resolve({
        certificates: leaves.map((l) => ({
          node_id: l.node_id,
          scope: l.scope,
          ...signed,
        })),
      });
    },
  );
  const ctx = {
    globalArgs: {
      gatewayUrl: "https://gateway.test",
      caFile: "/fixture",
      vaultName: "disposable",
      secretKey: "ca",
    },
    vaultService: {
      get: () => {
        throw new Error("Injected signer does not read CA");
      },
    },
    writeResource: (spec: string, _name: string, value: unknown) => {
      resources.push({ spec, value });
      return Promise.resolve({});
    },
  };
  const root = {
    operation_id: 19,
    kind: "route.converge",
    node_id: 9,
    run_id: "a075eb23-7ac4-4487-80d1-57a038a92676",
  };
  for (const participant of leaves) {
    await definition.methods.requestAll.execute({
      ...root,
      target_node_id: participant.node_id,
      leaves: [participant],
    }, ctx);
  }
  const requests = resources.filter((r) => r.spec === "requests").map((r) =>
    (r.value as { requests: unknown[] }).requests
  );
  await definition.methods.signBatch.execute({
    ...root,
    leaves,
    requests: requests as never,
    native_stdout: JSON.stringify({ certificates: [] }),
  }, ctx);
  assertEquals(signatures, 1);
  const batch = resources.find((r) => r.spec === "signedBatch")!.value as {
    certificates: ({ node_id: number; scope: string } & typeof signed)[];
  };
  for (let index = 0; index < leaves.length; index++) {
    await definition.methods.publishAll.execute({
      ...root,
      target_node_id: leaves[index].node_id,
      leaves: [leaves[index]],
      requests: requests[index] as never,
      signed: batch.certificates,
    }, ctx);
  }
  assertEquals(calls.map((c) => c.name), [
    "certificate.request-leaf",
    "certificate.request-leaf",
    "certificate.publish-leaf",
    "certificate.inspect-leaf",
    "certificate.publish-leaf",
    "certificate.inspect-leaf",
  ]);
});

Deno.test("home-less leaves and removal scopes keep the admitted user through every local call", async () => {
  const { home: _home, ...localLeaf } = leaf;
  const calls: CertificateSystemCall[] = [];
  const actions = certificateActions((call) => {
    calls.push(call);
    return Promise.resolve(
      call.name === "certificate.request-leaf"
        ? { state: "request", request: csr }
        : call.name === "certificate.inspect-leaf"
        ? { present: false }
        : {},
    );
  });
  const request = await actions.request(localLeaf);
  const applied = await actions.publish(localLeaf, request, signed);
  const localScope = {
    node_id: leaf.node_id,
    scope: leaf.scope,
    user: leaf.user,
    agent_sha256: leaf.agent_sha256,
  };
  await actions.inspect(localScope, applied);
  await actions.remove({ ...localScope, in_use: false });
  assertEquals(calls.map((call) => call.input.user), [
    "orbit",
    "orbit",
    "orbit",
    "orbit",
  ]);
  assertEquals(calls.every((call) => call.input.home === undefined), true);
  await assertRejects(() =>
    actions.remove({
      node_id: leaf.node_id,
      scope: leaf.scope,
      agent_sha256: leaf.agent_sha256,
      in_use: false,
    })
  );
  assertEquals(calls.length, 4);
});
