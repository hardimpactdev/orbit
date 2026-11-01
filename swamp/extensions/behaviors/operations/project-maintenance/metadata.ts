import { z } from "npm:zod@4.4.3";
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
import { behavior as p0 } from "../../projects/nckrtl-com/mod.ts";
import { behavior as p1 } from "../../projects/launch-starter-kit/mod.ts";
import { behavior as p2 } from "../../projects/launch/mod.ts";
import { behavior as p3 } from "../../projects/treadmill-website/mod.ts";
import { behavior as p4 } from "../../projects/orbit-website/mod.ts";
import { behavior as p5 } from "../../projects/recall/mod.ts";
import { behavior as p6 } from "../../projects/ai-cli-proxy/mod.ts";
import { behavior as p7 } from "../../projects/laravel-toolbar-site/mod.ts";
import { behavior as p8 } from "../../projects/operator-website/mod.ts";
import { behavior as p9 } from "../../projects/operator/mod.ts";
import { behavior as p10 } from "../../projects/fitta/mod.ts";
import { behavior as p11 } from "../../projects/house-italy/mod.ts";
import { behavior as p12 } from "../../projects/lindaretel/mod.ts";
import { behavior as p13 } from "../../projects/srpm/mod.ts";
import { behavior as p14 } from "../../projects/tankwerk/mod.ts";
import { behavior as p15 } from "../../projects/ditis-hr/mod.ts";
import { behavior as p16 } from "../../projects/platform11/mod.ts";
import { behavior as p17 } from "../../projects/hauzer/mod.ts";
import { behavior as p18 } from "../../projects/mealou/mod.ts";
const projects = [
    p0,
    p1,
    p2,
    p3,
    p4,
    p5,
    p6,
    p7,
    p8,
    p9,
    p10,
    p11,
    p12,
    p13,
    p14,
    p15,
    p16,
    p17,
    p18,
];
import { scheduleKey } from "../../_lib/project_schedule.ts";

const Snapshot = z.object({
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
    node_id: z.literal(209),
    kind: z.enum(["project.detect", "project.routine", "project.observe"]),
    intent: ProjectIntent,
}).strict();
const Public = z.record(z.string(), z.unknown());
type Context = {
    queryData(predicate: string): Promise<unknown[]>;
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
const output = async (ctx: Context, value: Record<string, unknown>) => ({
    dataHandles: [await ctx.writeResource("public", "output", value)],
});

async function configuredProject(
    snapshot: z.infer<typeof Snapshot>,
    ctx: Context,
) {
    let selected: unknown;
    // Configuration exports only transform metadata. No native method, CLI or HTTP is invoked.
    for (const project of projects) {
        let value: unknown;
        await project.methods.configuration.execute({}, {
            readResource: async (name: string) => {
                const rows = await ctx.queryData(
                    `modelType == ${JSON.stringify(project.type)} && name == ${
                        JSON.stringify(name)
                    }`,
                );
                return rows.length === 0 ? null : (rows[0] as {
                    attributes: Record<string, unknown>;
                }).attributes;
            },
            writeResource: (
                _spec: string,
                _name: string,
                data: Record<string, unknown>,
            ) => {
                value = data;
                return Promise.resolve({ name: "configuration" });
            },
        });
        // A matching stored export can be unchanged, so capture it explicitly too.
        if (!value) {
            const rows = await ctx.queryData(
                `modelType == ${
                    JSON.stringify(project.type)
                } && name == "configuration"`,
            );
            value = (rows[0] as { attributes?: unknown } | undefined)
                ?.attributes;
        }
        if (
            value &&
            (value as { projectId?: number }).projectId ===
                snapshot.id
        ) selected = value;
    }
    const project = DependencyProjectSchema.parse(
        selected && typeof selected === "object"
            ? Object.fromEntries(
                Object.entries(selected).filter(([key]) => key !== "schema"),
            )
            : selected,
    );
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
    version: "2026.11.01.1",
    resources: {
        public: { schema: Public, lifetime: "infinite", garbageCollection: 1 },
    },
    methods: {
        schedule: {
            description:
                "Select daily detection and weekly routines from full captured Gateway state",
            arguments: z.object({
                revision: z.number().int().positive(),
                snapshot_hash: z.string().regex(/^[a-f0-9]{64}$/),
                projects: z.array(Snapshot),
                groups: z.array(z.record(z.string(), z.unknown())),
                operations: z.array(z.record(z.string(), z.unknown())),
                admissions: z.record(z.string(), z.unknown()),
            }).strict(),
            async execute(
                args: {
                    revision: number;
                    snapshot_hash: string;
                    projects: z.infer<typeof Snapshot>[];
                    groups: Record<string, unknown>[];
                    operations: Record<string, unknown>[];
                    admissions: Record<string, unknown>;
                },
                ctx: Context,
            ) {
                const requests: {
                        project_id: number;
                        mode: "detect" | "routine" | "observe";
                        key: string;
                    }[] = [],
                    skipped: { project_id: number; reason: string }[] = [];
                const now = new Date();
                for (const snapshot of args.projects) {
                    let project: z.infer<typeof DependencyProjectSchema>;
                    try {
                        project = await configuredProject(snapshot, ctx);
                    } catch {
                        skipped.push({
                            project_id: snapshot.id,
                            reason: "project_configuration_unavailable",
                        });
                        continue;
                    }
                    if (
                        args.operations.some((op) =>
                            op.target_id === project.projectId
                        )
                    ) continue;
                    for (
                        const mode of ["detect", "routine", "observe"] as const
                    ) {
                        if (
                            mode === "observe" &&
                            !args.groups.some((g) =>
                                g.project_id === project.projectId &&
                                Boolean(
                                    g.pr_url || g.watched_pr_url ||
                                        (g.maintenance as {
                                            admission_pending?: boolean;
                                        } | null)?.admission_pending,
                                )
                            )
                        ) continue;
                        const key = mode === "observe"
                            ? `${project.projectId}-observe-${
                                now.toISOString().slice(0, 16)
                            }`
                            : scheduleKey(project.projectId, mode, now);
                        if (
                            (args.admissions[`${project.projectId}:${mode}`] as
                                | { key?: string }
                                | undefined)?.key === key
                        ) continue;
                        requests.push({
                            project_id: project.projectId,
                            mode,
                            key,
                        });
                        break;
                    }
                }
                return await output(ctx, {
                    result_json: JSON.stringify({
                        revision: args.revision,
                        snapshot_hash: args.snapshot_hash,
                        requests,
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
                const project = await configuredProject(
                    args.intent.project,
                    ctx,
                );
                const urls: string[] = [];
                for (
                    const group of args.intent.groups.filter((group) =>
                        group.project_id === project.projectId
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
                project: DependencyProjectSchema,
                groups: ProjectIntent.shape.groups,
                urls: z.array(z.string()),
                previous_pr: z.array(PrSchema).max(1),
                readings: z.record(z.string(), z.unknown()),
            }).strict(),
            async execute(
                args: {
                    project: z.infer<typeof DependencyProjectSchema>;
                    groups: Record<string, unknown>[];
                    urls: string[];
                    previous_pr: z.infer<typeof PrSchema>[];
                    readings: Record<string, unknown>;
                },
                ctx: Context,
            ) {
                const evidence = await batchEvidence(
                    args.project,
                    args.previous_pr[0] ?? null,
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
                        g.project_id === args.project.projectId &&
                        (g.pr_url === url || g.watched_pr_url === url)
                    ).map((g) => ({
                        group_id: g.id,
                        url,
                        state: pr.state.toLowerCase(),
                        head: pr.headRefOid,
                    }));
                });
                return await output(ctx, {
                    evidence: BatchEvidenceSchema.parse(evidence),
                    observations,
                    result_json: JSON.stringify({
                        project_id: args.project.projectId,
                        observations,
                        resume_group_ids: args.groups.filter((g) =>
                            g.project_id === args.project.projectId &&
                            g.status === "todo" &&
                            (g.maintenance as
                                    | { admission_pending?: boolean }
                                    | null)?.admission_pending === true
                        ).map((g) => g.id),
                        requests: [],
                    }),
                });
            },
        },
    },
};
