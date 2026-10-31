import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import { Globals } from "../../operation-runner/mod.ts";
import { DirectArgs, GatewayOperation } from "../_lib/direct.ts";
import { Probe, verifyLocal } from "./verification.ts";
import { analyticsRouteActions } from "../analytics/route-actions.ts";
import {
  Leaf as TrackingLeaf,
  Request as TrackingRequest,
  Signed as TrackingSigned,
} from "../analytics/lifecycle.ts";
import { routeWorkloadLocal } from "./route-workload-local.ts";
import {
  convergeFirewall,
  convergeRouteLan,
  FirewallRules,
} from "./firewall-local.ts";
import { inspectFirewall } from "../firewall/inspection.ts";
import { certificateActions } from "../certificates/actions.ts";
import { Leaf, Removal } from "../certificates/input.ts";

const Args = DirectArgs.extend({
  kind: z.enum(["route.create", "route.converge", "route.update"]),
  node_id: z.number().int().positive(),
});
const EffectArgs = DirectArgs.extend({
  node_id: z.number().int().positive(),
  target_node_id: z.number().int().positive(),
});
const Code = z.string().regex(/^[a-z0-9_.-]{1,100}$/);
const ObjectInput = z.record(z.string(), z.unknown());
const Member = z.object({
  instance_id: z.number().int().positive(),
  node_id: z.number().int().positive(),
  production: z.boolean(),
  certificate_scope: z.string(),
});
export const RouteIntent = z.object({
  route_id: z.number().int().positive(),
  status: z.string().default("pending"),
  mode: z.string(),
  domain: z.string(),
  node_ids: z.array(z.number().int().positive()),
  workloads: z.array(Member),
  certificates: z.array(Leaf),
  rollback_removals: z.array(Removal).default([]),
  rollback_lan: z.array(
    z.object({
      node_id: z.number().int().positive(),
      operation: z.literal("remove"),
      route_id: z.number().int().positive(),
    }).strict(),
  ).default([]),
  workload_node_ids: z.array(z.number().int().positive()).default([]),
  workload_caddy_node_ids: z.array(z.number().int().positive()).default([]),
  public_node_ids: z.array(z.number().int().positive()).default([]),
  upstream_firewall_node_ids: z.array(z.number().int().positive()).default([]),
  caddy_node_ids: z.array(z.number().int().positive()).default([]),
  dns_node_ids: z.array(z.number().int().positive()),
  router_node_id: z.number().int().positive().nullable(),
  ingress_node_id: z.number().int().positive().nullable(),
  production: z.boolean(),
  public: z.boolean(),
  web_root: z.string().nullable(),
});
const AnalyticsPlan = z.object({
  route_id: z.number().int().positive(),
  certificates: z.array(TrackingLeaf),
  cleanup: z.array(TrackingLeaf),
  caddy_node_ids: z.array(z.number().int().positive()),
  dns_node_ids: z.array(z.number().int().positive()),
  router_node_ids: z.array(z.number().int().positive()),
  ingress_node_ids: z.array(z.number().int().positive()),
  public_verifications: z.array(
    z.object({ node_id: z.number().int().positive(), verification: Probe }),
  ),
});
const AnalyticsChange = z.object({
  route_id: z.number().int().positive(),
  before: ObjectInput,
  after: ObjectInput,
  publish: AnalyticsPlan,
  rollback: AnalyticsPlan,
  node_ids: z.array(z.number().int().positive()),
});
const AnalyticsAdmission = {
  analytics_active: z.boolean().default(false),
  analytics_request_node_ids: z.array(z.number().int().positive()).default([]),
  analytics_signing_leaves: z.array(Leaf).default([]),
};
const RequestOutput = z.object({ requests: z.array(TrackingRequest) });
/** The only adaptation is the canonical signer's fixed public wire fields. */
function signingLeaf(raw: z.infer<typeof TrackingLeaf>) {
  const {
    node_id,
    user,
    home,
    scope,
    agent_sha256,
    domain,
    version,
    root_hash,
  } = raw;
  return Leaf.parse({
    node_id,
    user,
    ...(home === undefined ? {} : { home }),
    scope,
    agent_sha256,
    domain,
    version,
    root_hash,
  });
}
export function nativeRouteDnsGrace(signal?: AbortSignal) {
  return new Promise<void>((resolve, reject) => {
    signal?.throwIfAborted();
    const abort = () => {
      clearTimeout(timer);
      reject(signal?.reason);
    };
    const timer = setTimeout(() => {
      signal?.removeEventListener("abort", abort);
      resolve();
    }, 31000);
    signal?.addEventListener("abort", abort, { once: true });
  });
}
const Update = z.object({
  mode: z.enum([
    "publication",
    "web_root",
    "replace_domain",
    "replace_pending",
    "state_only",
  ]),
  route_id: z.number().int().positive(),
  result_route_id: z.number().int().positive(),
  previous: RouteIntent,
  desired: RouteIntent,
  node_ids: z.array(z.number().int().positive()),
  cleanup: RouteIntent.optional(),
  cleanup_removals: z.array(Removal).default([]),
  pending: z.boolean(),
  changed: z.boolean(),
  analytics_changes: z.array(AnalyticsChange).default([]),
  analytics_caddy_node_ids: z.array(z.number().int().positive()).default([]),
  analytics_dns_node_ids: z.array(z.number().int().positive()).default([]),
  analytics_router_node_ids: z.array(z.number().int().positive()).default([]),
  analytics_ingress_node_ids: z.array(z.number().int().positive()).default([]),
  certificates: z.array(ObjectInput).default([]),
});
const Parent = z.object({
  id: z.number().int().positive(),
  kind: DirectArgs.shape.kind,
  node_id: z.number().int().positive(),
  status: z.string(),
  cancel_requested: z.boolean(),
  input: z.object({
    route: RouteIntent.optional(),
    environment_fields: z.record(z.string(), z.string()).default({}),
    update: Update.optional(),
    publication: z.object({
      node_id: z.number().int().positive(),
      agent_sha256: z.string().regex(/^[a-f0-9]{64}$/),
    }).passthrough(),
    workloads: z.array(
      z.object({
        instance_id: z.number().int().positive(),
        node_id: z.number().int().positive(),
        production: z.boolean(),
      }).passthrough(),
    ),
    firewall: ObjectInput.nullable().optional(),
    probes: z.array(z.unknown()).optional(),
    analytics_plans: z.array(AnalyticsPlan).default([]),
    cleanup: z.array(TrackingLeaf).default([]),
    public_verification: z.array(Probe).default([]),
    upstream_firewall: z.object({
      node_id: z.number().int().positive(),
      expected: FirewallRules.shape.remove,
    }).nullable().optional(),
  }),
});
type Context = {
  writeResource?: (
    spec: string,
    name: string,
    value: unknown,
  ) => Promise<unknown>;
  globalArgs: z.infer<typeof Globals>;
  signal?: AbortSignal;
};
type Root = z.infer<typeof Args>;
type Effect = z.infer<typeof EffectArgs>;

/** The foreign participant keeps the parent's truthful root Node identity. */
export async function readRouteParticipant(
  gateway: Pick<GatewayOperation, "read">,
  args: Effect,
  signal?: AbortSignal,
  options: { recovery?: boolean } = {},
) {
  const parent = Parent.parse(
    await gateway.read(options.recovery ? undefined : signal),
  );
  if (
    parent.id !== args.operation_id || parent.kind !== args.kind ||
    parent.node_id !== args.node_id ||
    parent.input.publication.node_id !== args.target_node_id ||
    (parent.input.route !== undefined &&
      !parent.input.route.node_ids.includes(args.target_node_id))
  ) {
    throw new Error("Route participant does not match admitted intent");
  }
  if (
    parent.input.workloads.some((workload) =>
      workload.node_id !== args.target_node_id
    )
  ) throw new Error("Route workload Node differs from placement");
  if (!options.recovery && (parent.cancel_requested || signal?.aborted)) {
    throw new Error("route.cancelled");
  }
  if (!["queued", "running"].includes(parent.status)) {
    throw new Error("Route parent has ended");
  }
  return parent.input;
}

function requireEffect(result: Record<string, unknown>) {
  if (result.error_code !== undefined) {
    throw new Error(Code.parse(result.error_code));
  }
  return result;
}

export function convergence(
  makeHttp = (
    ctx: Context,
    _args?: z.infer<typeof DirectArgs> & { target_node_id?: number },
  ) => gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
  workload = routeWorkloadLocal(),
  network = {
    lan: convergeRouteLan,
    firewall: convergeFirewall,
    verify: verifyLocal,
  },
  analytics = analyticsRouteActions(),
  waitDnsGrace = nativeRouteDnsGrace,
  readFirewall = inspectFirewall,
) {
  const gateway = (args: z.infer<typeof DirectArgs>, ctx: Context) =>
    new GatewayOperation(makeHttp(ctx, args), args);
  async function effect(args: Effect, ctx: Context, recovery = false) {
    return await readRouteParticipant(gateway(args, ctx), args, ctx.signal, {
      recovery,
    });
  }
  return {
    type: "@nckrtl/route-convergence",
    version: "2026.10.31.1",
    globalArguments: Globals,
    resources: {
      analyticsRequests: {
        schema: RequestOutput,
        lifetime: "1d",
        garbageCollection: 10,
      },
      admission: {
        schema: z.object({
          intent: RouteIntent,
          update: Update.nullable(),
          ...AnalyticsAdmission,
        }),
        lifetime: "1d",
        garbageCollection: 10,
      },
    },
    methods: {
      admit: {
        description:
          "Read the admitted Route resources for one native parent DAG",
        arguments: Args,
        execute: async (args: Root, ctx: Context) => {
          const current = await readRouteParticipant(
            gateway(args, ctx),
            {
              ...args,
              target_node_id: args.node_id,
            },
            ctx.signal,
            { recovery: true },
          );
          const parent = Parent.parse(
            await gateway(args, ctx).read(ctx.signal),
          );
          if (!parent.cancel_requested) {
            await gateway(args, ctx).project({
              status: "running",
              started_at: new Date().toISOString(),
            });
          }
          const route = RouteIntent.parse(current.route);
          const intent = {
            ...route,
            caddy_node_ids: [
              ...new Set([
                ...route.caddy_node_ids,
                ...(current.update?.analytics_caddy_node_ids ?? []),
              ]),
            ],
            dns_node_ids: [
              ...new Set([
                ...route.dns_node_ids,
                ...(current.update?.analytics_dns_node_ids ?? []),
              ]),
            ],
            public_node_ids: [
              ...new Set([
                ...route.public_node_ids,
                ...(current.update?.mode === "publication"
                  ? current.update.previous.public_node_ids
                  : []),
                ...(current.update?.analytics_ingress_node_ids ?? []),
              ]),
            ],
            ingress_node_id: route.ingress_node_id ??
              (current.update?.mode === "publication"
                ? current.update.previous.ingress_node_id
                : null),
          };
          const changes = current.update?.analytics_changes ?? [];
          const leaves = changes.flatMap(
            (change) => [
              ...change.publish.certificates,
              ...change.rollback.certificates,
            ],
          );
          const signing = leaves.map(signingLeaf).filter((leaf, index, all) =>
            all.findIndex((other) =>
              JSON.stringify(other) === JSON.stringify(leaf)
            ) === index
          );
          const admission = {
            intent: parent.cancel_requested
              ? { ...intent, mode: "state_only" }
              : intent,
            update: current.update ?? null,
            analytics_active: !parent.cancel_requested && changes.length > 0 &&
              !["state_only", "replace_pending"].includes(intent.mode),
            analytics_request_node_ids:
              current.update?.analytics_router_node_ids ?? [],
            analytics_signing_leaves: signing,
          };
          if (!ctx.writeResource) {
            throw new Error("Native admission output writer is missing");
          }
          return {
            dataHandles: [
              await ctx.writeResource(
                "admission",
                "admission-" + args.run_id,
                admission,
              ),
            ],
          };
        },
      },
      checkpoint: {
        description: "Project the state selected by native dependencies",
        arguments: Args.extend({
          checkpoint: z.enum([
            "sites-published",
            "public-activated",
            "rollback-initial",
            "web-root-stored",
            "rollback-web-root-stored",
            "publication-stored",
            "rollback-publication-stored",
            "database-cutover",
            "cleanup",
            "rollback-sites",
            "analytics-certificates-ready",
            "analytics-public-verified",
            "analytics-placement-cleanup",
            "analytics-complete",
            "analytics-rollback",
          ]),
        }),
        execute: async (args: Root & { checkpoint: string }, ctx: Context) => {
          await effect(
            { ...args, target_node_id: args.node_id },
            ctx,
            args.checkpoint.startsWith("rollback-") ||
              args.checkpoint === "analytics-rollback",
          );
          await gateway(args, ctx).project({
            status: "running",
            result: { checkpoint: args.checkpoint },
          });
          return { ready: true };
        },
      },
      verifyUpstreamFirewall: {
        description:
          "Observe exact admitted Process listener allowances before public activation",
        arguments: EffectArgs.extend({ recovery: z.boolean().default(false) }),
        execute: async (args: Effect & { recovery: boolean }, ctx: Context) => {
          const current = await effect(args, ctx, args.recovery);
          const wanted = current.upstream_firewall;
          if (
            !wanted || wanted.node_id !== args.target_node_id ||
            wanted.expected.length === 0
          ) throw new Error("route.upstream_firewall_mismatch");
          const observed = await readFirewall(
            undefined,
            args.recovery ? undefined : ctx.signal,
          );
          if ("error_code" in observed) throw new Error(observed.error_code);
          if (
            observed.backend !== "active" ||
            !wanted.expected.some((expected) =>
              observed.live.some((actual) =>
                Object.keys(expected).every((key) =>
                  expected[key as keyof typeof expected] ===
                    actual[key as keyof typeof actual]
                )
              )
            )
          ) throw new Error("route.upstream_firewall_mismatch");
          return { ready: true };
        },
      },
      analyticsPrepare: {
        description:
          "Request each fresh owner-selected tracking leaf on this participant",
        arguments: EffectArgs.extend({ recovery: z.boolean().default(false) }),
        execute: async (args: Effect & { recovery: boolean }, ctx: Context) => {
          const current = await effect(args, ctx, args.recovery);
          const requests: z.infer<typeof TrackingRequest>[] = [];
          for (const plan of current.analytics_plans) {
            for (const leaf of plan.certificates) {
              if (leaf.node_id !== args.target_node_id) continue;
              requests.push(
                await analytics.hostPrepare(
                  leaf,
                  args.recovery ? undefined : ctx.signal,
                ),
              );
            }
          }
          if (!ctx.writeResource) {
            throw new Error("Native request output writer is missing");
          }
          return {
            dataHandles: [
              await ctx.writeResource(
                "analyticsRequests",
                "analytics-requests-" + args.run_id +
                  (args.recovery ? "-rollback" : ""),
                { requests },
              ),
            ],
          };
        },
      },
      analyticsPublish: {
        description: "Publish and inspect fresh owner-selected tracking leaves",
        arguments: EffectArgs.extend({
          requests: z.array(TrackingRequest),
          signed: z.array(TrackingSigned),
          recovery: z.boolean().default(false),
        }),
        execute: async (
          args: Effect & {
            requests: z.infer<typeof TrackingRequest>[];
            signed: z.infer<typeof TrackingSigned>[];
            recovery: boolean;
          },
          ctx: Context,
        ) => {
          const current = await effect(args, ctx, args.recovery);
          for (const plan of current.analytics_plans) {
            for (const leaf of plan.certificates) {
              if (leaf.node_id !== args.target_node_id) continue;
              requireEffect(
                await analytics.publish(
                  leaf,
                  args.requests,
                  args.signed.filter((signed) =>
                    signed.node_id === leaf.node_id &&
                    signed.scope === leaf.scope
                  ),
                  args.recovery ? undefined : ctx.signal,
                ),
              );
            }
          }
          return { ready: true };
        },
      },
      analyticsVerify: {
        description:
          "Verify caller-scoped tracking Router TLS before public activation",
        arguments: EffectArgs.extend({ recovery: z.boolean().default(false) }),
        execute: async (args: Effect & { recovery: boolean }, ctx: Context) => {
          const current = await effect(args, ctx, args.recovery);
          for (const probe of current.public_verification) {
            requireEffect(await network.verify(probe));
          }
          return { ready: true };
        },
      },
      analyticsGrace: {
        description:
          "Keep retiring tracking placements for the fixed 31-second native DNS grace",
        arguments: Args,
        execute: async (args: Root, ctx: Context) => {
          const target = { ...args, target_node_id: args.node_id };
          const current = await effect(target, ctx);
          if (
            current.update?.analytics_changes.some((change) =>
              change.after.transition_node_id != null ||
              change.after.transition_cluster_id != null
            )
          ) await waitDnsGrace(ctx.signal);
          await effect(target, ctx);
          return { ready: true };
        },
      },
      analyticsCleanup: {
        description:
          "Withdraw only fresh owner-filtered tracking cleanup after publication",
        arguments: EffectArgs.extend({ recovery: z.boolean().default(false) }),
        execute: async (args: Effect & { recovery: boolean }, ctx: Context) => {
          const current = await effect(args, ctx, args.recovery);
          for (const leaf of current.cleanup) {
            if (leaf.node_id !== args.target_node_id) {
              throw new Error("Analytics cleanup Node differs from placement");
            }
            requireEffect(
              await analytics.hostWithdraw(
                leaf,
                args.recovery ? undefined : ctx.signal,
              ),
            );
          }
          return { ready: true };
        },
      },
      prepareSource: {
        description:
          "Grant Caddy access on each admitted local development workload",
        arguments: EffectArgs,
        execute: async (args: Effect, ctx: Context) => {
          const current = await effect(args, ctx);
          for (const member of current.workloads) {
            if (!member.production && member.source_access != null) {
              requireEffect(
                await workload.grantSourceAccess(member.source_access),
              );
            }
          }
          return { ready: true };
        },
      },
      prepareRuntime: {
        description:
          "Prepare the admitted production runtime using pinned local primitives",
        arguments: EffectArgs.extend({ recovery: z.boolean().default(false) }),
        execute: async (
          args: Effect & { recovery?: boolean },
          ctx: Context,
        ) => {
          const current = await effect(args, ctx, args.recovery);
          for (const member of current.workloads) {
            requireEffect(
              await workload.prepareRuntime(
                member,
                args.recovery ? undefined : ctx.signal,
              ),
            );
          }
          return { ready: true };
        },
      },
      prepareWebRoots: {
        description:
          "Prepare served production web roots on their exact workload Node",
        arguments: EffectArgs,
        execute: async (args: Effect, ctx: Context) => {
          const current = await effect(args, ctx);
          for (const member of current.workloads) {
            if (member.production && member.served_web_roots != null) {
              requireEffect(
                await workload.prepareWebRoots(member.served_web_roots),
              );
            }
          }
          return { ready: true };
        },
      },
      convergeLan: {
        description: "Converge each admitted workload Router allowance locally",
        arguments: EffectArgs,
        execute: async (args: Effect, ctx: Context) => {
          const current = await effect(args, ctx);
          for (const member of current.workloads) {
            if (member.lan != null) {
              requireEffect(await network.lan(member.lan));
            }
          }
          return { ready: true };
        },
      },
      convergeFirewall: {
        description: "Publish the admitted serving role firewall on this Node",
        arguments: EffectArgs,
        execute: async (args: Effect, ctx: Context) => {
          const current = await effect(args, ctx);
          if (current.firewall) {
            requireEffect(await network.firewall(current.firewall));
          }
          return { ready: true };
        },
      },
      verify: {
        description:
          "Verify admitted workload or Router endpoints from their exact serving hop",
        arguments: EffectArgs,
        execute: async (args: Effect, ctx: Context) => {
          const current = await effect(args, ctx);
          for (const probe of current.probes ?? []) {
            requireEffect(await network.verify(probe));
          }
          return { ready: true };
        },
      },
      rollbackLan: {
        description:
          "Retire newly admitted Route LAN allowances after rollback publication",
        arguments: EffectArgs,
        execute: async (args: Effect, ctx: Context) => {
          const current = await effect(args, ctx, true);
          for (
            const rule of current.update?.desired.rollback_lan ??
              current.route?.rollback_lan ?? []
          ) {
            if (rule.node_id !== args.target_node_id) continue;
            const { node_id: _node, ...input } = rule;
            requireEffect(await network.lan(input));
          }
          return { ready: true };
        },
      },
      restoreFirewall: {
        description:
          "Restore the current serving firewall during native recovery",
        arguments: EffectArgs,
        execute: async (args: Effect, ctx: Context) => {
          const current = await effect(args, ctx, true);
          if (current.firewall) {
            requireEffect(await network.firewall(current.firewall));
          }
          return { ready: true };
        },
      },
      removeCertificates: {
        description:
          "Remove only the obsolete leaf scopes after native Caddy withdrawal",
        arguments: EffectArgs.extend({
          leaves: z.array(Removal),
          recovery: z.boolean().default(false),
        }),
        execute: async (
          args: Effect & {
            leaves: z.infer<typeof Removal>[];
            recovery: boolean;
          },
          ctx: Context,
        ) => {
          const current = await effect(args, ctx, args.recovery);
          const admitted = [
            ...(current.route?.rollback_removals ?? []),
            ...(current.update?.desired.rollback_removals ?? []),
            ...(current.update?.cleanup_removals ?? []),
          ];
          for (const leaf of args.leaves) {
            if (
              !admitted.some((candidate) =>
                candidate.node_id === leaf.node_id &&
                candidate.scope === leaf.scope &&
                candidate.home === leaf.home && candidate.in_use === leaf.in_use
              )
            ) {
              throw new Error(
                "Route certificate removal differs from admission",
              );
            }
            if (leaf.node_id !== args.target_node_id) {
              throw new Error(
                "Route certificate removal Node differs from placement",
              );
            }
            requireEffect(
              await certificateActions().remove(
                leaf,
                args.recovery ? undefined : ctx.signal,
              ),
            );
          }
          return { ready: true };
        },
      },
      synchronizeEnvironment: {
        description:
          "Write existing protected Instance environment material inside the local effect callback",
        arguments: EffectArgs.extend({ recovery: z.boolean().default(false) }),
        execute: async (
          args: Effect & { recovery?: boolean },
          ctx: Context,
        ) => {
          const current = await effect(args, ctx, args.recovery);
          if (!current.environment_fields[String(args.target_node_id)]) {
            return { ready: true };
          }
          const writable = new Set(
            current.workloads.map((member) => member.instance_id),
          );
          await gateway(args, ctx).withPrivateMaterial(async (material) => {
            const writes = z.array(
              z.object({
                instance_id: z.number().int().positive(),
                name: z.enum([
                  "instance.write-environment",
                  "instance.write-directory-url",
                  "instance.write-development-url",
                ]),
                input: ObjectInput,
              }).strict(),
            ).parse(
              material[current.environment_fields[String(args.target_node_id)]],
            );
            for (const write of writes) {
              if (!writable.has(write.instance_id)) {
                throw new Error("Route environment owner does not match");
              }
              await workload.synchronizeEnvironment([write]);
            }
          });
          return { ready: true };
        },
      },
      finish: {
        description: "Project the native Route DAG's terminal outcome",
        arguments: Args.extend({
          route_id: z.number().int().positive(),
          failed: z.boolean(),
          cancelled: z.boolean().default(false),
        }),
        execute: async (
          args: Root & {
            route_id: number;
            failed: boolean;
            cancelled: boolean;
          },
          ctx: Context,
        ) => {
          const current = Parent.parse(await gateway(args, ctx).read());
          if (
            current.id !== args.operation_id || current.kind !== args.kind ||
            current.node_id !== args.node_id
          ) throw new Error("Route parent differs from admission");
          args = {
            ...args,
            cancelled: args.cancelled || current.cancel_requested,
          };
          const result = args.failed
            ? { route_id: args.route_id, error_code: "route.native_failed" }
            : { route_id: args.route_id, ready: true };
          await gateway(args, ctx).project({
            status: args.cancelled
              ? "cancelled"
              : args.failed
              ? "failed"
              : "succeeded",
            started_at: new Date().toISOString(),
            finished_at: new Date().toISOString(),
            result: args.cancelled ? {} : result,
            error_code: args.failed && !args.cancelled
              ? "route.native_failed"
              : null,
          });
          return { ready: !args.failed && !args.cancelled };
        },
      },
    },
  };
}
export const behavior = convergence();
