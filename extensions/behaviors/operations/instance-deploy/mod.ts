import { z } from "npm:zod@4.4.3";
import { gatewayHttp, type Http } from "../../automatic-deploy/gateway.ts";
import {
  type Context,
  Executor,
  ExecutorError,
  Globals,
  Operation,
} from "../../operation-runner/mod.ts";
import {
  Completion,
  Configuration,
  type DeploymentPort,
  type Phase,
  Receipt,
} from "./contract.ts";
import { deploy } from "./execution.ts";
import { lifecycleFailure, Phase as LifecyclePhase } from "./lifecycle.ts";

const Kind = z.enum([
  "instance.deploy",
  "instance.rollback",
  "instance.sync_environment",
  "instance.run_steps",
  "instance.prepare_vp_environment",
  "instance.prepare_php_runtime",
  "instance.repair_projection",
  "instance.prepare_served_web_roots",
  "instance.synchronize_environment",
]);
const Assignment = z.object({ operation_id: z.number().int().positive() });
function canonical(value: unknown): unknown {
  if (Array.isArray(value)) return value.map(canonical);
  if (value !== null && typeof value === "object") {
    return Object.fromEntries(
      Object.entries(value).sort(([a], [b]) => a.localeCompare(b)).map((
        [key, item],
      ) => [key, canonical(item)]),
    );
  }
  return value;
}
const ExecutionOperation = Operation;
type ExecutionOperation = z.infer<typeof ExecutionOperation>;

class ChildWaiting extends Error {
  constructor(readonly key: string, readonly childId: number) {
    super("waiting");
  }
}

/** Each invocation remains bound to the family context and Operation target by the Gateway. */
class Port implements DeploymentPort {
  constructor(
    private executor: Executor,
    private id: number,
    private worker: string,
    private current: ExecutionOperation,
    private resultStep = "verification",
  ) {}
  async refresh() {
    this.current = ExecutionOperation.parse(
      await this.executor.post(`${this.id}/heartbeat`, { worker: this.worker }),
    );
    return this.current;
  }
  async cancelled() {
    return (await this.refresh()).cancel_requested;
  }
  activated() {
    return Promise.resolve(this.current.step_results.activation !== undefined);
  }
  async receipt(key: string): Promise<Receipt | null> {
    const data = await this.executor.receipt(this.id, key);
    if (data.effect_key !== `op-${this.id}.${key}`) {
      throw new ExecutorError("operation.receipt_invalid");
    }
    if (data.state === "running") {
      throw new ExecutorError("operation.execution_busy");
    }
    if (data.state === "unknown") return null;
    if (data.result === null) {
      throw new ExecutorError("operation.receipt_invalid");
    }
    return data.result;
  }
  async child(key: string) {
    const child = await this.executor.child(this.id, this.worker, key);
    if (!["succeeded", "failed", "cancelled"].includes(child.status)) {
      await this.executor.wait(this.id, this.worker, [key]);
      throw new ChildWaiting(key, child.id);
    }
  }
  async phase(phase: Phase): Promise<Receipt> {
    let last: Receipt = {};
    // Inputs depending on earlier receipts are refreshed after each invocation. Swamp
    // chooses the phase; the Gateway only exposes exact bindings with proven prerequisites.
    const visited = new Set<string>();
    for (let count = 0; count < 256; count++) {
      const operation = await this.refresh();
      const descriptors = operation.execution_context.descriptors.filter((
        item,
      ) => item.step === phase.name || item.step.startsWith(`${phase.name}.`));
      const next = descriptors.find((item) =>
        !visited.has(item.key) &&
        operation.step_results[item.step] === undefined
      );
      if (!next) return last;
      visited.add(next.key);
      const ended = operation.steps.some((step) =>
        step.name === next.step &&
        (step.status === "succeeded" || step.status === "failed")
      );
      if (!ended) {
        await this.executor.post(`${this.id}/step`, {
          worker: this.worker,
          key: `${next.key}:start`,
          name: next.step,
          status: "running",
          output: `${next.step}\n`,
        });
      }
      let result: Receipt;
      try {
        if (
          next.transport === "gateway" && next.name === "instance.child-receipt"
        ) {
          await this.child(z.string().parse(next.input.child_key));
        }
        const recovered = next.transport === "gateway"
          ? null
          : await this.receipt(next.key);
        result = recovered ?? Receipt.parse(
          await this.executor.post(`${this.id}/run`, {
            worker: this.worker,
            key: next.key,
            transport: next.transport,
            name: next.name,
            input: next.input,
          }),
        );
      } catch (error) {
        if (
          !(error instanceof ExecutorError) ||
          [
            "operation.lease_lost",
            "operation.execution_busy",
            "operation.execution_uncertain",
            "operation.outcome_unknown",
            "operation.execution_failed",
            "operation.receipt_unavailable",
            "operation.receipt_unproven",
          ].includes(error.code)
        ) throw error;
        result = { error_code: error.code };
      }
      let error = typeof result.error_code === "string"
        ? result.error_code
        : null;
      if (
        !error && typeof result.exit_code === "number" && result.exit_code !== 0
      ) {
        error = result.timed_out
          ? "deployment.command_timed_out"
          : "deployment.step_failed";
      }
      if (
        operation.kind === "instance.run_steps" &&
        next.name === "instance.run-lifecycle-step"
      ) {
        const failure = lifecycleFailure(
          LifecyclePhase.parse(operation.input.list),
          result,
          Number(result.budget_seconds ?? next.input.timeout_seconds),
          Number(result.timeout_seconds ?? next.input.timeout_seconds),
        );
        if (failure) {
          error = failure.error_code;
          result = { ...result, ...failure, failed_step: next.step };
        }
      }
      if (
        operation.kind === "instance.run_steps" &&
        error === "command.deadline_exceeded"
      ) {
        result = { ...result, outcome: "deadline", failed_step: next.step };
      }
      await this.executor.post(`${this.id}/step`, {
        worker: this.worker,
        key: `${next.key}:finish`,
        name: next.step,
        status: error ? "failed" : "succeeded",
        result,
        error_code: error,
        output: typeof result.output_tail === "string"
          ? result.output_tail.slice(-8000)
          : error
          ? `${error}\n`
          : "Completed\n",
      });
      last = result;
      if (error && !phase.cleanup) return { ...result, error_code: error };
    }
    throw new Error("Deployment phase exceeded its bound");
  }
  async result(): Promise<Receipt> {
    const operation = await this.refresh();
    // The final verification descriptor returns the Gateway's proven public projection.
    const final = operation.step_results[this.resultStep];
    return final === undefined
      ? operation.execution_context.result ?? {}
      : Receipt.parse(final);
  }
}

export function instanceDeploy(
  makeHttp: (ctx: Context) => Http = (ctx) =>
    gatewayHttp(
      ctx.globalArgs.gatewayUrl,
      ctx.globalArgs.caFile,
      ctx.signal,
      600_000,
    ),
) {
  return {
    type: "@nckrtl/operation-instance-deploy",
    version: "2026.10.28.1",
    globalArguments: Globals,
    resources: {
      assignment: {
        description:
          "Bind each placed dispatch to its first claim before effects",
        schema: Assignment,
        lifetime: "7d",
        garbageCollection: 1,
      },
      waiting: {
        description:
          "The child Operation currently blocking this Instance Operation",
        schema: z.object({
          operation_id: z.number().int(),
          child_id: z.number().int(),
          key: z.string(),
        }),
        lifetime: "infinite",
        garbageCollection: 10,
      },
      receipt: {
        description: "Permanent completion intent for an Instance Operation",
        schema: Completion,
        lifetime: "infinite",
        garbageCollection: 10,
      },
    },
    methods: {
      execute: {
        description:
          "Execute the admitted deployment with keyed named commands",
        arguments: z.object({
          kind: Kind,
          worker: z.string().min(1),
        }),
        execute: async (
          args: { kind: string; worker: string },
          ctx: Context,
        ) => {
          const http = makeHttp(ctx);
          const executor = new Executor(http);
          const attempt = `assignment-${args.worker}`;
          const assigned = await ctx.readResource(attempt);
          let operation: z.infer<typeof ExecutionOperation>;
          if (assigned) {
            const id = Assignment.parse(assigned).operation_id;
            const saved = await ctx.readResource(`operation-${id}`);
            if (saved) {
              const completion = Completion.parse(saved);
              const outcome = z.object({
                id: z.number().int(),
                kind: z.string(),
                status: z.string(),
                result: Receipt.nullable(),
                error_code: z.string().nullable(),
              }).parse(await executor.operation(id));
              if (
                ["succeeded", "failed", "cancelled"].includes(outcome.status)
              ) {
                if (
                  outcome.id !== id || outcome.kind !== args.kind ||
                  JSON.stringify(
                      canonical({
                        status: outcome.status,
                        result: outcome.result ?? {},
                        error_code: outcome.error_code,
                      }),
                    ) !== JSON.stringify(canonical(completion))
                ) {
                  throw new Error("Unproven saved Instance completion");
                }
                return {
                  claimed: true,
                  operationId: id,
                  status: completion.status,
                };
              }
            }
            operation = ExecutionOperation.parse(
              await executor.post(`${id}/heartbeat`, { worker: args.worker }),
            );
            if (operation.id !== id) {
              throw new Error("Wrong Instance assignment");
            }
          } else {
            const claimed = await executor.claim(args.kind, args.worker);
            if (claimed === null) return { claimed: false };
            operation = ExecutionOperation.parse(claimed);
            if (operation.kind !== args.kind) {
              throw new Error("Wrong claimed Instance kind");
            }
            await ctx.writeResource("assignment", attempt, {
              operation_id: operation.id,
            });
          }
          if (operation.kind !== args.kind) {
            throw new Error("Wrong claimed Instance kind");
          }
          const resource = `operation-${operation.id}`;
          const saved = await ctx.readResource(resource);
          if (saved) {
            const completion = Completion.parse(saved);
            await executor.post(`${operation.id}/complete`, {
              worker: args.worker,
              ...completion,
            });
            return {
              claimed: true,
              operationId: operation.id,
              status: completion.status,
            };
          }
          const resultStep =
            operation.kind === "instance.prepare_vp_environment"
              ? "vp_receipt"
              : operation.kind === "instance.prepare_php_runtime"
              ? "php_receipt"
              : operation.kind === "instance.sync_environment"
              ? "environment_receipt"
              : operation.kind === "instance.run_steps"
              ? "lifecycle_receipt"
              : "verification";
          const port = new Port(
            executor,
            operation.id,
            args.worker,
            operation,
            resultStep,
          );
          let heartbeatError: unknown;
          const timer = setInterval(() => {
            port.refresh().catch((error) => {
              heartbeatError = error;
            });
          }, 30_000);
          try {
            let completion: z.infer<typeof Completion>;
            if (
              operation.kind === "instance.sync_environment" ||
              operation.kind === "instance.run_steps" ||
              operation.kind === "instance.prepare_vp_environment" ||
              operation.kind === "instance.prepare_php_runtime" ||
              operation.kind === "instance.repair_projection" ||
              operation.kind === "instance.prepare_served_web_roots" ||
              operation.kind === "instance.synchronize_environment"
            ) {
              completion = {
                status: "succeeded",
                result: {},
                error_code: null,
              };
              const stages =
                operation.kind === "instance.prepare_vp_environment"
                  ? [
                    "vp_plan",
                    "vp_manager",
                    "vp_scope",
                    "vp_version",
                    "vp_inspect",
                    "vp_install",
                    "vp_verify",
                    "vp_prune",
                    "vp_receipt",
                  ]
                  : operation.kind === "instance.synchronize_environment"
                  ? [
                    "environment_sync",
                    "runtime_config",
                    "runtime_php",
                    "runtime_processes",
                    "verification",
                  ]
                  : operation.kind === "instance.prepare_served_web_roots"
                  ? ["web_roots", "verification"]
                  : operation.kind === "instance.repair_projection"
                  ? ["projection", "verification"]
                  : operation.kind === "instance.prepare_php_runtime"
                  ? ["php_plan", "php_packages", "php_converge", "php_receipt"]
                  : operation.kind === "instance.sync_environment"
                  ? [
                    "environment_prepare",
                    "environment_sync",
                    "environment_testing",
                    "environment_urls",
                    "environment_receipt",
                  ]
                  : [
                    "lifecycle_scope",
                    String(operation.input.list),
                    "lifecycle_receipt",
                  ];
              for (const name of stages) {
                if (await port.cancelled()) {
                  completion = {
                    status: "cancelled",
                    result: {
                      outcome: "cancelled",
                      instance_id: operation.input.instance_id,
                    },
                    error_code: null,
                  };
                  break;
                }
                const receipt = await port.phase({
                  name,
                  required: true,
                  cleanup: false,
                });
                if (typeof receipt.error_code === "string") {
                  completion = {
                    status: receipt.error_code === "operation.cancelled"
                      ? "cancelled"
                      : "failed",
                    result: {
                      ...receipt,
                      instance_id: operation.input.instance_id,
                    },
                    error_code: receipt.error_code,
                  };
                  break;
                }
              }
              if (completion.status === "succeeded") {
                completion.result = await port.result();
              }
            } else if (operation.input.deployment == null) {
              completion = {
                status: "failed",
                result: operation.execution_context.result ?? {},
                error_code: "deployment.interrupted",
              };
            } else {
              completion = await deploy(
                Configuration.parse(operation.input.deployment),
                port,
              );
            }
            if (
              completion.status === "succeeded" && resultStep === "verification"
            ) {
              const verification = await port.phase({
                name: "verification",
                required: true,
                cleanup: false,
              });
              if (typeof verification.error_code === "string") {
                completion = {
                  status: "failed",
                  result: {},
                  error_code: verification.error_code,
                };
              } else completion.result = await port.result();
            }
            if (heartbeatError) throw heartbeatError;
            const handle = await ctx.writeResource(
              "receipt",
              resource,
              completion,
            );
            await executor.post(`${operation.id}/complete`, {
              worker: args.worker,
              ...completion,
            });
            return {
              claimed: true,
              operationId: operation.id,
              status: completion.status,
              dataHandles: [handle],
            };
          } catch (error) {
            if (error instanceof ChildWaiting) {
              const handle = await ctx.writeResource(
                "waiting",
                `waiting-${resource}`,
                {
                  operation_id: operation.id,
                  child_id: error.childId,
                  key: error.key,
                },
              );
              return {
                claimed: true,
                operationId: operation.id,
                status: "waiting",
                dataHandles: [handle],
              };
            }
            throw error;
          } finally {
            clearInterval(timer);
          }
        },
      },
    },
  };
}
export const behavior = {
  ...instanceDeploy(),
  type: "@nckrtl/operation-instance-deploy",
};
