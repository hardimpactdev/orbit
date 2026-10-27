import { z } from "npm:zod@4";
export const behavior = {
  type: "@fixture/group-stage",
  version: "2026.10.27.1",
  globalArguments: z.object({}),
  resources: {
    job: {
      description: "Saved effect receipt",
      schema: z.object({
        state: z.string(),
        launchedNow: z.boolean(),
        launches: z.number(),
        artifacts: z.record(z.string(), z.unknown()),
        evidence: z.record(z.string(), z.unknown()),
      }),
      lifetime: "infinite",
      garbageCollection: 10,
    },
    starts: {
      description: "Effect start count",
      schema: z.object({ count: z.number() }),
      lifetime: "infinite",
      garbageCollection: 10,
    },
  },
  methods: {
    work: {
      description: "One keyed fixture effect",
      arguments: z.object({ workItem: z.string(), key: z.string() }),
      execute: async (args: { workItem: string; key: string }, ctx: {
        repoDir: string;
        readResource: (name: string) => Promise<Record<string, unknown> | null>;
        writeResource: (
          spec: string,
          name: string,
          data: Record<string, unknown>,
        ) => Promise<unknown>;
      }) => {
        if (args.workItem === "tg-9") throw new Error("fixture group failure");
        if (!await ctx.readResource(`job-${args.key}`)) {
          const starts = await ctx.readResource("starts");
          await ctx.writeResource("starts", "starts", {
            count: Number(starts?.count ?? 0) + 1,
          });
          await ctx.writeResource("job", `job-${args.key}`, {
            state: "succeeded",
            launchedNow: false,
            launches: 1,
            artifacts: {},
            evidence: { status: "succeeded", runId: args.key },
          });
          if (args.workItem === "tg-7") {
            await Deno.writeTextFile(
              `${ctx.repoDir}/effect-receipt-written`,
              args.key,
            );
            await new Promise((resolve) => setTimeout(resolve, 60000));
          }
        }
        return { dataHandles: [] };
      },
    },
  },
};
