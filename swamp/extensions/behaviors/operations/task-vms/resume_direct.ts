import { z } from "npm:zod@4.4.3";
import { gatewayHttp, type Http } from "../../automatic-deploy/gateway.ts";
import { Globals } from "../../operation-runner/mod.ts";
import {
  DirectArgs,
  DirectOperationCancelled,
  GatewayOperation,
} from "../_lib/direct.ts";
import { localSystemCommand } from "../node/system_command.ts";
import { runNodeAgent } from "../_lib/node-agent.ts";
import {
  ResumeInput,
  type ResumeIntent,
  resumeProvider,
  verifyResumeGuest,
  waitResumePi,
} from "./resume.ts";
import { cloudRequest } from "./upcloud_sweep.ts";

const Args = DirectArgs.extend({
  kind: z.literal("task_vm.resume"),
  node_id: z.number().int().positive(),
});
const Status = z.enum([
  "queued",
  "running",
  "succeeded",
  "failed",
  "cancelled",
]);
const Current = z.object({
  status: Status,
  cancel_requested: z.boolean(),
  input: ResumeInput,
});
const Stage = z.enum(["provider", "guest", "pi"]);
const Code = z.enum([
  "operation.cancelled",
  "task_vm.resume_refused",
  "task_vm.resume_failed",
  "task_vm.resume_timeout",
  "task_vm.vm_missing",
  "task_vm.not_settled",
]);
const Admission = z.object({
  active: z.boolean(),
  status: Status,
  started_at: z.string(),
  input: ResumeInput,
}).strict();
const Outcome = z.object({
  stage: Stage,
  ok: z.boolean(),
  error_code: Code.nullable(),
}).strict()
  .refine((outcome) => outcome.ok === (outcome.error_code === null));
const StepArgs = Args.extend({ admission: Admission, stage: Stage });
const ProjectArgs = Args.extend({
  admission: Admission.nullable(),
  outcomes: z.array(Outcome).max(3),
});
type Context = {
  globalArgs: z.infer<typeof Globals>;
  signal?: AbortSignal;
  writeResource: (
    spec: string,
    name: string,
    value: unknown,
  ) => Promise<unknown>;
};
type Gateway = Pick<
  GatewayOperation,
  "read" | "project" | "withPrivateMaterial"
>;
type Action = (
  stage: z.infer<typeof Stage>,
  input: ResumeIntent,
  gateway: Gateway,
  guard: () => Promise<void>,
  signal?: AbortSignal,
) => Promise<void>;

async function resumeStage(
  stage: z.infer<typeof Stage>,
  input: ResumeIntent,
  gateway: Gateway,
  guard: () => Promise<void>,
  signal?: AbortSignal,
) {
  if (stage === "provider") {
    await resumeProvider(
      input,
      guard,
      localSystemCommand,
      cloudRequest(gateway, signal),
      signal,
    );
  } else if (stage === "guest") {
    await verifyResumeGuest(input, runNodeAgent, signal);
  } else {
    await waitResumePi(input, gateway, guard, undefined, signal);
  }
}

/** One native root: host start, guest inspection, then owning-host private Pi probe. */
export function nativeResume(
  makeHttp: (ctx: Context) => Http = (ctx) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
  action: Action = resumeStage,
) {
  const gateway = (args: z.infer<typeof Args>, ctx: Context): Gateway => {
    const api = new GatewayOperation(makeHttp(ctx), args);
    return {
      read: (signal) => api.read(signal),
      project: (body) => api.project(body),
      withPrivateMaterial: (use) => api.withPrivateMaterial(use),
    };
  };
  const current = async (args: z.infer<typeof Args>, ctx: Context) =>
    Current.parse(await gateway(args, ctx).read(ctx.signal));
  return {
    type: "@nckrtl/task-vm-resume",
    version: "2026.10.31.1",
    globalArguments: Globals,
    resources: {
      admission: {
        description: "Safe frozen VM input and root start time",
        schema: Admission,
        lifetime: "1d",
        garbageCollection: 3,
      },
      outcome: {
        description: "Bounded native resume stage outcome",
        schema: Outcome,
        lifetime: "1d",
        garbageCollection: 3,
      },
    },
    methods: {
      admit: {
        description:
          "Read the owning VM intent and record running before provider work",
        arguments: Args,
        execute: async (raw: z.infer<typeof Args>, ctx: Context) => {
          const args = Args.parse(raw);
          const value = await current(args, ctx);
          const started_at = new Date().toISOString();
          let status = value.status;
          const active = ["queued", "running"].includes(status) &&
            !value.cancel_requested && !ctx.signal?.aborted;
          if (active) {
            if (!value.input.eligible) {
              throw new Error("task_vm.resume_refused");
            }
            await gateway(args, ctx).project({ status: "running", started_at });
            status = "running";
          } else if (["queued", "running"].includes(status)) {
            status = "cancelled";
            await gateway(args, ctx).project({
              status,
              started_at,
              finished_at: started_at,
              result: {},
              error_code: null,
            });
          }
          const admission = Admission.parse({
            active,
            status,
            started_at,
            input: value.input,
          });
          return {
            dataHandles: [
              await ctx.writeResource("admission", "admission", admission),
            ],
          };
        },
      },
      stage: {
        description:
          "Run one fixed resume stage on the workflow's admitted Node",
        arguments: StepArgs,
        execute: async (raw: z.infer<typeof StepArgs>, ctx: Context) => {
          const args = StepArgs.parse(raw);
          const input = args.admission.input;
          if (!args.admission.active || input.node_id !== args.node_id) {
            throw new Error("task_vm.resume_refused");
          }
          const api = gateway(args, ctx);
          const guard = async () => {
            const value = await current(args, ctx);
            if (value.cancel_requested || ctx.signal?.aborted) {
              throw new DirectOperationCancelled({ effectRunning: false });
            }
            if (
              !value.input.eligible ||
              !["queued", "running"].includes(value.status)
            ) throw new Error("task_vm.resume_refused");
          };
          let error_code: z.infer<typeof Code> | null = null;
          try {
            // Guest inspection has no Gateway callback or private material; placement owns its identity.
            if (args.stage !== "guest") await guard();
            await action(args.stage, input, api, guard, ctx.signal);
          } catch (error) {
            const known = Code.safeParse(
              error instanceof Error ? error.message : null,
            );
            error_code = error instanceof DirectOperationCancelled
              ? "operation.cancelled"
              : known.success
              ? known.data
              : "task_vm.resume_failed";
          }
          const outcome = Outcome.parse({
            stage: args.stage,
            ok: error_code === null,
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
          "Project native readiness once after all placed resume jobs finish",
        arguments: ProjectArgs,
        execute: async (raw: z.infer<typeof ProjectArgs>, ctx: Context) => {
          const args = ProjectArgs.parse(raw);
          const value = await current(args, ctx);
          if (["succeeded", "failed", "cancelled"].includes(value.status)) {
            return { status: value.status };
          }
          const admission = args.admission;
          const complete = args.outcomes.length === 3 &&
            Stage.options.every((stage, index) =>
              args.outcomes[index].stage === stage
            );
          const failure = args.outcomes.find((outcome) => !outcome.ok);
          const cancelled = failure?.error_code === "operation.cancelled" ||
            (!failure && complete && value.cancel_requested);
          const code = failure?.error_code ??
            (complete && admission?.active && value.input.eligible
              ? null
              : "task_vm.resume_refused");
          const status = cancelled
            ? "cancelled"
            : code
            ? "failed"
            : "succeeded";
          const result = cancelled ? {} : code ? { error_code: code } : {
            task_vm_id: value.input.task_vm_id,
            guest_node_id: value.input.guest_node_id,
            ready: true,
          };
          await gateway(args, ctx).project({
            status,
            started_at: admission?.started_at ?? new Date().toISOString(),
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
export const behavior = nativeResume();
