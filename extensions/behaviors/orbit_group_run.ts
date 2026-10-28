import { z } from "npm:zod@4";
import { behavior as driver } from "./orbit_driver.ts";
import {
  claimGroupRun,
  clearedFailures,
  ensureGroupRun,
  failedRun,
  type GroupRun,
  GroupRunSchema,
  type NativeGroupRun,
  type RunFailures,
  RunFailuresSchema,
} from "./_lib/group_runs.ts";
import { workflowCommand } from "./_lib/swamp_maintenance.ts";
import { groupFingerprint } from "./_lib/group_activity.ts";

const GlobalArgsSchema = driver.globalArguments.extend({
  factory: z.string().min(1),
  preapprovalModel: z.string().min(1),
  serveUrl: z.string().default("wss://swamp.orbit"),
  serveTokenFile: z.string().default(
    "/home/orbit/.config/swamp/serve-driver-token",
  ),
  caFile: z.string().default(
    "/usr/local/share/ca-certificates/orbit-managed-root-ca.crt",
  ),
  maxCallsPerTick: z.number().int().min(20).max(60).default(60),
  maxConsecutiveFailures: z.number().int().min(1).default(3),
  maxSteps: z.number().int().min(1).max(6).default(6),
});
type Context = Parameters<typeof driver.methods.tick.execute>[1] & {
  globalArgs: z.infer<typeof GlobalArgsSchema>;
  repoDir: string;
};
const RequestSchema = GroupRunSchema.extend({ runId: z.string() });
const RecoverySchema = z.object({
  runId: z.string(),
  attempts: z.number().int().nonnegative(),
  allowed: z.boolean(),
  reason: z.string().nullable(),
  updatedAt: z.string(),
});
type Recovery = z.infer<typeof RecoverySchema>;
async function read<T>(ctx: Context, name: string): Promise<T | null> {
  return (await ctx.queryData(
    `modelName == ${JSON.stringify(ctx.definition.name)} && name == ${
      JSON.stringify(name)
    }`,
    "attributes",
  ))[0] as T | undefined ?? null;
}

/** Only public run inputs and receipts cross this boundary. CLI owns credentials. */
async function command(
  ctx: Context,
  args: string[],
  input?: Record<string, unknown>,
) {
  return await workflowCommand(
    ctx as unknown as Parameters<typeof workflowCommand>[0],
    args,
    input,
  );
}
const getRun = async (ctx: Context, id: string) =>
  await command(ctx, [
    "workflow",
    "history",
    "get",
    id,
  ]) as unknown as NativeGroupRun;

async function notifyFailure(
  ctx: Context,
  intent: GroupRun,
  failure: RunFailures,
) {
  if (!ctx.globalArgs.assistanceModel) return;
  const result = await ctx.runModel({
    definition: ctx.globalArgs.assistanceModel,
    method: "assistance",
    arguments: {
      workItem: intent.workItem,
      factory: intent.factory,
      driverDefinition: ctx.definition.name,
      parked: failure.parked,
      stage: "driver",
      cycle: failure.generation,
      gateIds: failure.parked ? [`driver-retry-g${failure.generation}`] : [],
      reason: failure.parked
        ? `${failure.consecutive} consecutive group runs failed: ${failure.reason}; inspect the failed run, then explicitly retry ${ctx.definition.name}`
        : "",
    },
  });
  if (!result.ok) throw new Error("group failure assistance update failed");
}

async function recordFailure(ctx: Context, intent: GroupRun, reason: string) {
  const failure = failedRun(
    await read<RunFailures>(ctx, "failures"),
    intent,
    reason,
    ctx.globalArgs.maxConsecutiveFailures,
  );
  await ctx.writeResource("failures", "failures", failure);
  if (failure.parked) await notifyFailure(ctx, intent, failure);
  return failure;
}

export async function settleGroupRun(
  ctx: Context,
  intent: GroupRun,
  run: NativeGroupRun,
) {
  const previous = await read<RunFailures>(ctx, "failures");
  if (run.status === "succeeded") {
    await ctx.writeResource("failures", "failures", clearedFailures(intent));
    return true;
  }
  if (previous?.lastKey === intent.key && previous.lastOutcome === "retry") {
    await notifyFailure(ctx, intent, previous);
    return true;
  }
  if (run.status === "failed") {
    const failure = await recordFailure(
      ctx,
      intent,
      previous?.lastKey === intent.key && previous.reason
        ? previous.reason
        : run.failureReason ?? `workflow ${run.id} failed`,
    );
    return !failure.parked;
  }
  if (previous?.parked) {
    await notifyFailure(ctx, intent, previous);
    return false;
  }
  return true;
}

export const behavior = {
  type: "@nckrtl/orbit-group-run",
  version: "2026.10.28.1",
  globalArguments: GlobalArgsSchema,
  resources: {
    ...driver.resources,
    activity: {
      description:
        "Parked input identity; no new owner until its inputs change",
      schema: z.object({ idle: z.boolean(), fingerprint: z.string() }),
      lifetime: "infinite",
      garbageCollection: 1,
    },
    failures: {
      description:
        "Consecutive owner failures and fresh assistance state, counted once per generation",
      schema: RunFailuresSchema,
      lifetime: "infinite",
      garbageCollection: 1,
    },
    recovery: {
      description: "Reserved checkpoint attempts for the current native owner",
      schema: RecoverySchema,
      lifetime: "infinite",
      garbageCollection: 1,
    },
    retry: {
      description:
        "Explicit retry decision for a parked driver generation; grants no factory approval",
      schema: z.object({
        workItem: z.string(),
        gateId: z.string(),
        stageId: z.literal("driver"),
        cycle: z.number().int().positive(),
        actor: z.string().min(1),
        decision: z.literal("approved"),
        decidedAt: z.string(),
        note: z.string(),
      }),
      lifetime: "infinite",
      garbageCollection: 1,
    },
    intent: {
      description: "Next or current group generation, saved before launch",
      schema: GroupRunSchema,
      lifetime: "infinite",
      garbageCollection: 1,
    },
    owner: {
      description: "Only workflow run admitted to drive this group generation",
      schema: RequestSchema,
      lifetime: "infinite",
      garbageCollection: 1,
    },
    admission: {
      description: "Admission result pinned to this workflow step",
      schema: z.object({ admitted: z.boolean() }),
      lifetime: "30d",
      garbageCollection: 1,
    },
    result: {
      description:
        "Whether bounded group work reached a terminal factory stage",
      schema: z.object({ terminal: z.boolean() }),
      lifetime: "30d",
      garbageCollection: 1,
    },
    reconcile: {
      description:
        "Native owner run that may need checkpoint recovery or resume",
      schema: z.object({ runId: z.string().nullable() }),
      lifetime: "infinite",
      garbageCollection: 1,
    },
  },
  methods: {
    ensure: {
      outputLifetime: "7d",
      description: "Ensure one owning run for this group, keyed by generation",
      arguments: z.object({ workItem: GroupRunSchema.shape.workItem }),
      execute: async (args: { workItem: string }, ctx: Context) => {
        const state = (await ctx.queryData(
          `modelName == ${JSON.stringify(ctx.globalArgs.factory)} && name == ${
            JSON.stringify(`state-${args.workItem}`)
          }`,
          "attributes",
        ))[0] as { status?: string } | undefined;
        if (state?.status === "terminal") {
          return {
            dataHandles: [
              await ctx.writeResource("reconcile", "reconcile", {
                runId: null,
              }),
            ],
          };
        }
        const failure = await read<RunFailures>(ctx, "failures");
        const intent = await read<GroupRun>(ctx, "intent");
        if (failure?.parked && intent) {
          await ctx.writeResource("failures", "failures", {
            ...failure,
            updatedAt: new Date().toISOString(),
          });
          await notifyFailure(ctx, intent, failure);
          const handle = await ctx.writeResource("reconcile", "reconcile", {
            runId: null,
          });
          return { dataHandles: [handle] };
        }
        const result = await ensureGroupRun(
          args.workItem,
          ctx.globalArgs.factory,
          {
            read: () => read<GroupRun>(ctx, "intent"),
            save: async (intent) => {
              await ctx.writeResource("intent", "intent", intent);
            },
            owner: () => read<GroupRun>(ctx, "owner"),
            settled: (intent, run) => settleGroupRun(ctx, intent, run),
            get: (id) => getRun(ctx, id),
            search: async (key) => {
              const result = await command(ctx, [
                "workflow",
                "history",
                "search",
                "--workflow",
                "group-run",
                "--input",
                `key=${key}`,
              ]);
              return await Promise.all(
                (result.results as { runId: string }[]).map((r) =>
                  getRun(ctx, r.runId)
                ),
              );
            },
            launch: async (intent) => {
              const result = await command(ctx, [
                "workflow",
                "run",
                "group-run",
                "--detach",
                "--no-supersede",
                "--stdin",
              ], {
                ...intent,
                definition: ctx.definition.name,
                preapprovalModel: ctx.globalArgs.preapprovalModel,
              });
              if (typeof result.runId !== "string") {
                throw new Error("group workflow launch has no run id");
              }
              return result.runId;
            },
          },
        );
        const handle = await ctx.writeResource("reconcile", "reconcile", {
          runId: result.resume,
        });
        return { dataHandles: [handle] };
      },
    },
    claim: {
      outputLifetime: "7d",
      description: "Admit one workflow run before any group operation",
      arguments: RequestSchema,
      execute: async (request: z.infer<typeof RequestSchema>, ctx: Context) => {
        const admitted = claimGroupRun(
          await read<GroupRun>(ctx, "intent"),
          await read<GroupRun>(ctx, "owner"),
          request,
        );
        if (admitted) await ctx.writeResource("owner", "owner", request);
        const handle = await ctx.writeResource("admission", "admission", {
          admitted,
        });
        return { dataHandles: [handle] };
      },
    },
    drive: {
      outputLifetime: "7d",
      description: "Advance only the owning generation within its call budget",
      arguments: RequestSchema,
      execute: async (request: z.infer<typeof RequestSchema>, ctx: Context) => {
        const owner = await read<GroupRun>(ctx, "owner");
        if (
          !owner || owner.runId !== request.runId || !claimGroupRun(
            await read<GroupRun>(ctx, "intent"),
            owner,
            request,
          )
        ) throw new Error("group workflow does not own this generation");
        const failure = await read<RunFailures>(ctx, "failures");
        if (failure?.parked) {
          await ctx.writeResource("failures", "failures", {
            ...failure,
            updatedAt: new Date().toISOString(),
          });
          await notifyFailure(ctx, request, failure);
          throw new Error("group driver is parked; explicit retry required");
        }
        let item: { waiting: string | null; error?: string };
        try {
          await driver.methods.tick.execute({
            workItems: [request.workItem],
            maxSteps: ctx.globalArgs.maxSteps,
          }, ctx);
          const tick = await read<{ items: typeof item[] }>(ctx, "tick");
          if (!tick?.items[0]) {
            throw new Error("group driver returned no tick entry");
          }
          item = tick.items[0];
          if (item.error) throw new Error(item.error);
        } catch (error) {
          try {
            await recordFailure(ctx, request, String(error));
          } catch (notificationError) {
            ctx.logger.warning(
              "group failure assistance remains pending: {error}",
              { error: String(notificationError) },
            );
          }
          throw error;
        }
        await ctx.writeResource("activity", "activity", {
          idle: (item.waiting ?? "").startsWith("assistance:"),
          fingerprint: await groupFingerprint(
            ctx,
            request.factory,
            request.workItem,
            ctx.globalArgs.preapprovalModel,
          ),
        });
        const handle = await ctx.writeResource("result", "result", {
          terminal: (item.waiting ?? "").startsWith("terminal ("),
        });
        return { dataHandles: [handle] };
      },
    },
    retry: {
      outputLifetime: "7d",
      description:
        "Explicitly retry a parked driver after inspection; changes no factory state or effect",
      arguments: z.object({
        actor: z.string().trim().min(1),
        note: z.string().default(""),
      }),
      execute: async (args: { actor: string; note: string }, ctx: Context) => {
        const failure = await read<RunFailures>(ctx, "failures");
        const intent = await read<GroupRun>(ctx, "intent");
        if (!failure?.parked || !intent || failure.lastKey !== intent.key) {
          throw new Error("group driver is not parked at this generation");
        }
        await ctx.writeResource("retry", "retry", {
          workItem: intent.workItem,
          gateId: `driver-retry-g${failure.generation}`,
          stageId: "driver",
          cycle: failure.generation,
          actor: args.actor,
          decision: "approved",
          decidedAt: new Date().toISOString(),
          note: args.note,
        });
        const cleared = {
          ...clearedFailures(intent),
          lastOutcome: "retry" as const,
        };
        const recovery = await read<Recovery>(ctx, "recovery");
        if (recovery) {
          await ctx.writeResource("recovery", "recovery", {
            ...recovery,
            attempts: 0,
            allowed: false,
            reason: null,
            updatedAt: new Date().toISOString(),
          });
        }
        const handle = await ctx.writeResource("failures", "failures", cleared);
        await notifyFailure(ctx, intent, cleared);
        return { dataHandles: [handle] };
      },
    },
    checkpoint: {
      outputLifetime: "7d",
      description:
        "Reserve and settle bounded recovery attempts under the owner lock",
      arguments: RequestSchema.extend({
        phase: z.enum(["begin", "failed", "recovered"]),
      }),
      execute: async (
        args: z.infer<typeof RequestSchema> & {
          phase: "begin" | "failed" | "recovered";
        },
        ctx: Context,
      ) => {
        const intent = await read<GroupRun>(ctx, "intent");
        if (
          intent?.runId !== args.runId || !claimGroupRun(
            intent,
            await read<GroupRun>(ctx, "owner"),
            args,
          )
        ) throw new Error("checkpoint recovery does not own this generation");
        const saved = await read<Recovery>(ctx, "recovery");
        let attempts = saved?.runId === args.runId ? saved.attempts : 0;
        let failure = await read<RunFailures>(ctx, "failures");
        const exhausted = attempts >= ctx.globalArgs.maxConsecutiveFailures;
        const parked = failure?.parked ||
          (args.phase !== "recovered" && exhausted);
        if (args.phase === "begin" && !parked) attempts++;
        if (args.phase === "recovered" && !failure?.parked) attempts = 0;
        const reason = parked
          ? `checkpoint recovery failed or was interrupted ${attempts} times for run ${args.runId}`
          : args.phase === "failed"
          ? "checkpoint recovery or resume failed"
          : null;
        const handle = await ctx.writeResource("recovery", "recovery", {
          runId: args.runId,
          attempts,
          allowed: args.phase === "begin" && !parked,
          reason,
          updatedAt: new Date().toISOString(),
        });
        if (parked) {
          failure = {
            ...failedRun(
              failure,
              intent,
              reason!,
              ctx.globalArgs.maxConsecutiveFailures,
            ),
            parked: true,
          };
          await ctx.writeResource("failures", "failures", failure);
          await notifyFailure(ctx, intent, failure);
        }
        return { dataHandles: [handle] };
      },
    },
    recover: {
      outputLifetime: "7d",
      description:
        "Recover or resume the owner without holding its behavior lock",
      arguments: z.object({
        definition: z.string(),
        runId: z.string().nullable(),
      }),
      execute: async (
        args: { definition: string; runId: string | null },
        ctx: Context,
      ) => {
        if (!args.runId) return { dataHandles: [] };
        const run = await getRun(ctx, args.runId);
        if (run.inputs.definition !== args.definition) {
          throw new Error("group recovery definition differs from the run");
        }
        if (
          run.status !== "interrupted" &&
          !(run.status === "suspended" && run.awaitingResume)
        ) {
          return { dataHandles: [] };
        }
        const request = RequestSchema.parse({ ...run.inputs, runId: run.id });
        const checkpoint = async (phase: "begin" | "failed" | "recovered") => {
          const result = await ctx.runModel({
            definition: args.definition,
            method: "checkpoint",
            arguments: { ...request, phase },
          });
          if (!result.ok) {
            throw new Error("group recovery checkpoint update failed");
          }
        };
        // Reserve before the CLI: a killed recovery spends an attempt too.
        await checkpoint("begin");
        const recovery = (await ctx.queryData(
          `modelName == ${
            JSON.stringify(args.definition)
          } && name == "recovery"`,
          "attributes",
        ))[0] as Recovery | undefined;
        if (!recovery?.allowed || recovery.runId !== run.id) {
          return { dataHandles: [] };
        }
        try {
          if (run.status === "interrupted") {
            // Native recovery is local to the same repository as serve.
            const result = await new Deno.Command(Deno.execPath(), {
              args: [
                "workflow",
                "recover",
                "group-run",
                "--run",
                run.id,
                "--repo-dir",
                ctx.repoDir,
                "--json",
              ],
              stdin: "null",
              stdout: "piped",
              stderr: "piped",
            }).output();
            if (!result.success) {
              throw new Error("group workflow checkpoint recovery failed");
            }
          }
          await command(ctx, [
            "workflow",
            "resume",
            "group-run",
            "--run",
            run.id,
          ]);
          await checkpoint("recovered");
        } catch (error) {
          await checkpoint("failed");
          throw error;
        }
        return { dataHandles: [] };
      },
    },
  },
};
