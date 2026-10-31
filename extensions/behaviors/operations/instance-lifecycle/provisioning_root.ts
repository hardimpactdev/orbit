import { prepareProvisioningVp } from "./provisioning_vp.ts";
import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import { Globals } from "../_lib/direct.ts";
import { DirectArgs, GatewayOperation } from "../_lib/direct.ts";
import { runLocalSource } from "./source_system.ts";
import {
  isProductionHomeAction,
  runLocalProductionHome,
} from "./production_home_system.ts";
import type { NodeCall } from "../_lib/node-agent.ts";
import { invokeLocalEnvironment } from "../instance-environment/local.ts";
import { invokeLocalVp } from "./provisioning_vp_local.ts";
import {
  runProjectStepList,
  type StepListPort,
} from "../instance-deploy/run_list.ts";
import { synchronizeEnvironment } from "../instance-environment/sync.ts";
import {
  inspectProvisioningCandidate,
  prepareProvisioningSource,
  profileProvisioningSource,
  Provisioning,
  ProvisioningRefusal,
  resolveProvisioningSource,
  seedProvisioningTemplate,
} from "./provisioning_source.ts";

const Stage = z.enum([
  "candidate",
  "prepare",
  "resolve",
  "profile",
  "template",
  "environment",
  "vp",
  "setup",
  "complete",
]);
const Resolved = z.object({ branch: z.string(), commit: z.string() }).strict();
type Context = {
  globalArgs: z.infer<typeof Globals>;
  signal?: AbortSignal;
  writeResource(
    spec: string,
    name: string,
    data: Record<string, unknown>,
  ): Promise<{ name: string }>;
};

/** Route each admitted action to its closed local implementation. */
export function invokeLocalProvisioning(call: NodeCall, signal?: AbortSignal) {
  if (
    isProductionHomeAction(call.name) &&
    (call.name !== "instance.inspect-clone-candidate" ||
      call.input.environment === "production")
  ) {
    return runLocalProductionHome(call, signal);
  }
  switch (call.name) {
    case "instance.inspect-environment-sync":
    case "instance.write-environment":
      return invokeLocalEnvironment({ ...call, name: call.name }, signal);
    case "tool.read-vp-project":
    case "tool.inspect-vp-scope":
    case "tool.vp-version":
    case "tool.inspect-vp-store":
      return invokeLocalVp({ ...call, name: call.name }, signal);
    default:
      return runLocalSource(call, signal);
  }
}

/** One native root per parent kind. Its jobs use exact Node placement; no child admission. */
export function provisioningRoot(
  kind: "instance.create" | "instance.clone",
  makeHttp = (ctx: Context) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
  invoke = invokeLocalProvisioning,
  run?: StepListPort["run"],
) {
  const Args = DirectArgs.extend({
    kind: z.literal(kind),
    node_id: z.number().int().positive(),
  });
  type Args = z.infer<typeof Args>;
  const Current = z.object({
    id: z.number(),
    kind: z.literal(kind),
    node_id: z.number(),
    status: z.enum(["queued", "running", "succeeded", "failed", "cancelled"]),
    cancel_requested: z.boolean(),
    input: z.object({
      direct_provisioning: Provisioning,
      environment_plan: z.unknown().optional(),
      name_converted_from: z.string().nullable().default(null),
    }),
  });
  async function current(args: Args, ctx: Context) {
    const gateway = new GatewayOperation(makeHttp(ctx), args);
    const value = Current.parse(await gateway.read(ctx.signal));
    if (
      value.id !== args.operation_id || value.node_id !== args.node_id ||
      value.input.direct_provisioning.node_id !== args.node_id ||
      (kind === "instance.create") !==
        (value.input.direct_provisioning.environment === "development")
    ) throw new ProvisioningRefusal("instance.lifecycle_conflict", "admission");
    return { gateway, value, plan: value.input.direct_provisioning };
  }
  return {
    type: kind === "instance.create"
      ? "@nckrtl/instance-create"
      : "@nckrtl/instance-clone",
    version: "2026.10.31.1",
    globalArguments: Globals,
    resources: {
      placement: {
        description: "Admitted clone candidate placement",
        schema: z.object({ candidate_node_id: z.number().int().positive() }),
        lifetime: "1d",
        garbageCollection: 1,
      },
      candidate: {
        description: "Inspected public candidate commit",
        schema: z.object({ candidate_commit: z.string() }),
        lifetime: "1d",
        garbageCollection: 1,
      },
      source: {
        description: "Resolved public source identity only",
        schema: Resolved,
        lifetime: "1d",
        garbageCollection: 1,
      },
    },
    methods: {
      admit: {
        arguments: Args,
        description: "Resolve admitted clone placement without effects",
        execute: async (args: Args, ctx: Context) => {
          const { plan } = await current(args, ctx);
          if (kind !== "instance.clone" || plan.candidate === undefined) {
            throw new ProvisioningRefusal(
              "instance.lifecycle_conflict",
              "admission",
            );
          }
          return {
            dataHandles: [
              await ctx.writeResource("placement", "placement", {
                candidate_node_id: plan.candidate.node_id,
              }),
            ],
          };
        },
      },
      candidate: {
        arguments: Args,
        description:
          "Inspect the admitted candidate on its exact native worker",
        execute: async (args: Args, ctx: Context) => {
          const gateway = new GatewayOperation(makeHttp(ctx), args);
          const value = Current.parse(await gateway.read(ctx.signal));
          const plan = value.input.direct_provisioning;
          if (
            kind !== "instance.clone" || value.id !== args.operation_id ||
            plan.candidate?.node_id !== args.node_id ||
            value.node_id !== plan.node_id || value.cancel_requested ||
            !["queued", "running"].includes(value.status)
          ) {
            throw new ProvisioningRefusal(
              "instance.lifecycle_conflict",
              "candidate",
            );
          }
          const candidate = await inspectProvisioningCandidate(
            plan,
            gateway,
            (call, signal) =>
              invoke({
                ...call,
                input: call.input.environment === "production"
                  ? { ...call.input, project_id: plan.project_id }
                  : call.input,
              }, signal),
            ctx.signal,
          );
          return {
            dataHandles: [
              await ctx.writeResource("candidate", "candidate", {
                candidate_commit: candidate.commit,
              }),
            ],
          };
        },
      },
      stage: {
        arguments: Args.extend({
          stage: Stage,
          source: Resolved.optional(),
          candidate_commit: z.string().optional(),
        }),
        description:
          "Run a placed parent provisioning step using local pinned effects",
        execute: async (
          args: Args & {
            stage: z.infer<typeof Stage>;
            source?: z.infer<typeof Resolved>;
            candidate_commit?: string;
          },
          ctx: Context,
        ) => {
          const { gateway, value, plan } = await current(args, ctx);
          const invokeForPlan = (call: NodeCall, signal?: AbortSignal) =>
            invoke({
              ...call,
              input: isProductionHomeAction(call.name)
                ? { ...call.input, project_id: plan.project_id }
                : call.input,
            }, signal);
          if (value.status === "succeeded") return {};
          if (
            !["queued", "running"].includes(value.status) ||
            value.cancel_requested
          ) throw new ProvisioningRefusal("operation.cancelled", args.stage);
          const project = (result: Record<string, unknown> = {}) =>
            gateway.project({
              status: "running",
              started_at: new Date().toISOString(),
              result,
              error_code: null,
            });
          switch (args.stage) {
            case "complete":
            case "candidate":
              throw new ProvisioningRefusal(
                "instance.lifecycle_conflict",
                "candidate",
              );
            case "prepare":
              await project();
              await prepareProvisioningSource(
                plan,
                gateway,
                invokeForPlan,
                ctx.signal,
              );
              break;
            case "resolve": {
              const source = await resolveProvisioningSource(
                plan,
                gateway,
                invokeForPlan,
                ctx.signal,
                args.candidate_commit,
              );
              return {
                dataHandles: [
                  await ctx.writeResource("source", "source", source),
                ],
              };
            }
            case "profile": {
              const source = Resolved.parse(args.source);
              const profile = await profileProvisioningSource(
                plan,
                invokeForPlan,
                ctx.signal,
              );
              if (!plan.already_active) {
                await project({
                  phase: "source",
                  ...source,
                  ...profile,
                  ...(kind === "instance.clone"
                    ? { candidate_commit: args.candidate_commit }
                    : {}),
                });
              }
              break;
            }
            case "template":
              if (!plan.already_active) {
                await seedProvisioningTemplate(
                  plan,
                  Resolved.parse(args.source).commit,
                  gateway,
                  invokeForPlan,
                  ctx.signal,
                );
              }
              break;
            case "environment":
              if (!plan.already_active) {
                const output = await synchronizeEnvironment(
                  value.input.environment_plan,
                  gateway.withPrivateMaterial.bind(gateway),
                  (call, signal) =>
                    invokeForPlan({
                      ...call,
                      pin: plan.agent_sha256 ?? "",
                      sudo: true,
                      mutates: call.name === "instance.write-environment",
                      deadlineMs: 120000,
                    }, signal),
                  ctx.signal,
                );
                await project({ phase: "environment", keys: output.keys });
              }
              break;
            case "vp":
              if (!plan.already_active) {
                await project({
                  phase: "vp",
                  environment: await prepareProvisioningVp(
                    plan,
                    (call, signal) =>
                      invokeForPlan({
                        ...call,
                        pin: plan.agent_sha256 ?? "",
                        sudo: call.name === "tool.read-vp-project",
                        mutates: false,
                        deadlineMs: 120000,
                      }, signal),
                    ctx.signal,
                  ),
                });
              }
              break;
            case "setup":
              if (plan.environment === "development" && !plan.already_active) {
                const admitted = plan.setup!;
                const result = await runProjectStepList(admitted, {
                  read: async () => {
                    const latest = await current(args, ctx);
                    return {
                      input: latest.plan.setup,
                      cancel_requested: latest.value.cancel_requested,
                    };
                  },
                  program: (step, execute) =>
                    gateway.withPrivateMaterial(async (material) => {
                      const programs = z.object({
                        steps: z.array(
                          z.object({
                            name: z.string(),
                            timeout_seconds: z.number(),
                            command: z.string().max(16384),
                          }).strict(),
                        ),
                      }).strict().parse(material.setup_programs);
                      const found = programs.steps.filter((row) =>
                        row.name === step.name &&
                        row.timeout_seconds === step.timeout_seconds
                      );
                      if (found.length !== 1) {
                        throw new Error("instance.step_not_admitted");
                      }
                      await execute(found[0].command);
                    }),
                  ...(run === undefined ? {} : { run }),
                }, ctx.signal);
                if ("cancelled" in result) {
                  throw new ProvisioningRefusal("operation.cancelled", "setup");
                }
                if (result.outcome !== "succeeded") {
                  throw new ProvisioningRefusal(
                    result.error_code,
                    result.failed_step ?? "setup",
                  );
                }
              }
              break;
          }
          return {};
        },
      },
      finish: {
        arguments: Args,
        description: "Record successful native provisioning",
        execute: async (args: Args, ctx: Context) => {
          const { gateway, value, plan } = await current(args, ctx);
          if (value.status === "succeeded") return { ready: true };
          if (!["queued", "running"].includes(value.status)) {
            throw new ProvisioningRefusal("operation.not_running", "complete");
          }
          if (value.cancel_requested) {
            await gateway.project({
              status: "cancelled",
              started_at: new Date().toISOString(),
              finished_at: new Date().toISOString(),
              result: {},
              error_code: null,
            });
            return { ready: false };
          }
          await gateway.project({
            status: "succeeded",
            started_at: new Date().toISOString(),
            finished_at: new Date().toISOString(),
            result: {
              instance_id: plan.instance_id,
              ready: true,
              ...(kind === "instance.create"
                ? { name_converted_from: value.input.name_converted_from }
                : {}),
            },
            error_code: null,
          });
          return { ready: true };
        },
      },
      fail: {
        arguments: Args.extend({ failed_step: Stage }),
        description: "Record bounded failure without a rollback child",
        execute: async (
          args: Args & { failed_step: z.infer<typeof Stage> },
          ctx: Context,
        ) => {
          const gateway = new GatewayOperation(makeHttp(ctx), args);
          const operation = Current.parse(await gateway.read(ctx.signal));
          if (["succeeded", "failed", "cancelled"].includes(operation.status)) {
            return { recorded: true };
          }
          const cancelled = operation.cancel_requested;
          await gateway.project({
            status: cancelled ? "cancelled" : "failed",
            started_at: new Date().toISOString(),
            finished_at: new Date().toISOString(),
            result: cancelled ? {} : { failed_step: args.failed_step },
            error_code: cancelled ? null : "instance.provisioning_failed",
          });
          return { recorded: true };
        },
      },
    },
  };
}
