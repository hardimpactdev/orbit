import { z } from "npm:zod@4";
import { compact } from "../../bin/compact-services.ts";

type Context = {
  repoDir: string;
  modelType: { constructor: unknown };
  dataRepository: {
    findByName: (
      type: unknown,
      id: string,
      name: string,
    ) => Promise<{ version: number } | null>;
    delete: (
      type: unknown,
      id: string,
      name: string,
      version: number,
    ) => Promise<void>;
  };
  definitionRepository: {
    findByNameGlobal: (
      name: string,
    ) => Promise<{ definition: { id: string }; type: unknown } | null>;
    delete: (type: unknown, id: string, name: string) => Promise<void>;
  };
};
export const behavior = {
  type: "@nckrtl/runtime-retention-executor",
  version: "2026.10.28.2",
  globalArguments: z.object({}),
  methods: {
    prune: {
      description:
        "Bounded reference-aware runtime pruning through native repositories",
      outputLifetime: "7d",
      arguments: z.object({}),
      execute: async (_args: Record<string, never>, ctx: Context) => {
        await compact(ctx.repoDir, true, Date.now(), {
          limit: 5000,
          deleteVersion: async (r) => {
            const type =
              (ctx.modelType.constructor as { create: (s: string) => unknown })
                .create(r.type_normalized);
            const latest = await ctx.dataRepository.findByName(
              type,
              r.model_id,
              r.data_name,
            );
            // A changed producer is held until a fresh graph snapshot next pass.
            if (latest?.version !== r.snapshot_latest) return;
            await ctx.dataRepository.delete(
              type,
              r.model_id,
              r.data_name,
              r.version,
            );
          },
          retire: async (value) => {
            const found = await ctx.definitionRepository.findByNameGlobal(
              String(value.name),
            );
            if (!found || found.definition.id !== value.id) {
              throw new Error("retention_definition_changed");
            }
            await ctx.definitionRepository.delete(
              found.type,
              found.definition.id,
              String(value.name),
            );
          },
        });
        return { dataHandles: [] };
      },
    },
  },
};
