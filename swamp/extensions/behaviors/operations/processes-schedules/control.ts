import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import { Globals } from "../_lib/direct.ts";
import {
  DirectArgs,
  directOperation,
  GatewayOperation,
} from "../_lib/direct.ts";
import { scheduleSystem } from "./schedule-system.ts";
import {
  installSchedule,
  ScheduleInstallation,
  withScheduleCommand,
} from "./installation.ts";

const InstallArgs = DirectArgs.extend({
  kind: z.literal("schedule.create"),
  node_id: z.number().int().positive(),
});
const Kind = z.enum(["schedule.enable", "schedule.destroy"]);
const Args = DirectArgs.extend({
  kind: Kind,
  node_id: z.number().int().positive(),
});
export const ScheduleIntent = ScheduleInstallation;
const Removal = ScheduleIntent.extend({ cascade: z.boolean() });
const Input = z.union([ScheduleIntent, Removal]);
const Result = z.union([
  z.object({ ready: z.literal(true) }).strict(),
  z.object({ error_code: z.string().regex(/^[a-z0-9_.-]{1,100}$/) }).strict(),
]);
type Context = { globalArgs: z.infer<typeof Globals>; signal?: AbortSignal };
type PrivateMaterial = Pick<GatewayOperation, "withPrivateMaterial">;

/** Protected command bytes stay within this callback and the Node process stdin. */
export async function localControl(
  kind: z.infer<typeof Kind>,
  input: z.infer<typeof Input>,
  privateMaterial: PrivateMaterial,
  signal?: AbortSignal,
  invoke = scheduleSystem,
) {
  let outcome: Record<string, unknown> | undefined;
  const resolved = await withScheduleCommand(
    input,
    privateMaterial,
    async (command) => {
      if (kind === "schedule.destroy") {
        outcome = await invoke("schedule.remove", {
          ...Removal.parse(input),
          command,
        }, signal);
      } else {
        // Replace a verified obsolete runner before activating its public timer.
        outcome = await invoke(
          "schedule.install",
          { ...input, command },
          signal,
        );
        if (outcome.error_code !== undefined) return;
        outcome = await invoke("schedule.activate", {
          id: input.id,
          user: input.user,
          working_directory: input.working_directory,
        }, signal);
      }
    },
  );
  if (resolved !== undefined) return resolved;
  if (!outcome) throw new Error("Schedule action produced no outcome");
  return outcome.error_code === undefined
    ? { ready: true }
    : { error_code: outcome.error_code };
}

export function scheduleControl(
  makeHttp = (ctx: Context) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
  action = localControl,
  installation = installSchedule,
) {
  return {
    type: "@nckrtl/schedule-control",
    version: "2026.10.31.2",
    globalArguments: Globals,
    methods: {
      install: {
        description: "Install one admitted Schedule on its exact Node worker",
        arguments: InstallArgs,
        execute: async (args: z.infer<typeof InstallArgs>, ctx: Context) => {
          const gateway = new GatewayOperation(makeHttp(ctx), args);
          return await directOperation(
            args,
            gateway,
            (safe) =>
              installation(
                ScheduleInstallation.parse(safe),
                gateway,
                ctx.signal,
              ),
            (result) => Result.parse(result),
            ctx.signal,
          );
        },
      },
      control: {
        description: "Apply admitted Schedule control on its exact Node worker",
        arguments: Args,
        execute: async (args: z.infer<typeof Args>, ctx: Context) => {
          const gateway = new GatewayOperation(makeHttp(ctx), args);
          return await directOperation(
            args,
            gateway,
            (input) =>
              action(
                args.kind,
                args.kind === "schedule.destroy"
                  ? Removal.parse(input)
                  : ScheduleIntent.parse(input),
                gateway,
                ctx.signal,
              ),
            (result) => Result.parse(result),
            ctx.signal,
          );
        },
      },
    },
  };
}
export const behavior = scheduleControl();
