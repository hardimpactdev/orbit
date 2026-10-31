import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import {
  type Context,
  Executor,
  ExecutorError,
  Globals,
  Operation,
} from "../../operation-runner/mod.ts";

import { converge } from "./converge.ts";
import { bootstrap } from "./bootstrap.ts";
import { lifecycle } from "./lifecycle.ts";
import { network } from "./network.ts";
import { role } from "./role.ts";
import { projectWireGuard } from "./wireguard.ts";
const LifecycleKinds = [
  "node.add",
  "node.remove",
  "node.removal_begin",
  "node.removal_restore",
  "node.network_projection",
  "node.bootstrap_host",
  "node.bootstrap_verify",
  "node.macos_inspect",
  "node.enrollment_begin",
  "node.enrollment_activate",
  "node.enrollment_commit",
  "node.enrollment_restore",
  "node.enrollment_settings",
];
const RoleKinds = [
  "node.role_add",
  "node.role_converge",
  "node.role_remove",
  "node.role_relocate",
  "node.role_relocate_source",
  "node.role_relocate_target",
];
const AdditionalKinds = [
  ...LifecycleKinds,
  ...RoleKinds,
  "node.network_converge",
  "node.network_rollback",
  "node.wireguard_hub_converge",
] as const;

import { NodeExecution, PendingEffect } from "./execution.ts";

const Result = z.preprocess(
  (value) => Array.isArray(value) && value.length === 0 ? {} : value,
  z.record(z.string(), z.unknown()),
);
const ErrorCode = z.string().regex(/^[a-z0-9_.-]+$/);
const Completion = z.object({
  status: z.enum(["succeeded", "failed", "cancelled"]),
  result: Result,
  error_code: ErrorCode.nullable(),
});
const Input = z.object({
  versions: z.array(z.string().regex(/^[0-9]+\.[0-9]+$/)).min(1).max(8),
  profile: z.literal("app-prod"),
  _php_packages_revision: z.string().regex(/^[a-f0-9]{64}$/),
  _php_packages_deadline: z.number().int().positive(),
  _php_packages: z.object({
    versions: Result,
    mirror: z.string(),
    pcov: z.boolean(),
    opentelemetry: z.boolean(),
    extras: z.literal("keep"),
    fpm: z.literal("none"),
    repack: Result,
    release: Result,
  }).strict(),
}).strict();

function completion(
  result: Record<string, unknown>,
): z.infer<typeof Completion> {
  const refusal = result.error_code === undefined
    ? null
    : ErrorCode.parse(result.error_code);
  const source = [
    "php.build_platform_unsupported",
    "php.build_conflicting_source",
    "php.build_download_failed",
  ];
  let error = refusal?.startsWith("php.build_")
    ? source.includes(refusal)
      ? "app-prod.php_package_source_unavailable"
      : "app-prod.php_install_failed"
    : refusal;
  if (!error && !["current", "applied"].includes(String(result.outcome))) {
    error = "app-prod.php_install_failed";
  }
  return {
    status: error === "operation.cancelled"
      ? "cancelled"
      : error
      ? "failed"
      : "succeeded",
    result,
    error_code: error,
  };
}

/** Claims and executes on one placed worker; retries keep the original assignment. */
export function node(
  makeHttp = (ctx: Context) =>
    gatewayHttp(
      ctx.globalArgs.gatewayUrl,
      ctx.globalArgs.caFile,
      ctx.signal,
      600_000,
    ),
) {
  return {
    type: "@nckrtl/operation-node",
    version: "2026.10.31.1",
    globalArguments: Globals,
    resources: {
      assignment: {
        description: "First claimed Operation",
        schema: z.object({ operation_id: z.number().int().positive() }),
        lifetime: "7d",
        garbageCollection: 1,
      },
      intent: {
        description: "Reference-only immutable effect input",
        schema: z.union([
          Input.shape._php_packages,
          z.object({ transport: z.string(), name: z.string(), input: Result }),
        ]),
        lifetime: "infinite",
        garbageCollection: 10,
      },
      effect: {
        description: "Safe keyed effect receipt",
        schema: z.object({ result: Result }),
        lifetime: "infinite",
        garbageCollection: 10,
      },
      child: {
        description: "Stamped child identity",
        schema: z.object({ operation_id: z.number().int().positive() }),
        lifetime: "infinite",
        garbageCollection: 10,
      },
      receipt: {
        description: "Proven completion intent",
        schema: Completion,
        lifetime: "infinite",
        garbageCollection: 10,
      },
    },
    methods: {
      execute: {
        description:
          "Execute admitted Node footprint or production PHP packages",
        arguments: z.object({
          kind: z.enum([
            "node.prepare_php_packages",
            "node.converge",
            "node.bootstrap_probe",
            "node.bootstrap_install",
            ...AdditionalKinds,
          ]),
          worker: z.string().min(1),
        }),
        execute: async (
          args: { kind: string; worker: string },
          ctx: Context,
        ) => {
          const executor = new Executor(makeHttp(ctx));
          const assigned = await ctx.readResource(`assignment-${args.worker}`);
          const id = assigned
            ? z.number().int().positive().parse(assigned.operation_id)
            : undefined;
          const saved = id === undefined
            ? null
            : await ctx.readResource(`operation-${id}`);
          if (saved) {
            const prior = Completion.parse(saved);
            const observed = await executor.operation(id!);
            const terminal = z.object({
              id: z.number(),
              kind: z.string(),
              status: z.string(),
              result: Result.nullable(),
              error_code: ErrorCode.nullable(),
            }).parse(observed);
            if (
              ["succeeded", "failed", "cancelled"].includes(terminal.status)
            ) {
              const sorted = (value: Record<string, unknown>) =>
                JSON.stringify(
                  Object.entries(value).sort(([a], [b]) => a.localeCompare(b)),
                );
              if (
                terminal.id !== id || terminal.kind !== args.kind ||
                terminal.status !== prior.status ||
                terminal.error_code !== prior.error_code ||
                sorted(terminal.result ?? {}) !== sorted(prior.result)
              ) throw new ExecutorError("operation.completion_unproven");
              return { claimed: true, operationId: id, status: prior.status };
            }
          }
          const operation = id === undefined
            ? await executor.claim(args.kind, args.worker)
            : Operation.parse(
              await executor.post(`${id}/heartbeat`, { worker: args.worker }),
            );
          if (!operation) return { claimed: false };
          if (
            operation.kind !== args.kind ||
            ![
              "node.prepare_php_packages",
              "node.converge",
              "node.bootstrap_probe",
              "node.bootstrap_install",
              ...AdditionalKinds,
            ]
              .includes(
                operation.kind,
              ) ||
            (id !== undefined && operation.id !== id)
          ) throw new ExecutorError("operation.execution_forbidden");
          if (id === undefined) {
            await ctx.writeResource("assignment", `assignment-${args.worker}`, {
              operation_id: operation.id,
            });
          }
          const path = String(operation.id);
          const post = (suffix: string, body: Record<string, unknown>) =>
            executor.post(`${path}/${suffix}`, {
              worker: args.worker,
              ...body,
            });
          if (saved) {
            const prior = Completion.parse(saved);
            await post("complete", prior);
            return {
              claimed: true,
              operationId: operation.id,
              status: prior.status,
            };
          }
          if (operation.node_id === null) {
            throw new ExecutorError("operation.target_missing");
          }
          if (
            [
              "node.converge",
              "node.bootstrap_probe",
              "node.bootstrap_install",
              ...AdditionalKinds,
            ]
              .includes(operation.kind)
          ) {
            const execution = new NodeExecution(
              operation,
              executor,
              args.worker,
              ctx,
            );
            try {
              const outcome = LifecycleKinds.includes(operation.kind)
                ? await lifecycle(execution)
                : RoleKinds.includes(operation.kind)
                ? await role(execution)
                : operation.kind === "node.network_converge"
                ? await network(execution)
                : operation.kind === "node.wireguard_hub_converge"
                ? await projectWireGuard(execution)
                : operation.kind === "node.converge"
                ? await converge(execution, ctx)
                : await bootstrap(execution);
              if ("waiting" in outcome) {
                return {
                  claimed: true,
                  operationId: operation.id,
                  status: "waiting",
                };
              }
              const handle = await ctx.writeResource(
                "receipt",
                `operation-${path}`,
                outcome,
              );
              await execution.post("complete", outcome);
              return {
                claimed: true,
                operationId: operation.id,
                status: outcome.status,
                dataHandles: [handle],
              };
            } catch (error) {
              if (error instanceof PendingEffect) {
                return {
                  claimed: true,
                  operationId: operation.id,
                  status: "waiting",
                };
              }
              throw error;
            } finally {
              execution.close();
            }
          }
          const input = Input.parse(operation.input)._php_packages;
          let heartbeatError: unknown;
          let renewing = false;
          const timer = setInterval(() => {
            if (renewing || heartbeatError) return;
            renewing = true;
            post("heartbeat", {}).catch((error) => {
              heartbeatError = error;
            }).finally(() => {
              renewing = false;
            });
          }, 30_000);
          const fence = () => {
            if (heartbeatError) throw heartbeatError;
            ctx.signal?.throwIfAborted();
          };
          try {
            const step = "php-packages";
            const previous = await ctx.readResource(`intent-${path}`);
            let outcome: z.infer<typeof Completion>;
            if (
              operation.cancel_requested && !previous &&
              !operation.steps.length &&
              !Object.hasOwn(operation.step_results, step)
            ) {
              outcome = { status: "cancelled", result: {}, error_code: null };
            } else {
              if (
                previous && JSON.stringify(previous) !== JSON.stringify(input)
              ) throw new ExecutorError("operation.idempotency_conflict");
              fence();
              let result = operation.step_results[step] === undefined
                ? null
                : Result.parse(operation.step_results[step]);
              if (!result) {
                if (!previous) {
                  await ctx.writeResource("intent", `intent-${path}`, input);
                }
                fence();
                await post("step", {
                  key: `${step}:start`,
                  name: step,
                  status: "running",
                  output: "Prepare production PHP packages\n",
                });
                if (previous) {
                  const receipt = await executor.receipt(operation.id, step);
                  if (receipt.state === "running") {
                    return {
                      claimed: true,
                      operationId: operation.id,
                      status: "waiting",
                    };
                  }
                  if (
                    receipt.state === "succeeded" || receipt.state === "failed"
                  ) {
                    if (receipt.result === null) {
                      throw new ExecutorError("operation.outcome_unknown");
                    }
                    result = receipt.result;
                    if (receipt.state === "failed" && !result.error_code) {
                      throw new ExecutorError("operation.outcome_unknown");
                    }
                  }
                }
                fence();
                try {
                  result ??= Result.parse(
                    await post("run", {
                      key: step,
                      transport: "agent",
                      name: "php.install-build",
                      input,
                    }),
                  );
                } catch (error) {
                  if (
                    !(error instanceof ExecutorError) ||
                    error.code !== "app-prod.php_package_source_unavailable"
                  ) throw error;
                  result = { error_code: error.code };
                }
                outcome = completion(result);
                fence();
                await post("step", {
                  key: `${step}:finish`,
                  name: step,
                  status: outcome.status === "succeeded"
                    ? "succeeded"
                    : "failed",
                  result,
                  error_code: outcome.error_code,
                  output: "PHP package preparation ended\n",
                });
              } else outcome = completion(result);
            }
            fence();
            const handle = await ctx.writeResource(
              "receipt",
              `operation-${path}`,
              outcome,
            );
            await post("complete", outcome);
            return {
              claimed: true,
              operationId: operation.id,
              status: outcome.status,
              dataHandles: [handle],
            };
          } finally {
            clearInterval(timer);
          }
        },
      },
    },
  };
}
export const behavior = node();
