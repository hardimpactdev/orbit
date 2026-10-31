import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { behavior } from "./script_assets.ts";

const leaf = {
  node_id: 9,
  scope: "route-7-router",
  user: "orbit",
  domain: "private.test",
  version: "0123456789abcdef",
  root_hash: "b".repeat(64),
  agent_sha256: "a".repeat(64),
};
function context() {
  const resources: unknown[] = [];
  return {
    resources,
    extensionFile: (path: string) => "/cached/certificates/" + path,
    writeResource: (_spec: string, _name: string, value: unknown) => {
      resources.push(value);
      return Promise.resolve({});
    },
  };
}

Deno.test("public metadata ships fixed actions and keeps the admitted username without guessing a home", async () => {
  const ctx = context();
  const assets = await behavior.methods.read.execute({
    node_id: 9,
    intent: leaf,
  }, ctx);
  assertEquals(JSON.parse(assets.input_json), leaf);
  assertEquals(
    assets.request_script,
    "/cached/certificates/scripts/request.py",
  );
  assertEquals(assets.remove_script, "/cached/certificates/scripts/remove.py");
  assertEquals(
    assets.inspect_script,
    "/cached/certificates/scripts/inspect.py",
  );
  assertEquals(ctx.resources, [assets]);
});

Deno.test("metadata refuses a foreign Node and unproved proxy workers before any effect", async () => {
  for (const node of [8, 1, 18]) {
    await assertRejects(() =>
      behavior.methods.read.execute({
        node_id: node,
        intent: { ...leaf, node_id: node === 8 ? 9 : node },
      }, context())
    );
  }
});

Deno.test("a removal scope uses the same local metadata without any signing input", async () => {
  const scope = {
    node_id: 9,
    scope: leaf.scope,
    user: leaf.user,
    agent_sha256: leaf.agent_sha256,
    in_use: false,
  };
  const assets = await behavior.methods.read.execute({
    node_id: 9,
    intent: scope,
  }, context());
  assertEquals(JSON.parse(assets.input_json), scope);
});

Deno.test("typed CSR output preserves the participant selector for native fan-out", async () => {
  const request = {
    state: "request" as const,
    request_base64: btoa(
      "-----BEGIN CERTIFICATE REQUEST-----\npublic\n-----END CERTIFICATE REQUEST-----\n",
    ),
  };
  const result = await behavior.methods.decodeRequest.execute({
    leaf,
    stdout: JSON.stringify(request),
  }, context());
  assertEquals(result.record, { node_id: 9, scope: leaf.scope, ...request });
  assertEquals(JSON.parse(result.request_json), request);
});

Deno.test("typed CSR decoding refuses private or malformed output before writing resources", async () => {
  for (
    const stdout of [
      JSON.stringify({
        state: "request",
        request_base64: btoa("-----BEGIN PRIVATE KEY-----\nprivate"),
      }),
      JSON.stringify({ error_code: "certificate.leaf_failed" }),
      "invalid JSON",
    ]
  ) {
    const ctx = context();
    await assertRejects(() =>
      behavior.methods.decodeRequest.execute({ leaf, stdout }, ctx)
    );
    assertEquals(ctx.resources, []);
  }
});

Deno.test("an existing current leaf has no CSR or signature to publish", async () => {
  const request = { state: "current" as const };
  const result = await behavior.methods.decodeRequest.execute({
    leaf,
    stdout: JSON.stringify(request),
  }, context());
  assertEquals(result.record, {
    node_id: 9,
    scope: leaf.scope,
    state: "current",
  });
  const signed = await behavior.methods.decodeSigned.execute({
    leaf,
    request,
    stdout: '{"certificates":[]}',
  }, context());
  assertEquals(JSON.parse(signed.signed_json), { skipped: true });
});
