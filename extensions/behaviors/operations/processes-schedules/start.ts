import { z } from "npm:zod@4.4.3";
import type { Http } from "../../automatic-deploy/gateway.ts";
import {
  type Context as RunnerContext,
  Globals,
} from "../../operation-runner/mod.ts";
import {
  DirectArgs,
  directOperation,
  GatewayOperation,
} from "../_lib/direct.ts";
import { localAgent } from "./agent.ts";
import { Input } from "./process-input.ts";

const Code = z.string().regex(/^[a-z0-9_.-]{1,100}$/);
const Path = z.string().startsWith("/").max(4096);
const Marker = z.string().regex(
  /^\/dev\/shm\/orbit\/hibernation\/app-instance-[1-9][0-9]*\.awake$/,
);
const Plan = z.object({
  suspend: z.object({ operation: z.literal("suspend"), marker: Marker })
    .strict(),
  environment: z.object({
    operation: z.literal("environment"),
    application: Path,
    path: Path,
    instance_id: z.number().int().positive(),
    marker: Marker,
    content: z.string(),
  }).strict(),
  awake: z.object({ operation: z.literal("awake"), marker: Marker }).strict(),
  listener: z.object({
    unit: z.string(),
    port: z.number().int().min(1).max(65535),
    process_id: z.number().int().positive(),
    probe: z.literal(true),
  }).strict(),
}).strict();
export const StartInput = Input.extend({
  vite_plan: Plan.optional(),
  caddy_publication: z.unknown().optional(),
}).superRefine((input, ctx) => {
  const plan = input.vite_plan;
  const vite = input.vite;
  if (vite === null && plan === undefined) return;
  if (
    vite === null || plan === undefined || input.runtime !== "systemd" ||
    plan.environment.instance_id !== vite.instance_id ||
    plan.environment.path !== vite.path ||
    plan.listener.process_id !== input.process_id ||
    plan.listener.unit !== input.name ||
    plan.suspend.marker !==
      `/dev/shm/orbit/hibernation/app-instance-${vite.instance_id}.awake` ||
    plan.awake.marker !== plan.suspend.marker ||
    plan.environment.marker !== plan.suspend.marker ||
    plan.environment.content !==
      `# Orbit Instance ${vite.instance_id}\nORBIT_DEV_SERVER_PORT=${plan.listener.port}\n`
  ) {
    ctx.addIssue({
      code: "custom",
      message: "Vite preparation differs from admitted Process identity",
    });
  }
});
export const Preparation = z.object({
  vite: z.boolean(),
  proceed: z.boolean(),
  error_code: Code.optional(),
}).strict();
const Args = DirectArgs.extend({
  kind: z.enum(["process.start", "process.restart"]),
  node_id: z.number().int().positive(),
});
const Parent = z.object({
  id: z.number().int().positive(),
  kind: Args.shape.kind,
  node_id: z.number().int().positive(),
  status: z.enum(["queued", "running", "succeeded", "failed", "cancelled"]),
  cancel_requested: z.boolean(),
  input: StartInput,
});
type Context = { globalArgs: z.infer<typeof Globals>; signal?: AbortSignal };
type Result = { ready: true; state: string } | { error_code: string };
const pause = (signal?: AbortSignal) =>
  new Promise<void>((resolve, reject) => {
    signal?.throwIfAborted();
    const abort = () => {
      clearTimeout(timer);
      reject(signal?.reason);
    };
    const timer = setTimeout(() => {
      signal?.removeEventListener("abort", abort);
      resolve();
    }, 250);
    signal?.addEventListener("abort", abort, { once: true });
  });

/** Domain preparation/readiness runs locally; publication is a separate canonical native step. */
export function startActions(
  invoke: typeof localAgent = localAgent,
  wait = pause,
) {
  const call =
    (input: z.infer<typeof StartInput>, signal?: AbortSignal) =>
    (name: Parameters<typeof localAgent>[0], data: Record<string, unknown>) =>
      invoke(name, data, input.agent_sha256, signal);
  const refusal = (result: Record<string, unknown>) =>
    result.error_code === undefined
      ? null
      : { error_code: Code.parse(result.error_code) };
  return {
    prepare: async (
      input: z.infer<typeof StartInput>,
      signal?: AbortSignal,
      kind: z.infer<typeof Args>["kind"] = "process.start",
    ) => {
      const admitted = StartInput.parse(input);
      const run = call(admitted, signal);
      if (admitted.directory !== undefined) {
        const release = await run("process.check-release", {
          directory: admitted.directory,
        });
        const error = refusal(release);
        if (error) return error;
        if (release.available !== true) {
          return { error_code: "process.release_unavailable" };
        }
      }
      if (admitted.vite_plan !== undefined) {
        const suspend = refusal(
          await run("vite.set-traffic", admitted.vite_plan.suspend),
        );
        if (suspend) return suspend;
        if (kind === "process.restart") {
          const stop = refusal(
            await run("process.control", {
              process_id: admitted.process_id,
              runtime: admitted.runtime,
              name: admitted.name,
              on_demand: admitted.on_demand,
              operation: "stop",
            }),
          );
          if (stop) return stop;
        }
        const environment = refusal(
          await run("vite.set-traffic", admitted.vite_plan.environment),
        );
        if (environment) return environment;
      }
      return { ready: true as const };
    },
    activate: async (
      input: z.infer<typeof StartInput>,
      signal?: AbortSignal,
      kind: z.infer<typeof Args>["kind"] = "process.start",
    ): Promise<Result> => {
      const admitted = StartInput.parse(input);
      const run = call(admitted, signal);
      const artifact = {
        process_id: admitted.process_id,
        runtime: admitted.runtime,
        name: admitted.name,
      };
      const start = refusal(
        await run("process.control", {
          ...artifact,
          on_demand: admitted.on_demand,
          operation:
            kind === "process.restart" && admitted.vite_plan === undefined
              ? "restart"
              : "start",
        }),
      );
      if (start) return start;
      if (admitted.vite_plan !== undefined) {
        let ready = false;
        for (let check = 0; check < 60; check++) {
          const listener = await run(
            "vite.check-listener",
            admitted.vite_plan.listener,
          );
          const error = refusal(listener);
          if (error) return error;
          if (listener.owned === true && listener.ready === true) {
            ready = true;
            break;
          }
          await wait(signal);
        }
        if (!ready) {
          return refusal(
            await run("process.control", {
              ...artifact,
              on_demand: admitted.on_demand,
              operation: "stop",
            }),
          ) ?? { error_code: "vite.not_ready" };
        }
        if (kind === "process.start") {
          const awake = refusal(
            await run("vite.set-traffic", admitted.vite_plan.awake),
          );
          if (awake) return awake;
        }
      }
      const status = await run("process.status", artifact);
      return refusal(status) ??
        {
          ready: true,
          state: z.string().regex(/^[a-z][a-z_-]{0,63}$/).parse(status.state),
        };
    },
  };
}

export function startMethods(
  makeHttp: (ctx: Context) => Http,
  actions = startActions(),
) {
  return {
    prepareStart: {
      description:
        "Prepare the bound same-root Process start on its admitted Node",
      arguments: Args,
      execute: async (
        args: z.infer<typeof Args>,
        ctx: Context & Pick<RunnerContext, "writeResource">,
      ) => {
        const gateway = new GatewayOperation(makeHttp(ctx), args);
        const current = Parent.parse(await gateway.read(ctx.signal));
        if (
          current.id !== args.operation_id || current.kind !== args.kind ||
          current.node_id !== args.node_id
        ) throw new Error("Process start parent differs");
        const vite = current.input.vite_plan !== undefined;
        let preparation: z.infer<typeof Preparation>;
        if (
          !["queued", "running"].includes(current.status) ||
          current.cancel_requested || ctx.signal?.aborted
        ) {
          preparation = { vite, proceed: false };
        } else {
          const result = await actions.prepare(
            current.input,
            ctx.signal,
            args.kind,
          );
          preparation = "error_code" in result
            ? { vite, proceed: false, error_code: result.error_code }
            : { vite, proceed: true };
        }
        const handle = await ctx.writeResource(
          "startPreparation",
          "start-" + args.run_id,
          preparation,
        );
        return { dataHandles: [handle] };
      },
    },
    activateStart: {
      description:
        "Activate Process start after its same-root native publication and project the result",
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
        return await directOperation(args, gateway, (safe) => {
          const input = StartInput.parse(safe);
          if (args.preparation.error_code) {
            return Promise.resolve({ error_code: args.preparation.error_code });
          }
          if (!args.preparation.proceed) {
            throw new Error("Process start was not prepared");
          }
          if (
            input.vite_plan !== undefined &&
            args.publication_status !== "succeeded"
          ) return Promise.resolve({ error_code: "process.caddy_failed" });
          return actions.activate(input, ctx.signal, args.kind);
        }, (result) =>
          z.union([
            z.object({
              ready: z.literal(true),
              state: z.string().regex(/^[a-z][a-z_-]{0,63}$/),
            }).strict(),
            z.object({ error_code: Code }).strict(),
          ]).parse(result), ctx.signal);
      },
    },
  };
}
