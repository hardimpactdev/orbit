import { z } from "npm:zod@4.4.3";
import { Leaf, Request, Signed } from "../certificates/input.ts";
import { certificateActions } from "../certificates/actions.ts";
import { CertificateSignedRecord } from "../certificates/signing.ts";
import { localAccount } from "./converge_local.ts";
import {
  hasRoleTransport,
  ProxyNodeIds,
  RoleMetricsPlan,
} from "./role_metrics.ts";
import { revokeMetricsAccess } from "../integrations/metrics_certificate_system.ts";
import { networkCommand, runNetworkSystem } from "./network_system.ts";
import { gatewayHttp, type Http } from "../../automatic-deploy/gateway.ts";
import { DirectArgs, GatewayOperation, Globals } from "../_lib/direct.ts";
import { HubPublicationInput } from "./wireguard_local.ts";
import {
  nodeRemovalLocalActions,
  type RemovalActionDependencies,
} from "./removal_actions.ts";
import {
  NodeRemovalSnapshot,
  RemovalPlacement,
  RemovalStepResult,
} from "./removal_local.ts";
import {
  removalReachable,
  RemovalRetirement,
  retireNodeOffWorker,
} from "./removal_retirement.ts";
import type { BootstrapEnvironment } from "../node-bootstrap/bootstrap_ssh.ts";
import { RemovalSnapshot as RouteRemovalSnapshot } from "../routes/removal-local.ts";

const Args = DirectArgs.extend({
  kind: z.literal("node.remove"),
  restore: z.boolean().optional(),
  node_id: z.number().int().positive(),
});
export const RemovalAdmission = z.object({
  snapshot: NodeRemovalSnapshot,
  proxy_node_ids: ProxyNodeIds.default([]),
  placements: z.array(RemovalPlacement),
  hub: HubPublicationInput.nullable(),
  online_blocker: z.string().regex(/^[a-z0-9_.-]+$/).nullable().default(null),
  effective_offline: z.boolean().optional(),
  unavailable_node_ids: z.array(z.number().int().positive()).default([]),
  restore_hub: HubPublicationInput.nullable().default(null),
  restore_certificate_node_ids: z.array(z.number().int().positive()).default(
    [],
  ),
  restore_certificates: z.array(Leaf).max(256).default([]),
  public_ssh: z.record(z.string(), z.unknown()).nullable().default(null),
  metrics_fleet: RoleMetricsPlan.nullable().default(null),
  restore_metrics_fleet: RoleMetricsPlan.nullable().default(null),
  metrics_access: z.object({ node_id: z.number().int().positive() }).nullable()
    .default(null),
  retirement: RemovalRetirement.nullable(),
  host_retained: z.boolean(),
  retained_on_node: z.array(z.string().max(512)).max(128).default([]),
  routes: RouteRemovalSnapshot,
  started_at: z.string(),
  ready: z.boolean(),
}).strict();
const StepArgs = Args.extend({ admission: RemovalAdmission });
const ParticipantArgs = StepArgs.extend({ placement: RemovalPlacement });
type Context = BootstrapEnvironment & {
  globalArgs: z.infer<typeof Globals>;
  signal?: AbortSignal;
  writeResource: (
    spec: string,
    name: string,
    value: unknown,
  ) => Promise<unknown>;
};
const Outcome = z.object({
  schema_version: z.literal(1),
  node_id: z.number().int().positive(),
  removed: z.boolean(),
  name: z.string().max(255).optional(),
  offline: z.boolean().optional(),
  roles_shed: z.array(z.string()).optional(),
  retained_on_node: z.array(z.string()).optional(),
  follow_up: z.string().nullable().optional(),
  restored: z.boolean().optional(),
  error_code: z.string().regex(/^[a-z0-9_.-]{1,100}$/).optional(),
}).strict();

/** One native root. Participants never post the parent result or dispatch a Gateway child. */
export function nodeRemovalRoot(
  makeHttp: (ctx: Context) => Http = (ctx) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
  local: (
    deps: RemovalActionDependencies,
  ) => ReturnType<typeof nodeRemovalLocalActions> = nodeRemovalLocalActions,
  retire = retireNodeOffWorker,
  reachable = removalReachable,
  certificates = certificateActions(),
  access = revokeMetricsAccess(),
  invoke = runNetworkSystem,
) {
  const gateway = (args: z.infer<typeof Args>, ctx: Context) =>
    new GatewayOperation(makeHttp(ctx), args);
  const actions = (args: z.infer<typeof Args>, ctx: Context) =>
    local({
      withEnvironment: (field, use) =>
        gateway(args, ctx).withPrivateMaterial(async (material) => {
          await use(material[field]);
        }),
      read: async (_snapshot, placement) => {
        const current = z.object({
          id: z.literal(args.operation_id),
          node_id: z.literal(args.node_id),
          status: z.string(),
          cancel_requested: z.boolean(),
          input: z.unknown(),
        }).parse(
          await gateway(args, ctx).read(args.restore ? undefined : ctx.signal),
        );
        if (
          (!args.restore && current.cancel_requested) ||
          !["queued", "running"].includes(current.status)
        ) {
          throw new Error(
            "The removal parent no longer permits cleanup effects",
          );
        }
        if (placement.effect_node_id === args.node_id) {
          const root = z.object({ root_participant: z.unknown() }).parse(
            current.input,
          );
          return root.root_participant;
        }
        return current.input;
      },
    });
  const assertCurrent = async (
    args: z.infer<typeof StepArgs>,
    ctx: Context,
    restore = false,
  ) => {
    const current = z.object({
      id: z.literal(args.operation_id),
      kind: z.literal(args.kind),
      node_id: z.literal(args.node_id),
      status: z.enum(["queued", "running"]),
      cancel_requested: z.boolean(),
    })
      .parse(await gateway(args, ctx).read(restore ? undefined : ctx.signal));
    if (!restore && (current.cancel_requested || ctx.signal?.aborted)) {
      throw new Error("operation.cancelled");
    }
  };
  const output = async (
    ctx: Context,
    name: string,
    value: Record<string, unknown>,
  ) => {
    await ctx.writeResource("progress", name, value);
    return value;
  };
  const project = async (
    args: z.infer<typeof StepArgs>,
    ctx: Context,
    status: "failed" | "succeeded" | "cancelled",
    result: z.infer<typeof Outcome>,
  ) => {
    await gateway(args, ctx).project({
      status,
      started_at: args.admission.started_at,
      finished_at: new Date().toISOString(),
      result,
      error_code: result.error_code ?? null,
    });
    await ctx.writeResource("outcome", "outcome", result);
    return result;
  };
  return {
    type: "@nckrtl/node-removal-direct",
    version: "2026.10.31.2",
    globalArguments: Globals,
    resources: {
      progress: {
        description: "Public same-run stage results",
        schema: z.record(z.string(), z.unknown()),
        lifetime: "1d",
        garbageCollection: 10,
      },
      admission: {
        description: "Immutable removal inventory and exact Node placements",
        schema: RemovalAdmission,
        lifetime: "1d",
        garbageCollection: 10,
      },
      outcome: {
        description: "Bounded native removal outcome",
        schema: Outcome,
        lifetime: "1d",
        garbageCollection: 10,
      },
    },
    methods: {
      admit: {
        description: "Read one native Node removal root",
        arguments: Args,
        execute: async (raw: z.infer<typeof Args>, ctx: Context) => {
          const args = Args.parse(raw);
          const gw = gateway(args, ctx);
          const current = z.object({
            id: z.literal(args.operation_id),
            kind: z.literal(args.kind),
            node_id: z.literal(args.node_id),
            status: z.string(),
            cancel_requested: z.boolean(),
            input: RemovalAdmission.omit({
              started_at: true,
              ready: true,
            }).extend({
              root_participant: z.unknown().optional(),
              metrics_excluded_node_ids: z.array(z.number()).optional(),
              metrics_runtime_required: z.boolean().optional(),
            }),
          }).parse(await gw.read(ctx.signal));
          const snapshot = current.input.snapshot;
          const ids = [
            ...snapshot.participants.map((p) => p.node_id),
            ...(snapshot.hub_id === null ? [] : [snapshot.hub_id]),
          ];
          const excludedMetrics = snapshot.offline ? [snapshot.node_id] : [];
          const supported = (snapshot.metrics === null ||
            current.input.metrics_fleet !== null) &&
            current.input.unavailable_node_ids.length === 0 &&
            (current.input.metrics_fleet === null ||
              current.input.metrics_fleet.fleet.every((node) =>
                excludedMetrics.includes(node.node_id) ||
                hasRoleTransport(node, current.input.proxy_node_ids)
              )) &&
            JSON.stringify([...snapshot.route_ids].sort((a, b) => a - b)) ===
              JSON.stringify(
                current.input.routes.routes.map((row) => row.route_id).sort((
                  a,
                  b,
                ) => a - b),
              ) &&
            current.input.routes.nodes.every((member) =>
              ids.includes(member.node_id)
            ) &&
            snapshot.participants.every((member) =>
              member.routes ===
                current.input.routes.nodes.some((routeNode) =>
                  routeNode.node_id === member.node_id
                )
            ) &&
            (snapshot.offline || current.input.host_retained ||
              current.input.retirement !== null) &&
            (current.input.retirement === null ||
              (current.input.retirement.executor_node_id === args.node_id &&
                current.input.retirement.target.node_id ===
                  snapshot.node_id)) &&
            (snapshot.hub_id === null
              ? current.input.hub === null
              : current.input.hub?.hub_id === snapshot.hub_id) &&
            ids.every((id) =>
              current.input.placements.some((p) =>
                p.effect_node_id === id && p.execution_node_id === id &&
                p.transport === "local"
              )
            ) &&
            new Set(current.input.placements.map((p) => p.effect_node_id))
                .size === current.input.placements.length;
          const terminal = ["succeeded", "failed", "cancelled"].includes(
            current.status,
          );
          const {
            root_participant: _root,
            metrics_excluded_node_ids: _excluded,
            metrics_runtime_required: _runtime,
            ...safe
          } = current.input;
          const admission = {
            ...safe,
            effective_offline: snapshot.offline,
            restore_certificate_node_ids: [
              ...new Set(safe.restore_certificates.map((leaf) => leaf.node_id)),
            ],
            snapshot: current.input.snapshot,
            placements: current.input.placements,
            hub: current.input.hub,
            retirement: current.input.retirement,
            host_retained: current.input.host_retained,
            routes: current.input.routes,
            started_at: new Date().toISOString(),
            ready: !terminal && !current.cancel_requested &&
              !ctx.signal?.aborted && supported,
          };
          if (!terminal && (current.cancel_requested || ctx.signal?.aborted)) {
            await gw.project({
              status: "cancelled",
              started_at: admission.started_at,
              finished_at: new Date().toISOString(),
              result: {},
            });
          } else if (!terminal && !supported) {
            await project({ ...args, admission }, ctx, "failed", {
              schema_version: 1,
              node_id: snapshot.node_id,
              removed: false,
              restored: true,
              error_code: "node.removal_unavailable",
            });
          } else if (
            admission.ready && snapshot.offline && !snapshot.machine_deleted &&
            admission.retirement !== null &&
            await reachable(admission.retirement, undefined, ctx.signal)
          ) {
            if (admission.online_blocker !== null) {
              admission.ready = false;
              await project({ ...args, admission }, ctx, "failed", {
                schema_version: 1,
                node_id: snapshot.node_id,
                removed: false,
                restored: true,
                error_code: admission.online_blocker,
              });
            } else {
              admission.effective_offline = false;
            }
          }
          await ctx.writeResource("admission", "admission", admission);
          return admission;
        },
      },
      recoverAccess: {
        description:
          "Restore the online target's admitted public SSH rule before hub withdrawal",
        arguments: StepArgs,
        execute: async (args: z.infer<typeof StepArgs>, ctx: Context) => {
          if (!args.admission.ready) {
            throw new Error("node.removal_unavailable");
          }
          await assertCurrent(args, ctx);
          if (
            args.admission.public_ssh === null ||
            args.admission.effective_offline
          ) return await output(ctx, "ready", { ready: true });
          const result = z.object({
            matches: z.boolean().optional(),
            error_code: z.string().optional(),
          }).parse(
            await networkCommand(
              "firewall.rules",
              args.admission.public_ssh,
              invoke,
              ctx.signal,
            ),
          );
          if (result.error_code || result.matches !== true) {
            throw new Error("node.firewall_recovery_failed");
          }
          return await output(ctx, "ready", { ready: true });
        },
      },
      revokeAccess: {
        description:
          "Close Metrics streams through its canonical local action on the admitted Gateway",
        arguments: StepArgs,
        execute: async (args: z.infer<typeof StepArgs>, ctx: Context) => {
          if (!args.admission.ready) {
            throw new Error("node.removal_unavailable");
          }
          await assertCurrent(args, ctx);
          if (args.admission.metrics_access === null) {
            return await output(ctx, "ready", { ready: true });
          }
          await access(ctx.signal);
          return await output(ctx, "ready", { ready: true });
        },
      },
      beginRestore: {
        description: "Record restoration intent inside this same native run",
        arguments: StepArgs,
        execute: async (args: z.infer<typeof StepArgs>, ctx: Context) => {
          await assertCurrent(args, ctx, true);
          await gateway(args, ctx).project({
            status: "running",
            started_at: args.admission.started_at,
            result: { restoring: true },
          });
          return await output(ctx, "ready", { ready: true });
        },
      },
      requestRestoreCertificates: {
        description:
          "Prepare public CSRs on their exact Node using its canonical local account reader",
        arguments: StepArgs.extend({
          target_node_id: z.number().int().positive(),
        }),
        execute: async (
          args: z.infer<typeof StepArgs> & { target_node_id: number },
          ctx: Context,
        ) => {
          await assertCurrent(args, ctx, true);
          const leaves = [], requests = [];
          for (
            const raw of args.admission.restore_certificates.filter((leaf) =>
              leaf.node_id === args.target_node_id
            )
          ) {
            const leaf = {
              ...raw,
              home: raw.home === "/__orbit_converge_home__"
                ? (await localAccount(raw.user)).home
                : raw.home,
            };
            const request = Request.parse(
              await certificates.request(leaf),
            );
            if ("error_code" in request) throw new Error(request.error_code);
            leaves.push(leaf);
            requests.push({
              node_id: leaf.node_id,
              scope: leaf.scope,
              ...request,
            });
          }
          return await output(ctx, "requests", { leaves, requests });
        },
      },
      publishRestoreCertificates: {
        description:
          "Publish restored Route certificates without exporting Node-local keys",
        arguments: StepArgs.extend({
          target_node_id: z.number().int().positive(),
          requests: z.array(z.record(z.string(), z.unknown())),
          signed: z.array(CertificateSignedRecord),
        }),
        execute: async (
          args: z.infer<typeof StepArgs> & {
            target_node_id: number;
            requests: Record<string, unknown>[];
            signed: z.infer<typeof CertificateSignedRecord>[];
          },
          ctx: Context,
        ) => {
          await assertCurrent(args, ctx, true);
          for (
            const raw of args.admission.restore_certificates.filter((leaf) =>
              leaf.node_id === args.target_node_id
            )
          ) {
            const leaf = {
              ...raw,
              home: raw.home === "/__orbit_converge_home__"
                ? (await localAccount(raw.user)).home
                : raw.home,
            };
            const row = args.requests.find((r) =>
              r.node_id === leaf.node_id && r.scope === leaf.scope
            );
            if (!row) throw new Error("certificate.request_invalid");
            const request = Request.parse(
              row.state === "current"
                ? { state: "current" }
                : { state: "request", request_base64: row.request_base64 },
            );
            const record = args.signed.find((r) =>
              r.node_id === leaf.node_id && r.scope === leaf.scope
            );
            const signed = Signed.parse(
              record
                ? {
                  certificate_base64: record.certificate_base64,
                  root_certificate_base64: record.root_certificate_base64,
                }
                : { skipped: true },
            );
            const applied = await certificates.publish(
              leaf,
              request,
              signed,
              undefined,
            );
            if ("error_code" in applied) throw new Error(applied.error_code);
            const {
              domain: _domain,
              version: _version,
              root_hash: _root,
              user: _user,
              ...scope
            } = leaf;
            const presence = await certificates.inspect(scope, applied);
            if ("error_code" in presence || !presence.present) {
              throw new Error("certificate.presence_mismatch");
            }
          }
          return await output(ctx, "ready", { ready: true });
        },
      },
      begin: {
        description: "Project public membership before participant refresh",
        arguments: StepArgs,
        execute: async (raw: z.infer<typeof StepArgs>, ctx: Context) => {
          const args = StepArgs.parse(raw);
          if (!args.admission.ready) {
            throw new Error("The removal plan was not admitted");
          }
          ctx.signal?.throwIfAborted();
          await gateway(args, ctx).project({
            status: "running",
            started_at: args.admission.started_at,
            result: { removing: true },
          });
          return await output(ctx, "begun", { begun: true });
        },
      },
      participant: {
        description:
          "Apply canonical network, residue and DNS on one exact admitted participant",
        arguments: ParticipantArgs,
        execute: async (raw: z.infer<typeof ParticipantArgs>, ctx: Context) => {
          const args = ParticipantArgs.parse(raw);
          if (!args.admission.ready) {
            throw new Error("The removal plan was not admitted");
          }
          const s = args.admission.snapshot;
          const p = args.placement;
          const participant = s.participants.find((row) =>
            row.node_id === p.effect_node_id
          );
          if (
            !participant ||
            !args.admission.placements.some((row) =>
              row.effect_node_id === p.effect_node_id &&
              row.execution_node_id === p.execution_node_id &&
              row.transport === p.transport
            )
          ) {
            throw new Error(
              "The cleanup participant is outside the immutable inventory",
            );
          }
          const lower = actions(args, ctx);
          for (
            const phase of [
              ...(participant.peer ? ["peer" as const] : []),
              ...(participant.routes
                ? [args.restore ? "restore_routes" as const : "routes" as const]
                : []),
              ...(!args.restore && participant.residue
                ? ["residue" as const]
                : []),
              ...(participant.dns ? ["dns" as const] : []),
            ]
          ) {
            const action = lower[phase];
            if (!action) {
              throw new Error(
                "The required cleanup contributor is unavailable",
              );
            }
            const result = await action(
              s,
              p,
              args.restore ? undefined : ctx.signal,
            );
            if (!result.ready) {
              throw new Error(
                `Node cleanup refused: ${result.error_code}`,
              );
            }
          }
          return await output(ctx, "participant", {
            node_id: p.effect_node_id,
            ready: true,
          });
        },
      },
      guest: {
        description:
          "Retire the reachable guest off-worker and confirm cleanup before projection",
        arguments: StepArgs,
        execute: async (raw: z.infer<typeof StepArgs>, ctx: Context) => {
          const args = StepArgs.parse(raw);
          if (!args.admission.ready) {
            throw new Error("The removal plan was not admitted");
          }
          await assertCurrent(args, ctx);
          const s = args.admission.snapshot;
          if (
            args.admission.effective_offline || args.restore ||
            args.admission.host_retained
          ) return await output(ctx, "ready", { ready: true });
          const target = args.admission.retirement;
          if (
            target === null || target.executor_node_id !== args.node_id ||
            target.target.node_id !== s.node_id
          ) {
            throw new Error("The off-worker retirement binding is unavailable");
          }
          const outcome: { value: z.infer<typeof RemovalStepResult> | null } = {
            value: null,
          };
          await gateway(args, ctx).withPrivateMaterial(async (material) => {
            outcome.value = await retire(
              target,
              z.string().min(1).parse(material.bootstrap_identity),
              ctx,
              ctx.signal,
            );
          });
          const result = RemovalStepResult.parse(outcome.value);
          if (!result.ready) {
            throw new Error(`Node cleanup refused: ${result.error_code}`);
          }
          return await output(ctx, "ready", { ready: true });
        },
      },
      finish: {
        description: "Project success after every placed native job succeeded",
        arguments: StepArgs,
        execute: async (raw: z.infer<typeof StepArgs>, ctx: Context) => {
          const args = StepArgs.parse(raw);
          if (!args.admission.ready) {
            throw new Error("The removal plan was not admitted");
          }
          await assertCurrent(args, ctx);
          return await project(
            args,
            ctx,
            "succeeded",
            {
              schema_version: 1,
              node_id: args.admission.snapshot.node_id,
              removed: true,
              name: args.admission.snapshot.name,
              offline: args.admission.effective_offline,
              roles_shed: args.admission.effective_offline
                ? args.admission.snapshot.roles.map((row) => row.role)
                : [],
              retained_on_node: !args.admission.effective_offline &&
                  !args.admission.host_retained
                ? []
                : args.admission.retained_on_node,
              follow_up: args.admission.effective_offline
                ? "Discard this node, or clear only the leftovers listed above by hand once it is reachable."
                : null,
            },
          );
        },
      },
      fail: {
        description:
          "Retain the Node row after a failed cleanup without claiming restored state",
        arguments: StepArgs.extend({ restored: z.boolean().optional() }),
        execute: async (
          raw: z.infer<typeof StepArgs> & { restored?: boolean },
          ctx: Context,
        ) => {
          const args = StepArgs.extend({ restored: z.boolean().optional() })
            .parse(raw);
          return await project(args, ctx, "failed", {
            schema_version: 1,
            node_id: args.admission.snapshot.node_id,
            removed: false,
            restored: args.restored ?? false,
            error_code: args.restored === false
              ? "node.removal_rollback_failed"
              : "node.removal_failed",
          });
        },
      },
    },
  };
}
export const behavior = nodeRemovalRoot();
