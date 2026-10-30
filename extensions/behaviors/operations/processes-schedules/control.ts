import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import { Globals } from "../../operation-runner/mod.ts";
import {
  DirectArgs,
  directOperation,
  GatewayOperation,
} from "../_lib/direct.ts";
import { scheduleCall } from "./contract.ts";
import { localAgent } from "./agent.ts";

const Kind = z.enum(["schedule.enable", "schedule.run", "schedule.destroy"]);
const Args = DirectArgs.extend({
  kind: Kind,
  node_id: z.number().int().positive(),
});
const Common = z.object({
  id: z.string().uuid(),
  agent_sha256: z.string().regex(/^[a-f0-9]{64}$/),
});
const ControlInput = Common.extend({
  user: z.string().regex(/^[a-z_][a-z0-9_-]*$/),
  working_directory: z.string().startsWith("/").max(4096),
}).strict();
const RemoveInput = Common.extend({
  group: z.string().regex(/^[a-z_][a-z0-9_-]*$/),
  cascade: z.boolean(),
}).strict();
const Input = z.union([ControlInput, RemoveInput]);
const Result = z.union([
  z.object({ ready: z.literal(true) }).strict(),
  z.object({ error_code: z.string().regex(/^[a-z0-9_.-]{1,100}$/) }).strict(),
]);
type Context = { globalArgs: z.infer<typeof Globals>; signal?: AbortSignal };

/** The Schedule action returns only a bounded domain outcome. */
export async function localControl(
  kind: z.infer<typeof Kind>,
  input: z.infer<typeof Input>,
  signal?: AbortSignal,
) {
  const call = scheduleCall(kind, input);
  const result = await localAgent(
    call.name as "schedule.activate" | "schedule.run" | "schedule.remove",
    call.input,
    input.agent_sha256,
    signal,
  );
  return result.error_code === undefined
    ? { ready: true }
    : { error_code: result.error_code };
}

export function scheduleControl(
  makeHttp = (ctx: Context) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
  action = localControl,
) {
  return {
    type: "@nckrtl/schedule-control",
    version: "2026.10.30.3",
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
                args.kind === "schedule.destroy"
                  ? RemoveInput.parse(input)
                  : ControlInput.parse(input),
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
