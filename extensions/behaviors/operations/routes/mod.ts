import registration from "./registration.json" with { type: "json" };
import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import {
  type Context,
  Executor,
  Globals,
  Operation,
} from "../../operation-runner/mod.ts";
import {
  Completion,
  EffectReceipt,
  ExecutionContext,
  Invocation,
} from "./contract.ts";
import { RouteRefusal } from "./contract.ts";
import { analyticsKinds, executeAnalytics } from "./analytics.ts";
import { executeRoot, rootKinds } from "./roots.ts";

const Publication = Operation.extend({ execution_context: ExecutionContext });
const nodeEffectKinds = [
  "route.router_lan_retire",
  "route.workload_firewall",
  "route.lan_converge",
  "route.lan_remove",
  "route.public_firewall",
  "route.shared_php_withdraw",
  "route.transfer_php_withdraw",
  "route.transfer_lan_remove",
];
const specs: Record<string, { step: string; transport: string; name: string }> =
  {
    ...Object.fromEntries(
      nodeEffectKinds.map(
        (kind) => [kind, {
          step: "node-effect-proof",
          transport: "gateway",
          name: "route.node-effect",
        }],
      ),
    ),
    "route.workload_prepare": {
      step: "workload-proof",
      transport: "gateway",
      name: "route.workload",
    },
    "route.offline_residue_clean": {
      step: "offline-residue-proof",
      transport: "gateway",
      name: "route.offline-residue",
    },
    "route.withdraw_instance": {
      step: "instance-runtime-proof",
      transport: "gateway",
      name: "route.instance-runtime",
    },
    "route.cleanup_instance": {
      step: "instance-runtime-proof",
      transport: "gateway",
      name: "route.instance-runtime",
    },
  };

export function routes(
  makeHttp = (ctx: Context) =>
    gatewayHttp(
      ctx.globalArgs.gatewayUrl,
      ctx.globalArgs.caFile,
      ctx.signal,
      600_000,
    ),
) {
  return {
    type: "@nckrtl/operation-routes",
    version: "2026.10.28.1",
    globalArguments: Globals,
    resources: {
      assignment: {
        description: "Bind a dispatch retry to its original claimed Operation",
        schema: z.object({ operation_id: z.number().int().positive() }),
        lifetime: "7d",
        garbageCollection: 1,
      },
      waiting: {
        description: "Own child keys whose terminal proof resumes this Route",
        schema: z.object({ keys: z.array(z.string()) }),
        lifetime: "infinite",
        garbageCollection: 10,
      },
      invocation: {
        description: "Invocation identity saved before a Node effect",
        schema: Invocation,
        lifetime: "infinite",
        garbageCollection: 10,
      },
      receipt: {
        description: "Permanent Route completion intent",
        schema: Completion,
        lifetime: "infinite",
        garbageCollection: 10,
      },
    },
    methods: {
      execute: {
        description:
          "Publish the Gateway-rendered configuration on the Operation Node",
        arguments: z.object({
          kind: z.string().refine((kind) => registration.kinds.includes(kind)),
          worker: z.string(),
        }),
        execute: async (
          dispatch: { kind: string; worker: string },
          ctx: Context,
        ) => {
          if (!registration.kinds.includes(dispatch.kind)) {
            throw new Error("Unregistered family kind");
          }
          const http = makeHttp(ctx);
          const executor = new Executor(http);
          const attempt = `assignment-${dispatch.worker}`;
          const assigned = await ctx.readResource(attempt);
          let id: number;
          if (assigned) {
            id = z.object({ operation_id: z.number().int().positive() })
              .parse(assigned).operation_id;
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
                const ordered = (value: unknown): unknown =>
                  Array.isArray(value)
                    ? value.map(ordered)
                    : value !== null && typeof value === "object"
                    ? Object.fromEntries(
                      Object.entries(value).sort(([a], [b]) =>
                        a.localeCompare(b)
                      )
                        .map(([key, item]) => [key, ordered(item)]),
                    )
                    : value;
                if (
                  outcome.id !== id || outcome.kind !== dispatch.kind ||
                  outcome.status !== completion.status ||
                  outcome.error_code !== completion.error_code ||
                  JSON.stringify(ordered(outcome.result ?? {})) !==
                    JSON.stringify(ordered(completion.result))
                ) {
                  throw new Error("Unproven saved routes completion");
                }
                return { status: completion.status };
              }
            }
          } else {
            const claimed = await executor.claim(
              dispatch.kind,
              dispatch.worker,
            );
            if (!claimed) return { status: "idle" };
            if (claimed.kind !== dispatch.kind) {
              throw new Error("Wrong claimed routes kind");
            }
            id = claimed.id;
            await ctx.writeResource("assignment", attempt, {
              operation_id: id,
            });
          }
          const args = { operationId: id, worker: dispatch.worker };
          const path = String(id), resource = `operation-${path}`;
          const saved = await ctx.readResource(resource);
          const heartbeat = async () =>
            Publication.parse(
              await executor.post(`${path}/heartbeat`, { worker: args.worker }),
            );
          let operation = await heartbeat();
          if (operation.id !== id || operation.kind !== dispatch.kind) {
            throw new Error("Wrong routes assignment");
          }
          if (saved) {
            const completion = Completion.parse(saved);
            await executor.post(`${path}/complete`, {
              worker: args.worker,
              ...completion,
            });
            return { status: completion.status };
          }
          if (analyticsKinds.includes(operation.kind)) {
            return executeAnalytics(args, ctx, http, operation);
          }
          if (rootKinds.includes(operation.kind)) {
            return executeRoot(args, ctx, http, operation);
          }
          const spec = specs[operation.kind];
          if (!spec) throw new Error("Unknown Route publication kind");
          if (operation.kind === "route.offline_residue_clean") {
            z.object({
              removed_node_id: z.number().int().positive().nullable(),
            }).strict().parse(operation.input);
          } else if (nodeEffectKinds.includes(operation.kind)) {
            z.object({
              route_id: z.number().int().positive().optional(),
              instance_id: z.number().int().positive().optional(),
              router_node_id: z.number().int().positive().optional(),
              transfer_id: z.string().uuid().optional(),
            }).strict().parse(operation.input);
          } else if (operation.kind === "route.workload_prepare") {
            z.object({
              route_id: z.number().int().positive(),
              instance_id: z.number().int().positive(),
            }).strict().parse(operation.input);
          } else {
            z.object({ instance_id: z.number().int().positive() }).strict()
              .parse(operation.input);
          }
          let heartbeatError: unknown;
          const timer = setInterval(() => {
            heartbeat().catch((error) => {
              heartbeatError = error;
            });
          }, 30_000);
          try {
            let completion: z.infer<typeof Completion>;
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
              try {
                const completed = new Set<string>();
                let result: Record<string, unknown> | undefined;
                while (!result) {
                  const descriptor = operation.execution_context.descriptors
                    .find((d) => d.required && !completed.has(d.key));
                  if (!descriptor) {
                    throw new Error(
                      "Route publication has no proven final step",
                    );
                  }
                  const { key, step, transport, name, input } = descriptor;
                  let receipt = operation.step_results[step];
                  if (!receipt) {
                    await executor.post(`${path}/step`, {
                      worker: args.worker,
                      key: `${key}:start`,
                      name: step,
                      status: "running",
                      output: `${step}\n`,
                    });
                    const invocation = { key, transport, name, input };
                    const invocationResource = `${resource}-effect-${key}`;
                    const attempted = transport === "gateway"
                      ? null
                      : await ctx.readResource(invocationResource);
                    if (attempted) {
                      if (
                        JSON.stringify(Invocation.parse(attempted)) !==
                          JSON.stringify(invocation)
                      ) {
                        throw new Error(
                          "Route effect input changed during recovery",
                        );
                      }
                      const response = await http(
                        "GET",
                        `/api/v1/operation-executor/${path}/receipts/${
                          encodeURIComponent(key)
                        }`,
                      );
                      if (response.status !== 200) {
                        throw new Error("Route effect receipt unavailable");
                      }
                      const effect = EffectReceipt.parse(
                        z.object({ data: z.unknown() }).parse(response.body)
                          .data,
                      );
                      if (effect.effect_key !== `op-${path}.${key}`) {
                        throw new Error(
                          "Route effect receipt has another identity",
                        );
                      }
                      if (effect.state === "running") {
                        throw new Error("Route effect is still running");
                      }
                      if (
                        effect.state === "succeeded" ||
                        effect.state === "failed"
                      ) {
                        if (!effect.result) {
                          throw new Error("Route effect receipt has no result");
                        }
                        receipt = effect.state === "failed" &&
                            typeof effect.result.error_code !== "string"
                          ? {
                            ...effect.result,
                            error_code: "operation.effect_failed",
                          }
                          : effect.result;
                      }
                    } else if (transport !== "gateway") {
                      await ctx.writeResource(
                        "invocation",
                        invocationResource,
                        invocation,
                      );
                    }
                    if (!receipt) {
                      receipt = await executor.post(`${path}/run`, {
                        worker: args.worker,
                        ...invocation,
                      });
                    }
                    if (heartbeatError) throw heartbeatError;
                    const value = z.record(z.string(), z.unknown()).parse(
                      receipt,
                    );
                    const error = typeof value.error_code === "string"
                      ? value.error_code
                      : null;
                    if (
                      error && !/^[a-z0-9_.-]+$/.test(error)
                    ) throw new Error("Invalid Route refusal code");
                    await executor.post(`${path}/step`, {
                      worker: args.worker,
                      key: `${key}:finish`,
                      name: step,
                      status: error ? "failed" : "succeeded",
                      result: value,
                      error_code: error,
                      output: error ? `${error}\n` : `${step} succeeded\n`,
                    });
                    if (error) {
                      throw new RouteRefusal(error);
                    }
                  }
                  const value = z.record(z.string(), z.unknown()).parse(
                    receipt,
                  );
                  if (
                    typeof value.error_code === "string"
                  ) {
                    throw new RouteRefusal(value.error_code);
                  }
                  completed.add(key);
                  if (
                    step === spec.step && transport === spec.transport &&
                    name === spec.name
                  ) {
                    if (value.ready !== true) {
                      throw new Error(
                        "Route publication result is unproven",
                      );
                    }
                    result = value;
                  } else {
                    operation = await heartbeat();
                  }
                }
                completion = { status: "succeeded", result, error_code: null };
              } catch (error) {
                if (!(error instanceof RouteRefusal)) throw error;
                completion = {
                  status: error.code === "operation.cancelled"
                    ? "cancelled"
                    : "failed",
                  result: {
                    error_code: error.code,
                    ...(error.stage ? { stage: error.stage } : {}),
                  },
                  error_code: error.code,
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
export const behavior = { ...routes(), type: "@nckrtl/operation-routes" };
