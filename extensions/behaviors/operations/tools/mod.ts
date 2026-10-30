import { z } from "npm:zod@4.4.3";
import { executeToolPolicy } from "./policy.ts";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import {
  type Context,
  Executor,
  ExecutorError,
  Globals,
  Operation,
} from "../../operation-runner/mod.ts";
import {
  Completion,
  Input,
  ReceiptResult,
  refusalCode,
  type Result,
  ToolRefusal,
} from "./contract.ts";

export function tools(
  makeHttp = (ctx: Context) =>
    gatewayHttp(
      ctx.globalArgs.gatewayUrl,
      ctx.globalArgs.caFile,
      ctx.signal,
      600_000,
    ),
) {
  return {
    type: "@nckrtl/operation-tools",
    version: "2026.10.29.4",
    globalArguments: Globals,
    resources: {
      assignment: {
        description:
          "Bind a dispatch retry to its first claimed Tool Operation before effects",
        schema: z.object({ operation_id: z.number().int().positive() }),
        lifetime: "7d",
        garbageCollection: 1,
      },
      receipt: {
        description: "Permanent completion intent for one Tool Operation",
        schema: Completion,
        lifetime: "infinite",
        garbageCollection: 10,
      },
      effect: {
        description: "Exact Tool step intent and outcome",
        schema: z.record(z.string(), z.unknown()),
        lifetime: "infinite",
        garbageCollection: 10,
      },
    },
    methods: {
      execute: {
        description:
          "Claim and execute one Tool Operation on the same placed worker Node",
        arguments: z.object({
          kind: z.enum([
            "tool.manager_converge",
          ]),
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
                id: z.number().int(),
                kind: z.string(),
                status: z.string(),
                result: ReceiptResult.nullable(),
                error_code: z.string().nullable(),
              }).parse(await executor.operation(id));
              if (
                ["succeeded", "failed", "cancelled"].includes(outcome.status)
              ) {
                const scalarResult = (value: Record<string, unknown>) =>
                  JSON.stringify(
                    Object.entries(value).sort(([a], [b]) =>
                      a.localeCompare(b)
                    ),
                  );
                if (
                  outcome.id !== id || outcome.kind !== args.kind ||
                  outcome.status !== completion.status ||
                  outcome.error_code !== completion.error_code ||
                  scalarResult(outcome.result ?? {}) !==
                    scalarResult(completion.result)
                ) {
                  throw new Error("Unproven saved Tool completion");
                }
                return {
                  claimed: true,
                  operationId: id,
                  status: completion.status,
                };
              }
            }
            // A retry never claims another Operation. An expired/lost lease is
            // recovered by normal discovery and a new worker dispatch instead.
            operation = Operation.parse(
              await executor.post(`${id}/heartbeat`, { worker: args.worker }),
            );
            if (operation.id !== id) {
              throw new Error("Wrong Tool assignment");
            }
          } else {
            // Claim and every later write share this invocation's authenticated Node.
            const claimed = await executor.post("claim", {
              kind: args.kind,
              worker: args.worker,
            });
            if (claimed === null) return { claimed: false };
            operation = Operation.parse(claimed);
            await ctx.writeResource("assignment", attempt, {
              operation_id: operation.id,
            });
          }
          if (operation.kind !== args.kind) {
            throw new Error("Wrong claimed Tool kind");
          }
          const path = String(operation.id), resource = `operation-${path}`;
          const finish = async (completion: z.infer<typeof Completion>) => {
            const handle = await ctx.writeResource(
              "receipt",
              resource,
              completion,
            );
            await executor.post(`${path}/complete`, {
              worker: args.worker,
              ...completion,
            });
            return {
              dataHandles: [handle],
              claimed: true,
              operationId: operation.id,
              status: completion.status,
            };
          };
          const saved = await ctx.readResource(resource);
          if (saved) {
            const completion = Completion.parse(saved);
            await executor.post(`${path}/complete`, {
              worker: args.worker,
              ...completion,
            });
            return {
              claimed: true,
              operationId: operation.id,
              status: completion.status,
            };
          }
          const input = Input.parse(operation.input);
          if (input.node_id !== operation.node_id) {
            throw new Error("Wrong Tool Node");
          }
          let heartbeatError: unknown, activeStep: string | null = null;
          const timer = setInterval(() => {
            executor.post(`${path}/heartbeat`, { worker: args.worker }).catch(
              (error) => {
                heartbeatError = error;
              },
            );
          }, 30_000);
          const checkLease = () => {
            if (heartbeatError) throw heartbeatError;
          };
          const run = async (
            step: string,
          ): Promise<Result> => {
            checkLease();
            const descriptor = operation.execution_context.descriptors.find(
              (entry) =>
                entry.key === step && entry.step === step &&
                entry.transport === "agent",
            );
            if (!descriptor) {
              throw new ExecutorError("operation.execution_forbidden");
            }
            const { name, input: body } = descriptor;
            const recorded = operation.step_results[step];
            if (recorded) {
              return ReceiptResult.parse(recorded);
            }
            const intent = {
              worker: args.worker,
              key: step,
              transport: "agent",
              name,
              input: body,
            };
            // Keep the exact input before invoking the Gateway. Its run key is the effect boundary.
            const intentResource = `${resource}-${step}`;
            const stored = await ctx.readResource(intentResource);
            if (stored && typeof stored.error_code === "string") {
              activeStep = step;
              throw new ToolRefusal(
                stored.error_code,
                ReceiptResult.parse(stored.result ?? {}),
              );
            }
            await ctx.writeResource("effect", intentResource, { intent });
            activeStep = step;
            await executor.post(`${path}/step`, {
              worker: args.worker,
              key: `${step}:start`,
              name: step,
              status: "running",
              output: `${name}\n`,
            });
            let result: Result | undefined;
            if (
              stored || operation.steps.some((saved) => saved.name === step)
            ) {
              const receipt = await executor.receipt(operation.id, step);
              if (receipt.effect_key !== `op-${path}.${step}`) {
                throw new ExecutorError("operation.outcome_unknown");
              }
              if (receipt.state === "running") {
                throw new ExecutorError("operation.effect_running");
              }
              if (receipt.state === "succeeded" || receipt.state === "failed") {
                if (
                  receipt.result === null ||
                  receipt.state === "failed" && !receipt.result.error_code
                ) throw new ExecutorError("operation.outcome_unknown");
                result = ReceiptResult.parse(receipt.result);
              }
            }
            result ??= ReceiptResult.parse(
              await executor.post(`${path}/run`, intent),
            );
            checkLease();
            const error = typeof result.error_code === "string"
              ? refusalCode(step, result.error_code, operation.kind)
              : null;
            if (error && !/^[a-z0-9_.-]+$/.test(error)) {
              throw new Error("Invalid Tool refusal code");
            }
            // Save refusals before finishing a failed step, so a restart can complete it.
            if (error) {
              await ctx.writeResource("effect", intentResource, {
                intent,
                error_code: error,
                result,
              });
            }
            if (error) throw new ToolRefusal(error, result);
            await executor.post(`${path}/step`, {
              worker: args.worker,
              key: `${step}:finish`,
              name: step,
              status: error ? "failed" : "succeeded",
              result,
              error_code: error,
              output: error ? `${error}\n` : "Verified named command receipt\n",
            });
            activeStep = null;
            operation.step_results[step] = result;
            return result;
          };
          try {
            if (operation.cancel_requested && operation.steps.length === 0) {
              return await finish({
                status: "cancelled",
                result: {},
                error_code: null,
              });
            }
            const final = await executeToolPolicy(
              input,
              operation.kind,
              run,
              operation.execution_context.descriptors.some((entry) =>
                entry.key === "manager-materialize"
              ),
            );
            checkLease();
            return await finish({
              status: "succeeded",
              result: final,
              error_code: null,
            });
          } catch (error) {
            // Unknown transport outcomes and lost leases remain resumable. They are never effect failures.
            if (!(error instanceof ToolRefusal)) throw error;
            const step = activeStep ?? "tool-policy";
            if (!activeStep) {
              await executor.post(`${path}/step`, {
                worker: args.worker,
                key: `${step}:start`,
                name: step,
                status: "running",
              });
            }
            await executor.post(`${path}/step`, {
              worker: args.worker,
              key: `${step}:refused`,
              name: step,
              status: "failed",
              result: error.result,
              error_code: error.code,
              output: `${error.code}\n`,
            });
            return await finish({
              status: error.code === "operation.cancelled"
                ? "cancelled"
                : "failed",
              result: error.result,
              error_code: error.code,
            });
          } finally {
            clearInterval(timer);
          }
        },
      },
    },
  };
}
export const behavior = { ...tools(), type: "@nckrtl/operation-tools" };
