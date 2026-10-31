import { type DependencyProject } from "./dependency_projects.ts";
import { canonical, sha256 } from "./repository_analysis.ts";
import { type BatchPlan, type Candidate } from "./maintenance/batch.ts";
import { type Findings, FindingsSchema } from "./project_schedule.ts";
import {
  type Attempt,
  CommitSchema,
  type Finding,
  type Index,
  indexName,
  IndexSchema,
  type Pr,
  type Report,
  reportName,
  ReportSchema,
  repositoryIdentity,
  type Resolution,
  type SourceIdentity,
  SourceIdentitySchema,
  sourceKey,
} from "./dependency_results_schema.ts";

export interface ResultStore {
  readIndex(name: string): Promise<Index | null>;
  readReport(name: string, version: number): Promise<Report>;
  writeIndex(name: string, value: Index): Promise<void>;
  writeReport(name: string, value: Report, sourceRef: string): Promise<number>;
}
export interface ScanDependencies {
  store: ResultStore;
  now(): string;
  acquire(
    project: DependencyProject,
  ): Promise<{ root: string; commit: string; close(): Promise<void> }>;
  detect(
    root: string,
    source: SourceIdentity,
    project: DependencyProject,
  ): Promise<
    {
      packages: Resolution[];
      candidates: Candidate[];
      identities?: Record<string, string>;
    }
  >;
  plan(
    root: string,
    project: DependencyProject,
    candidates: Candidate[],
  ): Promise<BatchPlan[]>;
  pr(project: DependencyProject, previous: Pr | null): Promise<Pr>;
  saveFindings(value: Findings): Promise<void>;
  batchLinks?(): {
    group_id: number;
    run_id: string;
    targets: {
      path: string;
      manager: string;
      package: string;
      version: string;
    }[];
  }[];
}
export function sources(project: DependencyProject): SourceIdentity[] {
  const result = [
    ...project.worker.composerPaths.map((path) => ({
      path,
      ecosystem: "composer",
      package_manager: "composer",
    })),
    ...project.worker.jsPaths.map((path) => ({
      path,
      ecosystem: "npm",
      package_manager: project.worker.jsPackageManagers?.[path],
    })),
  ].map((s) => SourceIdentitySchema.parse(s));
  if (
    result.length > 64 || new Set(result.map(sourceKey)).size !== result.length
  ) throw new Error("source_configuration_limit");
  return result;
}
export const policyIdentity = (project: DependencyProject) =>
  sha256(canonical(project));
const attempt = (
  run_id: string,
  started_at: string,
  finished_at: string | null,
  status: Attempt["status"],
  error_code: string | null,
): Attempt => ({ run_id, started_at, finished_at, status, error_code });
function findings(
  packages: Resolution[],
  candidates: Candidate[],
  plans: BatchPlan[],
  identities: Record<string, string> = {},
  blockedCode?: string,
  links: ReturnType<NonNullable<ScanDependencies["batchLinks"]>> = [],
): Finding[] {
  return candidates.flatMap((c) => {
    const name = identities[c.package] ?? c.package;
    const versions = [
      ...new Set(
        packages.filter((p) => p.name === name).map((p) => p.version),
      ),
    ].sort();
    if (!versions.length) throw new Error("finding_resolution_missing");
    const target = plans.flatMap((p) => p.targets).find((t) =>
      t.path === c.path && t.manager === c.manager && t.package === c.package
    );
    return c.reasons.map((reason) => {
      const blockedMajor = reason === "major" && target &&
        !versions.every((v) =>
          Number(target.version.split(".")[0]) >
            Number(v.replace(/^v/, "").split(".")[0])
        );
      const ready = target && !blockedMajor && !blockedCode;
      const link = ready &&
        links.find((l) =>
          l.targets.some((t) =>
            t.path === c.path && t.manager === c.manager &&
            t.package === c.package && t.version === target.version
          )
        );
      return {
        id: `${reason === "security" ? "advisory" : "major"}:${c.package}`,
        package: name,
        kind: reason === "security" ? "advisory" as const : "major" as const,
        current_versions: versions,
        target_version: ready ? target.version : null,
        advisory_ids: reason === "security"
          ? [...new Set(c.advisories.map((a) => a.id))].sort()
          : [],
        state: link
          ? "in_batch" as const
          : ready
          ? "ready" as const
          : "blocked" as const,
        reason: link
          ? {
            code: "batch_admitted",
            text: "This verified target is linked to the project batch.",
          }
          : ready
          ? {
            code: "verified_target",
            text:
              "The native resolver verified this target under project policy.",
          }
          : {
            code: blockedCode ?? "no_verified_target",
            text: blockedCode === "sibling_source_failed"
              ? "A sibling source failed; the complete project batch is not verified."
              : blockedCode === "project_planning_failed"
              ? "The project planner failed; inventory is fresh but no target is verified."
              : "No jointly verified target satisfies the current project constraints and release-age policy.",
          },
        links: link ? { group_id: link.group_id, run_id: link.run_id } : null,
      };
    });
  });
}

/** Every refresh is a new attempt, even at the same SHA. Reports precede the index switch. */
export async function scanProject(
  project: DependencyProject,
  runId: string,
  deps: ScanDependencies,
): Promise<Index> {
  const ref = `refs/heads/${project.defaultBranch}`,
    name = await indexName(project.projectId, ref),
    repository = repositoryIdentity(project.repository),
    prior = await deps.store.readIndex(name),
    started = deps.now();
  if (
    prior &&
    (prior.repository !== repository ||
      prior.project_id !== project.projectId || prior.source_ref !== ref)
  ) throw new Error("index_identity_conflict");
  const empty = {
    schema: 1 as const,
    key: `${project.projectId}-${runId}`,
    projectId: project.projectId,
    source: runId,
    sourceRef: ref,
    policyIdentity: await policyIdentity(project),
    reports: [],
    plans: [],
    targets: [],
    blocked: [],
  };
  // Revoke the previous plan even when configuration cannot fit a bounded index.
  await deps.saveFindings(FindingsSchema.parse(empty));
  const configurationFailure = async (code: string) => {
    if (!prior) throw new Error(code);
    const finished = deps.now(),
      failure = attempt(runId, started, finished, "failed", code);
    const value = IndexSchema.parse({
      ...prior,
      publication_id: crypto.randomUUID(),
      generated_at: finished,
      source_commit: null,
      last_attempt: failure,
      sources: prior.sources.map((s) => ({ ...s, last_attempt: failure })),
    });
    await deps.store.writeIndex(name, value);
    return value;
  };
  let configured: SourceIdentity[];
  try {
    configured = sources(project);
  } catch {
    return await configurationFailure("source_configuration_limit");
  }
  const running = attempt(runId, started, null, "running", null);
  const initial = IndexSchema.safeParse({
    schema_version: 1,
    publication_id: crypto.randomUUID(),
    project_id: project.projectId,
    repository,
    source_ref: ref,
    source_commit: null,
    generated_at: started,
    last_attempt: running,
    sources: configured.map((s) => ({
      ...s,
      last_attempt: running,
      report: prior?.sources.find((p) =>
        sourceKey(p) === sourceKey(s)
      )?.report ?? null,
    })),
    batch_pr: await deps.pr(project, prior?.batch_pr ?? null),
  });
  if (!initial.success) {
    return await configurationFailure("index_publication_limit");
  }
  let index = initial.data;
  const failedSnapshot = (base: Index, error: string, finished: string) => {
    const failure = attempt(runId, started, finished, "failed", error);
    return {
      ...base,
      publication_id: crypto.randomUUID(),
      generated_at: finished,
      last_attempt: failure,
      sources: base.sources.map((s) => ({ ...s, last_attempt: failure })),
    };
  };
  // A running publication must have room for its bounded failure form, including the head.
  if (
    !IndexSchema.safeParse(
      failedSnapshot(
        { ...index, source_commit: "0".repeat(64) },
        "index_publication_limit",
        started,
      ),
    ).success
  ) {
    return await configurationFailure("index_publication_limit");
  }
  await deps.store.writeIndex(name, index);
  let checkout: Awaited<ReturnType<ScanDependencies["acquire"]>> | null = null;
  const results = new Map<
    string,
    {
      packages: Resolution[];
      candidates: Candidate[];
      identities?: Record<string, string>;
    }
  >();
  const failures = new Map<string, string>();
  let plans: BatchPlan[] = [];
  let planningFailed = false;
  try {
    checkout = await deps.acquire(project);
    index = IndexSchema.parse({
      ...index,
      publication_id: crypto.randomUUID(),
      generated_at: deps.now(),
      source_commit: CommitSchema.parse(checkout.commit),
    });
    await deps.store.writeIndex(name, index);
    for (const source of configured) {
      try {
        results.set(
          sourceKey(source),
          await deps.detect(checkout.root, source, project),
        );
      } catch {
        failures.set(sourceKey(source), "source_scan_failed");
      }
    }
    if (!failures.size) {
      try {
        plans = await deps.plan(
          checkout.root,
          project,
          [...results.values()].flatMap((r) => r.candidates),
        );
      } catch {
        planningFailed = true;
      }
    }
  } catch {
    for (const source of configured) {
      failures.set(sourceKey(source), "source_acquisition_failed");
    }
  } finally {
    if (checkout) await checkout.close();
  }
  const publication = crypto.randomUUID(), finished = deps.now();
  const nextSources: Index["sources"] = [];
  for (const source of index.sources) {
    let report = source.report;
    if (!failures.has(sourceKey(source))) {
      try {
        const result = results.get(sourceKey(source))!;
        const value = ReportSchema.parse({
          ...sourceIdentity(source),
          schema_version: 1,
          publication_id: publication,
          published_at: finished,
          project_id: project.projectId,
          repository,
          source_commit: index.source_commit,
          observed_at: finished,
          run_id: runId,
          basis: "locked",
          observed_on: "repository",
          instance_id: null,
          packages: result.packages,
          findings: findings(
            result.packages,
            result.candidates,
            failures.size ? [] : plans,
            result.identities,
            planningFailed
              ? "project_planning_failed"
              : failures.size
              ? "sibling_source_failed"
              : undefined,
            deps.batchLinks?.(),
          ),
        });
        const dataName = await reportName(publication, source);
        const version = await deps.store.writeReport(dataName, value, ref);
        if (!Number.isSafeInteger(version) || version <= 0) {
          throw new Error("invalid_native_version");
        }
        report = {
          ...sourceIdentity(source),
          modelIdOrName: "orbit-gateway",
          dataName,
          version,
          source_commit: value.source_commit,
          observed_at: value.observed_at,
          publication_id: publication,
        };
      } catch {
        failures.set(sourceKey(source), "report_publication_failed");
      }
    }
    const error = failures.get(sourceKey(source)) ??
      (planningFailed ? "project_planning_failed" : null);
    nextSources.push({
      ...sourceIdentity(source),
      report,
      last_attempt: attempt(
        runId,
        started,
        finished,
        error ? "failed" : "succeeded",
        error,
      ),
    });
  }
  const count =
    nextSources.filter((s) => s.last_attempt.status === "succeeded").length;
  const final = IndexSchema.safeParse({
    ...index,
    publication_id: publication,
    generated_at: finished,
    sources: nextSources,
    last_attempt: attempt(
      runId,
      started,
      finished,
      failures.size || planningFailed
        ? count ? "partial" : "failed"
        : "succeeded",
      planningFailed
        ? "project_planning_failed"
        : failures.size
        ? "source_refresh_failed"
        : null,
    ),
  });
  if (!final.success) {
    index = IndexSchema.parse(
      failedSnapshot(index, "index_publication_limit", finished),
    );
    await deps.store.writeIndex(name, index);
    return index;
  }
  index = final.data;
  await deps.store.writeIndex(name, index);
  if (!failures.size && !planningFailed) {
    await deps.saveFindings(
      FindingsSchema.parse({
        ...empty,
        sourceCommit: index.source_commit,
        reports: [...results.values()].map((r) => ({
          schema: 1,
          candidates: r.candidates,
        })),
        plans,
        targets: plans.flatMap((p) => p.targets),
        blocked: plans.flatMap((p) => p.blocked),
      }),
    );
  }
  return index;
}
export function sourceIdentity(s: SourceIdentity): SourceIdentity {
  return {
    path: s.path,
    ecosystem: s.ecosystem,
    package_manager: s.package_manager,
  };
}

/** Republish lifecycle evidence without rewriting scan time or run. */
export async function refreshLifecycle(
  index: Index,
  pr: Pr,
  links: {
    group_id: number;
    run_id: string;
    targets: {
      path: string;
      manager: string;
      package: string;
      version: string;
    }[];
  }[],
  store: ResultStore,
  now: string,
): Promise<Index> {
  const id = crypto.randomUUID(), next = structuredClone(index);
  const observation = coalescePr(index.batch_pr, pr);
  let changed = canonical(index.batch_pr) !== canonical(observation);
  next.batch_pr = observation;
  for (const source of next.sources) {
    if (!source.report) continue;
    const report = ReportSchema.parse(
      await store.readReport(source.report.dataName, source.report.version),
    );
    if (
      report.project_id !== index.project_id ||
      report.repository !== index.repository ||
      report.source_commit !== source.report.source_commit ||
      report.publication_id !== source.report.publication_id ||
      report.observed_at !== source.report.observed_at ||
      sourceKey(source) !== sourceKey(report)
    ) throw new Error("report_pointer_mismatch");
    const updated = report.findings.map((f) => {
      const match = links.find((l) =>
        l.targets.some((t) =>
          t.path === source.path &&
          (t.manager === "composer" ? "composer" : "npm") ===
            source.ecosystem &&
          t.package === f.package && t.version === f.target_version
        )
      );
      return match && f.target_version
        ? {
          ...f,
          state: "in_batch" as const,
          links: { group_id: match.group_id, run_id: match.run_id },
          reason: {
            code: "batch_admitted",
            text: "This verified target is linked to the project batch.",
          },
        }
        : f.state === "in_batch"
        ? {
          ...f,
          state: "ready" as const,
          links: null,
          reason: {
            code: "verified_target",
            text:
              "The native resolver verified this target under project policy.",
          },
        }
        : f;
    });
    if (canonical(updated) === canonical(report.findings)) continue;
    const value = ReportSchema.parse({
      ...report,
      findings: updated,
      publication_id: id,
      published_at: now,
    });
    const dataName = await reportName(id, source),
      version = await store.writeReport(dataName, value, index.source_ref);
    source.report = { ...source.report, dataName, version, publication_id: id };
    changed = true;
  }
  if (!changed) return index;
  next.publication_id = id;
  next.generated_at = now;
  await store.writeIndex(
    await indexName(index.project_id, index.source_ref),
    IndexSchema.parse(next),
  );
  return next;
}

/** Preserve producer times until evidence changes or its four-minute certainty expires. */
export function coalescePr(previous: Pr, next: Pr): Pr {
  const meaning = (p: Pr) =>
    canonical({ status: p.status, error_code: p.error_code, value: p.value });
  return meaning(previous) === meaning(next) &&
      Date.parse(next.checked_at) - Date.parse(previous.checked_at) < 240000
    ? previous
    : next;
}
