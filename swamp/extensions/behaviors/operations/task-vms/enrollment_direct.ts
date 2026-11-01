import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import { HubPublicationInput } from "../node/wireguard_local.ts";
import type { SshEnvironment } from "../_lib/pinned-ssh.ts";
import {
  nativeTaskVmEnrollmentActions,
  TaskVmSshIdentity,
} from "./enrollment.ts";
import { DirectArgs, GatewayOperation, Globals } from "../_lib/direct.ts";
export type {
  SystemCommand as EnrollmentSystemCommand,
  SystemTransport as EnrollmentSystemTransport,
} from "../node/system_command.ts";

export const TaskVmEnrollmentStages = [
  "bootstrap",
  "prepare-network",
  "publish-hub",
  "publish-network",
  "issue-worker",
  "configure-worker",
  "worker-ready",
  "issue-model",
  "prepare-runtime",
  "confirm-runtime",
] as const;
export type TaskVmEnrollmentStage = typeof TaskVmEnrollmentStages[number];
const Id = z.number().int().positive();
const Key = z.string().regex(/^[A-Za-z0-9+/]{43}=$/);
/** Only public identity and placement are interpreted here; each native lower action owns its own input. */
export const TaskVmEnrollmentInput = z.object({
  task_vm_id: Id,
  guest_node_id: Id,
  execution_node_id: Id,
  gateway_node_id: Id,
  issuer_node_id: Id,
  model_router_node_id: Id,
  _wireguard: HubPublicationInput,
  provider: z.enum(["incus", "upcloud"]),
  name: z.string().regex(/^tvm-[1-9][0-9]*$/),
  agent_sha256: z.string().regex(/^[a-f0-9]{64}$/),
  node: z.object({
    node_id: Id,
    name: z.string(),
    user: z.literal("orbit"),
    wireguard_ip: z.ipv4(),
  }).passthrough(),
  wireguard_public_key: Key.nullable(),
}).passthrough().superRefine((input, ctx) => {
  if (
    input.name !== `tvm-${input.task_vm_id}` ||
    input.node.node_id !== input.guest_node_id ||
    input.node.name !== input.name ||
    input.guest_node_id === input.execution_node_id ||
    input._wireguard.executor_node_id !== input.gateway_node_id ||
    input._wireguard.context_node_id !== input.guest_node_id ||
    input._wireguard.excluded_node_id !== null ||
    (input.provider === "upcloud" &&
      input.execution_node_id !== input.gateway_node_id)
  ) {
    ctx.addIssue({
      code: "custom",
      message: "Enrollment identity differs from its admitted VM",
    });
  }
});
export type TaskVmEnrollmentIntent = z.infer<typeof TaskVmEnrollmentInput>;
export type EnrollmentPrivateMaterial = Pick<
  GatewayOperation,
  "withPrivateMaterial" | "storeSecretOutput"
>;

export type EnrollmentStageContext = {
  input: TaskVmEnrollmentIntent;
  guard: () => Promise<void>;
  privateMaterial: EnrollmentPrivateMaterial;
  signal?: AbortSignal;
};
/** These ports must be native system-command lower actions. Missing ports refuse; no old agent/executor fallback. */
export type EnrollmentStageActions = Partial<
  Record<
    TaskVmEnrollmentStage,
    (context: EnrollmentStageContext) => Promise<unknown>
  >
>;
const refused = () => new Error("task_vm.enrollment_unavailable");

/** Callable by a truthful provision parent: one stage in its own DAG, with no child admission or new run. */
export function createTaskVmEnrollmentAdapter(
  raw: TaskVmEnrollmentIntent,
  guard: () => Promise<void>,
  privateMaterial: EnrollmentPrivateMaterial,
  actions: EnrollmentStageActions,
) {
  const input = TaskVmEnrollmentInput.parse(raw);
  return {
    async execute(
      stage: TaskVmEnrollmentStage,
      signal?: AbortSignal,
    ): Promise<Record<string, unknown>> {
      signal?.throwIfAborted();
      if (["issue-worker", "configure-worker", "worker-ready"].includes(stage)) {
        throw new Error("operation.kind_unavailable");
      }
      await guard();
      if (stage === "issue-model" || stage === "publish-hub") throw refused();
      if (stage === "publish-network" && input.wireguard_public_key === null) {
        throw refused();
      }
      const action = actions[stage];
      if (!action) throw refused();
      const result = await action({ input, guard, privateMaterial, signal });
      if (stage === "bootstrap") {
        return z.object({
          bootstrapped: z.literal(true),
          node: TaskVmSshIdentity,
        }).parse(result);
      }
      if (stage === "prepare-network") {
        return z.object({ wireguard_public_key: Key }).parse(result);
      }
      if (stage === "confirm-runtime") {
        return {
          ready: true,
          guest_node_id: input.guest_node_id,
          process: { status: "active" },
        };
      }
      // Lower outputs may contain private provider details. Only these fixed public facts leave the action.
      return { phase: stage, guest_node_id: input.guest_node_id };
    },
  };
}
const Args = DirectArgs.extend({
  kind: z.enum(["task_vm.enroll", "task_vm.prepare_runtime"]),
  node_id: Id,
});
type Args = z.infer<typeof Args>;
export type EnrollmentDirectContext = Partial<SshEnvironment> & {
  globalArgs: z.infer<typeof Globals>;
  signal?: AbortSignal;
  readResource(name: string): Promise<Record<string, unknown> | null>;
  writeResource(
    spec: string,
    name: string,
    data: Record<string, unknown>,
  ): Promise<{ name: string }>;
};
type MakeActions = (
  input: TaskVmEnrollmentIntent,
  guard: () => Promise<void>,
  material: GatewayOperation,
  context: EnrollmentDirectContext,
) => EnrollmentStageActions;
const errorCode = (error: unknown) =>
  error instanceof Error &&
    /^[a-z0-9_]+\.[a-z0-9_.-]{1,80}$/.test(error.message)
    ? error.message
    : "task_vm.enrollment_failed";

/** Native DAG adapter. The owning family wires the reviewed system-command ports before takeover. */
export function taskVmEnrollmentDirect(
  makeActions: MakeActions = nativeTaskVmEnrollmentActions,
  makeHttp = (ctx: EnrollmentDirectContext) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile, ctx.signal),
) {
  const api = (args: Args, ctx: EnrollmentDirectContext) =>
    new GatewayOperation(makeHttp(ctx), args);
  const read = async (args: Args, ctx: EnrollmentDirectContext) => {
    const current = z.object({
      id: z.literal(args.operation_id),
      kind: z.literal(args.kind),
      node_id: z.literal(args.node_id),
      status: z.enum(["queued", "running", "succeeded", "failed", "cancelled"]),
      cancel_requested: z.boolean(),
      input: TaskVmEnrollmentInput,
    }).parse(await api(args, ctx).read(ctx.signal));
    return current;
  };
  return {
    type: "@nckrtl/task-vm-enrollment",
    version: "2026.10.31.1",
    globalArguments: Globals,
    resources: {
      stage: {
        description:
          "Public native stage output; only the root Node projects it to the Gateway",
        schema: z.object({
          stage: z.enum(TaskVmEnrollmentStages),
          result: z.record(z.string(), z.unknown()),
        }),
        lifetime: "1d",
        garbageCollection: 1,
      },
      failure: {
        description:
          "Sanitized native stage failure for the root result projection",
        schema: z.object({ error_code: z.string() }),
        lifetime: "1d",
        garbageCollection: 1,
      },
      placement: {
        description:
          "Public immutable enrollment placement for this native root",
        schema: z.object({
          execution_node_id: Id,
          guest_node_id: Id,
          gateway_node_id: Id,
          issuer_node_id: Id,
          model_router_node_id: Id,
          _wireguard: HubPublicationInput,
        }),
        lifetime: "1d",
        garbageCollection: 1,
      },
    },
    methods: {
      admit: {
        description:
          "Read the owned VM intent on its root Node and publish public placement",
        arguments: Args,
        async execute(args: Args, ctx: EnrollmentDirectContext) {
          const current = await read(args, ctx);
          if (current.cancel_requested) {
            await api(args, ctx).project({
              status: "cancelled",
              finished_at: new Date().toISOString(),
              result: {},
            });
            throw new Error("operation.cancelled");
          }
          if (["failed", "cancelled"].includes(current.status)) {
            throw new Error("task_vm.enrollment_terminal");
          }
          const placement = {
            execution_node_id: current.input.execution_node_id,
            guest_node_id: current.input.guest_node_id,
            gateway_node_id: current.input.gateway_node_id,
            issuer_node_id: current.input.issuer_node_id,
            model_router_node_id: current.input.model_router_node_id,
            _wireguard: current.input._wireguard,
          };
          const handle = await ctx.writeResource(
            "placement",
            "placement-" + args.run_id,
            placement,
          );
          if (current.status !== "succeeded") {
            await api(args, ctx).project({
              status: "running",
              started_at: new Date().toISOString(),
              result: {},
            });
          }
          return { dataHandles: [handle] };
        },
      },
      step: {
        description:
          "Run a placed native stage and record public native output; never POST from a foreign Node",
        arguments: Args.extend({ stage: z.enum(TaskVmEnrollmentStages) }),
        async execute(
          args: Args & { stage: TaskVmEnrollmentStage },
          ctx: EnrollmentDirectContext,
        ) {
          const root = api(args, ctx);
          let current = await read(args, ctx);
          if (current.status === "succeeded") return {};
          const guard = async () => {
            const fresh = await read(args, ctx);
            if (fresh.cancel_requested) throw new Error("operation.cancelled");
            if (["failed", "cancelled"].includes(fresh.status)) {
              throw new Error("task_vm.enrollment_terminal");
            }
            if (
              fresh.input.task_vm_id !== current.input.task_vm_id ||
              fresh.input.guest_node_id !== current.input.guest_node_id ||
              fresh.input.execution_node_id !==
                current.input.execution_node_id ||
              JSON.stringify(fresh.input._wireguard) !==
                JSON.stringify(current.input._wireguard)
            ) throw new Error("operation.execution_forbidden");
            current = fresh;
          };
          try {
            await guard();
            const adapter = createTaskVmEnrollmentAdapter(
              current.input,
              guard,
              root,
              makeActions(current.input, guard, root, ctx),
            );
            const result = await adapter.execute(args.stage, ctx.signal);
            const handle = await ctx.writeResource(
              "stage",
              args.stage + "-" + args.run_id,
              { stage: args.stage, result },
            );
            return { dataHandles: [handle] };
          } catch (error) {
            const error_code = errorCode(error);
            await ctx.writeResource("failure", "failure-" + args.run_id, {
              error_code,
            });
            throw new Error(error_code);
          }
        },
      },
      project: {
        description:
          "Project a public native output only on the root Operation Node",
        arguments: Args.extend({
          projection: z.enum([
            "bootstrap",
            "network",
            "enrolled",
            "complete",
            "failure",
          ]),
        }),
        async execute(
          args: Args & {
            projection:
              | "bootstrap"
              | "network"
              | "enrolled"
              | "complete"
              | "failure";
          },
          ctx: EnrollmentDirectContext,
        ) {
          const root = api(args, ctx);
          const current = await read(args, ctx);
          if (["succeeded", "failed", "cancelled"].includes(current.status)) {
            return {};
          }
          if (args.projection === "failure" || current.cancel_requested) {
            const saved = await ctx.readResource("failure-" + args.run_id);
            const error_code = current.cancel_requested
              ? "operation.cancelled"
              : errorCode(new Error(String(saved?.error_code)));
            await root.project({
              status: error_code === "operation.cancelled"
                ? "cancelled"
                : "failed",
              finished_at: new Date().toISOString(),
              result: { error_code },
              error_code,
            });
            return {};
          }
          const stage = args.projection === "bootstrap"
            ? "bootstrap"
            : args.projection === "network"
            ? "prepare-network"
            : args.projection === "enrolled"
            ? "publish-network"
            : "confirm-runtime";
          const outcome = z.object({
            stage: z.literal(stage),
            result: z.record(z.string(), z.unknown()),
          }).parse(await ctx.readResource(stage + "-" + args.run_id));
          if (args.projection === "bootstrap") {
            const result = z.object({ node: TaskVmSshIdentity }).parse(
              outcome.result,
            );
            await root.project({ status: "running", result });
          } else if (args.projection === "network") {
            const prepared = z.object({ wireguard_public_key: Key }).parse(
              outcome.result,
            );
            await root.project({
              status: "running",
              result: {
                node: { wireguard_public_key: prepared.wireguard_public_key },
              },
            });
          } else if (args.projection === "enrolled") {
            await root.project({
              status: "running",
              result: { node: { network_enrolled: true } },
            });
          } else {
            await root.project({
              status: "succeeded",
              finished_at: new Date().toISOString(),
              result: outcome.result,
            });
          }
          return {};
        },
      },
    },
  };
}
export const behavior = taskVmEnrollmentDirect();
