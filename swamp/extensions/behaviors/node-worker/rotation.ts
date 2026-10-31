import { localCommand } from "../operations/_lib/local-worker.ts";
import { z } from "npm:zod@4.4.3";
import { gatewayHttp, type Http } from "../automatic-deploy/gateway.ts";

const hour = 3600000;
export const WorkerCredentialDuration = "24h";
export const Token = z.object({
  name: z.string(),
  state: z.string(),
  createdAt: z.string(),
  expiresAt: z.string(),
  vaultName: z.string().optional(),
  secretKey: z.string().optional(),
}).passthrough();
type Token = z.infer<typeof Token>;
export function due(token: Token, now = Date.now()) {
  const created = Date.parse(token.createdAt),
    expires = Date.parse(token.expiresAt);
  return ["revoked", "expired"].includes(token.state) ||
    !Number.isFinite(created) || !Number.isFinite(expires) ||
    expires - created > 24 * hour || now >= created + 20 * hour ||
    expires - now <= 4 * hour;
}
export type Native = (args: string[]) => Promise<unknown>;
/** Native CLI owns token custody, single-machine binding and transitions. No issuer or Node keys. */
export const native: Native = async (args) => {
  const result = await localCommand("/home/orbit/.local/bin/swamp", {
    args: [
      ...args,
      "--repo-dir",
      "/home/orbit/apps/orbit-monorepo-staging/checkout/swamp",
      "--json",
    ],
    cwd: "/home/orbit/apps/orbit-monorepo-staging/checkout/swamp",
    env: {
      SWAMP_HOME: "/home/orbit/.swamp",
      SWAMP_CONFIG_DIR: "/home/orbit/.config/swamp",
      SWAMP_API_KEY_FILE: "/home/orbit/.config/swamp/api-key",
    },
    stdin: "null",
    stdout: "piped",
    stderr: "null",
  }).output();
  if (!result.success || result.stdout.length > 4 * 1024 * 1024) {
    throw new Error("Native worker token command refused; inspect its audit");
  }
  return JSON.parse(new TextDecoder().decode(result.stdout));
};
const list = async (call: Native, group: string) =>
  z.array(Token).parse(await call([group, "token", "list"]));
export async function plan(call: Native = native, now = Date.now()) {
  const access = await list(call, "access");
  const workers = z.object({
    workers: z.array(
      z.object({
        tokenName: z.string(),
        status: z.string(),
        labels: z.record(z.string(), z.string()),
        enrolledAt: z.string(),
      }),
    ),
  }).parse(await call(["worker", "list"])).workers;
  return (await list(call, "worker")).filter((t) =>
    /^orbit-node-[1-9][0-9]*$/.test(t.name)
  )
    .filter((t) =>
      !workers.some((w) =>
        w.tokenName === t.name && ["idle", "busy"].includes(w.status) &&
        w.labels.node === t.name.slice("orbit-node-".length) &&
        Date.parse(w.enrolledAt) >= Date.parse(t.createdAt)
      ) || due(t, now) || !access.find((a) => a.name === `${t.name}-access`) ||
      due(access.find((a) => a.name === `${t.name}-access`)!, now)
    )
    .map((t) => ({ node_id: Number(t.name.slice("orbit-node-".length)) }));
}
const Args = z.object({ node_id: z.number().int().positive() }).strict();
const Node = z.object({
  id: z.number().int().positive(),
  user: z.string(),
  status: z.literal("active"),
  platform: z.literal("linux"),
  swamp_worker_selected: z.boolean(),
}).passthrough();
async function data(
  http: Http,
  method: "GET" | "POST",
  path: string,
  body?: unknown,
  key?: string,
) {
  const response = await http(method, path, body, key);
  if (response.status < 200 || response.status >= 300) {
    throw new Error("Node converge intent refused");
  }
  return z.object({ data: z.unknown() }).parse(response.body).data;
}
export async function convergeWorker(
  args: z.infer<typeof Args>,
  http: Http,
  call: Native = native,
  signal?: AbortSignal,
  proxyAdapter?: {
    selected: () => Promise<(1 | 18)[]>;
    renew: (
      node: 1 | 18,
      call: Native,
      signal?: AbortSignal,
    ) => Promise<{ node_id: number; user: string; proxy_refreshed: boolean }>;
  },
) {
  const node = Node.parse(
    await data(http, "GET", `/api/v1/nodes/${args.node_id}`),
  );
  if (node.id !== args.node_id) throw new Error("Node identity changed");
  if (node.id === 1 || node.id === 18) {
    let lower = proxyAdapter;
    if (lower === undefined) {
      const module = await import("./proxy.ts");
      lower = {
        selected: module.configuredProxyNodes,
        renew: module.renewProxyWorker,
      };
    }
    if (node.user !== "orbit" || !(await lower.selected()).includes(node.id)) {
      throw new Error("Proxy worker is not selected");
    }
    return await lower.renew(node.id, call, signal);
  }
  if (!node.swamp_worker_selected) {
    throw new Error("Node worker is not selected");
  }
  throw new Error(
    "Persistent worker rotation requires credential-only delivery",
  );
}
const Globals = z.object({
  gatewayUrl: z.string().url().default("https://gateway.orbit"),
  caFile: z.string().default(
    "/usr/local/share/ca-certificates/orbit-managed-root-ca.crt",
  ),
});
type Context = {
  globalArgs: z.infer<typeof Globals>;
  signal?: AbortSignal;
  writeResource: (
    spec: string,
    name: string,
    value: unknown,
  ) => Promise<unknown>;
};
export const behavior = {
  type: "@nckrtl/node-worker-lifecycle",
  version: "2026.10.31.5",
  globalArguments: Globals,
  resources: {
    work: {
      description: "Nodes due for the canonical credential lifetime policy",
      schema: z.object({ nodes: z.array(Args) }),
      lifetime: "infinite",
      garbageCollection: 10,
    },
    converged: {
      description: "Public Node worker converge identity",
      schema: Args.extend({
        user: z.string(),
        operation_id: z.number().int().positive().optional(),
        proxy_refreshed: z.boolean().optional(),
      }),
      lifetime: "infinite",
      garbageCollection: 10,
    },
  },
  methods: {
    plan: {
      description:
        "Select native Node enrollments due for canonical 24-hour rotation",
      arguments: Globals.strict(),
      execute: async (_args: unknown, ctx: Context) => {
        const http = gatewayHttp(
          ctx.globalArgs.gatewayUrl,
          ctx.globalArgs.caFile,
          ctx.signal,
        );
        const nodes = z.array(
          z.object({
            id: z.number().int().positive(),
            swamp_worker_selected: z.boolean(),
          }),
        ).parse(await data(http, "GET", "/api/v1/nodes"));
        const selected = new Set(
          nodes.filter((n) => n.swamp_worker_selected).map((n) => n.id),
        );
        for (
          const id of await (await import("./proxy.ts")).configuredProxyNodes()
        ) selected.add(id);
        return {
          dataHandles: [
            await ctx.writeResource("work", "work", {
              nodes: (await plan()).filter((n) => selected.has(n.node_id)),
            }),
          ],
        };
      },
    },
    converge: {
      description:
        "Renew installed proxy credentials; refuse persistent rotation before mutations",
      arguments: Globals.extend(Args.shape).strict(),
      execute: async (args: z.infer<typeof Args>, ctx: Context) => ({
        dataHandles: [
          await ctx.writeResource(
            "converged",
            "converged",
            await convergeWorker(
              args,
              gatewayHttp(
                ctx.globalArgs.gatewayUrl,
                ctx.globalArgs.caFile,
                ctx.signal,
              ),
              native,
              ctx.signal,
            ),
          ),
        ],
      }),
    },
  },
};
