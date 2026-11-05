import { z } from "npm:zod@4";
import {
    BatchEvidenceSchema,
    type DependencyContext,
    reconcileDependencyIndex,
    resultStore,
} from "./_lib/service_detection.ts";
import { DependencyProjectSchema } from "./_lib/dependency_project_schema.ts";
import { indexName, IndexSchema } from "./_lib/dependency_results_schema.ts";

const ScanResult = z.object({
    result_json: z.string(),
    scan_succeeded: z.boolean().optional(),
});
export const scanResources = {
    maintenanceResult: {
        description:
            "Typed Project maintenance result from the action that produced it",
        schema: z.object({ result: z.record(z.string(), z.unknown()) })
            .strict(),
        lifetime: "infinite",
        garbageCollection: 1,
    },
    scanResult: {
        description: "Public result of the admitted native scan",
        schema: ScanResult,
        lifetime: "infinite",
        garbageCollection: 1,
    },
};

const RefreshInput = z.object({
    result: z.record(z.string(), z.unknown()).optional(),
    rows: z.array(
        z.object({
            project: DependencyProjectSchema,
            index: IndexSchema,
            evidence: BatchEvidenceSchema,
        }).strict(),
    ),
}).strict();
/** Fresh GitHub observations arrive from native steps; only native data is updated here. */
export const refreshMethod = {
    description:
        "Publish native PR observations without reading Gateway state or admitting work",
    arguments: RefreshInput.strip(),
    execute: async (
        raw: z.infer<typeof RefreshInput>,
        ctx: DependencyContext,
    ) => {
        const { rows, result } = RefreshInput.strip().parse(raw),
            handles: { name: string; version?: number }[] = [];
        const context: DependencyContext = {
            ...ctx,
            writeResource: async (...input) => {
                const handle = await ctx.writeResource(...input);
                handles.push(handle);
                return handle;
            },
        };
        for (const row of rows) {
            if (row.project.projectId !== row.index.project_id) {
                throw new Error("dependency_refresh_input_mismatch");
            }
            const current = await resultStore(context).readIndex(
                await indexName(row.project.projectId, row.index.source_ref),
            );
            if (current?.publication_id !== row.index.publication_id) continue;
            await reconcileDependencyIndex(row.index, context, row.evidence);
        }
        if (result !== undefined) {
            handles.push(
                await ctx.writeResource("maintenanceResult", "refresh-result", {
                    result,
                }),
            );
        }
        return { dataHandles: handles };
    },
};
