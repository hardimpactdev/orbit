import { z } from "npm:zod@4.4.3";
import { gatewayHttp, type Http } from "../../automatic-deploy/gateway.ts";
import {
  DirectArgs,
  directOperation,
  GatewayOperation,
  Globals,
} from "../_lib/direct.ts";
import { processSystem } from "../processes-schedules/process-system.ts";
import { initializePlausible } from "./initialization.ts";

const Version = z.string().regex(/^\d+\.\d+\.\d+$/);
const Digest = z.string().regex(/^[a-f0-9]{64}$/);
const Container = z.object({
  process_id: z.number().int().positive(),
  container: z.string().regex(/^orbit-process-[0-9]+-plausible$/),
  desired: z.literal("running"),
  spec: Digest,
  image: z.string().regex(
    /^ghcr\.io\/plausible\/community-edition:v\d+\.\d+\.\d+$/,
  ),
  command: z.array(z.string()).min(1),
  restart: z.string(),
  working_directory: z.string().nullable(),
  ports: z.array(z.string()),
  mounts: z.array(
    z.object({ source: z.string(), target: z.string(), read_only: z.boolean() })
      .strict(),
  ),
}).strict();
export const UpdateInput = z.object({
  process_id: z.number().int().positive(),
  assignment_id: z.number().int().positive(),
  runtime_environment: z.object({
    "$secret": z.literal("analytics.runtime_environment"),
    id: z.number().int().positive(),
  }).strict(),
  version: Version,
  previous_version: Version,
  environment_sha256: Digest,
  health_url: z.string().url(),
  before: Container,
  after: Container,
}).strict();
const Environment = z.record(z.string(), z.string());
const Args = DirectArgs.extend({
  kind: z.literal("analytics.update"),
  node_id: z.number().int().positive(),
});
const Result = z.union([
  z.object({
    ready: z.literal(true),
    version: Version,
    previous_version: Version,
  }).strict(),
  z.object({
    error_code: z.string().regex(/^[a-z0-9_.-]{1,100}$/),
    rolled_back: z.boolean(),
  }).strict(),
]);
type Invoke = ReturnType<typeof processSystem>;
type Context = { globalArgs: z.infer<typeof Globals>; signal?: AbortSignal };

function failedUpdate(effect: Record<string, unknown>) {
  // Canonical recovery finishes before a refusal; never issue a second mutation here.
  const restored = effect.restored === true ||
    [
      "process.health_failed",
      "process.converge_failed",
      "process.candidate_invalid",
      "process.activation_failed",
      "process.spec_changed",
      "process.invalid",
      "process.name_collision",
    ].includes(String(effect.error_code));
  return {
    error_code: restored
      ? "analytics.update_failed"
      : "analytics.rollback_failed",
    rolled_back: restored,
  };
}

/** The canonical Process action owns container recovery and readiness on the placed Node. */
export async function updateRuntime(
  raw: unknown,
  environment: z.infer<typeof Environment>,
  invoke: Invoke = processSystem(),
  signal?: AbortSignal,
  initialize: typeof initializePlausible = initializePlausible,
) {
  const input = UpdateInput.parse(raw);
  if (
    input.before.process_id !== input.process_id ||
    input.after.process_id !== input.process_id ||
    input.before.container !== input.after.container ||
    input.after.image !==
      `ghcr.io/plausible/community-edition:v${input.version}`
  ) throw new Error("Runtime differs from admitted update");
  const bytes = new TextEncoder().encode(
    JSON.stringify(
      Object.fromEntries(
        Object.entries(environment).sort(([a], [b]) =>
          a < b ? -1 : a > b ? 1 : 0
        ),
      ),
    ),
  );
  const digest = Array.from(
    new Uint8Array(await crypto.subtle.digest("SHA-256", bytes)),
    (b) => b.toString(16).padStart(2, "0"),
  ).join("");
  if (digest !== input.environment_sha256) {
    return {
      error_code: "analytics.runtime_environment_changed",
      rolled_back: true,
    };
  }
  const effect = await invoke(
    "process.converge-container",
    {
      ...input.after,
      environment,
      expected_spec: input.before.spec,
      expected_running: true,
      health: { url: input.health_url, attempts: 90, interval_seconds: 2 },
    },
    signal,
    async () => await initialize(input.after, environment, invoke, signal),
  );
  if (effect.error_code !== undefined) {
    if (String(effect.error_code).startsWith("analytics.initialization")) {
      return { error_code: String(effect.error_code), rolled_back: true };
    }
    return failedUpdate(effect);
  }

  return {
    ready: true as const,
    version: input.version,
    previous_version: input.previous_version,
  };
}

export function analyticsUpdate(
  makeHttp = (ctx: Context): Http =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
  invoke: Invoke = processSystem(),
  initialize: typeof initializePlausible = initializePlausible,
) {
  return {
    type: "@nckrtl/analytics-update-direct",
    version: "2026.10.31.2",
    globalArguments: Globals,
    methods: {
      apply: {
        description:
          "Update the admitted Plausible image on its Node and verify health",
        arguments: Args,
        execute: async (args: z.infer<typeof Args>, ctx: Context) => {
          const gateway = new GatewayOperation(makeHttp(ctx), args);
          return await directOperation(
            args,
            gateway,
            async () => {
              const fresh = z.object({ input: UpdateInput }).parse(
                await gateway.read(ctx.signal),
              );
              let observation: z.infer<typeof Result> | undefined;
              await gateway.withPrivateMaterial(async (material) => {
                observation = Result.parse(
                  await updateRuntime(
                    fresh.input,
                    Environment.parse(material.runtime_environment),
                    invoke,
                    ctx.signal,
                    initialize,
                  ),
                );
              });
              return Result.parse(observation);
            },
            (result) => Result.parse(result),
            ctx.signal,
          );
        },
      },
    },
  };
}
export const behavior = analyticsUpdate();
