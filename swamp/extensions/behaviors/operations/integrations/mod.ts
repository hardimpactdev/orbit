import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import {
  type Context,
  Executor,
  Globals,
  Operation,
} from "../../operation-runner/mod.ts";
import { pipeline } from "./pipeline.ts";
import { maintainConn } from "../conn/maintenance.ts";
import { Completion } from "./contract.ts";

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
                (operation.kind === "ai_router.collector_converge" ||
                  /^websocket\.role_(converge|remove)$/
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
          throw new Error("integration.kind_invalid");
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

/** Persist completion before projecting it, so a lost reply reuses the saved result. */
async function finish(
  executor: Executor,
  operation: z.infer<typeof Operation>,
  worker: string,
  ctx: Context,
  completion: z.infer<typeof Completion>,
) {
  const handle = await ctx.writeResource(
    "receipt",
    `operation-${operation.id}`,
    completion,
  );
  await executor.post(`${operation.id}/complete`, { worker, ...completion });
  return { dataHandles: [handle], status: completion.status };
}
