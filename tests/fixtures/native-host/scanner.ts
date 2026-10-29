import { z } from "npm:zod@4";
export const behavior = {
  type: "@nckrtl/dependency-scanner",
  version: "2026.10.29.1",
  globalArguments: z.object({}),
  resources: {
    proof: {
      schema: z.object({
        projectId: z.number(),
        runId: z.string(),
        pid: z.number(),
        nested: z.boolean(),
      }),
      lifetime: "1h",
      garbageCollection: 1,
    },
  },
  methods: {
    scan: {
      description: "Exercise native nested execution",
      arguments: z.object({ projectId: z.number(), runId: z.string() }),
      execute: async (
        args: { projectId: number; runId: string },
        ctx: {
          runModel: (input: unknown) => Promise<{ ok: boolean }>;
          writeResource: (
            spec: string,
            name: string,
            value: unknown,
          ) => Promise<unknown>;
        },
      ) => {
        const nested = await ctx.runModel({
          modelType: "@fixture/native-leaf",
          name: "native-leaf",
          method: "probe",
        });
        if (!nested.ok) throw new Error("nested_native_call_refused");
        return {
          dataHandles: [
            await ctx.writeResource("proof", "proof", {
              ...args,
              pid: Deno.pid,
              nested: true,
            }),
          ],
        };
      },
    },
  },
};
