import { type DependencyProject } from "./dependency_project_schema.ts";
import { canonical, sha256 } from "./repository_analysis.ts";
import { type BatchPlan, type Candidate } from "./maintenance/batch.ts";
import { type Findings } from "./project_schedule.ts";
import {
    type Index,
    indexName,
    IndexSchema,
    type Pr,
    type Report,
    reportName,
    ReportSchema,
    type Resolution,
    type SourceIdentity,
    SourceIdentitySchema,
    sourceKey,
} from "./dependency_results_schema.ts";

export interface ResultStore {
    readIndex(name: string): Promise<Index | null>;
    readReport(name: string, version: number): Promise<Report>;
    writeIndex(name: string, value: Index): Promise<void>;
    writeReport(
        name: string,
        value: Report,
        sourceRef: string,
    ): Promise<number>;
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
        result.length > 64 ||
        new Set(result.map(sourceKey)).size !== result.length
    ) throw new Error("source_configuration_limit");
    return result;
}
export const policyIdentity = (project: DependencyProject) =>
    sha256(canonical(project));
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
            await store.readReport(
                source.report.dataName,
                source.report.version,
            ),
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
                        text:
                            "This verified target is linked to the project batch.",
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
            version = await store.writeReport(
                dataName,
                value,
                index.source_ref,
            );
        source.report = {
            ...source.report,
            dataName,
            version,
            publication_id: id,
        };
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
        canonical({
            status: p.status,
            error_code: p.error_code,
            value: p.value,
        });
    return meaning(previous) === meaning(next) &&
            Date.parse(next.checked_at) - Date.parse(previous.checked_at) <
                240000
        ? previous
        : next;
}
