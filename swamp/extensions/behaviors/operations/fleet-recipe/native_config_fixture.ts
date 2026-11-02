import { z } from "npm:zod@4.4.3";
const execute = async (_: unknown, ctx: { globalArgs: Record<string, unknown>; writeResource(s: string, n: string, v: unknown): Promise<unknown> }) => {
    const state = { status: "compliant", changes: [] };
    Deno.writeTextFileSync("calls.jsonl", JSON.stringify("cfgmgmt:" + (ctx.globalArgs.path ?? ctx.globalArgs.service ?? "resource")) + "\n", { append: true, create: true });
    return { dataHandles: [await ctx.writeResource("state", String(ctx.globalArgs.nodeHost), state)] };
};
export const behavior = {
    type: "@fixture/recipe-config", version: "2026.11.02.1",
    globalArguments: z.object({ nodeHost: z.string(), path: z.string().optional(), service: z.string().optional() }),
    resources: { state: { schema: z.record(z.string(), z.unknown()), lifetime: "1d", garbageCollection: 10 } },
    methods: {
        apply: { description: "Harmless resource transport boundary", arguments: z.object({}), execute },
        restart: { description: "Harmless systemd transport boundary", arguments: z.object({}), execute },
    },
};
