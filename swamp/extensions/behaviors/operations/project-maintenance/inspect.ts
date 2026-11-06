import { z } from "npm:zod@4.4.3";
import { join } from "node:path";
import { configuredProject, Snapshot } from "./metadata.ts";
import { inventory } from "../../_lib/dependency_inventory.ts";
import { sources } from "../../_lib/dependency_results.ts";
import { sourceKey } from "../../_lib/dependency_results_schema.ts";
import { batchReceipt, FindingsSchema } from "../../_lib/project_schedule.ts";
import { sha256 } from "../../_lib/canonical_json.ts";
import {
    changedSources,
    composerFacts,
    declared,
    fresh,
    manifests,
    ManifestsSchema,
    npmFacts,
    type RegistryFacts,
    RegistryFactsSchema,
    registryPackages,
    type SourcePackages,
    SourcePackagesSchema,
    TreeEntrySchema,
    updates,
} from "../../_lib/maintenance/inspect.ts";

type Context = {
    queryData(predicate: string): Promise<unknown[]>;
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
const Public = z.record(z.string(), z.unknown());
const Commit = z.string().regex(/^[a-f0-9]{40}$/);
// This run's directory on Node 209: the shell steps write GitHub's answers there, these methods read them.
const Root = z.string().regex(
    /^\/home\/orbit\/\.cache\/orbit-inspect\/[0-9a-f-]{36}$/,
);

const factName = async (ecosystem: string, name: string) =>
    `registry-${ecosystem}-${(await sha256(name)).slice(0, 40)}`;

async function storedFacts(ctx: Context): Promise<Map<string, RegistryFacts>> {
    const facts = new Map<string, RegistryFacts>();
    // Each Project's run writes facts under its own instance; the newest answer for a package wins.
    for (
        const row of await ctx.queryData(
            `modelType == "@nckrtl/project-maintenance-inspect" && name.startsWith("registry-")`,
        )
    ) {
        const parsed = RegistryFactsSchema.safeParse(
            (row as { attributes: unknown }).attributes,
        );
        const key = parsed.success
            ? `${parsed.data.ecosystem}:${parsed.data.name}`
            : "";
        if (
            parsed.success &&
            (facts.get(key)?.fetchedAt ?? "") < parsed.data.fetchedAt
        ) facts.set(key, parsed.data);
    }
    return facts;
}

/** Bounded concurrency for registry reads, so one inspection never floods Packagist or npm. */
async function each<T>(
    items: T[],
    limit: number,
    work: (item: T) => Promise<void>,
) {
    const queue = [...items];
    await Promise.all(
        Array.from({ length: Math.min(limit, queue.length) }, async () => {
            for (
                let item = queue.shift();
                item !== undefined;
                item = queue.shift()
            ) await work(item);
        }),
    );
}

export const behavior = {
    type: "@nckrtl/project-maintenance-inspect",
    version: "2026.11.03.1",
    resources: {
        public: { schema: Public, lifetime: "infinite", garbageCollection: 1 },
        facts: {
            schema: RegistryFactsSchema,
            lifetime: "infinite",
            garbageCollection: 1,
        },
    },
    methods: {
        select: {
            description:
                "Pick each policy source's manifest and lockfile blob SHAs from the tree just read, and the sources that changed",
            arguments: z.object({
                project: Snapshot,
                commit: Commit,
                root: Root,
                previous: ManifestsSchema,
            }).strict(),
            async execute(
                args: {
                    project: z.infer<typeof Snapshot>;
                    commit: string;
                    root: string;
                    previous: z.infer<typeof ManifestsSchema>;
                },
                ctx: Context,
            ) {
                const project = configuredProject(args.project);
                const tree = z.object({
                    sha: z.string(),
                    truncated: z.boolean(),
                    tree: z.array(
                        z.object({
                            path: z.string(),
                            type: z.string(),
                            sha: z.string(),
                        }),
                    ),
                })
                    .parse(
                        JSON.parse(
                            await Deno.readTextFile(
                                join(args.root, "tree.json"),
                            ),
                        ),
                    );
                if (tree.truncated) {
                    throw new Error(
                        "project.scan_failed: the Git tree is too large to read in one request",
                    );
                }
                const current = manifests(
                    project,
                    tree.tree.filter((e) => e.type === "blob").map((e) =>
                        TreeEntrySchema.parse({ path: e.path, sha: e.sha })
                    ),
                );
                const changed = changedSources(current, args.previous);
                // The next step reads only these blobs; without a change no step needs the directory.
                const fetch = changed.flatMap((key) =>
                    Object.entries(current[key] ?? {}).map(([path, sha]) =>
                        `${sha} ${path}`
                    )
                );
                await Deno.remove(
                    changed.length ? join(args.root, "tree.json") : args.root,
                    { recursive: true },
                );
                return {
                    dataHandles: [
                        await ctx.writeResource("public", "manifests", {
                            commit: args.commit,
                            manifests: current,
                            changed,
                            fetch: fetch.join("\n"),
                        }),
                    ],
                };
            },
        },
        packages: {
            description:
                "List the installed packages of the changed sources from the files just read; keep unchanged sources",
            arguments: z.object({
                project: Snapshot,
                root: Root,
                changed: z.array(z.string()),
                previous: z.array(SourcePackagesSchema),
            }).strict(),
            async execute(
                args: {
                    project: z.infer<typeof Snapshot>;
                    root: string;
                    changed: string[];
                    previous: SourcePackages[];
                },
                ctx: Context,
            ) {
                const project = configuredProject(args.project);
                const result: SourcePackages[] = [];
                try {
                    const files = join(args.root, "source");
                    for (const source of sources(project)) {
                        const kept = args.previous.find((row) =>
                            sourceKey(row.source) === sourceKey(source)
                        );
                        if (
                            !args.changed.includes(sourceKey(source)) &&
                            kept !== undefined
                        ) {
                            result.push(kept);
                            continue;
                        }
                        const manifest = JSON.parse(
                            await Deno.readTextFile(
                                join(
                                    files,
                                    source.path,
                                    source.ecosystem === "composer"
                                        ? "composer.json"
                                        : "package.json",
                                ),
                            ),
                        );
                        result.push(
                            SourcePackagesSchema.parse({
                                source,
                                packages: await inventory(files, source),
                                declared: declared(source, manifest),
                            }),
                        );
                    }
                } finally {
                    await Deno.remove(args.root, { recursive: true });
                }
                return {
                    dataHandles: [
                        await ctx.writeResource("public", "packages", {
                            sources: result,
                        }),
                    ],
                };
            },
        },
        registries: {
            description:
                "Refresh the latest releases and advisories of every listed package older than a day, cached across Projects",
            arguments: z.object({ sources: z.array(SourcePackagesSchema) })
                .strict(),
            async execute(args: { sources: SourcePackages[] }, ctx: Context) {
                const facts = await storedFacts(ctx), now = new Date();
                const stale = registryPackages(args.sources).filter((p) =>
                    !fresh(facts.get(`${p.ecosystem}:${p.name}`), now.getTime())
                );
                const handles: unknown[] = [];
                await each(stale, 8, async (p) => {
                    const value = p.ecosystem === "composer"
                        ? await composerFacts(p.name, now)
                        : await npmFacts(p.name, now);
                    handles.push(
                        await ctx.writeResource(
                            "facts",
                            await factName(p.ecosystem, p.name),
                            value,
                        ),
                    );
                });
                handles.push(
                    await ctx.writeResource("public", "registries", {
                        checked_at: now.toISOString(),
                        refreshed: stale.length,
                    }),
                );
                return { dataHandles: handles };
            },
        },
        updates: {
            description:
                "Find the eligible majors and advisory fixes under the Project policy, as one package group request",
            arguments: z.object({
                project: Snapshot,
                commit: Commit,
                sources: z.array(SourcePackagesSchema),
            }).strict(),
            async execute(
                args: {
                    project: z.infer<typeof Snapshot>;
                    commit: string;
                    sources: SourcePackages[];
                },
                ctx: Context,
            ) {
                const project = configuredProject(args.project);
                const { targets, blocked } = updates(
                    project,
                    args.sources,
                    await storedFacts(ctx),
                    Date.now(),
                );
                const findings = FindingsSchema.parse({
                    schema: 1,
                    key: `${project.projectId}-${args.commit}`,
                    projectId: project.projectId,
                    source: args.commit,
                    sourceCommit: args.commit,
                    reports: [],
                    plans: [],
                    targets,
                    blocked: [],
                });
                const requests = targets.length === 0 ? [] : [
                    await (async () => {
                        const receipt = await batchReceipt(
                            project,
                            findings,
                        );
                        return {
                            action: "create_group",
                            project_id: project.projectId,
                            key: receipt.key,
                            input: receipt.request,
                            maintenance: {
                                mode: "package",
                                project,
                                targets,
                                source_commit: args.commit,
                            },
                        };
                    })(),
                ];
                return {
                    dataHandles: [
                        await ctx.writeResource("public", "updates", {
                            targets,
                            blocked,
                            requests,
                        }),
                    ],
                };
            },
        },
    },
};
