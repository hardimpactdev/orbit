import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import { Globals } from "../../operation-runner/mod.ts";
import {
  DirectArgs,
  directOperation,
  GatewayOperation,
} from "../_lib/direct.ts";
import { localAgent, type LocalCommand } from "./agent.ts";

const Kind = z.enum([
  "process.start",
  "process.stop",
  "process.restart",
  "process.destroy",
]);
const Args = DirectArgs.extend({
  kind: Kind,
  node_id: z.number().int().positive(),
});
const Input = z.object({
  process_id: z.number().int().positive(),
  agent_sha256: z.string().regex(/^[a-f0-9]{64}$/),
  runtime: z.enum(["systemd", "docker"]),
  name: z.string().regex(/^orbit-process-[0-9]+-[a-z0-9-]+(?:\.service)?$/),
  on_demand: z.boolean(),
  directory: z.string().startsWith("/").max(4096).optional(),
  vite: z.null(),
}).strict();
const State = z.string().regex(/^[a-z][a-z_-]{0,63}$/);
const Result = z.union([
  z.object({ ready: z.literal(true), state: State.optional() }).strict(),
  z.object({ error_code: z.string().regex(/^[a-z0-9_.-]{1,100}$/) }).strict(),
]);
type Context = { globalArgs: z.infer<typeof Globals>; signal?: AbortSignal };
type Invoke = (
  name: LocalCommand,
  input: Record<string, unknown>,
  digest: string,
  signal?: AbortSignal,
) => Promise<Record<string, unknown>>;

/** The native behavior lock covers this complete, local Process mutation. */
export function processAction(invoke: Invoke = localAgent) {
  return async (
    kind: z.infer<typeof Kind>,
    input: z.infer<typeof Input>,
    signal?: AbortSignal,
  ) => {
    const artifact = {
      process_id: input.process_id,
      runtime: input.runtime,
      name: input.name,
    };
    const call = (name: LocalCommand, data: Record<string, unknown>) =>
      invoke(name, data, input.agent_sha256, signal);
    if (
      kind !== "process.stop" && kind !== "process.destroy" &&
      input.directory !== undefined
    ) {
      const release = await call("process.check-release", {
        directory: input.directory,
      });
      if (release.error_code !== undefined) {
        return { error_code: release.error_code };
      }
      if (release.available !== true) {
        return { error_code: "process.release_unavailable" };
      }
    }
    const result = kind === "process.destroy"
      ? await call("process.remove", { ...artifact, vite: null })
      : await call("process.control", {
        ...artifact,
        on_demand: input.on_demand,
        operation: kind.slice("process.".length),
      });
    if (result.error_code !== undefined) {
      return { error_code: result.error_code };
    }
    if (kind === "process.destroy") return { ready: true };
    const status = await call("process.status", artifact);
    return status.error_code !== undefined
      ? { error_code: status.error_code }
      : { ready: true, state: State.parse(status.state) };
  };
}
export function processControl(
  makeHttp = (ctx: Context) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
  action = processAction(),
) {
  return {
    type: "@nckrtl/process-control",
    version: "2026.10.30.1",
    globalArguments: Globals,
    methods: {
      control: {
        description: "Apply admitted Process control on its exact Node worker",
        arguments: Args,
        execute: async (args: z.infer<typeof Args>, ctx: Context) => {
          const gateway = new GatewayOperation(makeHttp(ctx), args);
          return await directOperation(
            args,
            gateway,
            (input) => action(args.kind, Input.parse(input), ctx.signal),
            (result) => {
              const bounded = Result.parse(result);
              if (
                !("error_code" in bounded) && args.kind !== "process.destroy" &&
                !("state" in bounded)
              ) {
                throw new Error("Process state is required");
              }
              return bounded;
            },
            ctx.signal,
          );
        },
      },
    },
  };
}
export const behavior = processControl();
