import registration from "./registration.json" with { type: "json" };
import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import { type Context, Executor, Globals } from "../../operation-runner/mod.ts";
import {
  type Call,
  Completion,
  Input,
  pick,
  scheduleCall,
} from "./contract.ts";

const Result = z.preprocess(
  (value) => Array.isArray(value) && value.length === 0 ? {} : value,
  z.record(z.string(), z.unknown()),
);
const EffectReceipt = z.object({
  effect_key: z.string(),
  state: z.enum(["succeeded", "failed", "running", "unknown"]),
  exit_code: z.number().int().nullable(),
  result: Result.nullable(),
  finished_at: z.string().nullable(),
});
class PendingReceipt extends Error {}
const Code = z.string().regex(/^[a-z0-9_.-]+$/);
class Refused extends Error {
  constructor(readonly code: string, readonly result: Record<string, unknown>) {
    super(code);
  }
}

export function processesSchedules(
  makeHttp = (ctx: Context) =>
    gatewayHttp(
      ctx.globalArgs.gatewayUrl,
      ctx.globalArgs.caFile,
      ctx.signal,
      600_000,
    ),
) {
  return {
    type: "@nckrtl/operation-processes-schedules",
    version: "2026.10.28.1",
    globalArguments: Globals,
    resources: {
      assignment: {
        description: "Bind this worker dispatch to one claimed Operation",
        schema: z.object({ operation_id: z.number().int().positive() }),
        lifetime: "7d",
        garbageCollection: 1,
      },
      receipt: {
        description:
          "Permanent completion intent for one Process or Schedule Operation",
        schema: Completion,
        lifetime: "infinite",
        garbageCollection: 10,
      },
    },
    methods: {
      execute: {
        description:
          "Execute admitted Process or Schedule intent through keyed Gateway commands",
        arguments: z.object({
          kind: z.string().refine((kind) => registration.kinds.includes(kind)),
          worker: z.string(),
        }),
        execute: async (
          args: { kind: string; worker: string },
          ctx: Context,
        ) => {
          if (!registration.kinds.includes(args.kind)) {
            throw new Error("Unregistered family kind");
          }
          const http = makeHttp(ctx);
          const executor = new Executor(http);
          const assignment = `assignment-${args.worker}`;
          const assigned = await ctx.readResource(assignment);
          const id = assigned
            ? z.object({ operation_id: z.number().int().positive() }).parse(
              assigned,
            ).operation_id
            : undefined;
          const saved = id === undefined
            ? null
            : await ctx.readResource(`operation-${id}`);
          if (saved) {
            const completion = Completion.parse(saved);
            await executor.post(`${id}/complete`, {
              worker: args.worker,
              ...completion,
            });
            return { status: completion.status };
          }
          const operation = await executor.claim(args.kind, args.worker, id);
          if (!operation) return { claimed: false };
          if (
            operation.kind !== args.kind ||
            (id !== undefined && operation.id !== id)
          ) throw new Error("Wrong runtime assignment");
          if (id === undefined) {
            await ctx.writeResource("assignment", assignment, {
              operation_id: operation.id,
            });
          }
          const path = `${operation.id}`;
          const resource = `operation-${path}`;
          const input = operation.kind.endsWith("_instance") ||
              operation.kind.endsWith("_instance_definitions")
            ? operation.input
            : Input.parse(operation.input);
          if (
            input.node_id !== undefined && input.node_id !== operation.node_id
          ) throw new Error("Wrong Process or Schedule Node");
          const family = operation.kind.split(".")[0];
          if (!["process", "schedule"].includes(family)) {
            throw new Error(
              "Wrong Process or Schedule kind",
            );
          }
          let heartbeatError: unknown;
          const timer = setInterval(() => {
            executor.post(`${path}/heartbeat`, { worker: args.worker }).catch(
              (error) => {
                heartbeatError = error;
              },
            );
          }, 30_000);
          try {
            const invoke = async (call: Call) => {
              if (heartbeatError) throw heartbeatError;
              const recorded = operation.step_results[call.step];
              if (recorded !== undefined) return Result.parse(recorded);
              const failed = operation.steps.find((step) =>
                step.name === call.step && step.status === "failed"
              );
              // The keyed run receipt also recovers a failed step whose completion
              // intent was not saved yet. A finished step must never be reopened.
              if (!failed) {
                await executor.post(`${path}/step`, {
                  worker: args.worker,
                  key: `${call.step}:start`,
                  name: call.step,
                  status: "running",
                  output: `${call.step}\n`,
                });
              }
              const effectful = call.transport !== "gateway" && ![
                "process.check-release",
                "process.status",
                "vite.check-listener",
              ].includes(call.name);
              let result: Record<string, unknown> | null = null;
              if (effectful) {
                const effectKey = `op-${operation.id}.${call.step}`;
                let receipt;
                try {
                  receipt = EffectReceipt.parse(
                    await executor.receipt(operation.id, call.step),
                  );
                } catch {
                  throw new PendingReceipt();
                }
                if (receipt.effect_key !== effectKey) {
                  throw new Error("Wrong effect receipt key");
                }
                if (["succeeded", "failed"].includes(receipt.state)) {
                  if (
                    receipt.result === null || receipt.exit_code === null ||
                    receipt.finished_at === null
                  ) throw new Error("Incomplete effect receipt");
                  result = receipt.result;
                  if (
                    receipt.state === "failed" &&
                    result.error_code === undefined
                  ) throw new Error("Unclassified failed effect receipt");
                } else {
                  if (receipt.state === "running") throw new PendingReceipt();
                }
              }
              result ??= Result.parse(
                await executor.post(`${path}/run`, {
                  worker: args.worker,
                  key: call.step,
                  transport: call.transport ?? "agent",
                  name: call.name,
                  input: call.input,
                }),
              );
              if (result.not_started === true) throw new PendingReceipt();
              if (result.error_code === "operation.execution_uncertain") {
                throw new PendingReceipt();
              }
              if (heartbeatError) throw heartbeatError;
              const code = result.error_code === undefined
                ? null
                : Code.safeParse(result.error_code);
              if (code && !code.success) {
                throw new Error("Invalid command error code");
              }
              const error = code
                ? refusalCode(code.data!, input.runtime, call.name)
                : null;
              if (!failed) {
                await executor.post(`${path}/step`, {
                  worker: args.worker,
                  key: `${call.step}:finish`,
                  name: call.step,
                  status: error ? "failed" : "succeeded",
                  result,
                  error_code: error,
                  output: error ? `${error}\n` : "Command succeeded\n",
                });
              }
              if (error) throw new Refused(error, result);
              return result;
            };
            let completion: z.infer<typeof Completion>;
            try {
              if (
                operation.cancel_requested && operation.steps.length === 0 &&
                Object.keys(operation.step_results).length === 0
              ) {
                completion = {
                  status: "cancelled",
                  result: {},
                  error_code: null,
                };
              } else {
                const waitFor = async (key: string) => {
                  const child = await executor.child(
                    operation.id,
                    args.worker,
                    key,
                  );
                  if (
                    child.status === "failed" || child.status === "cancelled"
                  ) {
                    throw new Refused(
                      child.status === "cancelled"
                        ? "operation.cancelled"
                        : "process.runtime_child_failed",
                      {},
                    );
                  }
                  if (child.status !== "succeeded") {
                    await executor.wait(operation.id, args.worker, [key]);
                    throw new PendingReceipt();
                  }
                };
                const composition = [
                  "process.install_instance_definitions",
                  "schedule.install_instance_definitions",
                  "process.restart_instance",
                ].includes(operation.kind);
                let result: Record<string, unknown>;
                if (composition) {
                  z.object({ instance_id: z.number().int().positive() })
                    .strict().parse(input);
                  const count = z.number().int().nonnegative().max(4096).parse(
                    operation.execution_context.result?.runtime_child_count,
                  );
                  for (let index = 0; index < count; index++) {
                    await waitFor(`runtime-${index}`);
                  }
                  const descriptor = operation.execution_context.descriptors
                    .find((entry) => entry.key === "runtime-ready");
                  if (!descriptor || descriptor.transport !== "gateway") {
                    throw new Error("Missing runtime readiness descriptor");
                  }
                  result = await invoke({
                    step: descriptor.key,
                    transport: "gateway",
                    name: descriptor.name,
                    input: descriptor.input,
                  });
                } else {
                  result = family === "schedule"
                    ? await invoke(scheduleCall("schedule.create", input))
                    : await executeProcess(
                      operation.kind,
                      input,
                      invoke,
                      () => waitFor("vite-caddy"),
                    );
                }
                if (family === "process") {
                  if (
                    input.vite_plan &&
                    input.desired === "running"
                  ) {
                    const plan = z.record(z.string(), Result).parse(
                      input.vite_plan,
                    );
                    let ready = false;
                    for (let check = 0; check < 60; check++) {
                      const observed = await invoke({
                        step: `vite-ready-${check}`,
                        name: "vite.check-listener",
                        input: plan.listener,
                      });
                      if (observed.ready === true && observed.owned === true) {
                        ready = true;
                        break;
                      }
                      await new Promise((resolve) => setTimeout(resolve, 250));
                    }
                    if (!ready) {
                      await invoke({
                        step: "vite-stop-unready",
                        name: "process.control",
                        input: {
                          ...pick(input, [
                            "process_id",
                            "runtime",
                            "name",
                            "on_demand",
                          ]),
                          operation: "stop",
                        },
                      });
                      throw new Refused("vite.not_ready", {});
                    }
                  }
                }
                if (family === "process" && !composition) {
                  await invoke({
                    step: "process-status",
                    name: "process.status",
                    input: pick(input, ["process_id", "runtime", "name"]),
                  });
                  result = await invoke({
                    step: "runtime-ready",
                    transport: "gateway",
                    name: "process.runtime_ready",
                    input: pick(input, ["process_id"]),
                  });
                }
                completion = { status: "succeeded", result, error_code: null };
              }
            } catch (error) {
              if (error instanceof PendingReceipt) return { status: "pending" };
              if (!(error instanceof Refused)) throw error;
              completion = {
                status: error.code === "operation.cancelled"
                  ? "cancelled"
                  : "failed",
                result: error.result,
                error_code: error.code,
              };
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

async function executeProcess(
  kind: string,
  input: Record<string, unknown>,
  invoke: (call: Call) => Promise<Record<string, unknown>>,
  project: () => Promise<void>,
) {
  const verb = kind.slice("process.".length);
  if (
    verb !== "update"
  ) throw new Error("Wrong Process kind");
  if (input.directory) {
    const release = await invoke({
      step: "process-check-release",
      name: "process.check-release",
      input: pick(input, ["directory"]),
    });
    if (release.available !== true) {
      throw new Refused("process.release_unavailable", {});
    }
  }
  if (input.vite_plan) {
    const plan = z.record(z.string(), Result).parse(input.vite_plan);
    await invoke({
      step: "vite-suspend",
      name: "vite.set-traffic",
      input: plan.suspend,
    });
    await invoke({
      step: "vite-environment",
      name: "vite.set-traffic",
      input: plan.environment,
    });
    await project();
  }
  if (input.retired_runtime) {
    await invoke({
      step: "process-remove-retired",
      name: "process.remove",
      input: {
        process_id: input.process_id,
        runtime: input.retired_runtime,
        name: input.retired_name,
        vite: null,
      },
    });
  }
  if (input.runtime === "docker") {
    return await invoke({
      step: "process-converge-container",
      name: "process.converge-container",
      input: pick(input, [
        "process_id",
        "container",
        "desired",
        "spec",
        "image",
        "command",
        "restart",
        "working_directory",
        "ports",
        "mounts",
        "environment",
      ]),
    });
  }
  const staged = await invoke({
    step: "process-stage-unit",
    name: "process.stage-unit",
    input: pick(input, ["process_id", "unit", "content"]),
  });
  const settled = input.desired === "running"
    ? staged.state === "active"
    : staged.state === "inactive";
  if (staged.staged === false && !input.activation_pending && settled) {
    return staged;
  }
  const change = staged.staged
    ? staged.previous ? "replace" : "create"
    : "reload";
  const activated = await invoke({
    step: "process-activate-unit",
    name: "process.activate-unit",
    input: {
      ...pick(input, ["process_id", "unit", "desired", "on_demand"]),
      change,
    },
  });
  if (activated.backup_left === true) {
    throw new Refused("process.systemd_converge_failed", activated);
  }
  return staged;
}

export const behavior = {
  ...processesSchedules(),
  type: "@nckrtl/operation-processes-schedules",
};

function refusalCode(code: string, runtime: unknown, command: string): string {
  if (code === "operation.cancelled") return code;
  if (command.startsWith("schedule.")) {
    if (
      [
        "schedule.target_unavailable",
        "schedule.artifact_conflict",
        "schedule.calendar_invalid",
        "schedule.rollback_failed",
        "schedule.run_active",
      ].includes(code)
    ) return code;
    return {
      "schedule.install": "schedule.install_failed",
    }[command] ?? code;
  }
  if (command.startsWith("process.")) {
    if (code === "process.name_collision") {
      return "process.runtime_name_collision";
    }
    if (code === "process.not_found") return "process.runtime_not_found";
    if (code === "process.vite_cleanup_failed") {
      return "vite.environment_cleanup_failed";
    }
    if (
      [
        "process.converge_failed",
        "process.candidate_invalid",
        "process.activation_failed",
        "process.rollback_failed",
        "process.recovery_required",
      ].includes(code)
    ) {
      return `process.${runtime === "docker" ? "docker" : "systemd"}_${
        code.slice("process.".length)
      }`;
    }
  }
  return code;
}
