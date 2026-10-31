import type { Http } from "../../automatic-deploy/gateway.ts";

const file = "github-app-fixture.json";
const read = async () => JSON.parse(await Deno.readTextFile(file));
const save = async (state: unknown) =>
  await Deno.writeTextFile(file, JSON.stringify(state));
const privateValue = (name: string) =>
  Deno.env.get(`ORBIT_APP_FIXTURE_${name}`)!;

export const fixtureHttp: Http = async (method, path, raw) => {
  const state = await read();
  const body = raw as Record<string, unknown> | undefined;
  state.calls.push({
    method,
    endpoint: path.split("?")[0],
    run_id: body?.run_id,
  });
  let data: unknown = state.operation;
  if (path.includes("/private-material?")) {
    data = state.operation.kind === "github.app_register"
      ? { registration_code: privateValue("CODE") }
      : {
        app_token: {
          app_id: 12,
          token: privateValue("JWT"),
          expires_at: new Date(Date.now() + 300_000).toISOString(),
        },
      };
  } else if (path.endsWith("/secret-output")) {
    const credential =
      (body?.outputs as Record<string, Record<string, unknown>>)
        ?.app_credentials;
    if (credential?.private_key !== privateValue("PEM")) {
      throw new Error("Invalid secret output");
    }
    state.credentialStores++;
    data = {};
  } else if (path.endsWith("/failure")) {
    if (
      !["succeeded", "failed", "cancelled"].includes(state.operation.status)
    ) {
      state.operation.status = "failed";
    }
  } else if (method === "POST") {
    state.operation.status = body?.status;
    state.operation.result = body?.result ?? null;
  }
  await save(state);
  return { status: 200, body: { data } };
};

export const fixtureFetch: typeof fetch = async (raw, options) => {
  const state = await read();
  const url = new URL(String(raw));
  if (
    url.origin !== "https://api.github.com" || options?.redirect !== "error"
  ) {
    throw new Error("Invalid fixture origin");
  }
  state.githubCalls++;
  await save(state);
  if (state.reject) throw new Error(privateValue("PEM"));
  if (state.operation.kind === "github.app_register") {
    if (url.pathname !== `/app-manifests/${privateValue("CODE")}/conversions`) {
      throw new Error("Wrong code");
    }
    return Response.json({
      id: 12,
      slug: "orbit-test",
      name: "Orbit test",
      owner: { login: "hardimpactdev", type: "Organization" },
      html_url: "https://github.com/apps/orbit-test",
      pem: privateValue("PEM"),
    });
  }
  if (
    url.pathname !== "/app/installations" ||
    new Headers(options?.headers).get("Authorization") !==
      `Bearer ${privateValue("JWT")}`
  ) throw new Error("Wrong App token");
  return Response.json([{
    id: 17,
    app_id: 12,
    account: { login: "hardimpactdev", type: "Organization" },
    repository_selection: "selected",
    suspended_at: null,
  }]);
};
