import { z } from "npm:zod@4.4.3";
import {
  PublicInstall,
  renderScheduleUnits,
} from "../processes-schedules/contract.ts";
const LifecycleArgs = z.object({
  schedule: PublicInstall,
}).strict();
type Context = {
  extensionFile(name: string): string;
  writeResource(
    schema: string,
    name: string,
    value: unknown,
  ): Promise<unknown>;
};

/** Public native file metadata only; no intent reads or execution. */
export const behavior = {
  type: "@nckrtl/schedule-run-assets",
  version: "2026.11.02.1",
  resources: {
    lifecycle: {
      schema: z.object({ service: z.string(), timer: z.string() }).strict(),
      lifetime: "infinite",
      garbageCollection: 10,
    },
    assets: {
      schema: z.object({ script: z.string() }).strict(),
      lifetime: "infinite",
      garbageCollection: 10,
    },
  },
  methods: {
    lifecycle: {
      description: "Render the canonical public service and timer units",
      arguments: LifecycleArgs,
      execute: async (
        args: z.infer<typeof LifecycleArgs>,
        ctx: Context,
      ) => {
        const result = renderScheduleUnits(args.schedule);
        await ctx.writeResource("lifecycle", "lifecycle", result);
        return result;
      },
    },
    read: {
      description: "Resolve the shipped Schedule check script",
      arguments: z.object({}).strict(),
      execute: async (_args: Record<string, never>, ctx: {
        extensionFile(name: string): string;
        writeResource(
          schema: string,
          name: string,
          value: unknown,
        ): Promise<unknown>;
      }) => {
        const assets = {
          script: await Deno.readTextFile(ctx.extensionFile("scripts/check.py")),
        };
        await ctx.writeResource("assets", "assets", assets);
        return assets;
      },
    },
  },
};
