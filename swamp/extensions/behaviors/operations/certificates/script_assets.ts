import { z } from "npm:zod@4.4.3";

type Context = {
  extensionFile: (path: string) => string;
  writeResource: (
    spec: string,
    name: string,
    value: unknown,
  ) => Promise<unknown>;
};

const Assets = z.object({ sign: z.string() });

/** The signer's path on 209. Official Node steps own every effect; this reads no input and changes nothing. */
export const behavior = {
  type: "@nckrtl/certificate-script-assets",
  version: "2026.11.02.1",
  resources: {
    assets: { schema: Assets, lifetime: "infinite", garbageCollection: 10 },
  },
  methods: {
    read: {
      description: "Resolve the cached signer that 209 runs",
      arguments: z.object({}),
      execute: async (_args: Record<string, never>, ctx: Context) => {
        const assets = Assets.parse({
          sign: ctx.extensionFile("scripts/sign.py"),
        });
        await ctx.writeResource("assets", "assets", assets);
        return assets;
      },
    },
  },
};
