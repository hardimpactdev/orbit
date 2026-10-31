import { z } from "npm:zod@4.4.3";
import { gatewayHttp, type Http } from "../../automatic-deploy/gateway.ts";
import { Globals } from "../../operation-runner/mod.ts";
import { DirectArgs, GatewayOperation } from "../_lib/direct.ts";
import { Leaf } from "./host-intent.ts";
export { Leaf } from "./host-intent.ts";
import { PlausibleCE, PlausibleRefusal, Site } from "./plausible.ts";

export const Args = DirectArgs.extend({
  node_id: z.number().int().positive(),
  kind: z.literal("analytics.enable"),
});
const Tracking = z.object({
  certificates: z.array(Leaf),
  cleanup: z.array(Leaf),
  caddy_node_ids: z.array(z.number().int().positive()),
  dns_node_ids: z.array(z.number().int().positive()),
  router_node_ids: z.array(z.number().int().positive()),
  ingress_node_ids: z.array(z.number().int().positive()),
  public_verify_node_ids: z.array(z.number().int().positive()),
  firewall_node_ids: z.array(z.number().int().positive()),
  cleanup_caddy_node_ids: z.array(z.number().int().positive()),
});
const Operation = z.object({
  id: z.number().int().positive(),
  kind: Args.shape.kind,
  node_id: z.number().int().positive(),
  status: z.enum(["queued", "running", "succeeded", "failed", "cancelled"]),
  cancel_requested: z.boolean(),
  input: z.object({
    instance_id: z.number().int().positive(),
    enabled: z.boolean(),
    base_url: z.string().nullable(),
    site: Site.nullable(),
    tracking: Tracking.nullable(),
  }),
});
export const Plan = z.object({
  instance_id: z.number().int().positive(),
  started_at: z.string().datetime(),
  stopped: z.boolean(),
  enabled: z.boolean(),
  base_url: z.string().nullable(),
  site: Site.nullable(),
  tracking: Tracking,
});
export const Request = z.object({
  node_id: z.number().int().positive(),
  scope: z.string(),
  state: z.enum(["current", "request"]),
  request_base64: z.string().max(32768).optional(),
});
export const Signed = z.object({
  node_id: z.number().int().positive(),
  scope: z.string(),
  certificate_base64: z.string().max(32768),
  root_certificate_base64: z.string().max(32768),
});
type Intent = z.infer<typeof Args>;
type Context = {
  globalArgs: z.infer<typeof Globals>;
  signal?: AbortSignal;
  writeResource: (
    spec: string,
    name: string,
    value: unknown,
  ) => Promise<unknown>;
};
type Gateway = Pick<
  GatewayOperation,
  "read" | "project" | "withPrivateMaterial"
>;
const empty = () => ({
  certificates: [],
  cleanup: [],
  caddy_node_ids: [],
  dns_node_ids: [],
  router_node_ids: [],
  ingress_node_ids: [],
  public_verify_node_ids: [],
  firewall_node_ids: [],
  cleanup_caddy_node_ids: [],
});
export async function owned(args: Intent, gateway: Pick<Gateway, "read">) {
  const current = Operation.parse(await gateway.read());
  if (
    current.id !== args.operation_id || current.kind !== args.kind ||
    current.node_id !== args.node_id ||
    current.input.enabled !== (args.kind === "analytics.enable")
  ) throw new Error("analytics.intent_mismatch");
  return current;
}
export async function begin(args: Intent, gateway: Gateway) {
  let current = await owned(args, gateway);
  const started_at = new Date().toISOString();
  if (current.status === "failed") throw new Error("analytics.parent_failed");
  const stopped = ["succeeded", "cancelled"].includes(current.status) ||
    current.cancel_requested;
  if (
    current.cancel_requested &&
    !current.status.match(/^(succeeded|cancelled)$/)
  ) {
    await gateway.project({
      status: "cancelled",
      started_at,
      finished_at: started_at,
      result: {},
      error_code: null,
    });
  }
  if (!stopped) {
    await gateway.project({ status: "running", started_at });
    current = await owned(args, gateway);
    if (current.status !== "running" || current.cancel_requested) {
      throw new Error("analytics.intent_changed");
    }
  }
  return Plan.parse({
    ...current.input,
    started_at,
    stopped,
    tracking: stopped ? empty() : current.input.tracking,
  });
}
export async function convergeSite(
  args: Intent,
  gateway: Gateway,
  request: typeof fetch = fetch,
) {
  const current = await owned(args, gateway);
  if (
    current.status !== "running" || current.cancel_requested ||
    !current.input.site || !current.input.base_url
  ) throw new Error("analytics.intent_changed");
  let code: string | undefined;
  await gateway.withPrivateMaterial(async (raw) => {
    let client: PlausibleCE | undefined;
    try {
      const credentials = z.object({
        plausible_credentials: z.object({
          email: z.string().min(1),
          password: z.string().min(1),
          apiKey: z.string().min(1),
          plugins: z.object({ domain: z.string(), token: z.string() })
            .optional(),
        }),
      }).parse(raw).plausible_credentials;
      client = new PlausibleCE(
        current.input.base_url!,
        credentials,
        request,
      );
      await client.login();
      await client.converge(current.input.site!);
    } catch (error) {
      code = error instanceof PlausibleRefusal &&
          /^[a-z0-9_.-]{1,100}$/.test(error.code)
        ? error.code
        : "analytics.site_failed";
    } finally {
      await client?.logout().catch(() => {});
    }
  });
  if (code) throw new Error(code);
  return { ready: true };
}
export async function publishRecords(args: Intent, gateway: Gateway) {
  const current = await owned(args, gateway);
  if (current.status !== "running" || current.cancel_requested) {
    throw new Error("analytics.intent_changed");
  }
  await gateway.project({
    status: "running",
    started_at: new Date().toISOString(),
    result: { certificates_ready: true },
  });
  return { ready: true };
}
export async function dnsGrace(
  args: Intent,
  gateway: Gateway,
  signal?: AbortSignal,
  wait = (signal?: AbortSignal) =>
    new Promise<void>((resolve, reject) => {
      signal?.throwIfAborted();
      const abort = () => {
        clearTimeout(timer);
        reject(signal?.reason);
      };
      const timer = setTimeout(() => {
        signal?.removeEventListener("abort", abort);
        resolve();
      }, 31_000);
      signal?.addEventListener("abort", abort, { once: true });
    }),
) {
  const current = await owned(args, gateway);
  if (current.status !== "running" || current.cancel_requested) {
    throw new Error("analytics.intent_changed");
  }
  if (current.input.tracking?.cleanup_caddy_node_ids.length) {
    await wait(signal);
  }
  const after = await owned(args, gateway);
  if (after.status !== "running" || after.cancel_requested) {
    throw new Error("analytics.intent_changed");
  }
  return { ready: true };
}
export async function checkpoint(
  args: Intent,
  field: "public_verified" | "placement_cleanup",
  gateway: Gateway,
) {
  const current = await owned(args, gateway);
  if (current.status !== "running" || current.cancel_requested) {
    throw new Error("analytics.intent_changed");
  }
  await gateway.project({
    status: "running",
    started_at: new Date().toISOString(),
    result: { [field]: true },
  });
  return { ready: true };
}
export async function finish(
  args: Intent,
  successful: boolean,
  gateway: Gateway,
) {
  const current = await owned(args, gateway);
  if (["succeeded", "failed", "cancelled"].includes(current.status)) {
    return { status: current.status };
  }
  const started_at = new Date().toISOString();
  const result = successful
    ? {
      ready: true,
      enabled: current.input.enabled,
      domain: current.input.site?.domain ?? null,
      timezone: current.input.site?.timezone ?? null,
      readable: true,
    }
    : { error_code: "analytics.change_failed" };
  await gateway.project({
    status: successful ? "succeeded" : "failed",
    started_at,
    finished_at: new Date().toISOString(),
    result,
    error_code: successful ? null : "analytics.change_failed",
  });
  if (!successful) throw new Error("analytics.change_failed");
  return { status: "succeeded" };
}
export function analyticsLifecycle(
  makeHttp = (ctx: Context): Http =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
  request: typeof fetch = fetch,
  waitForDns?: (signal?: AbortSignal) => Promise<void>,
) {
  const gateway = (args: Intent, ctx: Context) =>
    new GatewayOperation(makeHttp(ctx), args);
  return {
    type: "@nckrtl/analytics-lifecycle",
    version: "2026.10.31.2",
    globalArguments: Globals,
    resources: {
      plan: { schema: Plan, lifetime: "7d", garbageCollection: 10 },
    },
    methods: {
      begin: {
        description:
          "Project parent running and read the stored tracking intent",
        arguments: Args,
        execute: async (args: Intent, ctx: Context) => ({
          dataHandles: [
            await ctx.writeResource(
              "plan",
              args.run_id,
              await begin(args, gateway(args, ctx)),
            ),
          ],
        }),
      },
      site: {
        description:
          "Converge the Plausible site with protected credentials inside the effect callback",
        arguments: Args,
        execute: (args: Intent, ctx: Context) =>
          convergeSite(args, gateway(args, ctx), request),
      },
      publishRecords: {
        description:
          "Project certificate readiness into desired Route publication",
        arguments: Args,
        execute: (args: Intent, ctx: Context) =>
          publishRecords(args, gateway(args, ctx)),
      },
      dnsGrace: {
        description:
          "Keep retired placements serving for the fixed 31-second private DNS grace",
        arguments: Args,
        execute: (args: Intent, ctx: Context) =>
          dnsGrace(args, gateway(args, ctx), ctx.signal, waitForDns),
      },
      publicVerified: {
        description:
          "Activate public tracking only after ingress verifies the Router hop",
        arguments: Args,
        execute: (args: Intent, ctx: Context) =>
          checkpoint(args, "public_verified", gateway(args, ctx)),
      },
      placementCleanup: {
        description:
          "Withdraw retired placements after the native DNS grace period",
        arguments: Args,
        execute: (args: Intent, ctx: Context) =>
          checkpoint(args, "placement_cleanup", gateway(args, ctx)),
      },
      finish: {
        description:
          "Project parent success only after all placed effects succeed",
        arguments: Args.extend({ successful: z.boolean() }),
        execute: (
          args: Intent & { successful: boolean },
          ctx: Context,
        ) => finish(args, args.successful, gateway(args, ctx)),
      },
    },
  };
}
export const behavior = analyticsLifecycle();
