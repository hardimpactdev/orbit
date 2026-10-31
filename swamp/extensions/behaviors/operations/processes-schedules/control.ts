import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import { Globals } from "../_lib/direct.ts";
import {
  DirectArgs,
  directOperation,
  GatewayOperation,
} from "../_lib/direct.ts";
import { scheduleSystem } from "./schedule-system.ts";
import { ScheduleInstallation, withScheduleCommand } from "./installation.ts";

const Kind = z.literal("schedule.enable");
const Args = DirectArgs.extend({
  kind: Kind,
  node_id: z.number().int().positive(),
});
export const ScheduleIntent = ScheduleInstallation;
const Input = ScheduleIntent;
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
) {
  return {
    type: "@nckrtl/schedule-control",
    version: "2026.10.31.3",
    globalArguments: Globals,
    methods: {
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
                ScheduleIntent.parse(input),
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
