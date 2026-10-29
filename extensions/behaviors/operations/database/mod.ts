import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import {
  type Context,
  Executor,
  Globals,
  Operation,
} from "../../operation-runner/mod.ts";
import { Completion, Kinds, Plan, Result } from "./contract.ts";

export function database(
  makeHttp = (ctx: Context) =>
    gatewayHttp(
      ctx.globalArgs.gatewayUrl,
      ctx.globalArgs.caFile,
      ctx.signal,
      600_000,
    ),
) {
  return {
    type: "@nckrtl/operation-database",
    version: "2026.10.28.1",
    globalArguments: Globals,
    resources: {
      receipt: {
        description: "Permanent database completion intent",
        schema: Completion,
        lifetime: "infinite",
        garbageCollection: 10,
      },
      effect: {
        description:
          "Redacted named command receipt, saved before its step event",
        schema: Result,
        lifetime: "infinite",
        garbageCollection: 10,
      },
      pending: {
        description: "Permanent intent before a remote invocation",
        schema: z.object({ effect_key: z.string() }),
        lifetime: "infinite",
        garbageCollection: 10,
      },
    },
    methods: {
      execute: {
        description:
          "Execute admitted database commands and report proven results",
        arguments: z.object({
          operationId: z.number().int().positive(),
          worker: z.string(),
        }),
        execute: async (
          args: { operationId: number; worker: string },
          ctx: Context,
        ) => {
          const http = makeHttp(ctx);
          const executor = new Executor(http);
          const path = String(args.operationId);
          const resource = `operation-${path}`;
          const saved = await ctx.readResource(resource);
          if (saved) {
            const completion = Completion.parse(saved);
            await executor.post(`${path}/complete`, {
              worker: args.worker,
              ...completion,
            });
            return { status: completion.status };
          }
          const heartbeat = await executor.post(`${path}/heartbeat`, {
            worker: args.worker,
          });
          const operation = Operation.parse(heartbeat);
          if (!(Kinds as readonly string[]).includes(operation.kind)) {
            throw new Error("Wrong database kind");
          }
          let plan = Plan.parse(
            z.object({ execution_context: z.unknown() }).parse(heartbeat)
              .execution_context,
          );
          if (
            new Set(plan.descriptors.map((step) => step.key)).size !==
              plan.descriptors.length
          ) {
            throw new Error("Duplicate database effect key");
          }
          const children = async (phase: "before" | "after") => {
            const planned = plan.children.filter((child) =>
              child.phase === phase
            );
            if (planned.length === 0) return null;
            for (const child of planned) {
              await executor.post(`${path}/children`, {
                worker: args.worker,
                key: child.key,
              });
            }
            const response = await http(
              "GET",
              `/api/v1/operation-executor/${path}/children`,
            );
            if (response.status !== 200) {
              throw new Error("Cannot read database child receipt");
            }
            const rows = z.object({
              data: z.array(z.object({
                id: z.number().int().positive(),
                key: z.string(),
                kind: z.string(),
                node_id: z.number().int(),
                status: z.string(),
                error_code: z.string().nullable().optional(),
              })),
            }).parse(response.body).data;
            let failure: string | null = null;
            let pending = false;
            for (const child of planned) {
              const row = rows.find((row) =>
                row.key === child.key && row.kind === child.kind &&
                row.node_id === child.node_id
              );
              if (!row) throw new Error("Missing database child relationship");
              if (!["succeeded", "failed", "cancelled"].includes(row.status)) {
                pending = true;
              } else if (row.status !== "succeeded") {
                failure ??= row.error_code ?? "database.child_failed";
              }
            }
            if (pending) {
              await executor.post(`${path}/wait`, {
                worker: args.worker,
                keys: planned.map((child) => child.key),
              });
              return "waiting";
            }
            return failure;
          };
          let heartbeatError: unknown;
          const timer = setInterval(() => {
            executor.post(`${path}/heartbeat`, { worker: args.worker }).catch(
              (error) => {
                heartbeatError = error;
              },
            );
          }, 30_000);
          try {
            let completion: z.infer<typeof Completion> = {
              status: "succeeded",
              result: {},
              error_code: null,
            };
            if (
              operation.cancel_requested && operation.steps.length === 0 &&
              Object.keys(operation.step_results).length === 0
            ) {
              completion.status = "cancelled";
            } else {
              const childError = await children("before");
              if (childError === "waiting") return { status: "waiting" };
              if (childError) {
                completion = {
                  status: "failed",
                  result: { error_code: childError },
                  error_code: childError,
                };
              } else {
                const current = await executor.post(`${path}/heartbeat`, {
                  worker: args.worker,
                });
                plan = Plan.parse(
                  z.object({ execution_context: z.unknown() }).parse(current)
                    .execution_context,
                );
                for (const plannedStep of plan.descriptors) {
                  if (heartbeatError) throw heartbeatError;
                  if (ctx.signal?.aborted) throw ctx.signal.reason;
                  if (
                    completion.status !== "succeeded" && !plannedStep.cleanup
                  ) continue;
                  const currentStep = await executor.post(`${path}/heartbeat`, {
                    worker: args.worker,
                  });
                  const livePlan = Plan.parse(
                    z.object({ execution_context: z.unknown() }).parse(
                      currentStep,
                    ).execution_context,
                  );
                  const step = livePlan.descriptors.find((descriptor) =>
                    descriptor.key === plannedStep.key
                  );
                  if (!step) continue;
                  const recorded =
                    Operation.parse(currentStep).step_results[step.step];
                  if (recorded) {
                    if (!step.cleanup) {
                      const result = Result.parse(recorded);
                      completion = {
                        status: result.error_code === "operation.cancelled"
                          ? "cancelled"
                          : result.error_code
                          ? "failed"
                          : "succeeded",
                        result,
                        error_code: result.error_code ?? null,
                      };
                    }
                    continue;
                  }
                  if (step.key === "database-complete") {
                    plan = livePlan;
                    const cleanupError = await children("after");
                    if (cleanupError === "waiting") {
                      return { status: "waiting" };
                    }
                    if (cleanupError) {
                      completion = {
                        status: "failed",
                        result: { error_code: cleanupError },
                        error_code: cleanupError,
                      };
                      break;
                    }
                  }
                  const effectName = `${resource}-${step.key}`;
                  const savedEffect = await ctx.readResource(effectName);
                  await executor.post(`${path}/step`, {
                    worker: args.worker,
                    key: `${step.key}:start`,
                    name: step.step,
                    status: "running",
                    output: `Run ${step.step}\n`,
                  });
                  let result: z.infer<typeof Result>;
                  if (savedEffect) {
                    result = Result.parse(savedEffect);
                  } else {
                    const pendingName = `${effectName}-pending`;
                    if (
                      await ctx.readResource(pendingName) &&
                      ["agent", "remote_program"].includes(step.transport)
                    ) {
                      const response = await http(
                        "GET",
                        `/api/v1/operation-executor/${path}/receipts/${
                          encodeURIComponent(step.key)
                        }`,
                      );
                      if (response.status !== 200) {
                        throw new Error("Cannot read database effect receipt");
                      }
                      const receipt = z.object({
                        data: z.object({
                          effect_key: z.literal(`op-${path}.${step.key}`),
                          state: z.enum([
                            "succeeded",
                            "failed",
                            "running",
                            "unknown",
                          ]),
                          result: Result.nullable().optional(),
                        }),
                      }).parse(response.body).data;
                      if (receipt.state === "running") {
                        throw new Error("Database effect still running");
                      }
                      if (
                        receipt.state === "succeeded" ||
                        receipt.state === "failed"
                      ) {
                        const recovered = Result.parse(receipt.result);
                        if (
                          receipt.state === "failed" && !recovered.error_code
                        ) throw new Error("Unproven failed database receipt");
                        await ctx.writeResource(
                          "effect",
                          effectName,
                          recovered,
                        );
                      }
                    }
                    const recovered = await ctx.readResource(effectName);
                    await ctx.writeResource("pending", pendingName, {
                      effect_key: `op-${path}.${step.key}`,
                    });
                    result = Result.parse(
                      recovered ?? await executor.post(`${path}/run`, {
                        worker: args.worker,
                        key: step.key,
                        transport: step.transport,
                        name: step.name,
                        input: step.input,
                      }),
                    );
                    if (heartbeatError) throw heartbeatError;
                    if (!result.error_code && !result.backend) {
                      throw new Error("Unproven database outcome");
                    }
                    await ctx.writeResource("effect", effectName, result);
                  }
                  const error = result.error_code ?? null;
                  await executor.post(`${path}/step`, {
                    worker: args.worker,
                    key: `${step.key}:finish`,
                    name: step.step,
                    status: error ? "failed" : "succeeded",
                    result,
                    error_code: error,
                    output: `${error ?? result.backend}\n`,
                  });
                  if (
                    !step.cleanup ||
                    (error && completion.status === "succeeded")
                  ) {
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
                }
              }
              const cleanupContext = await executor.post(`${path}/heartbeat`, {
                worker: args.worker,
              });
              plan = Plan.parse(
                z.object({ execution_context: z.unknown() }).parse(
                  cleanupContext,
                ).execution_context,
              );
              const cleanupError = await children("after");
              if (cleanupError === "waiting") return { status: "waiting" };
              if (cleanupError && completion.status === "succeeded") {
                completion = {
                  status: "failed",
                  result: { error_code: cleanupError },
                  error_code: cleanupError,
                };
              }
            }
            const handle = await ctx.writeResource(
              "receipt",
              resource,
              completion,
            );
            await executor.post(`${path}/complete`, {
              worker: args.worker,
              ...completion,
            });
            return { dataHandles: [handle], status: completion.status };
          } finally {
            clearInterval(timer);
          }
        },
      },
    },
  };
}
export const behavior = { ...database(), type: "@nckrtl/operation-database" };
