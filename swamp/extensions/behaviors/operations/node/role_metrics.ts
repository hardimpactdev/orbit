import { z } from "npm:zod@4.4.3";
import { gatewayHttp, type Http } from "../../automatic-deploy/gateway.ts";
import { DirectArgs, GatewayOperation, Globals } from "../_lib/direct.ts";
import {
  fleetActions,
  Refused,
  type Snapshot,
} from "../integrations/metrics_fleet_local.ts";
import { metricsRuntimeActions } from "../integrations/metrics_runtime_actions.ts";
import { runMetricsProcess } from "../integrations/metrics_system.ts";

const Id = z.number().int().positive();
const Record = z.record(z.string(), z.unknown());
const Code = z.string().regex(/^[a-z][a-z0-9_.-]{1,99}$/);
export const ProxyNodeIds = z.array(z.union([z.literal(1), z.literal(18)])).max(
  2,
);
export function hasRoleTransport(
  member: { node_id: number; worker: boolean },
  proxies: readonly number[] = [],
) {
  return member.worker || proxies.includes(member.node_id);
}
export const MetricsMember = z.object({
  node_id: Id,
  address: z.ipv4(),
  agent_sha256: z.string().regex(/^[a-f0-9]{64}$/),
  worker: z.boolean(),
  selected: z.boolean(),
  caddy_managed: z.boolean(),
  fpm: z.boolean(),
  service: Record.and(z.object({ config: z.string().nullable() })),
  pools: z.array(
    z.object({
      instance_id: Id,
      inspect: Record,
      enabled: Record,
      disabled: Record,
    }),
  ),
}).passthrough();
export const RoleMetricsPlan = z.object({
  metrics_node_id: Id,
  metrics_address: z.ipv4(),
  fleet: z.array(MetricsMember).min(1).max(256),
}).passthrough();
const Args = DirectArgs.extend({
  node_id: Id,
  restoring: z.boolean().optional(),
});
const Current = z.object({
  id: Id,
  node_id: Id,
  kind: Args.shape.kind,
  status: z.enum(["queued", "running", "succeeded", "failed", "cancelled"]),
  cancel_requested: z.boolean(),
  input: Record,
});
const EnrollmentMetrics = z.object({
  schema_version: z.literal(1),
  node_id: Id,
  executor_node_id: Id,
  candidate: z.object({ user: z.string(), wireguard_ip: z.ipv4() }),
  role_snapshots: z.array(z.object({
    schema_version: z.literal(1),
    node_id: Id,
    assignment_id: Id,
    role: z.string(),
    agent_sha256: MetricsMember.shape.agent_sha256,
    user: z.string(),
  })).max(256),
});

/** Select initialization from the owning public view without changing the native root. */
export function metricsRuntimeInitializes(
  current: z.infer<typeof Current>,
  member: z.infer<typeof MetricsMember>,
): boolean {
  if (current.kind !== "node.add") {
    if (current.input.role !== "metrics") return false;
    if (
      current.kind === "node.role_remove" || current.node_id !== member.node_id
    ) throw new Error("metrics.owner_mismatch");
    return true;
  }
  const parsed = EnrollmentMetrics.safeParse(current.input);
  if (!parsed.success) throw new Error("metrics.owner_mismatch");
  const enrollment = parsed.data;
  if (enrollment.executor_node_id !== current.node_id) {
    throw new Error("metrics.owner_mismatch");
  }
  const assignments = enrollment.role_snapshots.filter((s) =>
    s.role === "metrics"
  );
  if (assignments.length === 0) return false;
  const assignment = assignments[0];
  if (
    assignments.length !== 1 || assignment.node_id !== enrollment.node_id ||
    member.node_id !== enrollment.node_id ||
    assignment.agent_sha256 !== member.agent_sha256 ||
    assignment.user !== enrollment.candidate.user ||
    member.address !== enrollment.candidate.wireguard_ip
  ) throw new Error("metrics.owner_mismatch");
  return true;
}
type Context = {
  globalArgs: z.infer<typeof Globals>;
  signal?: AbortSignal;
  writeResource: (
    spec: string,
    name: string,
    value: unknown,
  ) => Promise<unknown>;
};
type Runtime = Pick<
  ReturnType<typeof metricsRuntimeActions>,
  "runtime" | "credential"
>;
const StepArgs = Args.extend({
  phase: z.enum(["snapshot", "apply", "service", "verify", "restore"]),
  node: MetricsMember,
  metrics_address: z.ipv4(),
  snapshot: Record.optional(),
});
const RuntimeArgs = Args.extend({ node: MetricsMember });
const Material = z.object({ runtime: Record, verify: Record });

/** Native role roots reuse the Metrics family's local actions and private credential source. */
export function roleMetrics(
  makeHttp: (ctx: Context) => Http = (ctx) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
  local = fleetActions(),
  runtime?: Runtime,
) {
  const gateway = (args: z.infer<typeof Args>, ctx: Context) =>
    new GatewayOperation(makeHttp(ctx), args);
  const read = async (
    args: z.infer<typeof Args>,
    ctx: Context,
    restore = false,
  ) => {
    const current = Current.parse(
      await gateway(args, ctx).read(restore ? undefined : ctx.signal),
    );
    if (
      current.id !== args.operation_id || current.node_id !== args.node_id ||
      current.kind !== args.kind ||
      !["queued", "running"].includes(current.status)
    ) throw new Error("metrics.root_mismatch");
    return current;
  };
  const output = async (ctx: Context, name: string, value: unknown) => {
    await ctx.writeResource("output", name, value);
    return value;
  };
  return {
    type: "@nckrtl/node-role-metrics",
    version: "2026.10.31.2",
    globalArguments: Globals,
    resources: {
      output: {
        description: "Public Metrics stage state only",
        schema: Record,
        lifetime: "1d",
        garbageCollection: 10,
      },
    },
    methods: {
      checkpoint: {
        description:
          "Refuse the next fleet phase unless all native members succeeded",
        arguments: Args.extend({
          phase: z.enum(["snapshot", "apply", "service", "verify"]),
          ready: z.boolean(),
        }),
        execute: async (
          args: z.infer<typeof Args> & {
            phase: "snapshot" | "apply" | "service" | "verify";
            ready: boolean;
          },
          ctx: Context,
        ) => {
          await read(args, ctx, args.restoring);
          if (!args.ready) throw new Error(`metrics.${args.phase}_failed`);
          return await output(ctx, args.phase + "_ready", { ok: true });
        },
      },
      capture: {
        description:
          "Read the complete admitted fleet without filtering workerless members",
        arguments: Args,
        execute: async (args: z.infer<typeof Args>, ctx: Context) => {
          const current = await read(args, ctx, args.restoring);
          if (!args.restoring) ctx.signal?.throwIfAborted();
          if (current.cancel_requested && !args.restoring) {
            throw new Error("operation.cancelled");
          }
          const plan = current.input.metrics_fleet === null
            ? null
            : RoleMetricsPlan.parse(current.input.metrics_fleet);
          const excluded = args.kind === "node.remove"
            ? z.array(Id).parse(current.input.metrics_excluded_node_ids ?? [])
            : [];
          const ids =
            plan?.fleet.filter((node) => !excluded.includes(node.node_id)).map((
              node,
            ) => node.node_id) ?? [];
          if (new Set(ids).size !== ids.length) {
            throw new Error("metrics.member_duplicate");
          }
          if (
            plan && !ids.includes(plan.metrics_node_id) &&
            current.input.metrics_runtime_required !== false
          ) {
            throw new Error("metrics.owner_missing");
          }
          return await output(ctx, "capture", {
            plan,
            ids,
            available: plan === null ||
              plan.fleet.filter((node) => ids.includes(node.node_id)).every((
                node,
              ) =>
                hasRoleTransport(
                  node,
                  ProxyNodeIds.parse(current.input.proxy_node_ids ?? []),
                )
              ),
            runtime_required: plan !== null &&
              current.input.metrics_runtime_required !== false &&
              !(args.kind === "node.role_remove" &&
                current.input.role === "metrics"),
          });
        },
      },
      stage: {
        description:
          "A placed local fleet phase; rollback ignores cancellation to restore confirmed effects",
        arguments: StepArgs,
        execute: async (args: z.infer<typeof StepArgs>, ctx: Context) => {
          const restoring = args.phase === "restore" || args.restoring === true;
          const current = await read(args, ctx, restoring);
          if (!restoring && (current.cancel_requested || ctx.signal?.aborted)) {
            return await output(ctx, args.phase, {
              ok: false,
              error_code: "operation.cancelled",
            });
          }
          try {
            const value = await local(
              args.phase,
              args.node,
              args.metrics_address,
              args.snapshot as Snapshot | undefined,
              restoring ? undefined : ctx.signal,
            );
            return await output(ctx, args.phase, { ...value, ok: true });
          } catch (error) {
            if (!(error instanceof Refused)) throw error;
            return await output(ctx, args.phase, {
              ok: false,
              error_code: Code.parse(error.code),
            });
          }
        },
      },
      runtime: {
        description:
          "Commit the fleet runtime only after all placed member verification",
        arguments: RuntimeArgs,
        execute: async (args: z.infer<typeof RuntimeArgs>, ctx: Context) => {
          const signal = args.restoring ? undefined : ctx.signal;
          const current = await read(args, ctx, args.restoring);
          if (
            !args.restoring && (current.cancel_requested || signal?.aborted)
          ) {
            return await output(ctx, "runtime", {
              ok: false,
              committed: false,
              error_code: "operation.cancelled",
            });
          }
          const initializes = metricsRuntimeInitializes(current, args.node);
          const plan = RoleMetricsPlan.parse(current.input.metrics_fleet);
          if (
            plan.metrics_node_id !== args.node.node_id ||
            plan.metrics_address !== args.node.address
          ) {
            throw new Error("metrics.owner_mismatch");
          }
          const effects = runtime ?? metricsRuntimeActions({
            run: (program, argv, options) =>
              runMetricsProcess(program, argv, {
                ...options,
                signal: signal,
              }),
            fetch: (url, options) =>
              fetch(url, {
                ...options,
                signal: signal && options?.signal
                  ? AbortSignal.any([signal, options.signal])
                  : signal ?? options?.signal,
              }),
          });
          let attempted = false;
          let committed = false;
          let result:
            | { ok: boolean; committed: boolean; error_code?: string }
            | undefined;
          try {
            await gateway(args, ctx).withPrivateMaterial(async (raw) => {
              const material = Material.parse(raw.metrics_runtime);
              signal?.throwIfAborted();
              const verify = async () => {
                const verified = await effects.credential({
                  address: args.node.address,
                  action: material.verify,
                });
                return verified.verified === true &&
                  verified.error_code === undefined;
              };
              // The captured Metrics assignment owns initialization; other roles update a serving runtime.
              if (!initializes && !await verify()) {
                result = {
                  ok: false,
                  committed: false,
                  error_code: "metrics.credentials_unverified",
                };
                return;
              }
              signal?.throwIfAborted();
              attempted = true;
              const applied = await effects.runtime({
                address: args.node.address,
                action: material.runtime,
              });
              if (applied.error_code === "metrics.container_cleanup_failed") {
                committed = true;
                result = {
                  ok: false,
                  committed: true,
                  error_code: applied.error_code,
                };
              } else if (
                applied.changed === true && applied.error_code === undefined
              ) {
                committed = true;
                result = { ok: true, committed: true };
                if (initializes) {
                  signal?.throwIfAborted();
                  if (!await verify()) {
                    result = {
                      ok: false,
                      committed: true,
                      error_code: "metrics.credentials_unverified",
                    };
                  }
                }
              }
            });
          } catch (error) {
            if (committed) {
              result = {
                ok: false,
                committed: true,
                error_code: signal?.aborted
                  ? "operation.cancelled"
                  : "metrics.credentials_unverified",
              };
            } else {
              if (attempted) throw error;
              result = {
                ok: false,
                committed: false,
                error_code: "metrics.runtime_refused",
              };
            }
          }
          if (!result) throw new Error("metrics.outcome_unknown");
          return await output(ctx, "runtime", result);
        },
      },
      complete: {
        description:
          "Summarize native fleet stages without recording another Operation",
        arguments: Args.extend({
          enabled: z.boolean(),
          ready: z.boolean(),
          committed: z.boolean(),
          restored: z.boolean(),
          error_code: Code.nullable(),
        }),
        execute: async (
          args: z.infer<typeof Args> & {
            enabled: boolean;
            ready: boolean;
            committed: boolean;
            restored: boolean;
            error_code: string | null;
          },
          ctx: Context,
        ) => {
          await read(args, ctx, true);
          return await output(ctx, "complete", {
            ready: !args.enabled || args.ready,
            committed: args.committed,
            restored: args.restored,
            ...(args.error_code ? { error_code: args.error_code } : {}),
          });
        },
      },
    },
  };
}
export const behavior = roleMetrics();
