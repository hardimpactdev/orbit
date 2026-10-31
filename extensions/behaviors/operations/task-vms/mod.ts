import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import {
  type Context,
  Executor,
  ExecutorError,
  Globals,
  Operation,
} from "../../operation-runner/mod.ts";
import { Completion, invocationFor, leafKinds } from "./contract.ts";
import { ChildIntent, FamilyDriver, FamilyWaiting } from "./driver.ts";
import { prepareRuntime, RuntimeInput } from "./runtime.ts";
import { lifecycle, LifecycleInput } from "./lifecycle.ts";
import { buildUpCloudImage, UpCloudImageInput } from "./upcloud-images.ts";

export function taskVms(
  makeHttp = (ctx: Context) =>
    gatewayHttp(
      ctx.globalArgs.gatewayUrl,
      ctx.globalArgs.caFile,
      ctx.signal,
      600_000,
    ),
) {
  return {
    type: "@nckrtl/operation-task-vms",
    version: "2026.10.28.1",
    globalArguments: Globals,
    resources: {
      assignment: {
        description: "The first Operation claimed by this placed dispatch",
        schema: z.object({ operation_id: z.number().int().positive() }),
        lifetime: "7d",
        garbageCollection: 1,
      },
      checkpoint: {
        description: "Proven ordered family stage",
        schema: z.object({ value: z.unknown() }),
        lifetime: "infinite",
        garbageCollection: 10,
      },
      childIntent: {
        description: "Child input saved before its admission boundary",
        schema: ChildIntent,
        lifetime: "infinite",
        garbageCollection: 10,
      },
      child: {
        description: "Stable child Operation identities",
        schema: z.object({ id: z.number().int().positive() }),
        lifetime: "infinite",
        garbageCollection: 10,
      },
      receipt: {
        description: "Permanent completion intent for a task VM effect",
        schema: Completion,
        lifetime: "infinite",
        garbageCollection: 10,
      },
    },
    methods: {
      execute: {
        description: "Claim and execute a task VM kind on one placed worker",
        arguments: z.object({
          kind: z.enum(
            [
              ...leafKinds,
              "task_vm.prepare_runtime",
              "task_vm.provision",
              "task_vm.enroll",
              "task_vm.resume",
              "task_vm.park",
              "task_vm.destroy",
              "task_vm.upcloud_image_build",
            ],
          ),
          worker: z.string().min(1),
        }),
        execute: async (
          args: { kind: string; worker: string },
          ctx: Context,
        ): Promise<{
          status: string;
          dataHandles?: { name: string }[];
        }> => {
          const http = makeHttp(ctx);
          const executor = new Executor(http);
          const assignment = `assignment-${args.worker}`;
          const assigned = await ctx.readResource(assignment);
          let operation: z.infer<typeof Operation>;
          if (assigned) {
            const id =
              z.object({ operation_id: z.number().int().positive() }).parse(
                assigned,
              ).operation_id;
            const completion = await ctx.readResource(`operation-${id}`);
            if (completion) {
              const saved = Completion.parse(completion);
              const outcome = z.object({
                id: z.number().int(),
                kind: z.string(),
                status: z.string(),
                result: z.record(z.string(), z.unknown()).nullable(),
                error_code: z.string().nullable(),
              }).parse(await executor.operation(id));
              if (
                ["succeeded", "failed", "cancelled"].includes(outcome.status)
              ) {
                const expected: Record<string, unknown> = saved.result;
                const actual = outcome.result ?? {};
                if (
                  outcome.id !== id || outcome.kind !== args.kind ||
                  outcome.status !== saved.status ||
                  outcome.error_code !== saved.error_code ||
                  Object.keys(expected).some((key) =>
                    JSON.stringify(actual[key]) !==
                      JSON.stringify(expected[key])
                  ) ||
                  Object.keys(actual).some((key) =>
                    !(key in expected) &&
                    !(saved.status !== "succeeded" && key === "skipped")
                  )
                ) {
                  throw new Error("Unproven saved task VM completion");
                }
                return { status: saved.status };
              }
            }
            operation = Operation.parse(
              await executor.post(`${id}/heartbeat`, { worker: args.worker }),
            );
            if (operation.id !== id) {
              throw new Error("Wrong task VM assignment");
            }
          } else {
            const claimed = await executor.post("claim", {
              kind: args.kind,
              worker: args.worker,
            });
            if (claimed === null) return { status: "idle" };
            operation = Operation.parse(claimed);
            await ctx.writeResource("assignment", assignment, {
              operation_id: operation.id,
            });
          }
          if (operation.kind !== args.kind) {
            throw new Error("Wrong claimed task VM kind");
          }
          const path = String(operation.id);
          const resource = `operation-${path}`;
          const saved = await ctx.readResource(resource);
          if (saved) {
            const receipt = Completion.parse(saved);
            await executor.post(`${path}/complete`, {
              worker: args.worker,
              ...receipt,
            });
            return { status: receipt.status };
          }
          const runtime = operation.kind === "task_vm.prepare_runtime"
            ? RuntimeInput.parse(operation.input)
            : null;
          const life = [
              "task_vm.provision",
              "task_vm.enroll",
              "task_vm.resume",
              "task_vm.park",
              "task_vm.destroy",
            ].includes(operation.kind)
            ? LifecycleInput.parse(operation.input)
            : null;
          const upcloudImage = operation.kind === "task_vm.upcloud_image_build"
            ? UpCloudImageInput.parse(operation.input)
            : null;
          const invocation = runtime || life || upcloudImage
            ? null
            : invocationFor(operation.kind, operation.input);
          if (runtime && runtime.node_id !== operation.node_id) {
            throw new Error("Wrong runtime Node");
          }
          const step = invocation?.step ?? "runtime";
          let heartbeatError: unknown;
          let heartbeat: Promise<unknown> | undefined;
          const timer = setInterval(() => {
            heartbeat = executor.post(`${path}/heartbeat`, {
              worker: args.worker,
            }).catch((error) => {
              heartbeatError = error;
            });
          }, 30_000);
          const post = async (
            suffix: string,
            body: Record<string, unknown>,
          ) => {
            if (heartbeatError) throw heartbeatError;
            return await executor.post(`${path}/${suffix}`, {
              worker: args.worker,
              ...body,
            });
          };
          const readReceipt = async (): Promise<
            z.infer<typeof Completion>["result"] | null
          > => {
            const response = await http(
              "GET",
              `/api/v1/operation-executor/${path}/receipts/${
                encodeURIComponent(step)
              }`,
            );
            if (response.status < 200 || response.status >= 300) {
              throw new ExecutorError("operation.outcome_unknown");
            }
            const receipt = z.object({
              data: z.object({
                state: z.enum(["succeeded", "failed", "running", "unknown"]),
                result: z.unknown().nullable(),
              }),
            }).parse(response.body).data;
            if (receipt.state === "unknown") return null;
            if (receipt.state === "running") {
              throw new ExecutorError("operation.outcome_unknown");
            }
            return Completion.shape.result.parse(receipt.result);
          };
          try {
            let completion: z.infer<typeof Completion>;
            if (
              runtime || life || upcloudImage
            ) {
              const driver = new FamilyDriver(
                operation.id,
                args.worker,
                (runtime ?? life ?? upcloudImage)!
                  .deadline_at,
                ctx,
                makeHttp(ctx),
                operation.kind === "task_vm.resume"
                  ? "task_vm.resume_timeout"
                  : "task_vm.job_failed",
                () => {
                  if (heartbeatError) throw heartbeatError;
                },
              );
              if (operation.cancel_requested) {
                try {
                  await driver.cancelChildren();
                } catch (error) {
                  if (error instanceof FamilyWaiting) {
                    return { status: "waiting" };
                  }
                  throw error;
                }
                completion = {
                  status: "cancelled",
                  result: {},
                  error_code: null,
                };
              } else {
                try {
                  completion = {
                    status: "succeeded",
                    result: runtime
                      ? await prepareRuntime(runtime, driver)
                      : upcloudImage
                      ? await buildUpCloudImage(upcloudImage, driver)
                      : await lifecycle(operation.kind, life!, driver),
                    error_code: null,
                  };
                } catch (error) {
                  if (error instanceof FamilyWaiting) {
                    return { status: "waiting" };
                  }
                  if (
                    !(error instanceof ExecutorError) ||
                    [
                      "operation.lease_lost",
                      "operation.execution_busy",
                      "operation.outcome_unknown",
                    ].includes(error.code)
                  ) throw error;
                  completion = {
                    status: error.code === "operation.cancelled"
                      ? "cancelled"
                      : "failed",
                    result: {},
                    error_code: error.code,
                  };
                }
              }
              const handle = await ctx.writeResource(
                "receipt",
                resource,
                completion,
              );
              await post("complete", completion);
              return { dataHandles: [handle], status: completion.status };
            }
            if (!invocation) throw new Error("Missing task VM invocation");
            const recorded = operation.step_results[step];
            const existing = operation.steps.find((entry) =>
              entry.name === step
            );
            if (operation.cancel_requested && !recorded && !existing) {
              completion = {
                status: "cancelled",
                result: {},
                error_code: null,
              };
            } else {
              let result: z.infer<typeof Completion>["result"];
              if (recorded) {
                result = Completion.shape.result.parse(recorded);
              } else {
                if (!existing) {
                  await post("step", {
                    key: `${step}:start`,
                    name: step,
                    status: "running",
                    output: `${step}\n`,
                  });
                }
                const recovered = existing ? await readReceipt() : null;
                if (recovered !== null) result = recovered;
                else {
                  try {
                    result = Completion.shape.result.parse(
                      await post("run", {
                        key: step,
                        transport: invocation.transport,
                        name: invocation.name,
                        input: invocation.input,
                      }),
                    );
                  } catch (error) {
                    if (
                      error instanceof ExecutorError &&
                      [
                        "operation.execution_forbidden",
                        "operation.completion_unproven",
                        "operation.cancelled",
                        "task_vm.invalid_host_output",
                        "task_vm.image_mismatch",
                        "task_vm.vm_missing",
                        "task_vm.bootstrap_failed",
                        "task_vm.model_key_failed",
                        "task_vm.image_recipe_superseded",
                        "task_vm.image_build_superseded",
                        "task_vm.image_channel_lost",
                        "task_vm.upcloud_budget_full",
                        "task_vm.base_image_missing",
                        "task_vm.not_settled",
                        "task_vm.workspace_not_preserved",
                      ].includes(error.code)
                    ) result = { error_code: error.code };
                    else {
                      try {
                        const receipt = await readReceipt();
                        if (receipt !== null) result = receipt;
                        else throw error;
                      } catch {
                        throw error;
                      }
                    }
                  }
                }
                if (
                  !result.error_code &&
                  (invocation.proof === "ready"
                    ? result.ready !== true
                    : invocation.proof === "changed"
                    ? typeof result.changed !== "boolean"
                    : invocation.proof === "stopped"
                    ? result.stopped !== true
                    : false)
                ) {
                  result = { ...result, error_code: "task_vm.effect_unproven" };
                }
                if (existing?.status !== "failed") {
                  await post("step", {
                    key: `${step}:finish`,
                    name: step,
                    status: result.error_code ? "failed" : "succeeded",
                    result,
                    error_code: result.error_code ?? null,
                    output: `${result.error_code ?? "confirmed"}\n`,
                  });
                }
              }
              const error = result.error_code ?? null;
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
            if (heartbeatError) throw heartbeatError;
            const handle = await ctx.writeResource(
              "receipt",
              resource,
              completion,
            );
            await post("complete", completion);
            return { dataHandles: [handle], status: completion.status };
          } finally {
            clearInterval(timer);
            await heartbeat;
          }
        },
      },
    },
  };
}
export const behavior = taskVms();
