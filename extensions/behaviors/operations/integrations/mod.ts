import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import {
  type Context,
  Executor,
  ExecutorError,
  Globals,
  Operation,
} from "../../operation-runner/mod.ts";
import { pipeline, runEffect } from "./pipeline.ts";
import { maintainConn, revokeConn } from "./conn.ts";
import { metricsFleet } from "./metrics.ts";
import { Completion, invocation } from "./contract.ts";

/** Runs only an admitted integration effect; credentials are resolved by the Gateway. */
export function integrations(
  makeHttp = (ctx: Context) =>
    gatewayHttp(
      ctx.globalArgs.gatewayUrl,
      ctx.globalArgs.caFile,
      ctx.signal,
      600_000,
    ),
) {
  return {
    type: "@nckrtl/operation-integrations",
    version: "2026.10.28.1",
    globalArguments: Globals,
    resources: {
      assignment: {
        description:
          "Bind a worker dispatch to its first claimed Operation before effects",
        schema: z.object({ operation_id: z.number().int().positive() }),
        lifetime: "7d",
        garbageCollection: 1,
      },
      receipt: {
        description: "Permanent safe integration completion intent",
        schema: Completion,
        lifetime: "infinite",
        garbageCollection: 10,
      },
    },
    methods: {
      maintenance: {
        description: "Admit pending Conn registration session cleanup",
        arguments: z.object({ tick: z.string().min(1).max(128) }),
        execute: async (args: { tick: string }, ctx: Context) =>
          await maintainConn(makeHttp(ctx), args.tick),
      },
      execute: {
        description:
          "Apply an admitted integration effect and record its proven result",
        arguments: z.object({
          kind: z.string().min(1),
          worker: z.string().min(1),
        }),
        execute: async (
          args: { kind: string; worker: string },
          ctx: Context,
        ) => {
          const executor = new Executor(makeHttp(ctx));
          const attempt = `assignment-${args.worker}`;
          const assigned = await ctx.readResource(attempt);
          let operation: z.infer<typeof Operation>;
          if (assigned) {
            const id =
              z.object({ operation_id: z.number().int().positive() }).parse(
                assigned,
              ).operation_id;
            const saved = await ctx.readResource(`operation-${id}`);
            if (saved) {
              const completion = Completion.parse(saved);
              const outcome = z.object({
                id: z.number().int().positive(),
                kind: z.string(),
                status: z.string(),
                result: z.preprocess(
                  (value) =>
                    Array.isArray(value) && value.length === 0 ? {} : value,
                  z.record(z.string(), z.unknown()).nullable(),
                ),
                error_code: z.string().nullable(),
              }).parse(await executor.operation(id));
              if (
                ["succeeded", "failed", "cancelled"].includes(outcome.status)
              ) {
                if (
                  outcome.id !== id || outcome.kind !== args.kind ||
                  outcome.status !== completion.status ||
                  outcome.error_code !== completion.error_code ||
                  canonical(outcome.result ?? {}) !==
                    canonical(completion.result)
                ) {
                  throw new Error("integration.completion_unproven");
                }
                return {
                  claimed: true,
                  operationId: id,
                  status: completion.status,
                };
              }
            }
            operation = Operation.parse(
              await executor.post(`${id}/heartbeat`, { worker: args.worker }),
            );
            if (operation.id !== id) {
              throw new Error("integration.operation_invalid");
            }
          } else {
            const claimed = await executor.claim(args.kind, args.worker);
            if (claimed === null) return { claimed: false };
            operation = claimed;
            if (operation.kind !== args.kind) {
              throw new Error("integration.operation_invalid");
            }
            await ctx.writeResource("assignment", attempt, {
              operation_id: operation.id,
            });
          }
          if (operation.kind !== args.kind) {
            throw new Error("integration.operation_invalid");
          }
          const path = String(operation.id);
          const resource = `operation-${path}`;
          const saved = await ctx.readResource(resource);
          if (saved) {
            const completion = Completion.parse(saved);
            return await finish(
              executor,
              operation,
              args.worker,
              ctx,
              completion,
            );
          }
          if (
            operation.input.flow !== undefined ||
            operation.execution_context?.descriptors.length
          ) {
            let leaseError: unknown;
            const timer = setInterval(() => {
              executor.post(`${path}/heartbeat`, { worker: args.worker }).catch(
                (error) => {
                  leaseError = error;
                },
              );
            }, 30_000);
            try {
              if (
                (operation.kind === "conn.pairing_revoke" ||
                  operation.kind === "conn.gateway_sessions_revoke") &&
                operation.execution_context?.descriptors.some((item) =>
                  item.name === "conn.revocation"
                )
              ) {
                return await revokeConn(
                  operation,
                  executor,
                  args.worker,
                  ctx,
                  () => {
                    if (leaseError) throw leaseError;
                  },
                );
              }
              if (
                (operation.kind === "ai_router.collector_converge" ||
                  /^(metrics|analytics|websocket)\.role_(converge|remove)$/
                    .test(operation.kind)) &&
                operation.input.flow === undefined
              ) {
                const role = operation.kind !== "ai_router.collector_converge";
                const prepareKey = role ? "role-prepare" : "collector-prepare";
                const prepareName = role
                  ? "integration.prepare-role"
                  : "ai_router.prepare";
                const prepared = operation.step_results[prepareKey];
                if (prepared === undefined) {
                  const result = z.object({ prepared: z.literal(true) }).parse(
                    await executor.post(`${path}/run`, {
                      worker: args.worker,
                      key: prepareKey,
                      transport: "gateway",
                      name: prepareName,
                      input: {},
                    }),
                  );
                  await executor.post(`${path}/step`, {
                    worker: args.worker,
                    key: `${prepareKey}:finish`,
                    name: prepareKey,
                    status: "succeeded",
                    result,
                  });
                }
                operation.input = z.record(z.string(), z.unknown()).parse(
                  await executor.post(`${path}/integrations/intent`, {
                    worker: args.worker,
                  }),
                );
              }
              if (
                operation.input.preference_only !== true && [
                  "metrics.role_converge",
                  "metrics.role_remove",
                  "metrics.enable",
                  "metrics.disable",
                  "metrics.reconcile",
                  "metrics.exporter_enable",
                  "metrics.exporter_disable",
                ].includes(operation.kind)
              ) {
                return await metricsFleet(
                  operation,
                  executor,
                  args.worker,
                  ctx,
                  () => {
                    if (leaseError) throw leaseError;
                  },
                );
              }
              const refreshInputs = operation.input.flow === undefined;
              if (refreshInputs) {
                const descriptors = operation.execution_context!.descriptors;
                operation.input = {
                  ...operation.input,
                  effects: Object.fromEntries(
                    descriptors.map((item) => [item.key, item]),
                  ),
                  flow: descriptors.map((item) => ({
                    type: "run",
                    key: item.key,
                  })),
                };
              }
              return await pipeline(
                operation,
                executor,
                args.worker,
                ctx,
                () => {
                  if (leaseError) throw leaseError;
                },
                refreshInputs,
              );
            } finally {
              clearInterval(timer);
            }
          }
          const effect = invocation(operation.kind, operation.input);
          const probe =
            Object.keys(operation.step_results).filter((name) =>
              name.startsWith("reverb-probe-")
            ).length;
          const runKey = effect.pending
            ? `${effect.step}:${probe}`
            : effect.step;
          let heartbeatError: unknown;
          const timer = setInterval(() => {
            executor.post(`${path}/heartbeat`, { worker: args.worker }).catch(
              (error) => {
                heartbeatError = error;
              },
            );
          }, 30_000);
          try {
            const previous = operation.step_results[effect.step];
            const started = operation.steps.some((step) =>
              step.name === effect.step
            );
            let completion: z.infer<typeof Completion>;
            if (
              operation.cancel_requested && previous === undefined && !started
            ) {
              completion = {
                status: "cancelled",
                result: {},
                error_code: null,
              };
            } else {
              let result: Record<string, unknown>;
              if (previous !== undefined) {
                result = effect.parseResult(previous);
                if (!effect.error(result) && !effect.prove(result)) {
                  throw new Error("integration.outcome_unproven");
                }
              } else {
                await executor.post(`${path}/step`, {
                  worker: args.worker,
                  key: `${effect.step}:start`,
                  name: effect.step,
                  status: "running",
                  output: `${effect.step}\n`,
                });
                result = effect.parseResult(
                  await runEffect(executor, operation.id, args.worker, {
                    key: runKey,
                    transport: effect.transport,
                    name: effect.name,
                    input: effect.input,
                  }, started),
                );
                if (heartbeatError) throw heartbeatError;
                if (effect.pending?.(result)) {
                  await executor.post(`${path}/step`, {
                    worker: args.worker,
                    key: `reverb-probe-${probe}`,
                    name: `reverb-probe-${probe}`,
                    status: "succeeded",
                    result,
                    output: "Waiting for current Reverb process reports\n",
                  });
                  throw new Error("websocket.registry_pending");
                }
                const error = effect.error(result);
                if (!error && !effect.prove(result)) {
                  throw new Error("integration.outcome_unproven");
                }
                await executor.post(`${path}/step`, {
                  worker: args.worker,
                  key: `${effect.step}:finish`,
                  name: effect.step,
                  status: error ? "failed" : "succeeded",
                  result,
                  error_code: error,
                  output: error ? `${error}\n` : `${effect.step} applied\n`,
                });
              }
              const error = effect.error(result);
              completion = {
                status: error === "operation.cancelled"
                  ? "cancelled"
                  : error
                  ? "failed"
                  : "succeeded",
                result,
                error_code: error,
              };
            }
            return await finish(
              executor,
              operation,
              args.worker,
              ctx,
              completion,
            );
          } finally {
            clearInterval(timer);
          }
        },
      },
    },
  };
}
export const behavior = {
  ...integrations(),
  type: "@nckrtl/operation-integrations",
};

function canonical(value: unknown): string {
  const sort = (item: unknown): unknown => {
    if (Array.isArray(item)) return item.map(sort);
    if (item !== null && typeof item === "object") {
      return Object.fromEntries(
        Object.entries(item).sort(([a], [b]) => a.localeCompare(b)).map((
          [key, entry],
        ) => [key, sort(entry)]),
      );
    }
    return item;
  };
  return JSON.stringify(sort(value));
}

/** A successful read can be superseded before the Gateway's final fresh-state check. */
async function finish(
  executor: Executor,
  operation: z.infer<typeof Operation>,
  worker: string,
  ctx: Context,
  completion: z.infer<typeof Completion>,
) {
  let handle = await ctx.writeResource(
    "receipt",
    `operation-${operation.id}`,
    completion,
  );
  try {
    await executor.post(`${operation.id}/complete`, { worker, ...completion });
  } catch (error) {
    if (
      !(error instanceof ExecutorError) ||
      error.code !== "websocket.registry_superseded" ||
      completion.status !== "succeeded" ||
      ![
        "websocket.app_rotate",
      ].includes(operation.kind)
    ) {
      throw error;
    }
    completion = {
      status: "failed",
      result: { error_code: "websocket.registry_superseded" },
      error_code: "websocket.registry_superseded",
    };
    handle = await ctx.writeResource(
      "receipt",
      `operation-${operation.id}`,
      completion,
    );
    await executor.post(`${operation.id}/complete`, { worker, ...completion });
  }
  return { dataHandles: [handle], status: completion.status };
}
