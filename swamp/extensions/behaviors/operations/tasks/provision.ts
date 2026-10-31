import { z } from "npm:zod@4.4.3";
import { gatewayHttp, type Http } from "../../automatic-deploy/gateway.ts";
import { inspect } from "../../node-worker/canary.ts";
import { localCommand } from "../_lib/local-worker.ts";
import { DirectArgs, GatewayOperation, Globals } from "../_lib/direct.ts";
import {
  nativeProvision,
  type ProvisionActions,
  ProvisionInput,
  provisionNativeActions,
  provisionOutcome,
  ProvisionProjectArgs,
} from "../task-vms/provision_direct.ts";
import {
  prepareProvisioningSource,
  profileProvisioningSource,
  Provisioning,
  ProvisioningRefusal,
  resolveProvisioningSource,
} from "../instance-lifecycle/provisioning_source.ts";
import { invokeLocalProvisioning } from "../instance-lifecycle/provisioning_root.ts";
import { sourceGitCommand } from "../instance-lifecycle/source_system.ts";
import { currentProgram, sudoAppStep } from "../instance-deploy/app_step.ts";
import {
  runProjectStepList,
  type StepListPort,
} from "../instance-deploy/run_list.ts";
import { withTaskCancellation } from "./cancellation.ts";

const Id = z.number().int().positive();
const Sha = z.string().regex(/^[a-f0-9]{40}(?:[a-f0-9]{24})?$/);
export const TaskProvisionInput = ProvisionInput.safeExtend({
  group_id: Id,
  instance_id: Id,
  project_id: Id,
  repository: z.string().min(1),
  repository_identity: z.string().nullable(),
  direct_provisioning: Provisioning,
}).refine((input) =>
  input.instance_id === input.direct_provisioning.instance_id &&
  input.project_id === input.direct_provisioning.project_id &&
  input.guest_node_id === input.direct_provisioning.node_id &&
  input.direct_provisioning.environment === "development" &&
  input.repository === input.direct_provisioning.source.repository
);
export const ProvisionedWorkspace = z.object({
  ok: z.boolean(),
  source: z.object({
    branch: z.string().min(1),
    commit: Sha,
    php_version: z.string().nullable(),
    laravel: z.boolean(),
  }).strict().nullable(),
  analysis_base: Sha.nullable(),
  error_code: z.string().regex(/^[a-z0-9_.-]{1,100}$/).nullable(),
}).strict().refine((value) =>
  value.ok
    ? value.source !== null && value.error_code === null
    : value.source === null && value.error_code !== null
);
const Args = DirectArgs.extend({
  kind: z.literal("tasks.provision"),
  node_id: Id,
});
const WorkspaceArgs = ProvisionProjectArgs.safeExtend({
  kind: z.literal("tasks.provision"),
});
const FinishArgs = WorkspaceArgs.extend({
  workspace: ProvisionedWorkspace.nullable(),
});
type Context = {
  globalArgs: z.infer<typeof Globals>;
  signal?: AbortSignal;
  writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};

/** Same immutable analysis pin as the former provisioner: local origin/main, without a fetch. */
export async function provisionAnalysisBase(
  plan: z.infer<typeof Provisioning>,
  start: string,
  signal?: AbortSignal,
): Promise<string | null> {
  const command = sourceGitCommand([
    "-C",
    plan.checkout,
    "merge-base",
    "refs/remotes/origin/main",
    Sha.parse(start),
  ]);
  const result = await localCommand("/usr/bin/git", {
    args: command.args,
    clearEnv: true,
    env: {
      PATH: "/usr/bin:/bin",
      GIT_CONFIG_NOSYSTEM: "1",
      GIT_CONFIG_GLOBAL: "/dev/null",
      GIT_CONFIG_SYSTEM: "/dev/null",
    },
    stdin: "null",
    stdout: "piped",
    stderr: "null",
    signal,
  }).output();
  if (!result.success) return null;
  return Sha.parse(new TextDecoder().decode(result.stdout).trim());
}

export function nativeTaskProvision(
  makeHttp: (ctx: Context) => Http = (ctx) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
  actions: ProvisionActions = provisionNativeActions(),
  ports: {
    invoke?: typeof invokeLocalProvisioning;
    identity?: typeof inspect;
    run?: StepListPort["run"];
    base?: typeof provisionAnalysisBase;
  } = {},
) {
  const vm = nativeProvision(makeHttp, actions, "tasks.provision");
  async function current(args: z.infer<typeof Args>, ctx: Context) {
    const api = new GatewayOperation(makeHttp(ctx), args);
    const value = z.object({
      id: z.literal(args.operation_id),
      kind: z.literal("tasks.provision"),
      node_id: z.literal(args.node_id),
      status: z.enum(["queued", "running", "succeeded", "failed", "cancelled"]),
      cancel_requested: z.boolean(),
      input: TaskProvisionInput,
    }).parse(await api.read(ctx.signal));
    return { api, value };
  }
  return {
    ...vm,
    type: "@nckrtl/task-provision",
    resources: {
      ...vm.resources,
      workspace: {
        description: "Public workspace identity after native source and setup",
        schema: ProvisionedWorkspace,
        lifetime: "1d",
        garbageCollection: 3,
      },
    },
    methods: {
      ...vm.methods,
      vmReady: {
        description:
          "Record VM enrollment on the root Node before preparing its workspace",
        arguments: WorkspaceArgs,
        async execute(raw: z.infer<typeof WorkspaceArgs>, ctx: Context) {
          const args = WorkspaceArgs.parse(raw);
          const { api, value } = await current(args, ctx);
          const outcome = provisionOutcome(args, value);
          if (
            outcome.status !== "succeeded" ||
            !["queued", "running"].includes(value.status)
          ) {
            throw new Error(outcome.error_code ?? "tasks.provision_refused");
          }
          await api.project({
            status: "running",
            started_at: outcome.started_at,
            result: { phase: "vm-ready", ...outcome.result },
          });
          return {};
        },
      },
      workspace: {
        description:
          "Prepare the owned checkout on its exact enrolled guest worker",
        arguments: WorkspaceArgs,
        async execute(raw: z.infer<typeof WorkspaceArgs>, ctx: Context) {
          const args = WorkspaceArgs.parse(raw);
          const { api, value } = await current(args, ctx);
          let output: z.infer<typeof ProvisionedWorkspace>;
          try {
            if (
              provisionOutcome(args, value).status !== "succeeded" ||
              !["queued", "running"].includes(value.status)
            ) {
              throw new Error("tasks.provision_refused");
            }
            const plan = value.input.direct_provisioning;
            await (ports.identity ?? inspect)({
              node_id: value.input.guest_node_id,
              user: plan.user,
            });
            output = await withTaskCancellation(api, async (signal) => {
              const guard = async () => {
                signal.throwIfAborted();
                const latest = (await current(args, ctx)).value;
                if (latest.cancel_requested) {
                  throw new Error("operation.cancelled");
                }
                if (
                  !latest.input.eligible ||
                  !["queued", "running"].includes(latest.status) ||
                  Date.now() >= Date.parse(latest.input.deadline_at)
                ) throw new Error("tasks.provision_refused");
              };
              const invoke: typeof invokeLocalProvisioning = async (
                call,
                signal,
              ) => {
                await guard();
                return await (ports.invoke ?? invokeLocalProvisioning)(
                  call,
                  signal,
                );
              };
              await guard();
              await prepareProvisioningSource(plan, api, invoke, signal);
              const resolved = await resolveProvisioningSource(
                plan,
                api,
                invoke,
                signal,
              );
              const profile = await profileProvisioningSource(
                plan,
                invoke,
                signal,
              );
              const setup = plan.setup!;
              const result = await runProjectStepList(setup, {
                read: async () => {
                  await guard();
                  const latest = (await current(args, ctx)).value;
                  return {
                    input: latest.input.direct_provisioning.setup,
                    cancel_requested: latest.cancel_requested,
                  };
                },
                program: async (step, execute) => {
                  await execute(await currentProgram(makeHttp(ctx), step));
                },
                run: ports.run ??
                  ((step, program, signal) =>
                    sudoAppStep(step, program, signal, args)),
              }, signal);
              if ("cancelled" in result) throw new Error("operation.cancelled");
              if (result.outcome !== "succeeded") {
                throw new Error(result.error_code);
              }
              await guard();
              return ProvisionedWorkspace.parse({
                ok: true,
                source: { ...resolved, ...profile },
                analysis_base: await (ports.base ?? provisionAnalysisBase)(
                  plan,
                  resolved.commit,
                  signal,
                ),
                error_code: null,
              });
            }, ctx.signal);
          } catch (error) {
            const code = error instanceof ProvisioningRefusal
              ? error.code
              : error instanceof Error
              ? error.message
              : "";
            output = {
              ok: false,
              source: null,
              analysis_base: null,
              error_code: ctx.signal?.aborted
                ? "operation.cancelled"
                : /^[a-z0-9_.-]{1,100}$/.test(code)
                ? code
                : "tasks.provision_failed",
            };
          }
          return {
            dataHandles: [
              await ctx.writeResource("workspace", "workspace", output),
            ],
          };
        },
      },
      project: {
        description:
          "Record readiness only after both VM enrollment and workspace preparation",
        arguments: FinishArgs,
        async execute(raw: z.infer<typeof FinishArgs>, ctx: Context) {
          const args = FinishArgs.parse(raw);
          const { api, value } = await current(args, ctx);
          if (["succeeded", "failed", "cancelled"].includes(value.status)) {
            return { status: value.status };
          }
          const outcome = provisionOutcome(args, value);
          const workspace = args.workspace;
          let status = outcome.status;
          let error_code: string | null = outcome.error_code;
          let result: Record<string, unknown> = outcome.result;
          if (status === "succeeded") {
            if (workspace?.ok && workspace.source) {
              result = {
                ...result,
                source: workspace.source,
                instance_id: value.input.instance_id,
                node_id: value.input.guest_node_id,
                starting_commit: workspace.source.commit,
                analysis_base: workspace.analysis_base === null ? null : {
                  base: workspace.analysis_base,
                  instance_id: value.input.instance_id,
                  repository: value.input.repository,
                  branch: workspace.source.branch,
                },
              };
            } else {
              error_code = workspace?.error_code ?? "tasks.provision_failed";
              status = error_code === "operation.cancelled"
                ? "cancelled"
                : "failed";
              result = { failed_step: "workspace" };
            }
          }
          await api.project({
            ...outcome,
            status,
            result,
            error_code: status === "cancelled" ? null : error_code,
          });
          if (status === "failed") throw new Error(error_code!);
          return { status };
        },
      },
    },
  };
}

export const behavior = nativeTaskProvision();
