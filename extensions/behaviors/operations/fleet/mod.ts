import { EffectIntent, effectPost, targetReceipt } from "./effects.ts";
import { maintenance, MaintenanceReceipt } from "./maintenance.ts";
import { executeRollout } from "./rollout.ts";
import { executeCliConverge } from "./cli_converge.ts";
import { executeRecipe } from "./recipe.ts";
import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import {
  type Context,
  Executor,
  ExecutorError,
  Globals,
  Operation,
} from "../../operation-runner/mod.ts";
import { Completion } from "./contract.ts";

export function fleet(
  makeHttp = (ctx: Context) =>
    gatewayHttp(
      ctx.globalArgs.gatewayUrl,
      ctx.globalArgs.caFile,
      ctx.signal,
      600_000,
    ),
) {
  return {
    type: "@nckrtl/operation-fleet",
    version: "2026.10.30.2",
    globalArguments: Globals,
    resources: {
      assignment: {
        description:
          "Bind a worker dispatch retry to its first claimed Operation",
        schema: z.object({ operation_id: z.number().int().positive() }),
        lifetime: "7d",
        garbageCollection: 1,
      },
      effect: {
        description:
          "Remote effect intent before execution; target receipts resolve uncertain replies",
        schema: EffectIntent,
        lifetime: "infinite",
        garbageCollection: 10,
      },
      maintenance: {
        description: "Saved maintenance admission intent and Operation receipt",
        schema: MaintenanceReceipt,
        lifetime: "infinite",
        garbageCollection: 10,
      },
      child: {
        description: "Permanent child admission intent and receipt",
        schema: z.object({
          key: z.string(),
          id: z.number().optional(),
        }),
        lifetime: "infinite",
        garbageCollection: 10,
      },
      interruption: {
        description:
          "Stop intent retained until remote effects and children settle",
        schema: z.object({ code: z.string() }),
        lifetime: "infinite",
        garbageCollection: 10,
      },
      receipt: {
        description: "Permanent fleet Operation completion intent",
        schema: Completion,
        lifetime: "infinite",
        garbageCollection: 10,
      },
    },
    methods: {
      maintenance: {
        description:
          "Admit scheduled recipe, DNS and ordered rollout Operations",
        arguments: z.object({ tick: z.string().min(1) }),
        execute: (args: { tick: string }, ctx: Context) =>
          maintenance(args, ctx, makeHttp(ctx)),
      },
      execute: {
        description:
          "Reconcile the admitted peer DNS policy and prove the result",
        arguments: z.object({
          kind: z.enum([
            "fleet.recipe",
            "fleet.rollout",
            "fleet.cli_converge",
          ]),
          worker: z.string(),
        }),
        execute: async (
          args: { kind: string; worker: string },
          ctx: Context,
        ) => {
          const executor = new Executor(makeHttp(ctx));
          const assignmentKey = `assignment-${args.worker}`;
          const assigned = await ctx.readResource(assignmentKey);
          let id: number;
          if (assigned) {
            id = z.object({ operation_id: z.number().int().positive() }).parse(
              assigned,
            ).operation_id;
            const saved = await ctx.readResource(`operation-${id}`);
            if (saved) {
              const completion = Completion.parse(saved);
              const outcome = z.object({
                id: z.number().int(),
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
                const canonical = (value: unknown): unknown =>
                  Array.isArray(value)
                    ? value.map(canonical)
                    : value && typeof value === "object"
                    ? Object.fromEntries(
                      Object.entries(value).sort(([a], [b]) =>
                        a.localeCompare(b)
                      ).map(([key, entry]) => [key, canonical(entry)]),
                    )
                    : value;
                if (
                  outcome.id !== id || outcome.kind !== args.kind ||
                  outcome.status !== completion.status ||
                  outcome.error_code !== completion.error_code ||
                  JSON.stringify(canonical(outcome.result ?? {})) !==
                    JSON.stringify(canonical(completion.result))
                ) {
                  throw new Error("Unproven saved fleet completion");
                }
                return { status: completion.status };
              }
            }
          } else {
            const claimed = await executor.claim(args.kind, args.worker);
            if (claimed === null) return { status: "idle" };
            if (claimed.kind !== args.kind) {
              throw new Error("Wrong claimed fleet kind");
            }
            id = claimed.id;
            await ctx.writeResource("assignment", assignmentKey, {
              operation_id: id,
            });
          }
          const path = `${id}`;
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
          const operation = Operation.extend({
            steps: z.array(
              z.object({
                name: z.string(),
                status: z.string(),
                error_code: z.string().nullable().optional(),
              }),
            ).default([]),
          }).parse(
            await executor.post(`${path}/heartbeat`, { worker: args.worker }),
          );
          if (operation.id !== id || operation.kind !== args.kind) {
            throw new Error("Wrong fleet assignment");
          }

          const activeSteps = new Set(
            operation.steps.filter((s) => s.status === "running").map((s) =>
              s.name
            ),
          );
          let touched = operation.steps.length > 0;
          const stopVisit = async (code: string) => {
            await ctx.writeResource(
              "interruption",
              `${resource}-interruption`,
              { code },
            );
            for (const step of activeSteps) {
              const declared = operation.execution_context.descriptors.filter((
                d,
              ) =>
                d.step === step &&
                ["agent", "remote_program"].includes(d.transport)
              ).map((d) => d.key);
              const keys = declared.length > 0
                ? declared
                : ["recipe-begin", "verify", "cli-converge"].includes(step)
                ? []
                : step === "self-update"
                ? [
                  step,
                  `${step}-retry`,
                  `${step}-downgrade`,
                  `${step}-downgrade-retry`,
                ]
                : [step, ...(step === "cli-install" ? [`${step}-retry`] : [])];
              for (const key of keys) {
                const receipt = await targetReceipt(
                  operation.id,
                  key,
                  makeHttp(ctx),
                );
                if (receipt.state === "running") return { status: "waiting" };
              }
            }
            for (const name of activeSteps) {
              await executor.post(`${path}/step`, {
                worker: args.worker,
                key: `${name}:interrupted`,
                name,
                status: "failed",
                error_code: code,
                result: {},
              });
            }
            if (["fleet.recipe", "fleet.rollout"].includes(operation.kind)) {
              const response = await makeHttp(ctx)(
                "GET",
                `/api/v1/operation-executor/${path}/children`,
              );
              if (response.status !== 200) {
                throw new ExecutorError("operation.child_unproven");
              }
              const children = z.object({
                data: z.array(
                  z.object({ key: z.string(), status: z.string() }),
                ),
              }).parse(response.body).data;
              const pending = children.filter((child) =>
                !["succeeded", "failed", "cancelled"].includes(child.status)
              );
              if (pending.length > 0) {
                await executor.post(`${path}/wait`, {
                  worker: args.worker,
                  keys: pending.map((child) => child.key),
                });
                return { status: "waiting" };
              }
            }
            const completion = {
              status: "failed",
              error_code: code,
              result: {},
            };
            const handle = await ctx.writeResource(
              "receipt",
              resource,
              completion,
            );
            await executor.post(`${path}/complete`, {
              worker: args.worker,
              ...completion,
            });
            return { status: "failed", dataHandles: [handle] };
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
            const send = async (
              suffix: string,
              body: Record<string, unknown>,
            ) => {
              if (heartbeatError) throw heartbeatError;
              if (suffix === "step" && body.status === "running") {
                activeSteps.add(String(body.name));
                touched = true;
              }
              const result = await executor.post(`${path}/${suffix}`, {
                worker: args.worker,
                ...body,
              });
              if (suffix === "step" && body.status !== "running") {
                activeSteps.delete(String(body.name));
              }
              return result;
            };
            const post = effectPost(operation.id, ctx, makeHttp(ctx), send);
            if (
              [
                "fleet.recipe",
                "fleet.rollout",
                "fleet.cli_converge",
              ].includes(
                operation.kind,
              )
            ) {
              const guarded = operation.kind === "fleet.rollout" ||
                operation.kind === "fleet.recipe" &&
                  operation.input.automatic === true;
              let policy = { daytime: true, window_open: true };
              if (guarded) {
                const response = await makeHttp(ctx)(
                  "GET",
                  `/api/v1/fleet/nodes/${operation.node_id}/visit-policy`,
                );
                if (response.status !== 200) {
                  throw new ExecutorError("fleet.policy_unavailable");
                }
                policy = z.object({
                  data: z.object({
                    daytime: z.boolean(),
                    window_open: z.boolean(),
                  }),
                }).parse(response.body).data;
              }
              const stopping = await ctx.readResource(
                `${resource}-interruption`,
              );
              if (stopping) {
                return await stopVisit(
                  z.object({ code: z.string() }).parse(stopping).code,
                );
              }
              const waitResume =
                operation.execution_context.result?.wait_resume === true;
              if (
                guarded && !policy.daytime && operation.steps.length > 0 &&
                (!waitResume || !policy.window_open)
              ) {
                return await stopVisit("fleet.visit_interrupted");
              }
              const windowClosed = guarded && !policy.window_open;
              const failed = operation.steps.find((s) => s.status === "failed");
              const completion = windowClosed
                ? {
                  status: "failed",
                  result: {},
                  error_code: "fleet.window_closed",
                }
                : failed
                ? {
                  status: failed.error_code === "operation.cancelled"
                    ? "cancelled"
                    : "failed",
                  result: {},
                  error_code: failed.error_code ?? "node.recipe_failed",
                }
                : operation.cancel_requested && operation.steps.length === 0
                ? { status: "cancelled", result: {}, error_code: null }
                : operation.kind === "fleet.cli_converge"
                ? await executeCliConverge(operation, post)
                : await (operation.kind === "fleet.recipe"
                  ? executeRecipe
                  : executeRollout)(operation, ctx, post, async (id) => {
                    if (heartbeatError) throw heartbeatError;
                    const response = await makeHttp(ctx)(
                      "GET",
                      `/api/v1/operation-executor/${path}/children`,
                    );
                    if (response.status !== 200) {
                      throw new Error("Child Operations are unreadable");
                    }
                    const children = z.object({
                      data: z.array(z.object({
                        id: z.number(),
                        key: z.string(),
                        kind: z.string(),
                        node_id: z.number(),
                        status: z.string(),
                        error_code: z.string().nullable().optional(),
                        result: z.record(z.string(), z.unknown()).nullable()
                          .optional(),
                      })),
                    }).parse(response.body).data;
                    const child = children.find((c) =>
                      c.id === id && c.key === "footprint" &&
                      c.kind === "node.converge" &&
                      c.node_id === operation.node_id
                    );
                    if (!child) {
                      throw new Error(
                        "The footprint child does not belong to this parent",
                      );
                    }
                    return child;
                  }, (key) => targetReceipt(operation.id, key, makeHttp(ctx)));
              if (completion === null) {
                await post("wait", { keys: ["footprint"] });
                return { status: "waiting" };
              }
              const handle = await ctx.writeResource(
                "receipt",
                resource,
                completion,
              );
              await post("complete", completion);
              return { dataHandles: [handle], status: completion.status };
            }
            throw new Error("Wrong fleet kind");
          } catch (error) {
            if (
              operation.kind === "fleet.cli_converge" &&
              error instanceof ExecutorError &&
              error.code === "fleet.cli_ineligible"
            ) {
              return await stopVisit(error.code);
            }
            if (
              error instanceof ExecutorError &&
              error.code === "fleet.visit_interrupted"
            ) return await stopVisit(error.code);
            if (
              error instanceof ExecutorError &&
              ["fleet.window_closed", "operation.run_constraint_failed"]
                .includes(error.code)
            ) {
              return await stopVisit(
                touched ? "fleet.visit_interrupted" : "fleet.window_closed",
              );
            }
            if (
              error instanceof ExecutorError &&
              error.code === "operation.effect_running"
            ) return { status: "waiting" };
            throw error;
          } finally {
            clearInterval(timer);
          }
        },
      },
    },
  };
}
export const behavior = { ...fleet(), type: "@nckrtl/operation-fleet" };
