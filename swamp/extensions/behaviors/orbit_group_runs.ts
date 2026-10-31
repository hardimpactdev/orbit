import { queryWorkerData } from "./_lib/worker_query.ts";
import { SWAMP_REPOSITORY } from "./native_host_method.ts";
import { z } from "npm:zod@4";
import { currentOwners, groupFingerprint } from "./_lib/group_activity.ts";

const FactorySchema = z.object({
  factory: z.string().min(1),
  preapprovalModel: z.string().min(1),
});
const GroupSchema = FactorySchema.extend({
  workItem: z.string().regex(/^tg-[1-9]\d*$/),
});
const ConflictSchema = z.object({
  workItem: GroupSchema.shape.workItem,
  factories: z.array(z.string()),
  reason: z.string(),
});
type Context = {
  repoDir: string;
  definition: { name: string };
  globalArgs: {
    factories: z.infer<typeof FactorySchema>[];
  };
  queryData: (predicate: string, select?: string) => Promise<unknown[]>;
  runModel?: (
    args: {
      definition: string;
      method: string;
      arguments?: Record<string, unknown>;
    },
  ) => Promise<{
    ok: boolean;
    error?: { message: string };
  }>;
  writeResource: (
    spec: string,
    name: string,
    data: Record<string, unknown>,
  ) => Promise<{ name: string }>;
  logger: {
    warning: (message: string, props?: Record<string, unknown>) => void;
  };
};

export const behavior = {
  type: "@nckrtl/orbit-group-runs",
  version: "2026.10.28.1",
  globalArguments: z.object({
    factories: z.array(FactorySchema),
  }),
  resources: {
    conflict: {
      description:
        "Durable identity for assistance about conflicting active factories",
      schema: z.object({
        workItem: z.string(),
        factories: z.array(z.string()),
        startedAt: z.string(),
      }),
      lifetime: "infinite",
      garbageCollection: 1,
    },
    groups: {
      description:
        "Four admitted owners, rotating discovery cursor and isolated failures",
      schema: z.object({
        groups: z.array(GroupSchema),
        conflicts: z.array(ConflictSchema),
        errors: z.array(z.object({ factory: z.string(), error: z.string() })),
        nextOffset: z.number().int().nonnegative().optional(),
      }),
      lifetime: "infinite",
      garbageCollection: 1,
    },
  },
  methods: {
    discover: {
      outputLifetime: "7d",
      description:
        "Read active official factory states for native per-group fan-out",
      arguments: z.object({}),
      execute: async (_args: Record<string, never>, ctx: Context) => {
        return await discoverGroups({ ...ctx, repoDir: SWAMP_REPOSITORY });
      },
    },
  },
};

export async function discoverGroups(
  ctx: Omit<Context, "repoDir"> & { repoDir?: string },
) {
  const groups: z.infer<typeof GroupSchema>[] = [];
  const activeWorkItems = new Set<string>();
  const conflicts: z.infer<typeof ConflictSchema>[] = [];
  const errors: { factory: string; error: string }[] = [];
  const completed = new Set(
    (await queryWorkerData(
      ctx,
      'modelName == "orbit-gateway" && name.startsWith("schedule-") && attributes.completed == true',
      "attributes.workItem",
    )) as string[],
  );
  for (const factory of ctx.globalArgs.factories) {
    try {
      const overview = await queryWorkerData(
        ctx,
        `modelName == ${
          JSON.stringify(factory.factory)
        } && name.startsWith("state-")`,
        "attributes",
      ) as { workItem: string; status: string }[];
      if (!Array.isArray(overview)) {
        throw new Error("factory overview is missing");
      }
      for (const run of overview) {
        if (run.status !== "active" || completed.has(run.workItem)) {
          continue;
        }
        activeWorkItems.add(run.workItem);
        const activity = (await queryWorkerData(
          ctx,
          `modelName == ${
            JSON.stringify(`orbit-group-${run.workItem}`)
          } && name == "activity"`,
          "attributes",
        ))[0] as { idle?: boolean; fingerprint?: string } | undefined;
        if (
          activity?.idle &&
          activity.fingerprint ===
            await groupFingerprint(
              ctx,
              factory.factory,
              run.workItem,
              factory.preapprovalModel,
            )
        ) continue;
        groups.push(
          GroupSchema.parse({ ...factory, workItem: run.workItem }),
        );
      }
    } catch (e) {
      const error = String(e);
      errors.push({ factory: factory.factory, error });
      ctx.logger.warning("{factory}: group discovery failed: {error}", {
        factory: factory.factory,
        error,
      });
    }
  }
  const counts = new Map<string, number>();
  for (const group of groups) {
    counts.set(group.workItem, (counts.get(group.workItem) ?? 0) + 1);
  }
  for (const [workItem, count] of counts) {
    if (count > 1) {
      const factories = groups.filter((g) => g.workItem === workItem).map((g) =>
        g.factory
      ).sort();
      const reason = `group is active in multiple factories: ${
        factories.join(", ")
      }; park both factories and reconcile their saved operations before continuing`;
      errors.push({ factory: workItem, error: reason });
      try {
        const definition = ctx.definition.name;
        const saved = (await queryWorkerData(
          ctx,
          `modelName == ${JSON.stringify(definition)} && name == ${
            JSON.stringify(`conflict-${workItem}`)
          }`,
          "attributes",
        ))[0] as
          | { workItem: string; factories: string[]; startedAt: string }
          | undefined;
        // Keep one identity across restart and lost assistance replies.
        await ctx.writeResource("conflict", `conflict-${workItem}`, {
          workItem,
          factories,
          startedAt: saved?.startedAt ?? new Date().toISOString(),
        });
        conflicts.push({ workItem, factories, reason });
      } catch (error) {
        errors.push({ factory: workItem, error: String(error) });
        ctx.logger.warning(
          "factory conflict identity could not be saved: {error}",
          {
            error: String(error),
          },
        );
      }
    }
  }
  const eligible = groups.filter((g) => counts.get(g.workItem) === 1);
  const prior = (await queryWorkerData(
    ctx,
    `modelName == ${JSON.stringify(ctx.definition.name)} && name == "groups"`,
    "attributes",
  ))[0] as { nextOffset?: number } | undefined;
  const owners = await currentOwners(ctx, [...activeWorkItems]);
  const offset = (prior?.nextOffset ?? 0) % Math.max(eligible.length, 1);
  const ordered = [...eligible.slice(offset), ...eligible.slice(0, offset)];
  const selected = [
    ...ordered.filter((g) => owners.recover.has(g.workItem)).slice(0, 4),
    ...ordered.filter((g) => !owners.busy.has(g.workItem)).slice(
      0,
      Math.max(0, 4 - owners.busy.size),
    ),
  ].slice(0, 4);
  const handle = await ctx.writeResource("groups", "groups", {
    groups: selected,
    nextOffset: (offset + selected.length) % Math.max(eligible.length, 1),
    conflicts,
    errors,
  });
  return { dataHandles: [handle] };
}
