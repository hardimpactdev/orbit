import { z } from "npm:zod@4.4.3";
import { gatewayHttp, type Http } from "../automatic-deploy/gateway.ts";

const day = 86400000;
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
  return ["revoked", "expired"].includes(token.state) ||
    now >= Date.parse(token.createdAt) + 20 * day ||
    Date.parse(token.expiresAt) - now <= 10 * day;
}
export type Native = (args: string[]) => Promise<unknown>;
/** Native CLI owns token custody, single-machine binding and transitions. No issuer or Node keys. */
export const native: Native = async (args) => {
  const result = await new Deno.Command("/home/orbit/.local/bin/swamp", {
    args: [
      ...args,
      "--repo-dir",
      "/home/orbit/apps/orbit-swamp/default",
      "--json",
    ],
    cwd: "/home/orbit/apps/orbit-swamp/default",
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
  swamp_worker_selected: z.literal(true),
}).passthrough();
const Intent = z.object({
  id: z.number().int().positive(),
  kind: z.literal("node.converge"),
  target_type: z.literal("node"),
  target_id: z.number().int().positive(),
  status: z.string(),
  poll_after_seconds: z.number().nullish(),
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
) {
  const node = Node.parse(
    await data(http, "GET", `/api/v1/nodes/${args.node_id}`),
  );
  if (node.id !== args.node_id) throw new Error("Node identity changed");
  const name = `orbit-node-${node.id}`;
  const enrollment = (await list(call, "worker")).find((t) => t.name === name);
  // Enrollment plaintext is shown only at native create. Recovery re-mints;
  // it never assumes the reserved control-plane vault is publicly readable.
  if (enrollment && !["revoked", "expired"].includes(enrollment.state)) {
    await call(["worker", "token", "revoke", name]);
  }
  const enrollmentToken = z.object({ token: z.string() }).parse(
    await call(["worker", "token", "create", name, "--duration", "30d"]),
  ).token;
  const accessName = `${name}-access`;
  const access = (await list(call, "access")).find((t) =>
    t.name === accessName
  );
  if (!access || ["revoked", "expired"].includes(access.state)) {
    await call([
      "access",
      "token",
      "mint",
      accessName,
      "--principal",
      `worker:${name}`,
    ]);
  } else if (due(access)) await call(["access", "token", "rotate", accessName]);
  const serverToken = z.object({ token: z.string() }).parse(
    await call(["access", "token", "reveal", accessName, "--yes"]),
  ).token;
  let intent = Intent.parse(
    await data(http, "POST", `/api/v1/nodes/${node.id}/converge`, {
      worker_credentials: {
        enrollment_token: enrollmentToken,
        server_token: serverToken,
      },
    }, `node-worker-${node.id}-${crypto.randomUUID()}`),
  );
  if (intent.target_id !== node.id) {
    throw new Error("Node converge target changed");
  }
  const operationId = intent.id;
  while (!["succeeded", "failed", "cancelled"].includes(intent.status)) {
    signal?.throwIfAborted();
    await new Promise<void>((resolve, reject) => {
      const abort = () => {
        clearTimeout(timer);
        reject(signal?.reason);
      };
      const timer = setTimeout(() => {
        signal?.removeEventListener("abort", abort);
        resolve();
      }, Math.max(1, intent.poll_after_seconds ?? 1) * 1000);
      signal?.addEventListener("abort", abort, { once: true });
    });
    intent = Intent.parse(
      await data(http, "GET", `/api/v1/operations/${operationId}`),
    );
    if (intent.id !== operationId || intent.target_id !== node.id) {
      throw new Error("Node converge target changed");
    }
  }
  if (intent.status !== "succeeded") {
    throw new Error(
      "Node worker converge is not ready; inspect its Operation and journal",
    );
  }
  return { node_id: node.id, user: node.user, operation_id: intent.id };
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
  version: "2026.10.30.3",
  globalArguments: Globals,
  resources: {
    work: {
      description: "Nodes whose native credentials reach day20",
      schema: z.object({ nodes: z.array(Args) }),
      lifetime: "infinite",
      garbageCollection: 10,
    },
    converged: {
      description: "Public Node worker converge identity",
      schema: Args.extend({
        user: z.string(),
        operation_id: z.number().int().positive(),
      }),
      lifetime: "infinite",
      garbageCollection: 10,
    },
  },
  methods: {
    plan: {
      description: "Select native Node enrollments due for rotation by day20",
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
        "Mint or re-deliver native credentials through Node converge",
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
