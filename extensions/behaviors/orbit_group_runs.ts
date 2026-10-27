import { z } from "npm:zod@4";

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
  runModel: (
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
  version: "2026.10.27.1",
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
      garbageCollection: 10,
    },
    groups: {
      description:
        "All active groups and discovery failures, without a fleet call budget",
      schema: z.object({
        groups: z.array(GroupSchema),
        conflicts: z.array(ConflictSchema),
        errors: z.array(z.object({ factory: z.string(), error: z.string() })),
      }),
      lifetime: "infinite",
      garbageCollection: 10,
    },
  },
  methods: {
    discover: {
      description:
        "Read active official factory states for native per-group fan-out",
      arguments: z.object({}),
      execute: async (_args: Record<string, never>, ctx: Context) => {
        // Native liveness checks settle runs whose owners died before saving a
        // terminal status. This must precede generation reconciliation.
        const doctor = await new Deno.Command(Deno.execPath(), {
          args: ["run", "doctor", "--fix", "--repo-dir", ctx.repoDir, "--json"],
          stdin: "null",
          stdout: "piped",
          stderr: "piped",
        }).output();
        if (!doctor.success) {
          ctx.logger.warning(
            "Swamp run doctor failed; uncertain owners remain held",
          );
        }
        return await discoverGroups(ctx);
      },
    },
  },
};

export async function discoverGroups(ctx: Omit<Context, "repoDir">) {
  const groups: z.infer<typeof GroupSchema>[] = [];
  const conflicts: z.infer<typeof ConflictSchema>[] = [];
  const errors: { factory: string; error: string }[] = [];
  const completed = new Set(
    (await ctx.queryData(
      'modelName == "orbit-gateway" && name.startsWith("schedule-") && attributes.completed == true',
      "attributes.workItem",
    )) as string[],
  );
  for (const factory of ctx.globalArgs.factories) {
    try {
      // The official overview reads by stable factory ID, including runs
      // saved before a factory rename. Never copy or reset factory state.
      const result = await ctx.runModel({
        definition: factory.factory,
        method: "status",
      });
      if (!result.ok) {
        throw new Error(result.error?.message ?? "factory status failed");
      }
      const overview = (await ctx.queryData(
        `modelName == ${
          JSON.stringify(factory.factory)
        } && name == "status-_factory"`,
        "attributes.runs",
      ))[0] as { workItem: string; status: string }[];
      if (!Array.isArray(overview)) {
        throw new Error("factory overview is missing");
      }
      for (const run of overview) {
        if (run.status !== "active" || completed.has(run.workItem)) {
          continue;
        }
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
        const saved = (await ctx.queryData(
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
  const handle = await ctx.writeResource("groups", "groups", {
    groups: groups.filter((g) => counts.get(g.workItem) === 1),
    conflicts,
    errors,
  });
  return { dataHandles: [handle] };
}
