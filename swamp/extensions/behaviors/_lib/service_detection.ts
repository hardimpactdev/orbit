import { queryWorkerData } from "./worker_query.ts";
import { z } from "npm:zod@4";
import { type DependencyProject } from "./dependency_project_schema.ts";
import {
    CommitSchema,
    type Index,
    indexName,
    IndexSchema,
    type Pr,
    PrSchema,
    ReportSchema,
    repositoryIdentity,
} from "./dependency_results_schema.ts";
import {
    coalescePr,
    refreshLifecycle,
    type ResultStore,
} from "./dependency_results.ts";

export interface DependencyContext {
    definition: { name: string };
    signal?: AbortSignal;
    readResource?: (
        name: string,
        version?: number,
    ) => Promise<Record<string, unknown> | null>;
    queryData(predicate: string, select?: string): Promise<unknown[]>;
    writeResource(
        spec: string,
        name: string,
        data: Record<string, unknown>,
        overrides?: { tags: Record<string, string> },
    ): Promise<{ name: string; version?: number }>;
}
const query = (ctx: DependencyContext, name: string) =>
    queryWorkerData(
        ctx,
        `modelName == ${JSON.stringify(ctx.definition.name)} && name == ${
            JSON.stringify(name)
        }`,
        "attributes",
    );
export function resultStore(ctx: DependencyContext): ResultStore {
    return {
        readIndex: async (name) => {
            const values = await query(ctx, name);
            if (values.length > 1) {
                throw new Error("duplicate_dependency_index");
            }
            return values.length ? IndexSchema.parse(values[0]) : null;
        },
        readReport: async (name, version) => {
            if (!ctx.readResource) {
                throw new Error("native_resource_reader_unavailable");
            }
            return ReportSchema.parse(await ctx.readResource(name, version));
        },
        writeIndex: async (name, value) => {
            await ctx.writeResource(
                "dependencyIndex",
                name,
                IndexSchema.parse(value),
                { tags: tags(value, value.source_ref, "index") },
            );
        },
        writeReport: async (name, value, ref) => {
            if ((await query(ctx, name)).length) {
                throw new Error("immutable_report_exists");
            }
            const handle = await ctx.writeResource(
                "dependencyReport",
                name,
                ReportSchema.parse(value),
                { tags: tags(value, ref, "report") },
            );
            if (
                !Number.isSafeInteger(handle.version) ||
                Number(handle.version) <= 0
            ) throw new Error("native_report_version_unavailable");
            return handle.version!;
        },
    };
}
function tags(
    v: { project_id: number; repository: string },
    ref: string,
    kind: string,
) {
    return {
        contract: "orbit.dependencies.v1",
        kind,
        project_id: String(v.project_id),
        repository: v.repository,
        source_ref: ref,
    };
}
export const BatchEvidenceSchema = z.strictObject({
    pr: PrSchema,
    links: z.array(z.strictObject({
        group_id: z.number().int().positive(),
        run_id: z.string().min(1).max(256),
        targets: z.array(
            z.strictObject({
                path: z.string(),
                manager: z.string(),
                package: z.string(),
                version: z.string(),
            }),
        ).max(500),
    })).max(100),
});
export type BatchLink = {
    group_id: number;
    run_id: string;
    targets: {
        path: string;
        manager: string;
        package: string;
        version: string;
    }[];
};
/** Only successful authoritative group + GitHub lookups prove PR absence. */
export async function batchEvidence(
    project: DependencyProject,
    previous: Pr | null,
    ctx: DependencyContext,
    groups: Record<string, unknown>[],
    github: (url: string) => Promise<Record<string, unknown>> = () =>
        Promise.reject(new Error("pr_observation_unavailable")),
    diagnostic: (error: unknown) => void = () => {},
): Promise<{ pr: Pr; links: BatchLink[] }> {
    const checked = new Date().toISOString(),
        repository = repositoryIdentity(project.repository),
        links: BatchLink[] = [];
    try {
        ctx.signal?.throwIfAborted();
        let value: Pr["value"] = null;
        for (const g of groups) {
            ctx.signal?.throwIfAborted();
            if (g.project_id !== project.projectId) continue;
            if (g.maintenance === null || typeof g.maintenance !== "object") {
                continue;
            }
            const maintenance = z.object({
                mode: z.enum(["routine", "package"]),
                targets: z.array(
                    z.object({
                        path: z.string(),
                        manager: z.string(),
                        package: z.string(),
                        version: z.string(),
                    }),
                ),
                run_id: z.string().optional(),
            }).parse(g.maintenance);
            const urls = [
                ...new Set(
                    [g.pr_url, g.watched_pr_url].filter((v): v is string =>
                        typeof v === "string" && v !== ""
                    ),
                ),
            ];
            if (
                urls.length > 1 ||
                (!urls.length &&
                    (!Object.hasOwn(g, "pr_url") ||
                        !Object.hasOwn(g, "watched_pr_url")))
            ) throw new Error("pr_evidence_incomplete");
            const runId = maintenance.run_id;
            if (
                !["completed", "cancelled", "failed"].includes(
                    String(g.status),
                ) &&
                runId
            ) {
                links.push({
                    group_id: Number(g.id),
                    run_id: runId,
                    targets: maintenance.targets.map((
                        { path, manager, package: name, version },
                    ) => ({
                        path,
                        manager,
                        package: name,
                        version,
                    })),
                });
            }
            if (!urls.length) continue;
            if (
                !new RegExp(
                    `^https://${
                        repository.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")
                    }/pull/[1-9][0-9]*$`,
                ).test(urls[0])
            ) throw new Error("pr_identity_mismatch");
            const pr = await github(urls[0]);
            if (
                pr.url !== urls[0] ||
                !["OPEN", "CLOSED", "MERGED"].includes(String(pr.state))
            ) throw new Error("pr_lookup_invalid");
            if (pr.state !== "OPEN") continue;
            if (!runId || value) throw new Error("batch_pr_ambiguous");
            value = {
                repository,
                number: Number(pr.number),
                url: urls[0],
                head_commit: CommitSchema.parse(pr.headRefOid),
                group_id: Number(g.id),
                run_id: runId,
            };
        }
        return {
            pr: PrSchema.parse({
                status: value ? "open" : "absent",
                checked_at: checked,
                last_success_at: checked,
                error_code: null,
                value,
            }),
            links,
        };
    } catch (error) {
        diagnostic(error);
        return {
            pr: PrSchema.parse({
                status: "unknown",
                checked_at: checked,
                last_success_at: previous?.last_success_at ?? null,
                error_code: "batch_pr_lookup_failed",
                value: previous?.value ?? null,
            }),
            links: [],
        };
    }
}
export function unknownPr(previous: Pr | null, code: string): Pr {
    return PrSchema.parse({
        status: "unknown",
        checked_at: new Date().toISOString(),
        last_success_at: previous?.last_success_at ?? null,
        error_code: code,
        value: previous?.value ?? null,
    });
}

export async function reconcileDependencyIndex(
    index: Index,
    ctx: DependencyContext,
    evidence: { pr: Pr; links: BatchLink[] },
) {
    const now = new Date().toISOString();
    if (
        index.last_attempt.status === "running" &&
        Date.now() - Date.parse(index.last_attempt.started_at) > 180000
    ) {
        const failed = {
            ...index.last_attempt,
            status: "failed" as const,
            finished_at: now,
            error_code: "scan_interrupted",
        };
        index = IndexSchema.parse({
            ...index,
            publication_id: crypto.randomUUID(),
            generated_at: now,
            last_attempt: failed,
            sources: index.sources.map((s) => ({ ...s, last_attempt: failed })),
        });
        await resultStore(ctx).writeIndex(
            await indexName(index.project_id, index.source_ref),
            index,
        );
    }
    evidence.pr = coalescePr(index.batch_pr, evidence.pr);
    // Lookup uncertainty retains existing finding links as well as the last known PR value.
    if (evidence.pr.status === "unknown") {
        if (evidence.pr === index.batch_pr) return;
        const store = resultStore(ctx);
        await store.writeIndex(
            await indexName(index.project_id, index.source_ref),
            IndexSchema.parse({
                ...index,
                publication_id: crypto.randomUUID(),
                generated_at: new Date().toISOString(),
                batch_pr: evidence.pr,
            }),
        );
        return;
    }
    await refreshLifecycle(
        index,
        evidence.pr,
        evidence.links,
        resultStore(ctx),
        new Date().toISOString(),
    );
}
