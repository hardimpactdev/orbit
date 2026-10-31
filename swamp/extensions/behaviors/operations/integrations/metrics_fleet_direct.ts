import { z } from "npm:zod@4.4.3";
import { gatewayHttp, type Http } from "../../automatic-deploy/gateway.ts";
import { Globals } from "../_lib/direct.ts";
type Context = {
  globalArgs: z.infer<typeof Globals>;
  signal?: AbortSignal;
  writeResource: (
    kind: string,
    name: string,
    value: Record<string, unknown>,
  ) => Promise<unknown>;
};
import { DirectArgs, GatewayOperation } from "../_lib/direct.ts";
import { metricsRuntimeActions } from "./metrics_runtime_actions.ts";
import {
  fleetActions,
  type NodePlan,
  Refused,
  type Snapshot,
} from "./metrics_fleet_local.ts";
export type Plan = {
  fleet: NodePlan[];
  metrics_node_id: number;
  metrics_address: string;
};
const Args = DirectArgs.extend({
  kind: z.enum([
    "metrics.reconcile",
    "metrics.exporter_enable",
    "metrics.exporter_disable",
  ]),
  node_id: z.number().int().positive(),
});
type A = z.infer<typeof Args>;
type Steps = Record<
  string,
  { status: string; outputs?: Record<string, unknown> }
>;
const Output = z.record(z.string(), z.unknown());
function method<S extends z.ZodType>(
  description: string,
  schema: S,
  execute: (args: z.infer<S>, ctx: Context) => Promise<unknown>,
) {
  return { description, arguments: schema, execute };
}
/** Native jobs and outputs own ordering, recovery and resume. No family ledger. */
export function metricsFleetDirect(
  makeHttp = (ctx: Context): Http =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
  runtimeEffects: Pick<
    ReturnType<typeof metricsRuntimeActions>,
    "runtime" | "credential"
  > = metricsRuntimeActions(),
  local = fleetActions(),
) {
  const gateway = (a: A, c: Context) => new GatewayOperation(makeHttp(c), a);
  const output = async (
    ctx: Context,
    name: string,
    value: Record<string, unknown>,
  ) => {
    await ctx.writeResource("output", name, value);
    return {};
  };
  return {
    type: "@nckrtl/metrics-fleet-direct",
    version: "2026.10.31.1",
    globalArguments: Globals,
    resources: {
      output: {
        description: "Native step output",
        schema: Output,
        lifetime: "1d",
        garbageCollection: 10,
      },
    },
    methods: {
      capture: method("Read admitted intent once", Args, async (args, ctx) => {
        const current = await gateway(args, ctx).call(
          "GET",
          `?run_id=${args.run_id}`,
        ) as {
          id: number;
          node_id: number;
          status: string;
          cancel_requested: boolean;
          input: { metrics_fleet: Plan };
        };
        if (
          current.id !== args.operation_id || current.node_id !== args.node_id
        ) throw new Error("metrics.root_mismatch");
        const plan = current.input.metrics_fleet,
          started_at = new Date().toISOString();
        const cancelled = current.cancel_requested;
        if (!cancelled && !["queued", "running"].includes(current.status)) {
          throw new Refused("metrics.finished");
        }
        if (!cancelled) {
          await gateway(args, ctx).project({ status: "running", started_at });
        }
        return await output(ctx, "capture", {
          plan,
          started_at,
          cancelled,
          available: !cancelled && plan.fleet.every((node) => node.worker),
          keys: plan.fleet.map((node) => String(node.node_id)),
          ids: plan.fleet.filter((n) => !cancelled && n.worker).map((n) =>
            n.node_id
          ),
        });
      }),
      stage: method(
        "One local Metrics effect",
        Args.extend({
          phase: z.enum(["snapshot", "apply", "service", "verify", "restore"]),
          node: z.custom<NodePlan>(),
          metrics_address: z.string(),
          snapshot: z.custom<Snapshot>().optional(),
        }),
        async (args, ctx) => {
          try {
            return await output(ctx, args.phase, {
              ok: true,
              ...await local(
                args.phase,
                args.node,
                args.metrics_address,
                args.snapshot,
                args.phase === "restore" ? undefined : ctx.signal,
              ),
            });
          } catch (error) {
            if (!(error instanceof Refused)) throw error;
            await output(ctx, args.phase, { error_code: error.code });
            if (["snapshot", "verify"].includes(args.phase)) throw error;
            return {};
          }
        },
      ),
      runtime: method(
        "Owning credential callback and runtime commit",
        Args.extend({ node: z.custom<NodePlan>() }),
        async (args, ctx) => {
          let attempted = false;
          let result: Record<string, unknown> | undefined;
          try {
            await gateway(args, ctx).withPrivateMaterial(async (raw) => {
              const material = raw.fleet_runtime as {
                runtime: Record<string, unknown>;
                verify: Record<string, unknown>;
              };
              ctx.signal?.throwIfAborted();
              const verified = await runtimeEffects.credential({
                address: args.node.address,
                action: material.verify,
              });
              if (
                verified.verified !== true || verified.error_code !== undefined
              ) {
                result = {
                  committed: false,
                  error_code: "metrics.credentials_unverified",
                };
                return;
              }
              ctx.signal?.throwIfAborted();
              attempted = true;
              const applied = await runtimeEffects.runtime({
                address: args.node.address,
                action: material.runtime,
              });
              if (applied.error_code === "metrics.container_cleanup_failed") {
                result = {
                  committed: true,
                  error_code: applied.error_code,
                };
              } else if (
                typeof applied.error_code === "string" &&
                ![
                  "metrics.runtime_rollback_failed",
                  "metrics.container_rollback_failed",
                ].includes(applied.error_code)
              ) {
                result = { committed: false, error_code: applied.error_code };
              } else if (
                applied.changed === true && applied.error_code === undefined
              ) result = { ok: true, committed: true };
            });
          } catch (error) {
            if (attempted) throw error;
            result = {
              committed: false,
              error_code: "metrics.runtime_refused",
            };
          }
          if (!result) throw new Error("metrics.outcome_unknown");
          await output(ctx, "runtime", result);
          return {};
        },
      ),
      finish: method(
        "Project native step outputs",
        Args.extend({
          steps: z.custom<Steps>(),
        }),
        async (args, ctx) => {
          const steps = args.steps;
          const capture = steps.capture.outputs as {
            plan: Plan;
            ids: number[];
            started_at: string;
            cancelled: boolean;
          };
          const out = (phase: string, id: number) =>
            steps[`${phase}-${id}`]?.outputs;
          const verified = capture.ids.filter((id) =>
            out("verify", id)?.ok === true
          );
          const restored = capture.ids.filter((id) =>
            out("restore", id)?.ok === true
          );
          const unknown = capture.ids.filter((id) =>
            ["apply", "service", "caddy", "restore"].some(
              (phase) =>
                steps[`${phase}-${id}`]?.status === "failed" && !out(phase, id),
            )
          );
          const runtime = steps.runtime?.outputs;
          if (
            steps.runtime?.status === "failed" && !runtime &&
            !unknown.includes(args.node_id)
          ) unknown.push(args.node_id);
          const missing = capture.plan.fleet.filter((n) => !n.worker).map((n) =>
            n.node_id
          );
          const ready = missing.length === 0 &&
            verified.length === capture.plan.fleet.length &&
            runtime?.ok === true && restored.length === 0 &&
            unknown.length === 0;
          const code = unknown.length
            ? "metrics.outcome_unknown"
            : capture.ids.some((id) =>
                out("restore", id)?.error_code !== undefined
              )
            ? "metrics.rollback_failed"
            : missing.length
            ? "metrics.no_worker"
            : runtime?.error_code ?? "metrics.fleet_failed";
          const result = {
            ready,
            missing_worker_ids: missing,
            verified_node_ids: verified,
            restored_node_ids: restored,
            unknown_node_ids: unknown,
            runtime_committed: runtime?.committed === true,
            ...(ready ? {} : { error_code: code }),
          };
          await gateway(args, ctx).project({
            status: capture.cancelled
              ? "cancelled"
              : ready
              ? "succeeded"
              : "failed",
            started_at: capture.started_at,
            finished_at: new Date().toISOString(),
            result: capture.cancelled ? { cancelled: true } : result,
            error_code: ready || capture.cancelled ? null : code,
          });
          await output(ctx, "finish", { ready });
          if (!ready && !capture.cancelled) throw new Refused(String(code));
          return {};
        },
      ),
    },
  };
}
export const behavior = metricsFleetDirect();
