import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import { Globals } from "../_lib/direct.ts";
import {
  DirectArgs,
  directOperation,
  DirectOperationCancelled,
  GatewayOperation,
} from "../_lib/direct.ts";
import {
  type AppStep,
  type AppStepResult,
  currentProgram,
  sudoAppStep,
} from "./app_step.ts";
import { ProjectStepList, runProjectStepList } from "./run_list.ts";

const Args = DirectArgs.extend({
  kind: z.literal("instance.run_steps"),
  node_id: z.number().int().positive(),
});
const Input = ProjectStepList;
const Code = z.string().regex(/^[a-z0-9_.-]{1,100}$/);
const Result = z.union([
  z.object({
    instance_id: z.number().int().positive(),
    outcome: z.literal("succeeded"),
    no_op: z.boolean(),
  }).strict(),
  z.object({
    instance_id: z.number().int().positive(),
    outcome: z.enum(["failed", "missing", "busy", "deadline", "unconfirmed"]),
    error_code: Code,
    failed_step: z.string().nullable(),
  }).strict(),
]);
type Context = { globalArgs: z.infer<typeof Globals>; signal?: AbortSignal };
type Run = (
  step: AppStep,
  program: string,
  signal?: AbortSignal,
) => Promise<AppStepResult>;

/** Ordered list execution belongs to Swamp; private command reads never enter persisted inputs. */
export function setupDirect(
  makeHttp = (ctx: Context) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
  run: Run = sudoAppStep,
) {
  return {
    type: "@nckrtl/instance-setup-direct",
    version: "2026.10.30.1",
    globalArguments: Globals,
    methods: {
      run: {
        description:
          "Run admitted Project setup or teardown with direct sudo on its Node",
        arguments: Args,
        execute: async (raw: z.infer<typeof Args>, ctx: Context) => {
          const args = Args.parse(raw),
            http = makeHttp(ctx),
            gateway = new GatewayOperation(http, args);
          const port = {
            read: async () => {
              const raw = await gateway.read();
              const current = z.object({
                id: z.number(),
                kind: z.string(),
                node_id: z.number(),
                status: z.string(),
                input: Input,
              }).parse(raw);
              if (
                current.id !== args.operation_id ||
                current.kind !== args.kind || current.node_id !== args.node_id
              ) throw new Error("instance.step_not_admitted");
              if (current.status === "running") {
                const at = new Date().toISOString();
                await gateway.project({
                  status: "failed",
                  started_at: at,
                  finished_at: at,
                  error_code: "instance.step_execution_unconfirmed",
                  result: {
                    instance_id: current.input.instance_id,
                    outcome: "unconfirmed",
                    error_code: "instance.step_execution_unconfirmed",
                    failed_step: null,
                  },
                });
                throw new Error("instance.step_execution_unconfirmed");
              }
              return raw;
            },
            project: (body: Record<string, unknown>) => {
              return gateway.project(body);
            },
          };
          return await directOperation(
            args,
            port,
            async (input) => {
              const admitted = Input.parse(input);
              const result = await runProjectStepList(admitted, {
                read: async () => {
                  const current = z.object({
                    input: Input,
                    status: z.string(),
                    cancel_requested: z.boolean(),
                  }).parse(await gateway.read());
                  if (current.status !== "running") {
                    throw new Error("instance.step_execution_unconfirmed");
                  }
                  return current;
                },
                program: async (step, execute) => {
                  await execute(await currentProgram(http, step));
                },
                run,
              }, ctx.signal);
              if ("cancelled" in result) {
                throw new DirectOperationCancelled({ effectRunning: false });
              }
              return result;
            },
            (result) => Result.parse(result),
            ctx.signal,
          );
        },
      },
    },
  };
}
export const behavior = setupDirect();
