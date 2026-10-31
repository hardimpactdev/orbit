import { z } from "npm:zod@4.4.3";
import type { SshEnvironment } from "../_lib/pinned-ssh.ts";
import {
  EnrollmentInput,
  nativeTaskVmEnrollmentActions,
} from "./enrollment.ts";
import type { RuntimeProcessInstaller } from "./enrollment_runtime.ts";
import { localSystemCommand } from "../node/system_command.ts";
import { gatewayHttp, type Http } from "../../automatic-deploy/gateway.ts";
import {
  createTaskVmProvider,
  ProviderCreateInput,
} from "./provider_create.ts";
import { Globals } from "../_lib/direct.ts";
import {
  DirectArgs,
  DirectOperationCancelled,
  GatewayOperation,
} from "../_lib/direct.ts";

import {
  createTaskVmEnrollmentAdapter,
  type EnrollmentStageActions,
  TaskVmEnrollmentStages,
} from "./enrollment_direct.ts";

import { HubPublicationInput } from "../node/wireguard_local.ts";

const Id = z.number().int().positive();
export const ProvisionInput = ProviderCreateInput.safeExtend({
  eligible: z.boolean(),
  _wireguard: HubPublicationInput,
  host_node_id: Id.nullable(),
  gateway_node_id: Id,
  guest_node_id: Id,
  issuer_node_id: Id,
  enrollment: EnrollmentInput,
  model_router_node_id: Id,
  model_origin: z.url(),
  participant_node_ids: z.array(Id),
  private_bindings: z.record(
    z.string(),
    z.object({
      owner_node_id: Id,
      read_node_ids: z.array(Id),
      write_node_ids: z.array(Id),
    }),
  ),
  wireguard_ip: z.ipv4(),
  image_fingerprint: z.string().min(1),
  recipe_identity: z.string().regex(/^sha256:[a-f0-9]{64}$/),
  gateway_public_key: z.string().min(1),
}).refine((input) =>
  input.task_vm_id === input.enrollment.task_vm_id &&
  input.name === input.enrollment.name &&
  input.provider === input.enrollment.provider &&
  input.node_id === input.enrollment.execution_node_id &&
  input.gateway_node_id === input.enrollment.gateway_node_id &&
  input.issuer_node_id === input.enrollment.issuer_node_id &&
  input.guest_node_id === input.enrollment.guest_node_id &&
  input.model_router_node_id === input.enrollment.model_router_node_id &&
  input.model_origin === input.enrollment.model_origin &&
  input.model_origin === input.enrollment.runtime.origin &&
  JSON.stringify(input._wireguard) ===
    JSON.stringify(input.enrollment._wireguard), {
  message: "Provider and enrollment placement must be the same admitted intent",
})
  .refine((input) =>
    input.provider === "incus"
      ? input.host_node_id === input.node_id &&
        input.create?.image === input.image_fingerprint &&
        input.create.expected_fingerprint === input.image_fingerprint
      : input.host_node_id === null &&
        input.node_id === input.gateway_node_id &&
        input.upcloud?.source_template === input.image_fingerprint
  );
export type ProvisionIntent = z.infer<typeof ProvisionInput>;
const Args = DirectArgs.extend({
  kind: z.literal("task_vm.provision"),
  node_id: Id,
});
const Status = z.enum([
  "queued",
  "running",
  "succeeded",
  "failed",
  "cancelled",
]);
export const ProvisionStages = ["provider", ...TaskVmEnrollmentStages] as const;
const Stage = z.enum(ProvisionStages);
const Provider = z.object({
  address: z.ipv4(),
  provider_id: z.string().uuid().nullable(),
  image_fingerprint: z.string().min(1),
  ssh_host_key: z.string().nullable(),
  running: z.literal(true),
  settled: z.literal(true),
}).strict();
const PublicNode = z.object({
  address: z.ipv4().optional(),
  ssh_host_key_type: z.string().optional(),
  ssh_host_key: z.string().optional(),
  ssh_host_fingerprint: z.string().optional(),
  wireguard_public_key: z.string().regex(/^[A-Za-z0-9+/]{43}=$/).optional(),
  architecture: z.literal("x86_64").optional(),
}).strip();
const PublicOutput = z.object({
  guest_node_id: Id.optional(),
  bootstrapped: z.boolean().optional(),
  wireguard_public_key: z.string().regex(/^[A-Za-z0-9+/]{43}=$/).optional(),
  hub_configured: z.boolean().optional(),
  network_configured: z.boolean().optional(),
  configured: z.boolean().optional(),
  worker_ready: z.boolean().optional(),
  ready: z.boolean().optional(),
  issued: z.boolean().optional(),
  process_id: Id.optional(),
  process: z.object({ status: z.literal("active") }).strip().optional(),
  node: PublicNode.optional(),
}).strip();
const Output = z.union([Provider, PublicOutput]);
const Code = z.enum([
  "operation.cancelled",
  "operation.kind_unavailable",
  "task_vm.provision_failed",
  "task_vm.provision_refused",
  "task_vm.provision_timeout",
  "task_vm.vm_missing",
  "task_vm.not_settled",
  "task_vm.image_mismatch",
  "task_vm.worker_bootstrap_refused",
]);
const Outcome = z.object({
  stage: Stage,
  ok: z.boolean(),
  output: Output.nullable(),
  error_code: Code.nullable(),
}).strict().refine((value) => value.ok === (value.error_code === null));
const Admission = z.object({
  active: z.boolean(),
  started_at: z.string(),
  input: ProvisionInput,
}).strict();
const StepArgs = Args.extend({
  admission: Admission,
  stage: Stage.exclude(["issue-model", "publish-hub"]),
  provider: Provider.nullable(),
  outcomes: z.array(Outcome).max(ProvisionStages.length),
});
const ProjectArgs = Args.extend({
  admission: Admission.nullable(),
  outcomes: z.array(Outcome).max(ProvisionStages.length),
});
export type ProvisionGateway = Pick<
  GatewayOperation,
  "read" | "project" | "withPrivateMaterial" | "storeSecretOutput"
>;
type Context = Partial<SshEnvironment> & {
  globalArgs: z.infer<typeof Globals>;
  signal?: AbortSignal;
  writeResource: (
    spec: string,
    name: string,
    value: unknown,
  ) => Promise<unknown>;
};
export type ProvisionAction = (
  input: ProvisionIntent,
  prepared: {
    provider: z.infer<typeof Provider> | null;
    outcomes: z.infer<typeof Outcome>[];
  },
  gateway: ProvisionGateway,
  guard: () => Promise<void>,
  signal?: AbortSignal,
  context?: Context,
) => Promise<unknown>;
/** The enrollment owner supplies real same-root lower actions, never child admissions. */
const LocalStage = Stage.exclude(["issue-model", "publish-hub"]);
export type ProvisionActions = Record<
  z.infer<typeof LocalStage>,
  ProvisionAction
>;

/** Operational commands must use Swamp's direct system executor, never mutating agent calls. */
export function provisionProvider(
  executeSystem: NonNullable<Parameters<typeof createTaskVmProvider>[3]>,
): ProvisionAction {
  return (input, _prepared, gateway, guard, signal) =>
    createTaskVmProvider(
      ProviderCreateInput.strip().parse(input),
      guard,
      gateway,
      executeSystem,
      signal,
    );
}

/** Bind the owner's actual typed adapter to explicit native system-command ports. */
export function provisionEnrollment(
  actions:
    | EnrollmentStageActions
    | ((
      input: Parameters<typeof nativeTaskVmEnrollmentActions>[0],
      guard: () => Promise<void>,
      material: ProvisionGateway,
      environment: Partial<SshEnvironment>,
    ) => EnrollmentStageActions),
): Omit<ProvisionActions, "provider"> {
  const action =
    (stage: typeof TaskVmEnrollmentStages[number]): ProvisionAction =>
    (input, prepared, material, guard, signal, context) => {
      const peer = prepared.outcomes.find((outcome) =>
        outcome.stage === "prepare-network"
      );
      const wireguard_public_key = peer
        ? PublicOutput.parse(peer.output).wireguard_public_key ?? null
        : input.enrollment.wireguard_public_key;
      const enrollment = { ...input.enrollment, wireguard_public_key };
      const supplied = typeof actions === "function"
        ? actions(enrollment, guard, material, context ?? {})
        : actions;
      const adapter = createTaskVmEnrollmentAdapter(
        enrollment,
        guard,
        material,
        supplied,
      );
      return adapter.execute(stage, signal);
    };
  return {
    bootstrap: action("bootstrap"),
    "prepare-network": action("prepare-network"),
    "publish-network": action("publish-network"),
    "issue-worker": action("issue-worker"),
    "configure-worker": action("configure-worker"),
    "worker-ready": action("worker-ready"),
    "prepare-runtime": action("prepare-runtime"),
    "confirm-runtime": action("confirm-runtime"),
  };
}

/** Rebuild the canonical lower closures for each stage from its refreshed public input. */
export function provisionNativeActions(
  installProcess?: RuntimeProcessInstaller,
): ProvisionActions {
  return {
    provider: provisionProvider(localSystemCommand),
    ...provisionEnrollment((input, guard, material, environment) =>
      nativeTaskVmEnrollmentActions(input, guard, material, environment, {
        installProcess,
      })
    ),
  };
}

/** One parent root with explicit host, Gateway, issuer and exact-guest placements. */
export function nativeProvision(
  makeHttp: (ctx: Context) => Http = (ctx) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
  actions?: ProvisionActions,
) {
  const gateway = (args: z.infer<typeof Args>, ctx: Context) =>
    new GatewayOperation(makeHttp(ctx), args);
  const current = async (args: z.infer<typeof Args>, ctx: Context) => {
    const value = z.object({
      id: Id,
      kind: z.literal("task_vm.provision"),
      node_id: Id,
      status: Status,
      cancel_requested: z.boolean(),
      input: ProvisionInput,
    }).parse(await gateway(args, ctx).read(ctx.signal));
    if (value.id !== args.operation_id || value.node_id !== args.node_id) {
      throw new Error("task_vm.provision_refused");
    }
    return value;
  };
  return {
    type: "@nckrtl/task-vm-provision",
    version: "2026.10.31.1",
    globalArguments: Globals,
    resources: {
      admission: {
        description: "Frozen allocation input and root start time",
        schema: Admission,
        lifetime: "1d",
        garbageCollection: 3,
      },
      outcome: {
        description: "Bounded safe output from one same-root provision stage",
        schema: Outcome,
        lifetime: "1d",
        garbageCollection: 3,
      },
    },
    methods: {
      admit: {
        description: "Record running before the first provider effect",
        arguments: Args,
        execute: async (raw: z.infer<typeof Args>, ctx: Context) => {
          const args = Args.parse(raw);
          const api = gateway(args, ctx);
          const value = await current(args, ctx);
          const started_at = new Date().toISOString();
          const active = ["queued", "running"].includes(value.status) &&
            !value.cancel_requested && !ctx.signal?.aborted;
          if (active) {
            // Do not allocate a VM until the complete same-root enrollment is installed.
            if (
              !actions ||
              LocalStage.options.some((stage) =>
                typeof actions[stage] !== "function"
              )
            ) {
              throw new Error("operation.kind_unavailable");
            }
            if (!value.input.eligible) {
              throw new Error("task_vm.provision_refused");
            }
            await api.project({ status: "running", started_at });
          } else if (["queued", "running"].includes(value.status)) {
            await api.project({
              status: "cancelled",
              started_at,
              finished_at: started_at,
              result: {},
              error_code: null,
            });
          }
          return {
            dataHandles: [
              await ctx.writeResource("admission", "admission", {
                active,
                started_at,
                input: value.input,
              }),
            ],
          };
        },
      },
      stage: {
        description:
          "Run one supplied lifecycle action inside the owning native root",
        arguments: StepArgs,
        execute: async (raw: z.infer<typeof StepArgs>, ctx: Context) => {
          const args = StepArgs.parse(raw);
          const input = args.admission.input;
          if (!args.admission.active || input.node_id !== args.node_id) {
            throw new Error("task_vm.provision_refused");
          }
          const guard = async () => {
            const value = await current(args, ctx);
            if (ctx.signal?.aborted || value.cancel_requested) {
              throw new DirectOperationCancelled({ effectRunning: false });
            }
            if (
              !value.input.eligible ||
              !["queued", "running"].includes(value.status)
            ) {
              throw new Error("task_vm.provision_refused");
            }
            if (Date.now() >= Date.parse(input.deadline_at)) {
              throw new Error("task_vm.provision_timeout");
            }
          };
          let output: z.infer<typeof Output> | null = null;
          let error_code: z.infer<typeof Code> | null = null;
          try {
            if (
              !actions ||
              LocalStage.options.some((stage) =>
                typeof actions[stage] !== "function"
              )
            ) {
              throw new Error("operation.kind_unavailable");
            }
            // Exact guest jobs prove placement locally; they must not call the host's private endpoint.
            await guard();
            if (args.stage !== "provider" && !args.provider) {
              throw new Error("task_vm.provision_refused");
            }
            const result = await actions[args.stage](
              input,
              {
                provider: args.provider,
                outcomes: args.outcomes,
              },
              gateway(args, ctx),
              guard,
              ctx.signal,
              ctx,
            );
            output = args.stage === "provider"
              ? Provider.parse(result)
              : PublicOutput.parse(result);
            if (args.stage === "provider") {
              await gateway(args, ctx).project({
                status: "running",
                started_at: args.admission.started_at,
                result: { provider: output },
              });
            }
            if (args.stage === "bootstrap") {
              const node = PublicOutput.parse(output).node;
              if (node) {
                await gateway(args, ctx).project({
                  status: "running",
                  started_at: args.admission.started_at,
                  result: { node },
                });
              }
            }
            if (args.stage === "prepare-network") {
              const publicKey = PublicOutput.parse(output).wireguard_public_key;
              if (!publicKey) throw new Error("task_vm.provision_refused");
              await gateway(args, ctx).project({
                status: "running",
                started_at: args.admission.started_at,
                result: { node: { wireguard_public_key: publicKey } },
              });
            }
            if (args.stage === "publish-network") {
              await gateway(args, ctx).project({
                status: "running",
                started_at: args.admission.started_at,
                result: { node: { network_enrolled: true } },
              });
            }
          } catch (error) {
            const known = Code.safeParse(
              error instanceof Error ? error.message : null,
            );
            error_code = error instanceof DirectOperationCancelled
              ? "operation.cancelled"
              : known.success
              ? known.data
              : "task_vm.provision_failed";
          }
          const outcome = Outcome.parse({
            stage: args.stage,
            ok: error_code === null,
            output,
            error_code,
          });
          return {
            dataHandles: [
              await ctx.writeResource("outcome", args.stage, outcome),
            ],
          };
        },
      },
      project: {
        description:
          "Record one final native readiness outcome without another executor",
        arguments: ProjectArgs,
        execute: async (raw: z.infer<typeof ProjectArgs>, ctx: Context) => {
          const args = ProjectArgs.parse(raw);
          const value = await current(args, ctx);
          if (["succeeded", "failed", "cancelled"].includes(value.status)) {
            return { status: value.status };
          }
          const complete = args.outcomes.length === ProvisionStages.length &&
            Stage.options.every((stage, index) =>
              args.outcomes[index].stage === stage
            );
          const failure = args.outcomes.find((outcome) => !outcome.ok);
          const cancelled = failure?.error_code === "operation.cancelled" ||
            (!failure && complete && value.cancel_requested);
          const code = failure?.error_code ??
            (complete && args.admission?.active && value.input.eligible
              ? null
              : "task_vm.provision_refused");
          const status = cancelled
            ? "cancelled"
            : code
            ? "failed"
            : "succeeded";
          const nativeNode = Object.assign(
            {},
            ...args.outcomes.map((outcome) =>
              PublicOutput.safeParse(outcome.output).data?.node ?? {}
            ),
          );
          const peer = args.outcomes.find((outcome) =>
            outcome.stage === "prepare-network"
          );
          const provider = args.outcomes.find((outcome) =>
            outcome.stage === "provider"
          );
          const confirmed = args.outcomes.find((outcome) =>
            outcome.stage === "confirm-runtime"
          );
          const result = status === "succeeded"
            ? {
              task_vm_id: value.input.task_vm_id,
              guest_node_id: value.input.guest_node_id,
              ready: true,
              provider_id: Provider.parse(provider?.output).provider_id,
              enrollment: {
                ready: true,
                node: {
                  ...nativeNode,
                  address: Provider.parse(provider?.output).address,
                  wireguard_public_key:
                    PublicOutput.parse(peer?.output).wireguard_public_key,
                },
                process: PublicOutput.parse(confirmed?.output).process,
              },
            }
            : status === "failed"
            ? { error_code: code }
            : {};
          await gateway(args, ctx).project({
            status,
            started_at: args.admission?.started_at ?? new Date().toISOString(),
            finished_at: new Date().toISOString(),
            result,
            error_code: cancelled ? null : code,
          });
          if (status === "failed") throw new Error(code!);
          return { status };
        },
      },
    },
  };
}

export const behavior = nativeProvision(undefined, provisionNativeActions());
