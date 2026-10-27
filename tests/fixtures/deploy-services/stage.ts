import { z } from "npm:zod@4";

export const behavior = {
  type: "@fixture/deployment-drain",
  version: "2026.10.27.1",
  globalArguments: z.object({}),
  methods: {
    hold: {
      description: "Occupy the scheduler while ordinary cron fires queue",
      arguments: z.object({}),
      execute: async (
        _args: Record<string, never>,
        ctx: { repoDir: string },
      ) => {
        await Deno.writeTextFile(`${ctx.repoDir}/blocked`, "started");
        const deadline = Date.now() + 30000;
        while (true) {
          try {
            await Deno.stat(`${ctx.repoDir}/release`);
            break;
          } catch (error) {
            if (!(error instanceof Deno.errors.NotFound)) throw error;
          }
          if (Date.now() >= deadline) {
            throw new Error("fixture release timed out");
          }
          await new Promise((resolve) => setTimeout(resolve, 50));
        }
        return { dataHandles: [] };
      },
    },
    record: {
      description: "Record a start that must not occur while paused",
      arguments: z.object({}),
      execute: async (
        _args: Record<string, never>,
        ctx: { repoDir: string },
      ) => {
        await Deno.writeTextFile(`${ctx.repoDir}/unexpected-start`, "started");
        return { dataHandles: [] };
      },
    },
  },
};
