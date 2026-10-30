import { queryWorkerData } from "./worker_query.ts";
/** Input identity for a parked group. Status/report materializations are excluded. */
export async function groupFingerprint(
  ctx: {
    queryData: (predicate: string, select?: string) => Promise<unknown[]>;
  },
  factory: string,
  workItem: string,
  preapprovalModel: string,
) {
  const models = [factory, preapprovalModel, `orbit-group-${workItem}`];
  const rows = await queryWorkerData(
    ctx,
    `modelName in ${JSON.stringify(models)} && (` +
      ["state-", "artifact-", "evidence-", "approval-", "preapproval-"]
        .map((p) => `name.startsWith(${JSON.stringify(p + workItem)})`).join(
          " || ",
        ) +
      ")",
    "attributes",
  );
  const bytes = new TextEncoder().encode(
    JSON.stringify(rows.map((row) => JSON.stringify(row)).sort()),
  );
  const digest = new Uint8Array(await crypto.subtle.digest("SHA-256", bytes));
  return Array.from(digest, (byte) => byte.toString(16).padStart(2, "0")).join(
    "",
  );
}

import { parse } from "jsr:@std/yaml@1.0.12";
import { join } from "jsr:@std/path@1.1.4";

/** Read only current saved owners, never scan complete workflow history. */
export async function currentOwners(
  ctx: {
    repoDir?: string;
    queryData: (p: string, s?: string) => Promise<unknown[]>;
  },
  workItems: string[],
) {
  const busy = new Set<string>(), recover = new Set<string>();
  if (!ctx.repoDir) return { busy, recover };
  const rows = await queryWorkerData(
    ctx,
    'modelName.startsWith("orbit-group-") && (name == "owner" || name == "intent")',
    "attributes",
  ) as { workItem: string; runId: string | null; generation: number }[];
  const workflow = parse(
    await Deno.readTextFile(
      join(ctx.repoDir, "workflows/workflow-group-run.yaml"),
    ),
  ) as { id: string };
  if (!/^[a-f0-9-]{36}$/.test(workflow.id)) {
    throw new Error("invalid_group_workflow_id");
  }
  for (const wi of workItems) {
    const saved = rows.filter((r) =>
      r.workItem === wi && r.runId
    ).sort((a, b) => b.generation - a.generation)[0];
    if (!saved) {
      continue;
    }
    try {
      if (!/^[a-f0-9-]{36}$/.test(saved.runId!)) {
        throw new Error(
          "invalid owner id",
        );
      }
      const run = parse(
        await Deno.readTextFile(
          join(
            ctx.repoDir,
            ".swamp/workflow-runs",
            workflow.id,
            `workflow-run-${saved.runId}.yaml`,
          ),
        ),
      ) as { status: string; awaitingResume?: boolean };
      if (!["succeeded", "failed", "cancelled"].includes(run.status)) {
        busy.add(
          wi,
        );
      }
      if (
        run.status === "interrupted" ||
        (run.status === "suspended" && run.awaitingResume)
      ) {
        recover.add(wi);
      }
    } catch {
      busy.add(wi);
      recover.add(wi);
    } // Unknown owners retain their slot.
  }
  return { busy, recover };
}
