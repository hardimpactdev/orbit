import { z } from "npm:zod@4.4.3";

const Id = z.number().int().positive();
const Participant = z.object({
  node_id: Id,
  peer: z.boolean(),
  routes: z.boolean(),
  residue: z.boolean(),
  dns: z.boolean(),
}).strict();
/** Public immutable inventory; provider deletion is a preceding owning-parent phase. */
export const NodeRemovalSnapshot = z.object({
  schema_version: z.literal(1),
  node_id: Id,
  name: z.string().min(1).max(255),
  prior_status: z.enum([
    "active",
    "failed",
    "provisioning",
    "removing",
    "pending",
  ]),
  task_vm_id: Id.nullable(),
  machine_deleted: z.boolean(),
  offline: z.boolean(),
  hub_id: Id.nullable(),
  roles: z.array(
    z.object({ id: Id, role: z.string().regex(/^[a-z][a-z0-9-]*$/) }).strict(),
  ).max(64),
  participants: z.array(Participant).max(1024),
  metrics: z.object({ node_id: Id, gateway_id: Id }).strict().nullable(),
  route_ids: z.array(Id).max(16384),
}).strict().superRefine((s, ctx) => {
  const ids = s.participants.map((p) => p.node_id);
  if (
    new Set(ids).size !== ids.length ||
    (s.offline && ids.includes(s.node_id)) ||
    new Set(s.route_ids).size !== s.route_ids.length ||
    (s.machine_deleted && (!s.offline || s.task_vm_id === null))
  ) ctx.addIssue({ code: "custom", message: "Invalid removal inventory" });
});
export type NodeRemovalSnapshot = z.infer<typeof NodeRemovalSnapshot>;
export const RemovalPlacement = z.object({
  effect_node_id: Id,
  execution_node_id: Id,
  transport: z.enum(["local", "ssh"]),
}).strict()
  .refine(
    (p) =>
      p.transport === "local"
        ? p.effect_node_id === p.execution_node_id
        : p.effect_node_id !== p.execution_node_id,
    "Placement must identify its real effect and execution Nodes",
  );
export type RemovalPlacement = z.infer<typeof RemovalPlacement>;
const Code = z.string().regex(/^[a-z0-9_.-]{1,100}$/);
export const RemovalStepResult = z.union([
  z.object({ ready: z.literal(true) }).strict(),
  z.object({ ready: z.literal(false), error_code: Code }).strict(),
]);
type StepResult = z.infer<typeof RemovalStepResult>;
export const NodeRemovalOutcome = z.union([
  z.object({
    schema_version: z.literal(1),
    node_id: Id,
    removed: z.literal(true),
  }).strict(),
  z.object({
    schema_version: z.literal(1),
    node_id: Id,
    removed: z.literal(false),
    restored: z.boolean(),
    error_code: Code,
  }).strict(),
]);
export type NodeRemovalOutcome = z.infer<typeof NodeRemovalOutcome>;
export const RemovalPhase = z.enum([
  "unreachable",
  "hub",
  "peer",
  "residue",
  "routes",
  "dns",
  "metrics_access",
  "metrics",
  "guest",
  "restore_hub",
  "restore_peer",
  "restore_routes",
  "restore_dns",
  "restore_metrics_access",
  "restore_metrics",
]);
export type RemovalPhase = z.infer<typeof RemovalPhase>;
export type RemovalStep = { phase: RemovalPhase; node_id: number };
/** Fixed owning lower actions, never a command/program dispatcher. A parent places each call. */
export type RemovalActions = Partial<
  Record<
    RemovalPhase,
    (
      snapshot: NodeRemovalSnapshot,
      placement: RemovalPlacement,
      signal?: AbortSignal,
    ) => Promise<StepResult>
  >
>;

export function removalSteps(raw: NodeRemovalSnapshot): RemovalStep[] {
  const s = NodeRemovalSnapshot.parse(raw);
  return [
    ...(!s.machine_deleted && s.offline
      ? [{ phase: "unreachable" as const, node_id: s.node_id }]
      : []),
    ...(s.hub_id === null
      ? []
      : [{ phase: "hub" as const, node_id: s.hub_id }]),
    ...s.participants.flatMap((p) => [
      ...(p.peer ? [{ phase: "peer" as const, node_id: p.node_id }] : []),
      ...(p.routes ? [{ phase: "routes" as const, node_id: p.node_id }] : []),
      ...(p.residue ? [{ phase: "residue" as const, node_id: p.node_id }] : []),
      ...(p.dns ? [{ phase: "dns" as const, node_id: p.node_id }] : []),
    ]),
    ...(s.metrics
      ? [{ phase: "metrics_access" as const, node_id: s.metrics.gateway_id }, {
        phase: "metrics" as const,
        node_id: s.metrics.node_id,
      }]
      : []),
    ...(!s.offline ? [{ phase: "guest" as const, node_id: s.node_id }] : []),
  ];
}
const restorePhase: Partial<Record<RemovalPhase, RemovalPhase>> = {
  hub: "restore_hub",
  peer: "restore_peer",
  routes: "restore_routes",
  dns: "restore_dns",
  metrics_access: "restore_metrics_access",
  metrics: "restore_metrics",
};
/** One native step. Missing contributors and placements are checked for the WHOLE plan before effects. */
export function nodeRemovalActions(actions: RemovalActions) {
  const prepare = (
    raw: NodeRemovalSnapshot,
    placements: RemovalPlacement[],
  ) => {
    const snapshot = NodeRemovalSnapshot.parse(raw);
    const placed = placements.map((p) => RemovalPlacement.parse(p));
    if (new Set(placed.map((p) => p.effect_node_id)).size !== placed.length) {
      throw new Error("Duplicate removal placement");
    }
    const steps = removalSteps(snapshot);
    const available = steps.every((step) =>
      actions[step.phase] && placed.some((p) =>
        p.effect_node_id === step.node_id &&
        (p.transport === "local" || step.phase === "hub" ||
          step.phase === "restore_hub")
      ) &&
      (step.phase === "routes" || !restorePhase[step.phase] ||
        actions[restorePhase[step.phase]!])
    );
    return { snapshot, placed, steps, available };
  };
  const step = async (
    snapshot: NodeRemovalSnapshot,
    placement: RemovalPlacement,
    phase: RemovalPhase,
    signal?: AbortSignal,
  ): Promise<StepResult> => {
    const action = actions[phase];
    if (!action) {
      return { ready: false, error_code: "node.removal_unavailable" };
    }
    return RemovalStepResult.parse(await action(snapshot, placement, signal));
  };
  const restore = async (
    snapshot: NodeRemovalSnapshot,
    placed: RemovalPlacement[],
    completed: RemovalStep[],
    signal?: AbortSignal,
  ) => {
    for (const done of [...completed].reverse()) {
      const phase = restorePhase[done.phase];
      if (!phase) continue;
      const placement = placed.find((p) => p.effect_node_id === done.node_id)!;
      const result = await step(snapshot, placement, phase, signal);
      if (!result.ready) return false;
    }
    return true;
  };
  return {
    prepare,
    step: async (
      raw: NodeRemovalSnapshot,
      placements: RemovalPlacement[],
      requested: RemovalStep,
      signal?: AbortSignal,
    ) => {
      const plan = prepare(raw, placements);
      if (!plan.available) {
        return {
          ready: false,
          error_code: "node.removal_unavailable",
        } as StepResult;
      }
      if (
        !plan.steps.some((s) =>
          s.phase === requested.phase && s.node_id === requested.node_id
        )
      ) throw new Error("Removal step is outside the admitted inventory");
      return await step(
        plan.snapshot,
        plan.placed.find((p) => p.effect_node_id === requested.node_id)!,
        requested.phase,
        signal,
      );
    },
    restore: async (
      raw: NodeRemovalSnapshot,
      placements: RemovalPlacement[],
      completed: RemovalStep[],
      signal?: AbortSignal,
    ) => {
      const plan = prepare(raw, placements);
      if (!plan.available) return false;
      if (
        new Set(completed.map((s) => `${s.phase}:${s.node_id}`)).size !==
          completed.length ||
        completed.some((s) =>
          !plan.steps.some((p) =>
            p.phase === s.phase && p.node_id === s.node_id
          )
        )
      ) throw new Error("Restore is outside the admitted removal inventory");
      return await restore(plan.snapshot, plan.placed, completed, signal);
    },
    /** For same-root TaskVM composition; inject a placed-step adapter, never call the deleted guest. */
    clean: async (
      raw: NodeRemovalSnapshot,
      placements: RemovalPlacement[],
      signal?: AbortSignal,
    ): Promise<NodeRemovalOutcome> => {
      const plan = prepare(raw, placements);
      if (!plan.available) {
        return {
          schema_version: 1,
          node_id: plan.snapshot.node_id,
          removed: false,
          restored: true,
          error_code: "node.removal_unavailable",
        };
      }
      const completed: RemovalStep[] = [];
      for (const current of plan.steps) {
        signal?.throwIfAborted();
        const result = await step(
          plan.snapshot,
          plan.placed.find((p) => p.effect_node_id === current.node_id)!,
          current.phase,
          signal,
        );
        if (!result.ready) {
          const restored = await restore(plan.snapshot, plan.placed, completed);
          return {
            schema_version: 1,
            node_id: plan.snapshot.node_id,
            removed: false,
            restored,
            error_code: restored
              ? result.error_code
              : "node.removal_rollback_failed",
          };
        }
        completed.push(current);
      }
      return {
        schema_version: 1,
        node_id: plan.snapshot.node_id,
        removed: true,
      };
    },
  };
}
