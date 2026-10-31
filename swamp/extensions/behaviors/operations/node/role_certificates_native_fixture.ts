const read = async () =>
  JSON.parse(await Deno.readTextFile("role-certificates.json"));
const record = (value: unknown) =>
  Deno.writeTextFile("role-certificates.ndjson", JSON.stringify(value) + "\n", {
    append: true,
  });
export async function fixtureCertificateHttp(
  method: string,
  _path: string,
  body?: unknown,
) {
  await record({ method, ...(method === "POST" ? { body } : {}) });
  return { status: 200, body: { data: (await read()).operation } };
}
const pem = btoa(
  "-----BEGIN CERTIFICATE-----\ncHVibGlj\n-----END CERTIFICATE-----\n",
);
export async function fixtureSignBatch(leaves: unknown, requests: unknown) {
  await record({ method: "signBatch", leaves, requests });
  if ((await read()).fail === "sign") throw new Error("fixture.sign_failed");
  return {
    certificates: (leaves as { node_id: number; scope: string }[]).map((
      leaf,
    ) => ({
      node_id: leaf.node_id,
      scope: leaf.scope,
      certificate_base64: pem,
      root_certificate_base64: pem,
    })),
  };
}
export function fixtureRoleActions() {
  return {
    request: async (raw: unknown) => {
      const leaf = raw as { node_id: number; scope: string };
      await record({ method: "roleRequest", ...leaf });
      if ((await read()).current) return { state: "current" as const };
      return {
        state: "request" as const,
        request_base64: btoa("PUBLIC CSR " + leaf.node_id + ":" + leaf.scope),
      };
    },
    publish: async (raw: unknown, request: unknown, signed: unknown) => {
      const leaf = raw as { node_id: number; scope: string };
      await record({ method: "rolePublish", ...leaf, request, signed });
      if ((await read()).fail === "publish") {
        throw new Error("fixture.publish_failed");
      }
      return { applied: true as const };
    },
  };
}
