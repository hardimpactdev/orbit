import { z } from "npm:zod@4.4.3";
import { gatewayHttp, type Http } from "../../automatic-deploy/gateway.ts";
import { Globals } from "../../operation-runner/mod.ts";
import {
  DirectArgs,
  directOperation,
  GatewayOperation,
} from "../_lib/direct.ts";
import { type NodeCall, runNodeAgent } from "../_lib/node-agent.ts";

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
  version: Version,
  previous_version: Version,
  agent_sha256: Digest,
  environment_sha256: Digest,
  health_url: z.string().url(),
  before: Container,
  after: Container,
}).strict();
const Environment = z.record(z.string(), z.string());
const Args = DirectArgs.extend({
  kind: z.literal("analytics.update"),
  node_id: z.number().int().positive(),
  environment: z.preprocess((value) => {
    if (typeof value !== "string") return value;
    try {
      return JSON.parse(value);
    } catch {
      return null;
    }
  }, Environment).meta({ sensitive: true }),
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
type Invoke = (
  call: NodeCall,
  signal?: AbortSignal,
) => Promise<Record<string, unknown>>;
type Context = { globalArgs: z.infer<typeof Globals>; signal?: AbortSignal };

/** The agent keeps its Process lock and rollback container through readiness verification. */
export async function updateRuntime(
  raw: unknown,
  environment: z.infer<typeof Environment>,
  invoke: Invoke = runNodeAgent,
  signal?: AbortSignal,
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
  const changed = input.before.spec !== input.after.spec;
  const effect = await invoke(
    changed
      ? {
        name: "process.converge-container",
        input: {
          ...input.after,
          environment,
          expected_spec: input.before.spec,
          expected_running: true,
          health: { url: input.health_url, attempts: 90, interval_seconds: 2 },
        },
        pin: input.agent_sha256,
        sudo: true,
        mutates: true,
        deadlineMs: 900_000,
      }
      : {
        name: "http.await-healthy",
        input: { url: input.health_url, attempts: 90, interval_seconds: 2 },
        pin: input.agent_sha256,
        sudo: false,
        mutates: false,
        deadlineMs: 600_000,
      },
    signal,
  );
  if (effect.error_code !== undefined) {
    // The agent restores the prior container before releasing its Process lock.
    // Never send a second mutation after a refusal or uncertain transport outcome.
    const restored = !changed || effect.restored === true ||
      [
        "process.activation_failed",
        "process.spec_changed",
        "process.invalid",
        "process.name_collision",
        "agent.version_mismatch",
      ].includes(String(effect.error_code));
    return {
      error_code: restored
        ? "analytics.update_failed"
        : "analytics.rollback_failed",
      rolled_back: restored,
    };
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
  invoke: Invoke = runNodeAgent,
) {
  return {
    type: "@nckrtl/analytics-update-direct",
    version: "2026.10.30.1",
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
              return await updateRuntime(
                fresh.input,
                args.environment,
                invoke,
                ctx.signal,
              );
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
