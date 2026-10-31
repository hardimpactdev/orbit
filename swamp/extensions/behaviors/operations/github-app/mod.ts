import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import {
  DirectArgs,
  type DirectIntent,
  directOperation,
  GatewayOperation,
  Globals,
  type Result,
} from "../_lib/direct.ts";

const AppId = z.number().int().positive();
const Owner = z.object({
  login: z.string().min(1).max(100),
  type: z.enum(["User", "Organization"]),
});
const Conversion = z.object({
  id: AppId,
  slug: z.string().min(1).max(100).regex(/^[A-Za-z0-9_-]+$/),
  name: z.string().min(1).max(255),
  owner: Owner,
  html_url: z.string().url().startsWith("https://github.com/apps/"),
  pem: z.string().min(64).max(32768).startsWith("-----BEGIN "),
});
const Installation = z.object({
  id: AppId,
  app_id: AppId,
  account: Owner,
  repository_selection: z.enum(["all", "selected"]),
  suspended_at: z.string().nullable(),
});
const RegisterInput = z.object({
  registration_id: z.string().regex(/^[a-f0-9]{64}$/),
});
const SyncInput = z.object({ app_id: AppId });
const Token = z.object({
  app_id: AppId,
  token: z.string().max(8192).regex(
    /^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/,
  ),
  expires_at: z.iso.datetime(),
});
const PublicInstallation = z.object({
  id: AppId,
  account: z.string().min(1).max(100),
  type: z.enum(["user", "organization"]),
  repositories: z.enum(["all", "selected"]),
  suspended: z.boolean(),
}).strict();
const RegisterResult = z.object({ registered: z.literal(true) }).strict();
const SyncResult = z.object({
  app_id: AppId,
  observed_at: z.iso.datetime(),
  installations: z.array(PublicInstallation).max(1000),
}).strict();

/** No redirects, upstream error bodies, private diagnostics, or unbounded replies. */
async function githubJson(
  request: typeof fetch,
  path: string,
  options: { method?: "GET" | "POST"; token?: string } = {},
): Promise<unknown> {
  const response = await request(`https://api.github.com${path}`, {
    method: options.method ?? "GET",
    redirect: "error",
    headers: {
      Accept: "application/vnd.github+json",
      "X-GitHub-Api-Version": "2022-11-28",
      ...(options.token ? { Authorization: `Bearer ${options.token}` } : {}),
    },
    signal: AbortSignal.timeout(15_000),
  });
  if (!response.ok || !response.body) {
    await response.body?.cancel();
    throw new Error("github.app_request_failed");
  }
  const reader = response.body.getReader();
  const chunks: Uint8Array[] = [];
  let bytes = 0;
  try {
    while (true) {
      const next = await reader.read();
      if (next.done) break;
      bytes += next.value.length;
      if (bytes > 2_097_152) throw new Error("github.app_response_too_large");
      chunks.push(next.value);
    }
  } finally {
    await reader.cancel();
    reader.releaseLock();
  }
  const body = new Uint8Array(bytes);
  let offset = 0;
  for (const chunk of chunks) {
    body.set(chunk, offset);
    offset += chunk.length;
  }
  return JSON.parse(new TextDecoder("utf-8", { fatal: true }).decode(body));
}

type PrivateGateway = Pick<
  GatewayOperation,
  "read" | "project" | "withPrivateMaterial" | "storeSecretOutput"
>;

export async function registerApp(
  input: Result,
  gateway: PrivateGateway,
  request: typeof fetch = fetch,
): Promise<Result> {
  RegisterInput.parse(input);
  await gateway.withPrivateMaterial(async (material) => {
    const code = z.string().min(1).max(2048).parse(material.registration_code);
    const app = Conversion.parse(
      await githubJson(
        request,
        `/app-manifests/${encodeURIComponent(code)}/conversions`,
        { method: "POST" },
      ),
    );
    await gateway.storeSecretOutput({
      app_credentials: {
        app_id: app.id,
        slug: app.slug,
        name: app.name,
        owner: app.owner.login,
        owner_type: app.owner.type === "Organization" ? "organization" : "user",
        url: app.html_url,
        private_key: app.pem,
      },
    });
  });
  return { registered: true };
}

export async function syncApp(
  input: Result,
  gateway: PrivateGateway,
  request: typeof fetch = fetch,
  now = Date.now,
): Promise<Result> {
  const admitted = SyncInput.parse(input);
  const installations: z.infer<typeof PublicInstallation>[] = [];
  await gateway.withPrivateMaterial(async (material) => {
    const credential = Token.parse(material.app_token);
    const expiry = Date.parse(credential.expires_at);
    if (
      credential.app_id !== admitted.app_id || expiry <= now() ||
      expiry > now() + 605_000
    ) throw new Error("github.app_token_refused");
    const seen = new Set<number>();
    for (let page = 1; page <= 10; page++) {
      const rows = z.array(Installation).max(100).parse(
        await githubJson(
          request,
          `/app/installations?per_page=100&page=${page}`,
          { token: credential.token },
        ),
      );
      for (const row of rows) {
        if (row.app_id !== admitted.app_id || seen.has(row.id)) {
          throw new Error("github.app_installation_refused");
        }
        seen.add(row.id);
        installations.push({
          id: row.id,
          account: row.account.login,
          type: row.account.type === "Organization" ? "organization" : "user",
          repositories: row.repository_selection,
          suspended: row.suspended_at !== null,
        });
      }
      if (rows.length < 100) return;
      if (page === 10) throw new Error("github.app_installations_truncated");
    }
  });
  return {
    app_id: admitted.app_id,
    observed_at: new Date(now()).toISOString(),
    installations,
  };
}

type Context = { globalArgs: z.infer<typeof Globals>; signal?: AbortSignal };
export function githubApp(
  makeGateway: (args: DirectIntent, ctx: Context) => PrivateGateway = (
    args,
    ctx,
  ) =>
    new GatewayOperation(
      gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
      args,
    ),
  request: typeof fetch = fetch,
) {
  const method = (kind: "github.app_register" | "github.app_sync") => ({
    description: kind === "github.app_register"
      ? "Exchange a private manifest code and store the existing App credential"
      : "Read public installation observations with a private App JWT",
    arguments: DirectArgs.extend({ kind: z.literal(kind), node_id: AppId }),
    execute: (args: DirectIntent, ctx: Context) => {
      const gateway = makeGateway(args, ctx);
      return directOperation(
        args,
        gateway,
        (input) =>
          kind === "github.app_register"
            ? registerApp(input, gateway, request)
            : syncApp(input, gateway, request),
        kind === "github.app_register"
          ? RegisterResult.parse
          : SyncResult.parse,
        ctx.signal,
      );
    },
  });
  return {
    type: "@nckrtl/github-app",
    version: "2026.10.31.1",
    globalArguments: Globals,
    methods: {
      register: method("github.app_register"),
      sync: method("github.app_sync"),
    },
  };
}
export const behavior = githubApp();
