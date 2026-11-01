import { z } from "npm:zod@4.4.3";
import { localProcessAction } from "./native-action.ts";
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
type Kind = "process.start" | "process.restart";
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
  invoke: typeof localProcessAction = localProcessAction,
  wait = pause,
) {
  const call = (input: z.infer<typeof StartInput>, signal?: AbortSignal) =>
  (
    name: Parameters<typeof localProcessAction>[0],
    data: Record<string, unknown>,
  ) => invoke(name, data, input.agent_sha256, signal);
  const refusal = (result: Record<string, unknown>) =>
    result.error_code === undefined
      ? null
      : { error_code: Code.parse(result.error_code) };
  const verify = async (
    admitted: z.infer<typeof StartInput>,
    signal?: AbortSignal,
    wake = false,
  ): Promise<Result> => {
    StartInput.parse(admitted);
    const run = call(admitted, signal);
    const artifact = {
      process_id: admitted.process_id,
      runtime: admitted.runtime,
      name: admitted.name,
    };
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
      if (wake) {
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
  };
  return {
    verify,
    prepare: async (
      input: z.infer<typeof StartInput>,
      signal?: AbortSignal,
      kind: Kind = "process.start",
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
      kind: Kind = "process.start",
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
      return await verify(admitted, signal, kind === "process.start");
    },
  };
}
