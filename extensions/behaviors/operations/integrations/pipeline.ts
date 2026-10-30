import { z } from "npm:zod@4.4.3";
import {
  type Context,
  Executor,
  Operation,
} from "../../operation-runner/mod.ts";
import { Completion, ErrorCode } from "./contract.ts";

const Condition = z.object({
  step: z.string(),
  field: z.string(),
  equals: z.union([z.boolean(), z.string(), z.number()]),
});
const Descriptor = z.object({
  key: z.string(),
  step: z.string(),
  transport: z.enum(["agent", "remote_program", "gateway"]),
  name: z.string(),
  input: z.record(z.string(), z.unknown()),
  resumable: z.boolean(),
  required: z.boolean(),
});
const Flow = z.discriminatedUnion("type", [
  z.object({
    type: z.literal("run"),
    key: z.string(),
    when: Condition.optional(),
  }),
  z.object({
    type: z.literal("child"),
    key: z.string(),
    kind: z.string(),
    node_id: z.number().int().positive(),
    input: z.record(z.string(), z.unknown()),
    when: Condition.optional(),
    continue_on_failure: z.boolean().optional(),
  }),
]);
const SafeResult = z.object({
  backend: z.enum([
    "active",
    "absent",
    "enabled",
    "disabled",
    "reconciled",
    "recorded",
  ])
    .optional(),
  error_code: ErrorCode.optional(),
  changed: z.boolean().optional(),
  removed: z.boolean().optional(),
  healthy: z.boolean().optional(),
  staged: z.boolean().optional(),
  previous: z.boolean().optional(),
  backup_left: z.boolean().optional(),
  state: z.string().optional(),
  published: z.boolean().optional(),
  matches: z.boolean().optional(),
  recorded: z.boolean().optional(),
  restored: z.boolean().optional(),
  configuration: z.string().nullable().optional(),
  service_active: z.boolean().optional(),
  firewall_source: z.string().nullable().optional(),
  config: z.string().nullable().optional(),
  unit: z.string().nullable().optional(),
  rules: z.array(z.string()).max(16).optional(),
  active: z.boolean().optional(),
  enabled: z.boolean().optional(),
  binary: z.boolean().optional(),
  fingerprint: z.string().optional(),
  applied: z.boolean().optional(),
  verified: z.boolean().optional(),
});

type PipelineResult = {
  status: "waiting" | "succeeded" | "failed" | "cancelled";
  dataHandles?: Awaited<ReturnType<Context["writeResource"]>>[];
  result?: Record<string, unknown>;
  error_code?: string | null;
};

/** Applies the target-bound intent. Only the owning family can execute a child. */
export async function pipeline(
  operation: z.infer<typeof Operation>,
  executor: Executor,
  worker: string,
  ctx: Context,
  checkLease: () => void = () => {},
  refreshInputs = false,
  persistCompletion = true,
): Promise<PipelineResult> {
  const plan = z.object({
    effects: z.record(z.string(), Descriptor),
    flow: z.array(Flow).nonempty(),
    failure_flow: z.array(Flow).optional(),
    failure_when: Condition.optional(),
    failure_recovery: z.object({
      before_child: z.string(),
      effect: z.string(),
      child: z.object({
        key: z.string(),
        kind: z.string(),
        node_id: z.number().int().positive(),
        input: z.record(z.string(), z.unknown()),
      }),
    }).optional(),
  }).parse(operation.input);
  const path = String(operation.id);
  const results = { ...operation.step_results };
  let last: Record<string, unknown> = {};
  let childFailed = false;
  const succeededChildren = new Set<string>();
  for (const entry of plan.flow) {
    checkLease();
    if (entry.when) {
      const evidence = SafeResult.parse(results[entry.when.step]);
      if (
        evidence[entry.when.field as keyof typeof evidence] !==
          entry.when.equals
      ) continue;
    }
    if (entry.type === "child") {
      if (operation.cancel_requested) {
        return await complete({
          status: "cancelled",
          result: {},
          error_code: null,
        });
      }
      const { key, kind, node_id } = entry;
      const child = Operation.parse(
        await executor.post(`${path}/children`, {
          worker,
          key,
        }),
      );
      checkLease();
      if (child.kind !== kind || child.node_id !== node_id) {
        throw new Error("integration.child_invalid");
      }
      if (child.status === "failed" || child.status === "cancelled") {
        if (entry.continue_on_failure) {
          childFailed = true;
          continue;
        }
        return await complete({
          status: child.status,
          result: {},
          error_code: child.status === "failed"
            ? "integration.child_failed"
            : null,
        });
      }
      if (child.status !== "succeeded") {
        await executor.post(`${path}/wait`, { worker, keys: [key] });
        return { status: "waiting" };
      }
      succeededChildren.add(entry.key);
      continue;
    }
    let effect = plan.effects[entry.key];
    if (refreshInputs) {
      const current = z.object({
        execution_context: z.object({ descriptors: z.array(Descriptor) }),
      }).parse(
        await executor.post(`${path}/heartbeat`, { worker }),
      );
      effect = current.execution_context.descriptors.find((item) =>
        item.key === entry.key
      )!;
    }
    if (!effect || effect.key !== entry.key) {
      throw new Error("integration.plan_invalid");
    }
    const saved = results[effect.step];
    if (saved !== undefined) {
      last = SafeResult.parse(saved);
      if (last.error_code) {
        return await complete({
          status: last.error_code === "operation.cancelled"
            ? "cancelled"
            : "failed",
          result: last,
          error_code: ErrorCode.parse(last.error_code),
        });
      }
      continue;
    }
    if (
      operation.cancel_requested &&
      !operation.steps.some((step) => step.name === effect.step)
    ) {
      return await complete({
        status: "cancelled",
        result: {},
        error_code: null,
      });
    }
    const attempted = operation.steps.some((step) => step.name === effect.step);
    await executor.post(`${path}/step`, {
      worker,
      key: `${effect.key}:start`,
      name: effect.step,
      status: "running",
      output: `${effect.step}\n`,
    });
    const result = SafeResult.parse(
      await runEffect(
        executor,
        operation.id,
        worker,
        effect,
        attempted,
      ),
    );
    checkLease();
    const error = result.error_code ?? null;
    if (!error) prove(effect.name, effect.input, result);
    await executor.post(`${path}/step`, {
      worker,
      key: `${effect.key}:finish`,
      name: effect.step,
      status: error ? "failed" : "succeeded",
      result,
      error_code: error,
      output: error ? `${error}\n` : `${effect.step} applied\n`,
    });
    if (error) {
      return await complete({
        status: error === "operation.cancelled" ? "cancelled" : "failed",
        result,
        error_code: error,
      });
    }
    results[effect.step] = result;
    last = result;
  }
  return await complete({
    status: childFailed ? "failed" : "succeeded",
    result: last,
    error_code: childFailed ? "integration.child_failed" : null,
  });

  async function complete(
    completion: z.infer<typeof Completion>,
  ): Promise<PipelineResult> {
    checkLease();
    if (!persistCompletion) {
      return {
        status: completion.status,
        result: completion.result,
        error_code: completion.error_code,
      };
    }
    if (
      completion.status === "failed" && plan.failure_flow &&
      plan.failure_when &&
      completion.error_code !== "node.lock_lost" &&
      completion.error_code !== "operation.lease_lost"
    ) {
      const evidence = SafeResult.safeParse(results[plan.failure_when.step]);
      if (
        evidence.success &&
        evidence.data[plan.failure_when.field as keyof typeof evidence.data] ===
          plan.failure_when.equals
      ) {
        const rollback = await pipeline(
          {
            ...operation,
            input: { effects: plan.effects, flow: plan.failure_flow },
            step_results: results,
          },
          executor,
          worker,
          ctx,
          checkLease,
          false,
          false,
        );
        if (rollback.status === "waiting") return rollback;
        if (rollback.status !== "succeeded") {
          completion = {
            status: "failed",
            result: {},
            error_code: "websocket.rollback_failed",
          };
        }
      }
    }
    const recovery = plan.failure_recovery;
    if (
      completion.status === "failed" && recovery &&
      !succeededChildren.has(recovery.before_child)
    ) {
      const effect = plan.effects[recovery.effect];
      if (
        !effect || effect.name !== "ai_router.restore-takeover" ||
        effect.transport !== "gateway"
      ) {
        throw new Error("integration.recovery_invalid");
      }
      let restored = results[effect.step];
      if (restored === undefined) {
        restored = SafeResult.parse(
          await executor.post(`${path}/run`, {
            worker,
            key: effect.key,
            transport: effect.transport,
            name: effect.name,
            input: effect.input,
          }),
        );
        if (SafeResult.parse(restored).restored !== true) {
          throw new Error("ai_router.takeover_restore_failed");
        }
        await executor.post(`${path}/step`, {
          worker,
          key: `${effect.key}:finish`,
          name: effect.step,
          status: "succeeded",
          result: restored,
        });
      }
      if (SafeResult.parse(restored).restored !== true) {
        throw new Error("ai_router.takeover_restore_failed");
      }
      const child = Operation.parse(
        await executor.post(`${path}/children`, {
          worker,
          key: recovery.child.key,
        }),
      );
      checkLease();
      if (
        child.kind !== recovery.child.kind ||
        child.node_id !== recovery.child.node_id
      ) throw new Error("integration.child_invalid");
      if (
        !child.status || child.status === "failed" ||
        child.status === "cancelled"
      ) throw new Error("ai_router.takeover_restore_failed");
      if (child.status !== "succeeded") {
        await executor.post(`${path}/wait`, {
          worker,
          keys: [recovery.child.key],
        });
        return { status: "waiting" };
      }
    }
    const handle = await ctx.writeResource(
      "receipt",
      `operation-${path}`,
      completion,
    );
    await executor.post(`${path}/complete`, { worker, ...completion });
    return { status: completion.status, dataHandles: [handle] };
  }
}

function prove(
  name: string,
  input: Record<string, unknown>,
  result: z.infer<typeof SafeResult>,
) {
  const action = input.action as { operation?: string } | undefined;
  const snapshot = action?.operation === "snapshot" ||
    input.operation === "snapshot";
  const valid =
    snapshot && ["metrics.node-exporter", "metrics.cadvisor"].includes(name)
      ? typeof result.service_active === "boolean" &&
        "configuration" in result && "firewall_source" in result
      : snapshot && name === "metrics.service-runtime"
      ? typeof result.active === "boolean" && result.rules !== undefined
      : name === "php.inspect-production-pool"
      ? typeof result.enabled === "boolean" &&
        typeof result.fingerprint === "string"
      : result.applied === true || result.verified === true
      ? true
      : ["integration.receipt", "metrics.receipt"].includes(name)
      ? result.backend !== undefined
      : name === "integration.certificate-record"
      ? result.recorded === true
      : name === "websocket.runtime-active"
      ? result.active === true
      : name === "http.await-healthy"
      ? result.healthy === true
      : name === "firewall.rules"
      ? result.matches === true
      : name === "ai_router.restore-takeover"
      ? result.restored === true
      : name === "ai_router.rename-cache"
      ? result.changed === true
      : typeof result.changed === "boolean" || result.removed === true ||
        result.staged === true;
  if (!valid) throw new Error("integration.outcome_unproven");
}

/** Resolve an earlier remote attempt through Kit I before sending the same key again. */
export async function runEffect(
  executor: Executor,
  operationId: number,
  worker: string,
  effect: {
    key: string;
    transport: string;
    name: string;
    input: Record<string, unknown>;
  },
  started: boolean,
): Promise<unknown> {
  if (started && effect.transport !== "gateway") {
    const receipt = await executor.receipt(operationId, effect.key);
    if (receipt.state === "succeeded" || receipt.state === "failed") {
      if (receipt.result === null) {
        throw new Error("integration.outcome_unproven");
      }
      return receipt.result;
    }
    if (receipt.state === "running") {
      throw new Error("operation.effect_running");
    }
  }
  const result = await executor.post(`${operationId}/run`, {
    worker,
    key: effect.key,
    transport: effect.transport,
    name: effect.name,
    input: effect.input,
  });
  if (
    typeof result === "object" && result !== null && "not_started" in result
  ) {
    const refusal = z.object({
      error_code: z.enum([
        "agent.target_busy",
        "agent.lock_unavailable",
        "agent.supervisor_not_ready",
      ]),
      not_started: z.literal(true),
    }).safeParse(result);
    if (!refusal.success) throw new Error("integration.outcome_unproven");
    throw new Error("operation.effect_not_started");
  }
  return result;
}
