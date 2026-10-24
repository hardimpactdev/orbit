import {
  type AnalysisResult,
  type AuthorizedPlan,
  briefSource,
  canonical,
  type Manifest,
  sha256,
} from "../../extensions/behaviors/_lib/repository_analysis.ts";

export const HEAD = "a".repeat(40), TREE = "b".repeat(40);
export function bound(payload: unknown, recordedAt = "2026-10-24T10:00:00Z") {
  return { payload, recordedAt };
}
export async function analysisFixture(group: Parameters<typeof briefSource>[0], entries: Manifest["paths"] = [{ change: "add", path: "new.txt" }]) {
  const manifest: Manifest = {
    id: "fixture",
    revision: 1,
    source: { brief_id: `tg-${(group as { id?: number }).id ?? 7}`, revision_sha256: await sha256(briefSource(group)) },
    asserted_by: "fixture-owner",
    complete: true,
    paths: entries,
  };
  const plan: AuthorizedPlan = {
    manifest,
    plan_sha256: await sha256(canonical(manifest)),
    base: HEAD,
    repository: "https://example.test/repo.git",
    branch: `task-${(group as { id?: number }).id ?? 7}`,
    docsSubtask: group.tasks[0].id,
  };
  const report = {
    base: HEAD,
    paths: entries.flatMap((p) => p.change === "rename" ? [p.from, p.path] : [p.path]).sort(),
    impacted_pages: [],
    surfaces: [],
    surfaces_handled_by_generator: [],
    errors: [],
    verdict: "no_docs_change",
  };
  const content = JSON.stringify({
    schema: 1,
    execution_id: "e".repeat(32),
    plan_sha256: plan.plan_sha256,
    base: HEAD,
    head: HEAD,
    tree: TREE,
    status: "ready",
    diagnostics: [],
    report: { schema: "orbit.docs-impact.v1", data: report },
  });
  const result: AnalysisResult = {
    outcome: "ready",
    artifact: { content, bytes: new TextEncoder().encode(content).length, sha256: await sha256(content) },
    provenance: {
      operation_id: 899,
      group: (group as { id?: number }).id ?? 7,
      subtask: group.tasks[0].id,
      repository: plan.repository,
      branch: plan.branch,
      plan_sha256: plan.plan_sha256,
      base: HEAD,
      instance_id: 1,
      node_id: 2,
      lease_id: 3,
      execution_id: "e".repeat(32),
      entrypoint_sha256: "f".repeat(64),
      plan_id: manifest.id,
      plan_revision: 1,
      before: { head: HEAD, tree: TREE },
      after: { head: HEAD, tree: TREE },
      started_at: "2026-10-24T10:00:00Z",
      finished_at: "2026-10-24T10:00:01Z",
      duration_ms: 1000,
      exit_code: 0,
      mechanical_llm_calls: 0,
    },
  };
  const analysis = (purpose = "implement") =>
    bound({
      subtask: group.tasks[0].id,
      outcome: "ready",
      purpose,
      operationId: 899,
      plan_sha256: plan.plan_sha256,
      plan,
      candidate: { head: HEAD, tree: TREE },
      result,
    });
  return {
    plan,
    result,
    report,
    analysis,
    inputs: {
      plan: bound(plan),
      workspace: bound({ instanceId: 1, nodeId: 2 }),
      baseline: bound({ subtask: group.tasks[0].id, outcome: "passed", candidate: { head: HEAD, tree: TREE } }),
    },
  };
}
