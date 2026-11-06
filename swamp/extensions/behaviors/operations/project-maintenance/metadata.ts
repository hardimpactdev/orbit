import { z } from "npm:zod@4.4.3";
import projects from "./projects.json" with { type: "json" };
import { DependencyProjectSchema } from "../../_lib/dependency_project_schema.ts";
import {
    indexName,
    IndexSchema,
    PrSchema,
    repositoryIdentity,
} from "../../_lib/dependency_results_schema.ts";
import {
    batchEvidence,
    BatchEvidenceSchema,
} from "../../_lib/service_detection.ts";
import { scheduleKey, scheduleReceipt } from "../../_lib/project_schedule.ts";

export const Snapshot = z.object({
    id: z.number().int().positive(),
    repository: z.string(),
    default_branch: z.string().min(1),
    check: z.string().min(1),
}).strict();
export const ProjectIntent = z.object({
    project: Snapshot,
    groups: z.array(z.record(z.string(), z.unknown())),
    admitted_at: z.iso.datetime(),
}).strict();
const Envelope = z.object({
    operation_id: z.number().int().positive(),
    node_id: z.number().int().positive(),
    kind: z.enum(["project.detect", "project.routine", "project.observe"]),
    intent: ProjectIntent,
}).strict();
const Public = z.record(z.string(), z.unknown());
type Context = {
    queryData(predicate: string): Promise<unknown[]>;
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
const output = async (
    ctx: Pick<Context, "writeResource">,
    value: Record<string, unknown>,
) => ({
    dataHandles: [await ctx.writeResource("public", "output", value)],
});

/** The source-owned policy of one Project, from `projects.json`. Maintenance is opt-in: any other Project is refused by name. */
export function configuredProject(snapshot: z.infer<typeof Snapshot>) {
    const owner = projects.find((project) => project.projectId === snapshot.id);
    if (owner === undefined) {
        throw new Error("project.maintenance_not_configured");
    }
    const project = DependencyProjectSchema.parse(owner);
    if (
        repositoryIdentity(project.repository) !==
            repositoryIdentity(snapshot.repository) ||
        project.defaultBranch !==
            snapshot.default_branch ||
        project.check !== snapshot.check
    ) {
        throw new Error("project_settings_mismatch");
    }
    return project;
}

/** Metadata only: source-owned Project policy and admitted public Gateway state. */
export const behavior = {
    type: "@nckrtl/project-maintenance-input",
    version: "2026.11.06.1",
    resources: {
        public: { schema: Public, lifetime: "infinite", garbageCollection: 1 },
    },
    methods: {
        routine: {
            description:
                "Select this week's routine group from the captured Project and its task groups",
            arguments: ProjectIntent,
            async execute(raw: z.infer<typeof ProjectIntent>, ctx: Context) {
                const args = ProjectIntent.parse(raw);
                const project = configuredProject(args.project);
                const desired = scheduleReceipt(
                    project,
                    "routine",
                    new Date(args.admitted_at),
                );
                // An earlier attempt needs no record here: the Gateway admits one project.* task at a time and reuses
                // the group whose brief carries this key, so a retry can never create a second group.
                const blocked = args.groups.some((group) =>
                    group.project_id === project.projectId &&
                    group.brief !== desired.request.brief &&
                    (!["completed", "cancelled", "failed"].includes(
                        String(group.status),
                    ) || group.taskable_id != null)
                );
                return await output(ctx, {
                    result: {
                        project_id: project.projectId,
                        key: desired.key,
                        requests: blocked ? [] : [{
                            action: "create_group",
                            project_id: project.projectId,
                            key: desired.key,
                            input: desired.request,
                            maintenance: {
                                mode: "routine",
                                project,
                                targets: [],
                            },
                        }],
                        blocked: blocked ? ["project_has_unfinished_work"] : [],
                    },
                });
            },
        },
        schedule: {
            description:
                "Tick of the hourly schedule: list the Projects this source configures, with the request keys of the fire; the Gateway selects the work from its own state",
            arguments: z.object({}).strict(),
            async execute(
                _args: Record<string, never>,
                ctx: Pick<Context, "writeResource">,
            ) {
                const now = new Date();
                const configured: {
                    id: number;
                    repository: string;
                    default_branch: string;
                    check: string;
                    keys: Record<"routine" | "observe" | "detect", string>;
                }[] = [];
                const skipped: { project_id: number; reason: string }[] = [];
                for (const entry of projects) {
                    try {
                        const project = DependencyProjectSchema.parse(entry);
                        configured.push({
                            id: project.projectId,
                            repository: repositoryIdentity(project.repository),
                            default_branch: project.defaultBranch,
                            check: project.check,
                            keys: {
                                routine: scheduleKey(
                                    project.projectId,
                                    "routine",
                                    now,
                                ),
                                observe: `${project.projectId}-observe-${
                                    now.toISOString().slice(0, 16)
                                }`,
                                detect: scheduleKey(
                                    project.projectId,
                                    "detect",
                                    now,
                                ),
                            },
                        });
                    } catch {
                        skipped.push({
                            project_id: entry.projectId,
                            reason: "project_configuration_unavailable",
                        });
                    }
                }
                return await output(ctx, {
                    result_json: JSON.stringify({
                        projects: configured,
                        skipped,
                    }),
                });
            },
        },
        read: {
            description:
                "Bind source-owned maintenance policy to the captured Project settings",
            arguments: Envelope,
            async execute(raw: z.infer<typeof Envelope>, ctx: Context) {
                const args = Envelope.parse(raw);
                const project = configuredProject(args.intent.project);
                const urls: string[] = [];
                for (
                    // Only open groups have pull requests to watch; ended groups keep URLs from older repository names.
                    const group of args.intent.groups.filter((group) =>
                        group.project_id === project.projectId &&
                        !["completed", "cancelled", "failed"].includes(
                            String(group.status),
                        )
                    )
                ) {
                    const candidates = [group.pr_url, group.watched_pr_url];
                    urls.push(
                        ...candidates.filter((url): url is string =>
                            typeof url === "string" && url !== ""
                        ),
                    );
                }
                const indexes = await ctx.queryData(
                    `modelName == "orbit-gateway" && name == ${
                        JSON.stringify(
                            await indexName(
                                project.projectId,
                                `refs/heads/${project.defaultBranch}`,
                            ),
                        )
                    }`,
                );
                if (indexes.length > 1) {
                    throw new Error("duplicate_dependency_index");
                }
                const previous = indexes.length
                    ? IndexSchema.parse(
                        (indexes[0] as { attributes: unknown }).attributes,
                    ).batch_pr
                    : null;
                const repository = repositoryIdentity(project.repository);
                const prPattern = new RegExp(
                    `^https://${
                        repository.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")
                    }/pull/[1-9][0-9]*$`,
                );
                if (urls.some((url) => !prPattern.test(url))) {
                    throw new Error("pr_identity_mismatch");
                }
                return await output(ctx, {
                    project,
                    groups: args.intent.groups,
                    pr_urls: [...new Set(urls)],
                    previous_pr: previous === null ? [] : [previous],
                    previous_index: indexes.length
                        ? IndexSchema.parse(
                            (indexes[0] as { attributes: unknown }).attributes,
                        )
                        : null,
                    clone_url: `https://${
                        repositoryIdentity(project.repository)
                    }.git`,
                    source_ref: `refs/heads/${project.defaultBranch}`,
                });
            },
        },
        evidence: {
            description:
                "Combine captured groups and bounded native GitHub observations",
            arguments: z.object({
                project: Snapshot,
                groups: ProjectIntent.shape.groups,
                urls: z.array(z.string()),
                previous_pr: z.array(PrSchema).max(1),
                readings: z.record(z.string(), z.unknown()),
            }).strict(),
            async execute(
                args: {
                    project: z.infer<typeof Snapshot>;
                    groups: Record<string, unknown>[];
                    urls: string[];
                    previous_pr: z.infer<typeof PrSchema>[];
                    readings: Record<string, unknown>;
                },
                ctx: Context,
            ) {
                const project = configuredProject(args.project);
                const indexes = await ctx.queryData(
                    `modelName == "orbit-gateway" && name == ${
                        JSON.stringify(
                            await indexName(
                                project.projectId,
                                `refs/heads/${project.defaultBranch}`,
                            ),
                        )
                    }`,
                );
                if (indexes.length > 1) {
                    throw new Error("duplicate_dependency_index");
                }
                const previous = indexes.length
                    ? IndexSchema.parse(
                        (indexes[0] as { attributes: unknown }).attributes,
                    )
                    : null;
                const evidence = await batchEvidence(
                    project,
                    previous?.batch_pr ?? args.previous_pr[0] ?? null,
                    {
                        ...ctx,
                        definition: { name: "orbit-gateway" },
                        writeResource: () =>
                            Promise.reject(new Error("evidence_is_read_only")),
                    },
                    args.groups,
                    (url) => {
                        const index = args.urls.indexOf(url);
                        const step = args.readings[`pr-${index}`] as {
                            status?: string;
                            outputs?: { stdout?: string };
                        } | undefined;
                        if (
                            index < 0 || step?.status !== "succeeded" ||
                            typeof step.outputs?.stdout !== "string"
                        ) throw new Error("pr_observation_unavailable");
                        return Promise.resolve(JSON.parse(step.outputs.stdout));
                    },
                );
                const observations = args.urls.flatMap((url, index) => {
                    const step = args.readings[`pr-${index}`] as {
                        status?: string;
                        outputs?: { stdout?: string };
                    } | undefined;
                    if (step?.status !== "succeeded" || !step.outputs?.stdout) {
                        return [];
                    }
                    const pr = z.object({
                        number: z.number().int().positive(),
                        url: z.string(),
                        state: z.enum(["OPEN", "CLOSED", "MERGED"]),
                        headRefOid: z.string().regex(/^[a-f0-9]{40,64}$/),
                    }).parse(JSON.parse(step.outputs.stdout));
                    if (pr.url !== url || !url.endsWith(`/pull/${pr.number}`)) {
                        throw new Error("pr_identity_mismatch");
                    }
                    return args.groups.filter((g) =>
                        g.project_id === project.projectId &&
                        (g.pr_url === url || g.watched_pr_url === url)
                    ).map((g) => ({
                        group_id: g.id,
                        url,
                        state: pr.state.toLowerCase(),
                        head: pr.headRefOid,
                    }));
                });
                return await output(ctx, {
                    project,
                    previous_index: previous,
                    evidence: BatchEvidenceSchema.parse(evidence),
                    observations,
                    result: {
                        project_id: project.projectId,
                        observations,
                        resume_group_ids: args.groups.filter((g) =>
                            g.project_id === project.projectId &&
                            g.status === "todo" &&
                            (g.maintenance as
                                    | { admission_pending?: boolean }
                                    | null)?.admission_pending === true
                        ).map((g) => g.id),
                        requests: [],
                    },
                });
            },
        },
    },
};
