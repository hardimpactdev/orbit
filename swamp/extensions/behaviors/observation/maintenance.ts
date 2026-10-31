import { z } from "npm:zod@4.4.3";
import { gatewayHttp, type Http } from "../automatic-deploy/gateway.ts";
import {
  DirectArgs,
  directOperation,
  GatewayOperation,
  Globals,
} from "../operations/_lib/direct.ts";
import { ValkeyClient } from "../operations/database/valkey_client.ts";
import { snapshot } from "./maintenance_snapshot.ts";

const Redis = z.object({
  host: z.string().min(1),
  port: z.number().int().positive(),
  database: z.number().int().nonnegative(),
  prefix: z.string().min(1),
  queue_prefix: z.string(),
  trim_job: z.number().int().nonnegative(),
  trim_queue: z.number().int().nonnegative(),
});
const Input = z.object({
  routine: z.enum(["maintenance", "retention"]),
  tasks_enabled: z.boolean(),
  activity_seconds: z.number().int().positive(),
  batch_hours: z.number().int().nonnegative(),
  failed_hours: z.number().int().nonnegative(),
  redis: Redis.nullable(),
  activities: z.array(
    z.object({ id: z.number().int().positive(), created_at: z.string() }),
  ).default([]),
  batches: z.array(
    z.object({
      id: z.string().min(1),
      created_at: z.number().int(),
      finished_at: z.number().int().nullable(),
      cancelled_at: z.number().int().nullable(),
    }),
  ).default([]),
  failed: z.array(
    z.object({ id: z.number().int().positive(), failed_at: z.string() }),
  ).default([]),
});
const Args = DirectArgs.extend({
  kind: z.literal("observation.maintain"),
  node_id: z.number().int().positive(),
});
const Result = z.object({
  activity_ids: z.array(z.number().int().positive()),
  batch_ids: z.array(z.string()),
  failed_ids: z.array(z.number().int().positive()),
  snapshot_jobs: z.number().int().nonnegative(),
  snapshot_queues: z.number().int().nonnegative(),
  error_code: z.literal("observation.metrics_unavailable").optional(),
});
type Context = { globalArgs: z.infer<typeof Globals>; signal?: AbortSignal };
function timestamp(value: string): number {
  const ms = Date.parse(
    /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d+)?$/.test(value)
      ? value.replace(" ", "T") + "Z"
      : value,
  );
  if (!Number.isFinite(ms)) throw new Error("observation.timestamp_invalid");
  return ms;
}
/** Cutoffs, strict comparisons and the interrupted-Activity cap live only in Swamp. */
export function selectMaintenance(
  raw: unknown,
  now: Date = new Date(),
): z.infer<typeof Result> {
  const input = Input.parse(raw),
    result: z.infer<typeof Result> = {
      activity_ids: [],
      batch_ids: [],
      failed_ids: [],
      snapshot_jobs: 0,
      snapshot_queues: 0,
    };
  if (input.routine === "maintenance") {
    result.activity_ids = input.activities.filter((row) =>
      timestamp(row.created_at) <
        (Math.floor(now.getTime() / 1000) - input.activity_seconds) * 1000
    ).sort((a, b) => a.id - b.id).slice(0, 500).map((row) => row.id);
  } else {
    const cutoff = Math.floor(now.getTime() / 1000) - input.batch_hours * 3600;
    if (input.tasks_enabled) {
      result.batch_ids = input.batches.filter((row) =>
        (row.finished_at !== null && row.finished_at < cutoff) ||
        (row.finished_at === null && row.created_at < cutoff) ||
        (row.cancelled_at !== null && row.created_at < cutoff)
      ).map((row) => row.id);
    }
    result.failed_ids = input.failed.filter((row) =>
      timestamp(row.failed_at) <
        (Math.floor(now.getTime() / 1000) - input.failed_hours * 3600) * 1000
    ).map((row) => row.id);
  }
  return result;
}
export function maintenance(
  makeHttp: (ctx: Context) => Http = (ctx) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
  connect: (
    host: string,
    port: number,
  ) => Promise<Pick<ValkeyClient, "command" | "close">> = ValkeyClient.connect,
) {
  return {
    type: "@nckrtl/observation-maintenance",
    version: "2026.10.31.1",
    globalArguments: Globals,
    methods: {
      apply: {
        description:
          "Select bounded Gateway state changes and snapshot Horizon directly in Redis",
        arguments: Args,
        execute: async (args: z.infer<typeof Args>, ctx: Context) => {
          const gateway = new GatewayOperation(makeHttp(ctx), args);
          return await directOperation(
            args,
            gateway,
            async (raw) => {
              const input = Input.parse(raw), result = selectMaintenance(input);
              if (input.routine === "maintenance" && input.redis !== null) {
                try {
                  await gateway.withPrivateMaterial(async (material) => {
                    const credentials = z.object({
                      username: z.string().nullable(),
                      password: z.string().nullable(),
                    }).parse(material.queue_redis);
                    const redis = input.redis!;
                    const client = await connect(redis.host, redis.port);
                    try {
                      if (credentials.password) {
                        await client.command(
                          "AUTH",
                          ...(credentials.username
                            ? [credentials.username]
                            : []),
                          credentials.password,
                        );
                      }
                      await client.command("SELECT", String(redis.database));
                      const counts = await snapshot({
                        command: (args) => client.command(...args),
                      }, redis);
                      result.snapshot_jobs = counts.jobs;
                      result.snapshot_queues = counts.queues;
                    } finally {
                      client.close();
                    }
                  });
                } catch {
                  result.error_code = "observation.metrics_unavailable";
                }
              }
              return result;
            },
            (value) => Result.parse(value),
            ctx.signal,
          );
        },
      },
    },
  };
}
export const behavior = maintenance();
