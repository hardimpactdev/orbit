import { z } from "npm:zod@4.4.3";
export const behavior = {
  type: "@fixture/recipe-config", version: "2026.11.02.1", globalArguments: z.object({ nodeHost: z.string(), path: z.string() }).passthrough(),
  resources: { state: { schema: z.record(z.string(), z.unknown()), lifetime: "1d", garbageCollection: 10 } },
  methods: { apply: { description: "Hold official file effects at the transport boundary", arguments: z.object({}),
    execute: async (_: unknown, ctx: { globalArgs: Record<string, unknown>; writeResource(s: string, n: string, v: unknown): Promise<unknown> }) => {
      const state = { status: "compliant", changes: [] };
      Deno.writeTextFileSync("calls.jsonl", JSON.stringify("cfgmgmt-file:" + ctx.globalArgs.path) + "\n", { append: true, create: true });
      return { dataHandles: [await ctx.writeResource("state", String(ctx.globalArgs.nodeHost), state)] };
    } } },
};
