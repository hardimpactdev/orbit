import { z } from "npm:zod@4.4.3";

const Args = z.object({
  intent: z.object({ bootstrap: z.record(z.string(), z.unknown()) }),
});
const Assets = z.object({
  scan_script: z.string(),
  probe_script: z.string(),
  input_json: z.string(),
});
/** Native additionalFiles owns delivery and fingerprinting. This step reads public intent only. */
export const behavior = {
  type: "@nckrtl/node-bootstrap-script-assets",
  version: "2026.10.31.1",
  resources: {
    assets: { schema: Assets, lifetime: "infinite", garbageCollection: 10 },
  },
  methods: {
    read: {
      description:
        "Resolve the admitted public intent and cached native script assets",
      arguments: Args,
      execute: async (args: z.infer<typeof Args>, ctx: {
        extensionFile: (name: string) => string;
        writeResource: (
          spec: string,
          name: string,
          value: unknown,
        ) => Promise<unknown>;
      }) => {
        const assets = Assets.parse({
          scan_script: ctx.extensionFile("scripts/scan_host_key.py"),
          probe_script: ctx.extensionFile("scripts/probe_host.py"),
          input_json: JSON.stringify(args.intent.bootstrap),
        });
        await ctx.writeResource("assets", "assets", assets);
        return assets;
      },
    },
  },
};
