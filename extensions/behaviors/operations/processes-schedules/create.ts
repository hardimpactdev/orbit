import { z } from "npm:zod@4.4.3";
import type { Http } from "../../automatic-deploy/gateway.ts";
import { Globals } from "../../operation-runner/mod.ts";
import {
  DirectArgs,
  directOperation,
  GatewayOperation,
} from "../_lib/direct.ts";
import { localAgent } from "./agent.ts";
import { installProcess, ProcessInstallation } from "./process-installation.ts";
import { Preparation, startActions, StartInput } from "./start.ts";

const Args = DirectArgs.extend({
  kind: z.literal("process.create"),
  node_id: z.number().int().positive(),
});
export const Creation = z.object({
  control: StartInput,
  installation: ProcessInstallation,
  caddy_publication: z.unknown().optional(),
}).strict().superRefine((input, ctx) => {
  const a = input.control;
  const b = input.installation;
  if (
    a.process_id !== b.process_id || a.runtime !== b.runtime ||
    a.name !== b.name || a.agent_sha256 !== b.agent_sha256 ||
    a.on_demand !== b.on_demand || a.directory !== b.directory
  ) {
    ctx.addIssue({
      code: "custom",
      message: "Process installation identity differs",
    });
  }
});
const Parent = z.object({
  id: z.number().int().positive(),
  kind: Args.shape.kind,
  node_id: z.number().int().positive(),
  status: z.enum(["queued", "running", "succeeded", "failed", "cancelled"]),
  cancel_requested: z.boolean(),
  input: Creation,
});
const Result = z.union([
  z.object({
    ready: z.literal(true),
    state: z.string().regex(/^[a-z][a-z_-]{0,63}$/),
  }).strict(),
  z.object({ error_code: z.string().regex(/^[a-z0-9_.-]{1,100}$/) }).strict(),
]);
type Context = { globalArgs: z.infer<typeof Globals>; signal?: AbortSignal };

/** Same-root installation; only the final native step projects the parent. */
export function createMethods(
  makeHttp: (ctx: Context) => Http,
  invoke: typeof localAgent = localAgent,
  traffic = startActions(),
) {
  return {
    prepareCreate: {
      description:
        "Prepare admitted Process creation before same-root publication",
      arguments: Args,
      execute: async (
        args: z.infer<typeof Args>,
        ctx: Context & {
          writeResource: (
            type: string,
            name: string,
            value: z.infer<typeof Preparation>,
          ) => Promise<unknown>;
        },
      ) => {
        const current = Parent.parse(
          await new GatewayOperation(makeHttp(ctx), args).read(ctx.signal),
        );
        if (
          current.id !== args.operation_id || current.kind !== args.kind ||
          current.node_id !== args.node_id
        ) throw new Error("Process creation parent differs");
        const vite = current.input.control.vite_plan !== undefined;
        let preparation: z.infer<typeof Preparation> = { vite, proceed: false };
        if (
          ["queued", "running"].includes(current.status) &&
          !current.cancel_requested && !ctx.signal?.aborted
        ) {
          const result = await traffic.prepare(
            current.input.control,
            ctx.signal,
          );
          preparation = "error_code" in result
            ? { vite, proceed: false, error_code: result.error_code }
            : { vite, proceed: true };
        }
        const handle = await ctx.writeResource(
          "startPreparation",
          "creation-" + args.run_id,
          preparation,
        );
        return { dataHandles: [handle] };
      },
    },
    installCreate: {
      description:
        "Install the admitted private Process definition and project bounded readiness",
      arguments: Args.extend({
        preparation: Preparation,
        publication_status: z.enum(["succeeded", "failed", "skipped"]),
      }),
      execute: async (
        args: z.infer<typeof Args> & {
          preparation: z.infer<typeof Preparation>;
          publication_status: "succeeded" | "failed" | "skipped";
        },
        ctx: Context,
      ) => {
        const gateway = new GatewayOperation(makeHttp(ctx), args);
        return await directOperation(
          args,
          gateway,
          async (safe) => {
            const input = Creation.parse(safe);
            if (args.preparation.error_code) {
              return {
                error_code: args.preparation.error_code,
              };
            }
            if (!args.preparation.proceed) {
              throw new Error(
                "Process creation was not prepared",
              );
            }
            if (
              input.control.vite_plan !== undefined &&
              args.publication_status !== "succeeded"
            ) return { error_code: "process.caddy_failed" };
            const result = await installProcess(
              input.installation,
              gateway,
              ctx.signal,
              invoke,
            );
            if (
              "error_code" in result ||
              input.installation.desired === "stopped" ||
              input.control.vite_plan === undefined
            ) return result;
            return await traffic.verify(input.control, ctx.signal);
          },
          (result) => Result.parse(result),
          ctx.signal,
        );
      },
    },
  };
}
