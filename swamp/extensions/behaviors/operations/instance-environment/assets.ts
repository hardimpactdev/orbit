import { z } from "npm:zod@4.4.3";

const Args = z.object({
  environment: z.record(z.string(), z.unknown()),
  runtime: z.record(z.string(), z.unknown()).nullable(),
}).strict();

/** The shipped scripts and the public plan as their JSON argument; no execution. */
export const behavior = {
  type: "@nckrtl/instance-environment-assets",
  version: "2026.11.02.1",
  resources: {
    assets: {
      schema: z.object({
        environment: z.string(),
        refresh: z.string(),
        plan: z.string(),
        runtime: z.string(),
      }).strict(),
      lifetime: "1d",
      garbageCollection: 10,
    },
  },
  methods: {
    read: {
      description:
        "Resolve the shipped Instance environment and refresh scripts and their public plans",
      arguments: Args,
      execute: async (args: z.infer<typeof Args>, ctx: {
        extensionFile(name: string): string;
        writeResource(
          schema: string,
          name: string,
          value: unknown,
        ): Promise<unknown>;
      }) => {
        const assets = {
          environment: await Deno.readTextFile(
            ctx.extensionFile("scripts/environment.py"),
          ),
          refresh: await Deno.readTextFile(
            ctx.extensionFile("scripts/refresh.py"),
          ),
          plan: JSON.stringify(args.environment),
          runtime: JSON.stringify(args.runtime),
        };
        await ctx.writeResource("assets", "assets", assets);
        return assets;
      },
    },
  },
};
