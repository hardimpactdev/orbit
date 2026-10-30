import { z } from "npm:zod@4.4.3";
import { type ProjectConfigContext } from "../../_lib/project_config.ts";
import { MergeConfiguration } from "./configuration.ts";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import { type Context, Executor, Globals } from "../../operation-runner/mod.ts";
const Result = z.object({
  merged: z.literal(true),
  head_sha: z.string().regex(/^[a-f0-9]{40}$/),
  merge_sha: z.string().regex(/^[a-f0-9]{40}$/),
}).strict();
const Completion = z.object({
  status: z.enum(["succeeded", "failed", "cancelled"]),
  result: z.record(z.string(), z.unknown()),
  error_code: z.string().nullable(),
});
const Operation = z.object({
  id: z.number().int().positive(),
  kind: z.literal("tasks.dependency_merge"),
  target_type: z.literal("task_group"),
  target_id: z.number().int().positive(),
  input: z.object({
    project_id: z.number().int().positive(),
    repository: z.string(),
    default_branch: z.string(),
    smoke_workflow: z.string(),
    group_id: z.number().int().positive(),
    head_sha: z.string().regex(/^[a-f0-9]{40}$/),
    token: z.object({
      $secret: z.literal("github.dependency_merge"),
      id: z.number().int().positive(),
    }),
  }).passthrough(),
  cancel_requested: z.boolean(),
  step_results: z.preprocess(
    (v) => Array.isArray(v) && !v.length ? {} : v,
    z.record(z.string(), z.unknown()),
  ),
});
export function dependencyMerge(
  makeHttp = (ctx: Context) =>
    gatewayHttp(
      ctx.globalArgs.gatewayUrl,
      ctx.globalArgs.caFile,
      ctx.signal,
      120_000,
    ),
) {
  return {
    type: "@nckrtl/operation-dependency-merge",
    version: "2026.10.30.1",
    globalArguments: Globals,
    resources: {
      claim: {
        description: "First merge claimed by this scheduled invocation",
        schema: z.object({ operation_id: z.number().int().positive() }),
        lifetime: "infinite",
        garbageCollection: 1,
      },
      receipt: {
        description: "Exact-head dependency squash completion intent",
        schema: Completion,
        lifetime: "infinite",
        garbageCollection: 10,
      },
    },
    methods: {
      execute: {
        description: "Request one proven Gateway-owned squash merge",
        arguments: z.object({
          kind: z.literal("tasks.dependency_merge"),
          worker: z.string(),
        }),
        execute: async (
          args: { kind: "tasks.dependency_merge"; worker: string },
          ctx: Context & ProjectConfigContext,
        ) => {
          const executor = new Executor(makeHttp(ctx));
          const claimName = `claim-${args.worker}`;
          const savedClaim = await ctx.readResource(claimName);
          let id: number;
          if (savedClaim) {
            id = z.object({ operation_id: z.number().int().positive() }).parse(
              savedClaim,
            ).operation_id;
          } else {
            const raw = await executor.post("claim", {
              kind: args.kind,
              worker: args.worker,
            });
            if (raw === null) return { status: "idle" };
            id = Operation.parse(raw).id;
            await ctx.writeResource("claim", claimName, { operation_id: id });
          }
          const path = String(id), resource = `operation-${path}`;
          const complete = async (value: z.infer<typeof Completion>) => {
            const handle = await ctx.writeResource("receipt", resource, value);
            await executor.post(`${path}/complete`, {
              worker: args.worker,
              ...value,
            });
            return { dataHandles: [handle], status: value.status };
          };
          const saved = await ctx.readResource(resource);
          if (saved) return await complete(Completion.parse(saved));
          const op = Operation.parse(
            await executor.post(`${path}/heartbeat`, { worker: args.worker }),
          );
          if (
            op.id !== id || op.target_id !== op.input.group_id
          ) throw new Error("Merge operation identity changed");
          const stored = op.step_results.squash;
          if (op.cancel_requested && !stored) {
            return await complete({
              status: "cancelled",
              result: {},
              error_code: null,
            });
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
            let result: unknown = stored;
            if (!result) {
              const refreshed = await ctx.runModel({
                modelType: "@nckrtl/orbit-gateway",
                name: "orbit-gateway",
                method: "dependency_merge_configuration",
                arguments: { projectId: op.input.project_id },
              });
              if (!refreshed.ok) {
                throw new Error("Cannot refresh private merge configuration");
              }
              const rows = await ctx.queryData(
                `modelName == "orbit-gateway" && name == "dependency-merge-configuration-${op.input.project_id}"`,
                "attributes",
              );
              if (rows.length !== 1) {
                throw new Error("Cannot read private merge configuration");
              }
              const config = MergeConfiguration.parse(rows[0]);
              if (
                !config.enabled || config.projectId !== op.input.project_id ||
                config.repository !== op.input.repository ||
                config.defaultBranch !== op.input.default_branch ||
                config.smokeWorkflow !== op.input.smoke_workflow
              ) {
                return await complete({
                  status: "failed",
                  result: {},
                  error_code: "dependency_merge.disabled",
                });
              }
              await executor.post(`${path}/step`, {
                worker: args.worker,
                key: "squash:start",
                name: "squash",
                status: "running",
              });
              result = await executor.post(`${path}/run`, {
                worker: args.worker,
                key: "squash",
                transport: "gateway",
                name: "github.dependency-squash",
                input: op.input,
              });
              if (heartbeatError) throw heartbeatError;
              const error = z.object({
                error_code: z.string().regex(/^[a-z0-9_.-]+$/),
              }).safeParse(result);
              if (error.success) {
                await executor.post(`${path}/step`, {
                  worker: args.worker,
                  key: "squash:finish",
                  name: "squash",
                  status: "failed",
                  result: {},
                  error_code: error.data.error_code,
                });
                return await complete({
                  status: error.data.error_code === "operation.cancelled"
                    ? "cancelled"
                    : "failed",
                  result: {},
                  error_code: error.data.error_code,
                });
              }
              const proof = Result.parse(result);
              if (proof.head_sha !== op.input.head_sha) {
                throw new Error(
                  "Merge receipt head changed",
                );
              }
              await executor.post(`${path}/step`, {
                worker: args.worker,
                key: "squash:finish",
                name: "squash",
                status: "succeeded",
                result: proof,
              });
            }
            const proof = Result.parse(result);
            if (proof.head_sha !== op.input.head_sha) {
              throw new Error(
                "Merge receipt head changed",
              );
            }
            return await complete({
              status: "succeeded",
              result: proof,
              error_code: null,
            });
          } finally {
            clearInterval(timer);
          }
        },
      },
    },
  };
}
export const behavior = dependencyMerge();
