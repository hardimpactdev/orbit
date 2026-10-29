import { z } from "npm:zod@4";
export const behavior = {
  type: "@fixture/remote-refusal",
  version: "2026.10.29.1",
  globalArguments: z.object({}),
  methods: {
    probe: {
      description: "Require actual remote execution",
      arguments: z.object({}),
      execute: async (
        _: unknown,
        ctx: {
          runModel: (
            input: unknown,
          ) => Promise<{ ok: boolean; error?: { message?: string } }>;
        },
      ) => {
        const result = await ctx.runModel({
          modelType: "@fixture/native-leaf",
          name: "must-not-run",
          method: "probe",
        });
        if (
          result.ok ||
          !result.error?.message?.includes("not available in remote execution")
        ) throw new Error("expected_native_remote_refusal");
        return { dataHandles: [] };
      },
    },
  },
};
