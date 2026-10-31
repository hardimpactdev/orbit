import { assertEquals, assertRejects } from "jsr:@std/assert@1";
import { signCertificateBatch } from "./signing.ts";
const leaf = {
  node_id: 9,
  scope: "route-7-router",
  home: "/home/orbit",
  agent_sha256: "a".repeat(64),
  user: "orbit",
  domain: "private.test",
  version: "b".repeat(16),
  root_hash: "c".repeat(64),
};
const request = {
  node_id: 9,
  scope: leaf.scope,
  state: "request",
  request_base64: btoa("public CSR"),
};
Deno.test("signing reads CA inside the placed step and returns only public bundle", async () => {
  const accesses: unknown[] = [];
  const vault = {
    vaultName: "disposable-test",
    secretKey: "test-ca",
    vaultService: {
      get: (...args: string[]) => {
        accesses.push(args);
        return Promise.resolve(
          JSON.stringify({
            certificate: "public root",
            private_key: "fixture key",
          }),
        );
      },
    },
  };
  const result = await signCertificateBatch(
    [leaf],
    [request],
    vault,
    undefined,
    (_program, input) => {
      assertEquals(input.ca, {
        certificate: "public root",
        private_key: "fixture key",
      });
      return Promise.resolve({
        certificates: [{
          node_id: 9,
          scope: leaf.scope,
          certificate_base64: btoa("public leaf"),
          root_certificate_base64: btoa("public root"),
        }],
      });
    },
  );
  assertEquals(accesses, [[
    "disposable-test",
    "test-ca",
    "orbit-certificate-signing",
  ]]);
  assertEquals(result.certificates.length, 1);
  assertEquals(JSON.stringify(result).includes("fixture key"), false);
});
Deno.test("current leaf and pre-start cancellation never read CA", async () => {
  const vault = {
    vaultName: "test",
    secretKey: "ca",
    vaultService: {
      get: () => {
        throw new Error("must not read");
      },
    },
  };
  assertEquals(
    await signCertificateBatch([leaf], [{
      node_id: 9,
      scope: leaf.scope,
      state: "current",
    }], vault),
    { certificates: [] },
  );
  await assertRejects(() =>
    signCertificateBatch([leaf], [request], vault, AbortSignal.abort())
  );
});
Deno.test("vault and program diagnostics never escape the signer", async () => {
  const vault = {
    vaultName: "test",
    secretKey: "ca",
    vaultService: {
      get: () => {
        throw new Error("PRIVATE CA MATERIAL");
      },
    },
  };
  await assertRejects(
    () => signCertificateBatch([leaf], [request], vault),
    Error,
    "app-dev.certificate_sign_failed",
  );
});
