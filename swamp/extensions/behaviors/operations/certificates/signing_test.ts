import { assertEquals, assertThrows } from "jsr:@std/assert@1";
import { signCertificateBatch } from "./signing.ts";
const leaf = {
  node_id: 9,
  scope: "route-7-router",
  user: "orbit",
  domain: "private.test",
  version: "b".repeat(16),
  root_hash: "c".repeat(64),
  agent_sha256: "a".repeat(64),
};
const request = {
  node_id: 9,
  scope: leaf.scope,
  state: "request",
  request_base64: btoa("public CSR"),
};
const pem = btoa(
  "-----BEGIN CERTIFICATE-----\npublic\n-----END CERTIFICATE-----\n",
);
const certificate = {
  node_id: 9,
  scope: leaf.scope,
  certificate_base64: pem,
  root_certificate_base64: pem,
};
Deno.test("native Gateway signing response decodes only matched public certificates", () => {
  assertEquals(
    signCertificateBatch(
      [leaf],
      [request],
      JSON.stringify({ certificates: [certificate] }),
    ),
    { certificates: [certificate] },
  );
});
Deno.test("current request requires no signature", () => {
  assertEquals(
    signCertificateBatch([leaf], [{
      node_id: 9,
      scope: leaf.scope,
      state: "current",
    }], '{"certificates":[]}'),
    { certificates: [] },
  );
});
Deno.test("foreign, duplicate, missing and private signature outputs are refused", () => {
  for (
    const certificates of [[], [certificate, certificate], [{
      ...certificate,
      node_id: 8,
    }], [{ ...certificate, certificate_base64: btoa("PRIVATE KEY") }]]
  ) {
    assertThrows(() =>
      signCertificateBatch([leaf], [request], JSON.stringify({ certificates }))
    );
  }
  assertThrows(() =>
    signCertificateBatch([leaf], [request], '{"error_code":"unavailable"}')
  );
});
Deno.test("public signing batch supports the admitted maximum without a local process", () => {
  const leaves = Array.from(
    { length: 256 },
    (_, i) => ({ ...leaf, node_id: i + 1, scope: `route-${i + 1}-router` }),
  );
  const requests = leaves.map((l) => ({
    ...request,
    node_id: l.node_id,
    scope: l.scope,
  }));
  const certificates = leaves.map((l) => ({
    ...certificate,
    node_id: l.node_id,
    scope: l.scope,
  }));
  assertEquals(
    (signCertificateBatch(
      leaves,
      requests,
      JSON.stringify({ certificates }),
    )).certificates.length,
    256,
  );
});
