import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import { Globals } from "../../operation-runner/mod.ts";
import {
  DirectArgs,
  directOperation,
  DirectOperationCancelled,
  GatewayOperation,
} from "../_lib/direct.ts";
import { type NodeCall, runNodeAgent } from "../_lib/node-agent.ts";

const key = z.string().min(1).refine((value) =>
  new TextEncoder().encode(value).length <= 1024
);
const Artifact = z.object({
  manager: z.enum(["composer", "npm"]),
  package: z.string(),
  version: z.string(),
  url: z.string().url(),
  integrity: z.string().nullable(),
  key,
}).strict();
const emptyMap = <T extends z.ZodType>(value: T) =>
  z.preprocess(
    (raw) => Array.isArray(raw) && raw.length === 0 ? {} : raw,
    z.record(z.string(), value),
  );
export const CacheIntent = z.object({
  agent_sha256: z.string().regex(/^[a-f0-9]{64}$/),
  artifacts: z.array(Artifact).max(4096 * 128),
  conflicts: emptyMap(z.literal("IntegrityConflict")),
  max_bytes: z.number().int().positive(),
}).strict();
type Intent = z.infer<typeof CacheIntent>;
type Batch = {
  artifacts: Intent["artifacts"];
  conflicts: Record<string, string>;
};
const Args = DirectArgs.extend({
  kind: z.literal("task_vm.fill_package_cache"),
  node_id: z.number().int().positive(),
});
const Final = z.union([
  z.object({ ready: z.literal(true) }).strict(),
  z.object({ error_code: z.literal("task_vm.package_cache_failed") }).strict(),
]);
const Reply = z.object({
  hashes: emptyMap(z.string().regex(/^sha256-[a-f0-9]{64}$/)),
  failures: emptyMap(z.string().regex(/^[\x20-\x7e]{1,160}$/)),
}).strict();

/** Native partitioning keeps each projection below the shared 256 KiB result limit. */
export function cacheBatches(input: Intent): Batch[] {
  const batches: Batch[] = [];
  let batch: Batch = { artifacts: [], conflicts: {} };
  let bytes = 256;
  const seen = new Set<string>();
  for (
    const [name, artifact] of [
      ...input.artifacts.map((item) => [item.key, item] as const),
      ...Object.entries(input.conflicts),
    ]
  ) {
    if (seen.has(name) || !key.safeParse(name).success) {
      throw new Error("Invalid cache identity");
    }
    seen.add(name);
    // Gateway result limits count PHP JSON's escaped Unicode and forward slashes.
    const encoded = JSON.stringify(name).replaceAll("/", "\\/")
      .replace(
        /[\u0080-\uffff]/g,
        (letter) => `\\u${letter.charCodeAt(0).toString(16).padStart(4, "0")}`,
      );
    const cost = encoded.length + 324;
    if (
      bytes + cost > 196608 ||
      batch.artifacts.length + Object.keys(batch.conflicts).length === 4096
    ) {
      batches.push(batch);
      batch = { artifacts: [], conflicts: {} };
      bytes = 256;
    }
    if (typeof artifact === "string") {
      batch.conflicts = { ...batch.conflicts, [name]: artifact };
    } else batch.artifacts.push(artifact);
    bytes += cost;
  }
  batches.push(batch);
  if (batches.length > 128) {
    throw new Error("Cache inventory exceeds its bound");
  }
  return batches;
}

type CacheGateway = Pick<
  GatewayOperation,
  "read" | "project" | "withPrivateMaterial"
>;
type Action = (
  call: NodeCall,
  signal?: AbortSignal,
) => Promise<Record<string, unknown>>;

/** One root run; only public batch outcomes cross the result boundary. */
export async function fillNativeCache(
  input: Intent,
  gateway: CacheGateway,
  action: Action,
  signal?: AbortSignal,
) {
  const batches = cacheBatches(input);
  let failed = false;
  for (const [index, batch] of batches.entries()) {
    if (signal?.aborted) {
      throw new DirectOperationCancelled({ effectRunning: false });
    }
    const current = z.object({ cancel_requested: z.boolean() }).parse(
      await gateway.read(signal),
    );
    if (current.cancel_requested || signal?.aborted) {
      throw new DirectOperationCancelled({ effectRunning: false });
    }
    let outcome: Record<string, unknown> | undefined;
    await gateway.withPrivateMaterial(async (material) => {
      const token = z.string().nullable().parse(material.github_token);
      const raw = await action({
        name: "package-cache.host",
        pin: input.agent_sha256,
        sudo: true,
        mutates: true,
        deadlineMs: 1_700_000,
        maxOutputBytes: 256 * 1024,
        input: {
          operation: "fill",
          artifacts: batch.artifacts,
          max_bytes: input.max_bytes,
          github_token: token,
        },
      }, signal);
      // The agent's diagnostic text and callback errors never become native outputs.
      const keys = batch.artifacts.map((item) => item.key);
      const reply = "error_code" in raw
        ? {
          hashes: {},
          failures: Object.fromEntries(
            keys.map((name) => [name, "FillRefused"]),
          ),
        }
        : Reply.parse(raw);
      if (
        keys.some((name) =>
          Object.hasOwn(reply.hashes, name) ===
            Object.hasOwn(reply.failures, name)
        ) ||
        Object.keys({ ...reply.hashes, ...reply.failures }).some((name) =>
          !keys.includes(name)
        )
      ) {
        throw new Error("Invalid cache result identity");
      }
      const failures = { ...reply.failures, ...batch.conflicts };
      failed ||= Object.keys(failures).length > 0;
      outcome = {
        replace_inventory: index === 0,
        archive_ids: Object.keys(reply.hashes),
        archive_digests: Object.values(reply.hashes),
        failed_ids: Object.keys(failures),
        failure_reasons: Object.values(failures).map((reason) =>
          token ? reason.split(token).join("[REDACTED]") : reason
        ),
      };
    });
    await gateway.project({
      status: "running",
      started_at: new Date().toISOString(),
      result: outcome,
    });
  }
  return failed
    ? { error_code: "task_vm.package_cache_failed" }
    : { ready: true };
}

type Context = { globalArgs: z.infer<typeof Globals>; signal?: AbortSignal };
export function cacheFillDirect(
  makeHttp = (ctx: Context) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
  action: Action = runNodeAgent,
) {
  return {
    type: "@nckrtl/task-vm-cache-fill",
    version: "2026.10.31.1",
    globalArguments: Globals,
    methods: {
      execute: {
        description:
          "Fill the admitted host cache in one native run on its exact Node",
        arguments: Args,
        execute: async (args: z.infer<typeof Args>, ctx: Context) => {
          const gateway = new GatewayOperation(makeHttp(ctx), args);
          return await directOperation(
            args,
            gateway,
            (raw) =>
              fillNativeCache(
                CacheIntent.parse(raw),
                gateway,
                action,
                ctx.signal,
              ),
            (result) => Final.parse(result),
            ctx.signal,
          );
        },
      },
    },
  };
}
export const behavior = {
  ...cacheFillDirect(),
  type: "@nckrtl/task-vm-cache-fill",
};
