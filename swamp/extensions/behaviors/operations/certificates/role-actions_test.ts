import {
  assertEquals,
  assertRejects,
  assertThrows,
} from "jsr:@std/assert@1.0.19";
import { certificates } from "./mod.ts";
import { roleCertificateActions } from "./role-actions.ts";
import { signCertificateBatch } from "./signing.ts";

const leaf = {
  node_id: 9,
  scope: "role-gateway" as const,
  domain: "gateway.test",
  version: "0123456789abcdef",
  root_hash: "a".repeat(64),
};
const request = {
  state: "request" as const,
  request_base64: btoa("-----BEGIN CERTIFICATE REQUEST-----\npublic\n"),
};
const pem = btoa("-----BEGIN CERTIFICATE-----\npublic\n");
const signed = { certificate_base64: pem, root_certificate_base64: pem };
const root = {
  operation_id: 89,
  kind: "node.role_add",
  node_id: 4,
  target_node_id: 9,
  run_id: "role-test-run",
};
Deno.test("role request and publication never take or return a private key", async () => {
  const calls: Record<string, unknown>[] = [];
  const actions = roleCertificateActions((_program, input) => {
    calls.push(input);
    return Promise.resolve(
      input.operation === "request" ? request : { applied: true },
    );
  });
  assertEquals(await actions.request(leaf), request);
  assertEquals(await actions.publish(leaf, request, signed), { applied: true });
  assertEquals(calls[0], { operation: "request", ...leaf });
  assertEquals(calls[1], {
    operation: "publish",
    ...leaf,
    request,
    signed,
  });
  assertEquals(JSON.stringify(calls).includes("PRIVATE KEY"), false);
  await assertRejects(() => actions.request({ ...leaf, key: "private" }));
  await assertRejects(() =>
    actions.request({ ...leaf, scope: "role-../../other" })
  );
  await assertRejects(() =>
    actions.publish(leaf, request, {
      ...signed,
      certificate_base64: btoa("PRIVATE KEY"),
    })
  );
  assertEquals(calls.length, 2);
});
Deno.test("role stages compose in one parent with the canonical signer and public resources", async () => {
  const resources: { spec: string; value: unknown }[] = [];
  const events: string[] = [];
  const actions = roleCertificateActions((_program, input) => {
    events.push(String(input.operation));
    return Promise.resolve(
      input.operation === "request" ? request : { applied: true },
    );
  });
  const definition = certificates(
    undefined,
    undefined,
    async (leaves, requests, response) => {
      events.push("sign");
      return await signCertificateBatch(leaves, requests, response);
    },
    actions,
  );
  const context = {
    globalArgs: {
      gatewayUrl: "https://gateway.test",
      caFile: "/fixture/root.pem",
    },
    writeResource: (spec: string, _name: string, value: unknown) => {
      resources.push({ spec, value });
      return Promise.resolve(spec);
    },
  };
  await definition.methods.roleRequest.execute({ ...root, leaf }, context);
  await definition.methods.sign.execute({
    ...root,
    leaf,
    request,
    native_stdout: JSON.stringify({
      certificates: [{ node_id: leaf.node_id, scope: leaf.scope, ...signed }],
    }),
  }, context);
  await definition.methods.rolePublish.execute({
    ...root,
    leaf,
    request,
    signed,
  }, context);
  assertEquals(events, ["request", "sign", "publish"]);
  assertEquals(resources.map((r) => r.spec), ["request", "signed", "applied"]);
  assertEquals(resources[1].value, { signed });
  assertEquals(JSON.stringify(resources).includes("PRIVATE KEY"), false);
  await assertRejects(() =>
    definition.methods.roleRequest.execute(
      { ...root, target_node_id: 8, leaf },
      context,
    )
  );
  await assertRejects(() =>
    definition.methods.rolePublish.execute({
      ...root,
      target_node_id: 8,
      leaf,
      request,
      signed,
    }, context)
  );
  assertEquals(events, ["request", "sign", "publish"]);
});
Deno.test("role signing decodes the public Gateway bundle without a custody locator", async () => {
  const result = await signCertificateBatch(
    [leaf],
    [{ node_id: leaf.node_id, scope: leaf.scope, ...request }],
    JSON.stringify({
      certificates: [{ node_id: leaf.node_id, scope: leaf.scope, ...signed }],
    }),
  );
  assertEquals(result.certificates[0].scope, "role-gateway");
});

Deno.test("role native methods fail before dependents when local preparation or publication refuses", async () => {
  const actions = roleCertificateActions(() =>
    Promise.resolve({ error_code: "certificate.role_unsafe" })
  );
  const definition = certificates(
    undefined,
    undefined,
    signCertificateBatch,
    actions,
  );
  const context = {
    globalArgs: {
      gatewayUrl: "https://gateway.test",
      caFile: "/fixture/root.pem",
    },
    writeResource: () => {
      throw new Error("must not write success");
    },
  };
  await assertRejects(
    () => definition.methods.roleRequest.execute({ ...root, leaf }, context),
    Error,
    "certificate.role_unsafe",
  );
  await assertRejects(
    () =>
      definition.methods.rolePublish.execute(
        { ...root, leaf, request, signed },
        context,
      ),
    Error,
    "certificate.role_unsafe",
  );
});

Deno.test("Analytics role fixes its hostname and pair scope before any key or CA access", async () => {
  const analytics = {
    ...leaf,
    scope: "role-analytics",
    domain: "analytics.orbit",
  };
  let effects = 0;
  const actions = roleCertificateActions((_program, input) => {
    effects++;
    assertEquals(input.scope, "role-analytics");
    assertEquals(input.domain, "analytics.orbit");
    return Promise.resolve(
      input.operation === "request" ? request : { applied: true },
    );
  });
  assertEquals(await actions.request(analytics), request);
  assertEquals(await actions.publish(analytics, request, signed), {
    applied: true,
  });
  await assertRejects(() =>
    actions.request({ ...analytics, domain: "foreign.orbit" })
  );
  await assertRejects(() =>
    actions.publish({ ...analytics, domain: "foreign.orbit" }, request, signed)
  );
  assertEquals(effects, 2);
  const requests = [{
    node_id: analytics.node_id,
    scope: analytics.scope,
    ...request,
  }];
  const response = JSON.stringify({
    certificates: [{
      node_id: analytics.node_id,
      scope: analytics.scope,
      ...signed,
    }],
  });
  const batch = await signCertificateBatch([analytics], requests, response);
  assertEquals(batch.certificates[0].scope, "role-analytics");
  assertThrows(() =>
    signCertificateBatch(
      [{ ...analytics, domain: "foreign.orbit" }],
      requests,
      response,
    )
  );
});
