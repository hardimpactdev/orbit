/** The real provider is disposable. Only its admitted network address changes. */
export const fixtureProvider: typeof fetch = async (input, init) => {
  const url = new URL(String(input));
  if (url.origin !== "http://127.0.0.1:8000") {
    throw new Error("fixture.provider_origin_refused");
  }
  const response = await fetch(
    "__BACKEND_URL__" + url.pathname + url.search,
    init,
  );
  const bytes = await response.clone().text();
  const categories = [
    "Invalid API key",
    "not found",
    "does not exist",
    "date_range",
    "date range",
    "feature",
    "upgrade",
    "subscription",
    "team",
    "site_id",
    "authentication",
  ];
  await Deno.writeTextFile(
    "__SCRATCH__/host-state/provider.jsonl",
    JSON.stringify({
      method: init?.method ?? "GET",
      path: url.pathname,
      status: response.status,
      categories: categories.filter((value) =>
        bytes.toLowerCase().includes(value.toLowerCase())
      ),
    }) + "\n",
    { append: true },
  );
  return response;
};
const state = "__SCRATCH__/host-state/";
async function event(name: string) {
  await Deno.writeTextFile(
    state + "events.jsonl",
    JSON.stringify({ name }) + "\n",
    { append: true },
  );
}
export async function fixtureCaddySystem(
  _program: string,
  input: Record<string, unknown>,
) {
  await Deno.writeTextFile(state + "Caddyfile", String(input.content));
  await event("caddy.write");
  return { result: "published" };
}
export async function fixtureDns(
  input: { records: string; catalog: string; digest: string },
) {
  await Deno.writeTextFile(state + "records", input.records);
  await Deno.writeTextFile(state + "catalog", input.catalog);
  await event("dns.publish");
  return { outcome: "published", data: { ready: true, digest: input.digest } };
}
export async function fixtureLan(input: { route_id: number }) {
  await Deno.writeTextFile(state + "lan-removed", String(input.route_id));
  await event("lan.remove");
  return { ready: true };
}
/** Disposable CA locator substitutes vault custody only; the canonical signer stays real. */
export function fixtureCustody() {
  return {
    vaultName: "fixture-ca",
    secretKey: "fixture-ca",
    vaultService: {
      get: async (_vault: string, _key: string, _reason: string) =>
        JSON.stringify({
          certificate: await Deno.readTextFile("__SCRATCH__/cert.pem"),
          private_key: await Deno.readTextFile("__SCRATCH__/ca.key"),
        }),
    },
  };
}
