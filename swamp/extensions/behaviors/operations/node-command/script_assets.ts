import { z } from "npm:zod@4.4.3";

const Assets = z.object({ runner: z.string().min(1) }).strict();
/** Public asset metadata only. Native dispatch ships and fingerprints every file. */
export const behavior = {
  type: "@nckrtl/node-command-assets",
  version: "2026.10.31.1",
  resources: {
    assets: { schema: Assets, lifetime: "infinite", garbageCollection: 10 },
  },
  methods: {
    read: {
      description: "Locate the one shared placed Node shell transport",
      arguments: z.object({}).strict(),
      execute: async (_args: unknown, ctx: {
        extensionFile: (path: string) => string;
        writeResource: (
          spec: string,
          name: string,
          value: unknown,
        ) => Promise<unknown>;
      }) => {
        const result = Assets.parse({
          runner: ctx.extensionFile("scripts/node_command.py"),
        });
        await ctx.writeResource("assets", "assets", result);
        return result;
      },
    },
  },
};
