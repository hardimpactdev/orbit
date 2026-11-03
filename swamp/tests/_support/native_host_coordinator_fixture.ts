import { z } from "npm:zod@4";
import { callWorkerModel } from "../../extensions/behaviors/_lib/worker_model.ts";
import { projectConfigurations } from "../../extensions/behaviors/_lib/project_config.ts";
import { queryWorkerData } from "../../extensions/behaviors/_lib/worker_query.ts";
export const behavior = {
  type: "@fixture/native-coordinator",
  version: "2026.10.30.1",
  globalArguments: z.object({}),
  methods: {
    probe: {
      description: "Nested method from a placed coordinator",
      arguments: z.object({}),
      execute: async (_: unknown, ctx: { signal?: AbortSignal; queryData: (p: string) => Promise<unknown[]> }) => {
        await callWorkerModel(ctx, {
          modelType: "@nckrtl/dependency-scanner",
          name: "dependency-scanner-20",
          method: "scan",
          arguments: { projectId: 20, runId: "native-host-proof" },
        });
        const result = await callWorkerModel(ctx, {
          modelType: "@nckrtl/dependency-scanner",
          name: "dependency-scanner-21",
          method: "scan",
          arguments: { projectId: 21, runId: "coordinator-proof" },
        });
        if (!result.ok) throw new Error("coordinator_native_call_failed");
        const predicate = 'modelName == "dependency-scanner-20" && name == "proof"';
        const attrs = await queryWorkerData(ctx, predicate, "attributes") as { projectId: number }[];
        if (attrs.length !== 1 || attrs[0].projectId !== 20) throw new Error("worker_query_not_hydrated");
        const content = await queryWorkerData(ctx, predicate, "content") as { projectId: number }[];
        if (content.length !== 1 || content[0].projectId !== 20) throw new Error("worker_content_not_hydrated");
        const configs = await projectConfigurations([], [{ projectId: 20, type: "@nckrtl/project-native-proof" }], ctx);
        if (configs.length !== 1 || configs[0].projectId !== 20) throw new Error("project_refresh_not_hydrated");
        return { dataHandles: [] };
      },
    },
  },
};
