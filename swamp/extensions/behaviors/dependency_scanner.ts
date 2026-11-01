import { z } from "npm:zod@4";
import {
  BatchEvidenceSchema,
  type DependencyContext,
  reconcileDependencyIndex,
  resultStore,
  serviceDetection,
} from "./_lib/service_detection.ts";
import { DependencyProjectSchema } from "./_lib/dependency_project_schema.ts";
import { batchReceipt, FindingsSchema } from "./_lib/project_schedule.ts";
import {
  CommitSchema,
  indexName,
  IndexSchema,
} from "./_lib/dependency_results_schema.ts";

import {
  failProjectScan,
  prepareProjectScan,
} from "./_lib/dependency_results.ts";

export const ScanInput = z.object({
  project: DependencyProjectSchema,
  runId: z.string().min(1).max(256),
  root: z.string().startsWith("/"),
  commit: CommitSchema,
  evidence: BatchEvidenceSchema,
}).strict();
const ScanResult = z.object({
  result_json: z.string(),
  scan_succeeded: z.boolean().optional(),
});
export const scanResources = {
  scanResult: {
    description: "Public result of the admitted native scan",
    schema: ScanResult,
    lifetime: "infinite",
    garbageCollection: 1,
  },
};

/** Invoked as a native step on the canonical result model; no nested invocation. */
export const scanMethod = {
  description:
    "Scan the exact native Git checkout using captured Project inputs and PR observations",
  arguments: ScanInput.strip(),
  execute: async (raw: z.infer<typeof ScanInput>, ctx: DependencyContext) => {
    const args = ScanInput.strip().parse(raw);
    const handles: { name: string; version?: number }[] = [];
    const context: DependencyContext = {
      ...ctx,
      writeResource: async (...input) => {
        const handle = await ctx.writeResource(...input);
        handles.push(handle);
        return handle;
      },
    };
    const index = await serviceDetection(
      args.project,
      args.runId,
      context,
      args,
    );
    const findings = await ctx.queryData(
      `modelName == "orbit-gateway" && name == "findings-${args.project.projectId}"`,
    );
    const selected = findings.map((row) =>
      FindingsSchema.parse((row as { attributes: unknown }).attributes)
    );
    const requests = await Promise.all(
      selected.filter((row) =>
        index.last_attempt.status === "succeeded" &&
        row.targets.length > 0
      )
        .map(async (row) => {
          const receipt = await batchReceipt(args.project, row);
          return {
            action: "create_group",
            project_id: args.project.projectId,
            key: receipt.key,
            input: receipt.request,
            maintenance: {mode:"package",project:args.project,targets:row.targets,source_commit:args.commit},
          };
        }),
    );
    const result = {
      project_id: args.project.projectId,
      run_id: args.runId,
      source_commit: index.source_commit,
      status: index.last_attempt.status,
      error_code: index.last_attempt.error_code,
      findings: selected,
      requests,
    };
    handles.push(
      await ctx.writeResource("scanResult", "scan-result", {
        result_json: JSON.stringify(result),
        scan_succeeded: index.last_attempt.status === "succeeded",
      }),
    );
    return { dataHandles: handles };
  },
};

const RefreshInput = z.object({
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
    const { rows } = RefreshInput.strip().parse(raw),
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
    return { dataHandles: handles };
  },
};

const AttemptInput = z.object({
  project: DependencyProjectSchema,
  runId: z.string().min(1).max(256),
});
const attemptContext = (
  ctx: DependencyContext,
  handles: { name: string; version?: number }[],
) => ({
  ...ctx,
  writeResource: async (
    ...args: Parameters<DependencyContext["writeResource"]>
  ) => {
    const h = await ctx.writeResource(...args);
    handles.push(h);
    return h;
  },
});
export const beginScanMethod = {
  description: "Revoke the old dependency plan before native Git acquisition",
  arguments: AttemptInput.strip(),
  async execute(raw: z.infer<typeof AttemptInput>, ctx: DependencyContext) {
    const args = AttemptInput.parse({
        project: raw.project,
        runId: raw.runId,
      }),
      handles: { name: string; version?: number }[] = [],
      context = attemptContext(ctx, handles);
    await prepareProjectScan(args.project, args.runId, {
      store: resultStore(context),
      now: () => new Date().toISOString(),
      pr: async (_p, previous) =>
        previous ??
          {
            status: "unknown",
            checked_at: new Date().toISOString(),
            last_success_at: null,
            error_code: "pr_not_observed",
            value: null,
          },
      saveFindings: async (value) => {
        await context.writeResource(
          "findings",
          `findings-${args.project.projectId}`,
          value,
        );
      },
    });
    return { dataHandles: handles };
  },
};
export const finishScanMethod = {
  description:
    "Settle acquisition/validation failures and emit the exact current attempt result",
  arguments: AttemptInput.extend({ steps: z.record(z.string(), z.unknown()) })
    .strip(),
  async execute(
    args: {
      project: z.infer<typeof DependencyProjectSchema>;
      runId: string;
      steps: Record<string, unknown>;
    },
    ctx: DependencyContext,
  ) {
    const handles: { name: string; version?: number }[] = [],
      context = attemptContext(ctx, handles);
    const step = (name: string) =>
      args.steps[name] as {
        status?: string;
        outputs?: { result_json?: string; scan_succeeded?: boolean };
      } | undefined;
    const usable = step("scan")?.status === "succeeded" &&
      step("verify_head")?.status === "succeeded";
    let result: Record<string, unknown>;
    if (usable) result = JSON.parse(step("scan")!.outputs!.result_json!);
    else {
      const index = await failProjectScan(
        args.project,
        args.runId,
        {
          store: resultStore(context),
          now: () => new Date().toISOString(),
          saveFindings: async (value) => {
            await context.writeResource(
              "findings",
              `findings-${args.project.projectId}`,
              value,
            );
          },
        },
        step("scan")?.status === "succeeded"
          ? "source_head_unverified"
          : "source_acquisition_failed",
      );
      result = {
        project_id: args.project.projectId,
        run_id: args.runId,
        status: "failed",
        error_code: index?.last_attempt.error_code ??
          "stale_scan_attempt",
        requests: [],
      };
    }
    handles.push(
      await ctx.writeResource("scanResult", "scan-terminal-result", {
        result_json: JSON.stringify(result),
        scan_succeeded: result.status === "succeeded",
      }),
    );
    return { dataHandles: handles };
  },
};
