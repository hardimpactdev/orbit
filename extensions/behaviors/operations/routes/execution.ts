import { z } from "npm:zod@4.4.3";
import type { Http } from "../../automatic-deploy/gateway.ts";
import {
  type Context,
  Executor,
  ExecutorError,
  Operation,
} from "../../operation-runner/mod.ts";
import { RouteRefusal } from "./lifecycle.ts";
import { ParentPlan, RouteParentStages, RouteWaiting } from "./parents.ts";
import { Completion } from "./contract.ts";

/** Durable family composition over B; every wait ends this workflow instance. */
export async function executeComposition(
  args: { operationId: number; worker: string },
  ctx: Context,
  http: Http,
  operation: z.infer<typeof Operation>,
  commandName: string,
  drive: (
    stages: RouteParentStages,
    plan: z.infer<typeof ParentPlan>,
  ) => Promise<void>,
) {
  const executor = new Executor(http),
    path = String(args.operationId),
    resource = `operation-${path}`;
  let heartbeatError: unknown;
  const timer = setInterval(() => {
    executor.post(`${path}/heartbeat`, { worker: args.worker }).catch(
      (error) => {
        heartbeatError = error;
      },
    );
  }, 30_000);
  const gateway = async (key: string, input: Record<string, unknown>) => {
    const saved = operation.step_results[key];
    if (saved) {
      const result = z.record(z.string(), z.unknown()).parse(saved);
      if (typeof result.error_code === "string") {
        throw new RouteRefusal(result.error_code);
      }
      return result;
    }
    await executor.post(`${path}/step`, {
      worker: args.worker,
      key: `${key}:start`,
      name: key,
      status: "running",
    });
    const result = z.record(z.string(), z.unknown()).parse(
      await executor.post(`${path}/run`, {
        worker: args.worker,
        key,
        transport: "gateway",
        name: commandName,
        input,
      }),
    );
    const error = typeof result.error_code === "string"
      ? result.error_code
      : null;
    await executor.post(`${path}/step`, {
      worker: args.worker,
      key: `${key}:finish`,
      name: key,
      status: error ? "failed" : "succeeded",
      result,
      error_code: error,
    });
    if (error) throw new RouteRefusal(error);
    operation.step_results[key] = result;
    return result;
  };
  try {
    let completion: z.infer<typeof Completion>;
    if (operation.cancel_requested && operation.steps.length === 0) {
      completion = { status: "cancelled", result: {}, error_code: null };
    } else {
      try {
        const plan = ParentPlan.parse(
          await gateway("parent-prepare", { action: "prepare" }),
        );
        const stages = new RouteParentStages(
          http,
          args.operationId,
          args.worker,
          plan.child_phases,
          operation.step_results,
          async (keys) => {
            await ctx.writeResource("waiting", `${resource}-waiting`, { keys });
          },
          commandName,
        );
        if (heartbeatError) throw heartbeatError;
        await drive(stages, plan);
        completion = {
          status: "succeeded",
          result: await gateway("route-proof", { action: "prove" }),
          error_code: null,
        };
      } catch (error) {
        if (error instanceof RouteWaiting) {
          return { status: "waiting", keys: error.keys };
        }
        if (
          error instanceof ExecutorError && error.code === "operation.cancelled"
        ) {
          completion = {
            status: "cancelled",
            result: { error_code: error.code },
            error_code: null,
          };
        } else {
          if (!(error instanceof RouteRefusal)) throw error;
          completion = {
            status: error.code === "operation.cancelled"
              ? "cancelled"
              : "failed",
            result: { error_code: error.code },
            error_code: error.code,
          };
        }
      }
    }
    const handle = await ctx.writeResource("receipt", resource, completion);
    await executor.post(`${path}/complete`, {
      worker: args.worker,
      ...completion,
    });
    return { status: completion.status, dataHandles: [handle] };
  } finally {
    clearInterval(timer);
  }
}
