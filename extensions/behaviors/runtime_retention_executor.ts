import { z } from "npm:zod@4";
import { pruneOnline } from "../../bin/retention-online.ts";

type Context = {
  repoDir: string;
  writeResource: (
    spec: string,
    name: string,
    value: Record<string, unknown>,
  ) => Promise<{ name: string }>;
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
};
export const behavior = {
  type: "@nckrtl/runtime-retention-executor",
  version: "2026.10.28.2",
  globalArguments: z.object({}),
  resources: {
    progress: {
      description:
        "Last bounded scan/prune result; source files remain authoritative",
      schema: z.object({
        partial: z.boolean(),
        phase: z.string(),
        scanned: z.number(),
        removedVersions: z.number(),
        removedPaths: z.number(),
        more: z.boolean(),
      }),
      lifetime: "infinite",
      garbageCollection: 1,
    },
  },
  methods: {
    prune: {
      description:
        "Bounded reference-aware runtime pruning through native repositories",
      outputLifetime: "7d",
      arguments: z.object({}),
      execute: async (_args: Record<string, never>, ctx: Context) => {
        const result = await pruneOnline(ctx.repoDir, {
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
            if (latest?.version !== r.snapshot_latest) return false;
            await ctx.dataRepository.delete(
              type,
              r.model_id,
              r.data_name,
              r.version,
            );
            return true;
          },
        }, Date.now());
        const progress = {
          partial: result.partial,
          phase: "phase" in result ? String(result.phase) : "prune",
          scanned: result.scanned,
          removedVersions: result.removedVersions,
          removedPaths: result.removedPaths,
          more: result.partial || ("more" in result && result.more),
        };
        const handle = await ctx.writeResource(
          "progress",
          "progress",
          progress,
        );
        return { dataHandles: [handle], ...progress };
      },
    },
  },
};
