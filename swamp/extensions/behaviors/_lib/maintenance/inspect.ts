// project:maintenance:inspect without a clone: manifest blob SHAs from one Git tree, changed sources read
// by blob SHA, registry and advisory facts cached per package, and update targets under the Project policy.
import { z } from "npm:zod@4";
import { type DependencyProject } from "../dependency_project_schema.ts";
import { sources } from "../dependency_results.ts";
import {
    PackageSchema,
    type Resolution,
    type SourceIdentity,
    SourceIdentitySchema,
    sourceKey,
} from "../dependency_results_schema.ts";
import {
    isMajorJump,
    normaliseVersion,
    satisfies,
    toRange,
} from "../versions.ts";
import {
    compare,
    eligible,
    key,
    type UpdateTarget,
    UpdateTargetSchema,
} from "./batch.ts";

const LOCKS = {
    composer: "composer.lock",
    npm: "package-lock.json",
    bun: "bun.lock",
    pnpm: "pnpm-lock.yaml",
} as const;
const DAY = 86400000;

export const TreeEntrySchema = z.strictObject({
    path: z.string().min(1),
    sha: z.string().regex(/^[a-f0-9]{40,64}$/),
});
export type TreeEntry = z.infer<typeof TreeEntrySchema>;
/** sourceKey -> repository path -> blob SHA. */
export const ManifestsSchema = z.record(
    z.string(),
    z.record(z.string(), z.string().regex(/^[a-f0-9]{40,64}$/)),
);
export type Manifests = z.infer<typeof ManifestsSchema>;

export const SourcePackagesSchema = z.strictObject({
    source: SourceIdentitySchema,
    packages: z.array(PackageSchema),
    declared: z.record(z.string(), z.string()),
});
export type SourcePackages = z.infer<typeof SourcePackagesSchema>;

const prefix = (path: string) => path === "." ? "" : `${path}/`;

/** The files that decide one source's installed packages: its manifest, its lockfile and workspace manifests. */
export function manifests(
    project: DependencyProject,
    tree: TreeEntry[],
): Manifests {
    const result: Manifests = {};
    for (const source of sources(project)) {
        const base = prefix(source.path);
        const wanted = source.ecosystem === "composer"
            ? [`${base}composer.json`, `${base}composer.lock`]
            : [
                `${base}package.json`,
                `${base}${LOCKS[source.package_manager]}`,
            ];
        const files: Record<string, string> = {};
        for (const entry of tree) {
            const inside = entry.path.startsWith(base);
            const parts = entry.path.split("/");
            const workspaceManifest = source.ecosystem === "npm" && inside &&
                entry.path.endsWith("/package.json") &&
                !parts.includes("node_modules") && !parts.includes("vendor");
            if (wanted.includes(entry.path) || workspaceManifest) {
                files[entry.path] = entry.sha;
            }
        }
        for (const required of wanted) {
            if (!(required in files)) {
                throw new Error(`manifest_missing: ${required}`);
            }
        }
        result[sourceKey(source)] = Object.fromEntries(
            Object.entries(files).sort(([a], [b]) => a.localeCompare(b)),
        );
    }
    return result;
}

/** Sources whose file set or any blob SHA differs from the last inspection, and sources the policy dropped. */
export function changedSources(
    current: Manifests,
    previous: Manifests,
): string[] {
    const keys = new Set([...Object.keys(current), ...Object.keys(previous)]);
    return [...keys].filter((source) =>
        JSON.stringify(current[source] ?? null) !==
            JSON.stringify(previous[source] ?? null)
    ).sort();
}

/** Direct constraints as the manifest declares them, so a major target is one the range does not allow yet. */
export function declared(
    source: SourceIdentity,
    manifest: Record<string, unknown>,
): Record<string, string> {
    const sections = source.ecosystem === "composer"
        ? ["require", "require-dev"]
        : ["dependencies", "devDependencies", "optionalDependencies"];
    const result: Record<string, string> = {};
    for (const section of sections) {
        const value = manifest[section];
        if (value === undefined) continue;
        if (
            value === null || typeof value !== "object" || Array.isArray(value)
        ) throw new Error(`invalid_manifest_section: ${section}`);
        for (const [name, constraint] of Object.entries(value)) {
            if (typeof constraint === "string") result[name] = constraint;
        }
    }
    return result;
}

export const ReleaseSchema = z.strictObject({
    version: z.string(),
    publishedAt: z.string(),
});
export const AdvisorySchema = z.strictObject({
    id: z.string().min(1),
    url: z.string().nullable(),
    affected: z.string().min(1),
});
export const RegistryFactsSchema = z.strictObject({
    ecosystem: z.enum(["composer", "npm"]),
    name: z.string().min(1),
    fetchedAt: z.iso.datetime(),
    releases: z.array(ReleaseSchema),
    advisories: z.array(AdvisorySchema),
});
export type RegistryFacts = z.infer<typeof RegistryFactsSchema>;

/** A fact is reused for a day: new releases and advisories appear without any repository change. */
export const fresh = (facts: RegistryFacts | undefined, now: number) =>
    facts !== undefined && now - Date.parse(facts.fetchedAt) < DAY;

/** The registry packages one inspection needs facts for. */
export function registryPackages(sourcesPackages: SourcePackages[]) {
    const seen = new Map<
        string,
        { ecosystem: "composer" | "npm"; name: string }
    >();
    for (const { source, packages } of sourcesPackages) {
        for (const row of packages) {
            if (row.origin !== "registry") continue;
            seen.set(`${source.ecosystem}:${row.name}`, {
                ecosystem: source.ecosystem,
                name: row.name,
            });
        }
    }
    return [...seen.values()].sort((a, b) =>
        `${a.ecosystem}:${a.name}`.localeCompare(`${b.ecosystem}:${b.name}`)
    );
}

type Fetch = (url: string, init?: RequestInit) => Promise<Response>;
const json = async (response: Response, what: string): Promise<unknown> => {
    if (!response.ok) throw new Error(`${what}_${response.status}`);
    try {
        return await response.json();
    } catch {
        throw new Error(`${what}_invalid_json`);
    }
};
const stable = (raw: string) => {
    const version = normaliseVersion(raw);
    return version !== null && /^\d+\.\d+\.\d+$/.test(version) ? version : null;
};

/** Packagist p2 metadata is minified: each release repeats only the fields that changed. */
export async function composerFacts(
    name: string,
    now: Date,
    fetcher: Fetch = fetch,
): Promise<RegistryFacts> {
    const body = z.object({
        packages: z.record(
            z.string(),
            z.array(z.record(z.string(), z.unknown())),
        ),
    })
        .parse(
            await json(
                await fetcher(`https://repo.packagist.org/p2/${name}.json`),
                "packagist",
            ),
        );
    const rows = body.packages[name];
    if (!rows) throw new Error("packagist_package_missing");
    let inherited: Record<string, unknown> = {};
    const releases: RegistryFacts["releases"] = [];
    for (const row of rows) {
        inherited = { ...inherited, ...row };
        const version = typeof inherited.version === "string"
            ? stable(inherited.version)
            : null;
        if (version !== null && typeof inherited.time === "string") {
            releases.push({ version, publishedAt: inherited.time });
        }
    }
    const advisories = z.object({
        advisories: z.record(
            z.string(),
            z.array(z.record(z.string(), z.unknown())),
        ),
    })
        .parse(
            await json(
                await fetcher(
                    `https://packagist.org/api/security-advisories/?packages[]=${
                        encodeURIComponent(name)
                    }`,
                ),
                "packagist_advisories",
            ),
        );
    return RegistryFactsSchema.parse({
        ecosystem: "composer",
        name,
        fetchedAt: now.toISOString(),
        releases,
        advisories: (advisories.advisories[name] ?? []).flatMap((row) => {
            const range = typeof row.affectedVersions === "string"
                ? toRange(
                    "composer",
                    row.affectedVersions.replaceAll("|", "||"),
                )
                : null;
            return typeof row.advisoryId === "string" && range !== null
                ? [{
                    id: row.advisoryId,
                    url: typeof row.link === "string" ? row.link : null,
                    affected: range,
                }]
                : [];
        }),
    });
}

/** The full npm document carries release times; the bulk advisory endpoint answers for every release at once. */
export async function npmFacts(
    name: string,
    now: Date,
    fetcher: Fetch = fetch,
): Promise<RegistryFacts> {
    const body = z.object({
        name: z.string(),
        versions: z.record(z.string(), z.unknown()).default({}),
        time: z.record(z.string(), z.string()).default({}),
    })
        .parse(
            await json(
                await fetcher(
                    `https://registry.npmjs.org/${name.replace("/", "%2F")}`,
                ),
                "npm",
            ),
        );
    if (body.name !== name) throw new Error("npm_foreign_package");
    const releases = Object.entries(body.time).flatMap(([raw, publishedAt]) => {
        const version = stable(raw);
        // `time` also lists unpublished releases; only those still in `versions` can be installed.
        return version === null || version !== raw ||
                !Object.hasOwn(body.versions, raw)
            ? []
            : [{ version, publishedAt }];
    });
    const answer = z.record(
        z.string(),
        z.array(z.record(z.string(), z.unknown())),
    ).parse(
        await json(
            await fetcher(
                "https://registry.npmjs.org/-/npm/v1/security/advisories/bulk",
                {
                    method: "POST",
                    headers: { "content-type": "application/json" },
                    body: JSON.stringify({
                        [name]: releases.map((r) => r.version),
                    }),
                },
            ),
            "npm_advisories",
        ),
    );
    return RegistryFactsSchema.parse({
        ecosystem: "npm",
        name,
        fetchedAt: now.toISOString(),
        releases,
        advisories: (answer[name] ?? []).flatMap((row) =>
            (typeof row.id === "number" || typeof row.id === "string") &&
                typeof row.vulnerable_versions === "string"
                ? [{
                    id: String(row.id),
                    url: typeof row.url === "string" ? row.url : null,
                    affected: row.vulnerable_versions,
                }]
                : []
        ),
    });
}

/** An exception keeps a package out of maintenance until its recorded days have passed. */
function excepted(
    project: DependencyProject,
    target: Pick<UpdateTarget, "path" | "manager" | "package">,
    now: number,
) {
    return project.exceptions.exceptions.some((row) =>
        row.path === target.path && row.manager === target.manager &&
        row.package === target.package &&
        now < Date.parse(`${row.recorded_on}T00:00:00Z`) + row.days * DAY
    );
}

/** The union of two security targets' advisories, in a stable order. */
function merged(prior: UpdateTarget | undefined, next: UpdateTarget) {
    if (prior?.purpose !== "security") return {};
    const rows = new Map<string, string>();
    [prior, next].forEach((t) =>
        t.advisories.forEach((id, i) => rows.set(id, t.advisoryUrls[i]))
    );
    const ids = [...rows.keys()].sort();
    return { advisories: ids, advisoryUrls: ids.map((id) => rows.get(id)!) };
}

export type Blocked = {
    path: string;
    manager: "composer" | "js";
    package: string;
    reason: string;
};

/**
 * Majors for direct dependencies whose declared range does not allow the newest eligible release yet, and
 * the lowest eligible fix for any installed package an advisory affects. The task VM resolves the batch.
 */
export function updates(
    project: DependencyProject,
    sourcesPackages: SourcePackages[],
    facts: Map<string, RegistryFacts>,
    now: number,
): { targets: UpdateTarget[]; blocked: Blocked[] } {
    const targets = new Map<string, UpdateTarget>(), blocked: Blocked[] = [];
    for (const { source, packages, declared: ranges } of sourcesPackages) {
        const manager = source.ecosystem === "composer" ? "composer" : "js";
        const days =
            project.worker.policy[source.path]?.minimumReleaseAgeDays ?? 0;
        for (const row of packages) {
            const fact = facts.get(`${source.ecosystem}:${row.name}`);
            const current = stable(row.version);
            if (
                row.origin !== "registry" || fact === undefined ||
                current === null
            ) continue;
            const identity = {
                path: source.path,
                manager,
                package: row.name,
            } as const;
            if (excepted(project, identity, now)) continue;
            const affecting = fact.advisories.filter((a) =>
                satisfies(current, a.affected)
            );
            if (affecting.length) {
                const fix = eligible(fact.releases, current, days, now)
                    .reverse()
                    .find((r) =>
                        !fact.advisories.some((a) =>
                            satisfies(r.version, a.affected)
                        )
                    );
                const parsed = fix && affecting.every((a) =>
                        a.url?.startsWith("https://")
                    )
                    ? UpdateTargetSchema.safeParse({
                        ...identity,
                        version: fix.version,
                        purpose: "security",
                        advisories: affecting.map((a) => a.id),
                        advisoryUrls: affecting.map((a) => a.url),
                    })
                    : undefined;
                // Several locked copies of one package share a target: keep the highest fix and every advisory.
                const prior = targets.get(key(identity));
                if (parsed?.success) {
                    targets.set(
                        key(identity),
                        prior?.purpose !== "security" ||
                            compare(parsed.data.version, prior.version) > 0
                            ? { ...parsed.data, ...merged(prior, parsed.data) }
                            : { ...prior, ...merged(prior, parsed.data) },
                    );
                } else {blocked.push({
                        ...identity,
                        reason: fix
                            ? "Advisory has no verified source URL"
                            : "No eligible release fixes the advisory",
                    });}
                continue;
            }
            const range = ranges[row.name];
            const constraint = range === undefined
                ? null
                : toRange(source.ecosystem, range);
            if (!row.direct || constraint === null) continue;
            const newest = eligible(fact.releases, current, days, now)[0];
            if (
                newest === undefined || satisfies(newest.version, constraint) ||
                !isMajorJump(current, newest.version)
            ) continue;
            const parsed = UpdateTargetSchema.safeParse({
                ...identity,
                version: newest.version,
                purpose: "major",
                advisories: [],
                advisoryUrls: [],
            });
            if (parsed.success) targets.set(key(identity), parsed.data);
            else {blocked.push({
                    ...identity,
                    reason: parsed.error.issues.map((i) => i.message).join(
                        "; ",
                    ),
                });}
        }
    }
    return {
        targets: [...targets.values()].sort((a, b) =>
            key(a).localeCompare(key(b))
        ),
        blocked,
    };
}

export type { Resolution };
