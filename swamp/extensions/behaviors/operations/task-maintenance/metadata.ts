import { DependencyProjectSchema } from "../../_lib/dependency_project_schema.ts";
import {
    assertFreshTarget,
    compare,
    targetRange,
    type UpdateTarget,
    UpdateTargetSchema,
    z,
} from "../../_lib/maintenance/batch.ts";
import { versions as composerVersions } from "../../_lib/maintenance/composer.ts";
import {
    coupled,
    descriptor,
    direct,
    lockDecode,
    type Workspace,
} from "../../js-maintenance/_lib/workspace.ts";
import {
    bunAdvisories,
    npmAdvisories,
} from "../../js-maintenance/_lib/reports.ts";
// @deno-types="npm:@types/semver@7.7.1"
import { satisfies } from "npm:semver@7.7.2";

const Id = z.number().int().positive();
const Place = z.object({
    path: z.string(),
    manager: z.enum(["composer", "js"]),
    checkout_path: z.string().startsWith("/"),
    mode: z.enum(["routine", "package"]),
    targets: z.array(UpdateTargetSchema),
    minimum_days: z.number().min(0),
});
const Snapshot = z.object({
    directory: z.string(),
    files: z.record(z.string(), z.string()),
    node_modules: z.boolean(),
});
type Place = z.infer<typeof Place>;
type Snapshot = z.infer<typeof Snapshot>;
function workspace(p: Place, s: Snapshot) {
    const manifest = JSON.parse(
        s.files[p.manager === "composer" ? "composer.json" : "package.json"],
    );
    if (p.manager === "composer") {
        return {
            manifest,
            manager: "composer" as const,
            vitePlus: false,
            expo: false,
        };
    }
    const signal = manifest.packageManager ??
        manifest.devEngines?.packageManager?.name;
    const declared = typeof signal === "string" ? signal.split("@")[0] : null;
    const locks = ["npm", "bun"].filter((m) =>
        s.files[m === "npm" ? "package-lock.json" : "bun.lock"] !== undefined
    );
    if (locks.length !== 1 || (declared !== null && declared !== locks[0])) {
        throw Error("maintenance_manager_ambiguous");
    }
    return {
        manifest,
        manager: locks[0] as "npm" | "bun",
        vitePlus: "vite-plus" in direct(manifest),
        expo: "expo" in direct(manifest),
    };
}
function locked(p: Place, s: Snapshot): Record<string, string> {
    const w = workspace(p, s);
    if (w.manager === "composer") {
        return composerVersions(s.files["composer.lock"] ?? null);
    }
    const packages = lockDecode(
        s.files[w.manager === "npm" ? "package-lock.json" : "bun.lock"],
        w.manager,
    ).packages as Record<string, unknown>;
    const result: Record<string, string> = {};
    for (const [name, row] of Object.entries(packages)) {
        if (w.manager === "npm") {
            const r = row as { version?: string; name?: string };
            if (name && r.version) result[name] = r.version;
        } else if (Array.isArray(row) && typeof row[0] === "string") {
            const [n, v] = descriptor(row[0]);
            result[n] = v;
        }
    }
    return result;
}
function audit(
    p: Place,
    s: Snapshot,
    text: string,
): Record<string, { id: string }[]> {
    const report = z.object({
        code: z.number().int().min(0).max(3),
        stdout: z.string(),
    }).parse(JSON.parse(text));
    const data = JSON.parse(report.stdout), w = workspace(p, s);
    if (data.error) throw Error("maintenance_audit_failed");
    if (w.manager === "composer") {
        if (!data.advisories || typeof data.advisories !== "object") {
            throw Error("maintenance_audit_unreadable");
        }
        return Object.fromEntries(
            Object.entries(data.advisories).map((
                [name, rows],
            ) => [
                name,
                Object.values(rows as object).map((r) => ({
                    id: z.string().min(1).parse(
                        (r as { advisoryId?: unknown }).advisoryId,
                    ),
                })),
            ]),
        );
    }
    const js = {
        ...w,
        path: p.path,
        dir: s.directory,
        direct: direct(w.manifest),
    } as Workspace;
    const packages = lockDecode(
        s.files[w.manager === "npm" ? "package-lock.json" : "bun.lock"],
        w.manager,
    ).packages as Record<string, unknown>;
    return w.manager === "bun"
        ? bunAdvisories(data, js, packages)
        : npmAdvisories(data, js, packages, true);
}
const writeData = async (ctx: Context, value: Record<string, unknown>) => ({
    dataHandles: [await ctx.writeResource("public", "output", value)],
});
type Context = {
    extensionFile(name: string): string;
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
    signal?: AbortSignal;
};
export function plan(p: Place, s: Snapshot, report: string) {
    const w = workspace(p, s),
        before = locked(p, s),
        findings = audit(p, s, report),
        after = structuredClone(w.manifest);
    const sections = p.manager === "composer"
        ? ["require", "require-dev"]
        : ["dependencies", "devDependencies", "optionalDependencies"];
    for (const t of p.targets) {
        assertFreshTarget(
            t,
            before[
                w.manager === "npm" ? `node_modules/${t.package}` : t.package
            ],
            findings[t.package] ?? [],
        );
        const found = sections.filter((key) => t.package in (after[key] ?? {}));
        if (found.length > 1 || (p.manager === "js" && found.length !== 1)) {
            throw Error("maintenance_direct_target_required");
        }
        if (found.length) {
            after[found[0]][t.package] = targetRange(
                z.string().parse(after[found[0]][t.package]),
                t,
            );
        }
    }
    if (
        p.manager === "js" && w.expo &&
        p.targets.some((t) => coupled(t.package)) &&
        !p.targets.some((t) => t.package === "expo")
    ) throw Error("maintenance_expo_batch_required");
    const prepare = w.manager !== "composer" && w.vitePlus && !s.node_modules
        ? {
            argv: [
                w.manager,
                ...(w.manager === "npm"
                    ? ["ci", "--ignore-scripts"]
                    : ["install", "--frozen-lockfile", "--ignore-scripts"]),
            ],
        }
        : null;
    const update = p.mode === "routine"
        ? {
            argv: w.manager === "composer"
                ? ["composer", "update", "--no-interaction"]
                : [w.vitePlus ? "./node_modules/.bin/vp" : w.manager, "update"],
        }
        : {
            argv: w.manager === "composer"
                ? [
                    "composer",
                    "update",
                    ...p.targets.map((t) => t.package),
                    ...p.targets.flatMap(
                        (t) => ["--with", `${t.package}:${t.version}`],
                    ),
                    "--with-dependencies",
                    "--no-interaction",
                ]
                : [w.manager, "install"],
        };
    const declarations = structuredClone(after);
    if (p.mode === "package" && p.manager === "js") {
        for (const t of p.targets) {
            for (const section of sections) {
                if (t.package in (after[section] ?? {})) {
                    after[section][t.package] = t.version;
                }
            }
        }
    }
    return {
        patch: { before: w.manifest, after },
        declarations,
        prepare,
        update,
        targets: p.targets,
        manager: w.manager,
        expo: w.expo,
        expo_url: p.targets.some((t) => t.package === "expo")
            ? `https://registry.npmjs.org/expo/-/expo-${
                p.targets.find((t) => t.package === "expo")!.version
            }.tgz`
            : null,
    };
}
export function verify(
    p: Place,
    before: Snapshot,
    after: Snapshot,
    report: string,
) {
    const a = locked(p, before),
        b = locked(p, after),
        findings = audit(p, after, report);
    for (const t of p.targets) {
        if (
            b[
                workspace(p, after).manager === "npm"
                    ? `node_modules/${t.package}`
                    : t.package
            ] !== t.version
        ) throw Error("maintenance_exact_lock_mismatch");
        if (t.purpose === "security" && findings[t.package]?.length) {
            throw Error("maintenance_advisory_unfixed");
        }
    }
    for (const [name, version] of Object.entries(b)) {
        if (a[name] && compare(a[name], version) > 0) {
            throw Error("maintenance_downgrade");
        }
    }
    return Object.entries(b).filter(([name, v]) => a[name] !== v).map((
        [key, version],
    ) => ({
        key,
        name: key.split("node_modules/").at(-1)!,
        version,
        before: a[key] ?? null,
    }));
}
export function verifyExpo(
    p: Place,
    snapshot: Snapshot,
    catalog: Record<string, string>,
) {
    const expo = p.targets.find((t) => t.package === "expo");
    if (!expo) throw Error("maintenance_expo_batch_required");
    const declared = direct(workspace(p, snapshot).manifest);
    for (const [name, range] of Object.entries(catalog)) {
        if (!(name in declared) || !coupled(name)) continue;
        const selected = p.targets.find((t) => t.package === name);
        if (!selected || !satisfies(selected.version, range)) {
            throw Error("maintenance_expo_catalog_mismatch");
        }
    }
    return { verified: true };
}
export const behavior = {
    type: "@nckrtl/task-maintenance-input",
    version: "2026.11.01.1",
    resources: {
        public: {
            schema: z.record(z.string(), z.unknown()),
            lifetime: "infinite",
            garbageCollection: 10,
        },
    },
    methods: {
        read: {
            description:
                "Capture configured maintenance paths and co-located scripts",
            arguments: z.object({
                intent: z.object({
                    group_id: Id,
                    instance_id: Id,
                    task_vm_id: Id,
                    project_id: Id,
                    checkout_path: z.string(),
                    base: z.string().regex(/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/),
                    maintenance: z.object({
                        mode: z.enum(["routine", "package"]),
                        project: DependencyProjectSchema,
                        targets: z.array(UpdateTargetSchema),
                    }).passthrough(),
                }).passthrough(),
            }),
            async execute(
                args: {
                    intent: {
                        checkout_path: string;
                        maintenance: {
                            mode: "routine" | "package";
                            project: z.infer<typeof DependencyProjectSchema>;
                            targets: UpdateTarget[];
                        };
                    };
                },
                ctx: Context,
            ) {
                const { project, mode, targets } = args.intent.maintenance;
                const paths = (["composer", "js"] as const).flatMap((manager) =>
                    project.worker[
                        manager === "composer" ? "composerPaths" : "jsPaths"
                    ].filter((path) =>
                        mode === "routine" ||
                        targets.some((t) =>
                            t.manager === manager && t.path === path
                        )
                    ).map((path) => ({
                        path,
                        manager,
                        checkout_path: args.intent.checkout_path,
                        mode,
                        targets: targets.filter((t) =>
                            t.path === path && t.manager === manager
                        ),
                        minimum_days: project.worker.policy[path]
                            ?.minimumReleaseAgeDays ?? 0,
                    }))
                );
                if (
                    mode === "package" &&
                    targets.some((t) =>
                        !paths.some((p) =>
                            p.path === t.path && p.manager === t.manager
                        )
                    )
                ) throw Error("maintenance_unconfigured_target");
                return await writeData(ctx, {
                    paths: paths.map((p) => ({
                        ...p,
                        input_json: JSON.stringify(p),
                    })),
                    assets: ctx.extensionFile("scripts/workspace_files.py")
                        .replace(/\/[^/]+$/, ""),
                    input_json: JSON.stringify(args.intent),
                });
            },
        },
        expo: {
            description:
                "Require the selected Expo SDK coupled dependency catalog before any manifest mutation",
            arguments: z.object({
                place: Place,
                snapshot: Snapshot,
                stdout: z.string(),
            }),
            async execute(
                args: { place: Place; snapshot: Snapshot; stdout: string },
                ctx: Context,
            ) {
                return await writeData(
                    ctx,
                    verifyExpo(
                        args.place,
                        args.snapshot,
                        z.record(z.string(), z.string()).parse(
                            JSON.parse(args.stdout),
                        ),
                    ),
                );
            },
        },
        inspect: {
            description:
                "Decode one remote directory and select its fixed audit command",
            arguments: z.object({ place: Place, stdout: z.string() }),
            async execute(
                args: { place: Place; stdout: string },
                ctx: Context,
            ) {
                const snapshot = Snapshot.parse(JSON.parse(args.stdout)),
                    w = workspace(args.place, snapshot);
                return await writeData(ctx, {
                    snapshot,
                    audit_json: JSON.stringify({
                        argv: w.manager === "composer"
                            ? [
                                "composer",
                                "audit",
                                "--locked",
                                "--format=json",
                                "--no-plugins",
                                "--no-scripts",
                            ]
                            : [w.manager, "audit", "--json"],
                        report: true,
                    }),
                });
            },
        },
        plan: {
            description:
                "Preserve range style and require current exact target/advisory evidence",
            arguments: z.object({
                place: Place,
                snapshot: Snapshot,
                audit: z.string(),
            }),
            async execute(
                args: { place: Place; snapshot: Snapshot; audit: string },
                ctx: Context,
            ) {
                const result = plan(args.place, args.snapshot, args.audit);
                return await writeData(ctx, {
                    ...result,
                    patch_json: JSON.stringify(result.patch),
                    prepare_json: JSON.stringify(result.prepare),
                    update_json: JSON.stringify(result.update),
                    release_targets: result.targets.map((t) => ({
                        name: t.package,
                        version: t.version,
                    })),
                });
            },
        },
        release: {
            description:
                "Read one package release catalog from its public registry",
            arguments: z.object({
                manager: z.enum(["composer", "js"]),
                name: z.string(),
            }),
            async execute(
                args: { manager: "composer" | "js"; name: string },
                ctx: Context,
            ) {
                const url = args.manager === "composer"
                    ? `https://repo.packagist.org/p2/${args.name}.json`
                    : `https://registry.npmjs.org/${
                        encodeURIComponent(args.name)
                    }`;
                const response = await fetch(url, { signal: ctx.signal });
                if (!response.ok) {
                    throw Error("maintenance_release_metadata_unavailable");
                }
                const data = await response.json();
                const releases = args.manager === "composer"
                    ? (data.packages?.[args.name] ?? []).map((
                        r: { version: string; time: string },
                    ) => ({
                        version: r.version.replace(/^v/, ""),
                        publishedAt: r.time,
                    }))
                    : Object.entries(data.time ?? {}).map((
                        [version, publishedAt],
                    ) => ({ version, publishedAt }));
                return await writeData(ctx, { name: args.name, releases });
            },
        },
        age: {
            description:
                "Require verified release age for each exact changed package",
            arguments: z.object({
                place: Place,
                changed: z.array(
                    z.object({ name: z.string(), version: z.string() })
                        .passthrough(),
                ),
                readings: z.record(z.string(), z.unknown()),
                prefix: z.string().default("release"),
            }),
            async execute(
                args: {
                    place: Place;
                    changed: { name: string; version: string }[];
                    readings: Record<string, unknown>;
                    prefix?: string;
                },
                ctx: Context,
            ) {
                for (const [i, item] of args.changed.entries()) {
                    const step = args
                        .readings[`${args.prefix ?? "release"}-${i}`] as {
                            status?: string;
                            outputs?: {
                                name?: string;
                                releases?: {
                                    version: string;
                                    publishedAt: string;
                                }[];
                            };
                        };
                    const released = step?.outputs?.releases?.find((r) =>
                        r.version === item.version
                    );
                    const time = Date.parse(released?.publishedAt ?? "");
                    if (
                        step?.status !== "succeeded" ||
                        step.outputs?.name !== item.name ||
                        !Number.isFinite(time) ||
                        Date.now() - time < args.place.minimum_days * 86400000
                    ) throw Error("maintenance_release_age_refused");
                }
                return await writeData(ctx, { verified: true });
            },
        },
        restore: {
            description:
                "Restore the preserved declaration ranges after the exact JS solve",
            arguments: z.object({
                place: Place,
                after: Snapshot,
                declarations: z.record(z.string(), z.unknown()),
            }),
            async execute(
                args: {
                    place: Place;
                    after: Snapshot;
                    declarations: Record<string, unknown>;
                },
                ctx: Context,
            ) {
                const w = workspace(args.place, args.after);
                return await writeData(ctx, {
                    patch_json: JSON.stringify({
                        before: w.manifest,
                        after: args.declarations,
                    }),
                    sync_json: JSON.stringify({
                        argv: [
                            w.manager,
                            "install",
                            w.manager === "npm"
                                ? "--package-lock-only"
                                : "--lockfile-only",
                            "--ignore-scripts",
                        ],
                    }),
                });
            },
        },
        synchronized: {
            description:
                "Require manifest restoration to preserve every exact resolved version",
            arguments: z.object({
                place: Place,
                solved: Snapshot,
                after: Snapshot,
            }),
            async execute(
                args: { place: Place; solved: Snapshot; after: Snapshot },
                ctx: Context,
            ) {
                const before = locked(args.place, args.solved),
                    after = locked(args.place, args.after);
                if (
                    JSON.stringify(Object.entries(before).sort()) !==
                        JSON.stringify(Object.entries(after).sort())
                ) {
                    throw Error(
                        "maintenance_lock_synchronization_changed_versions",
                    );
                }
                return await writeData(ctx, { verified: true });
            },
        },
        verify: {
            description:
                "Require exact locks, fixed advisories and no downgrades",
            arguments: z.object({
                place: Place,
                before: Snapshot,
                after: Snapshot,
                audit: z.string(),
            }),
            async execute(
                args: {
                    place: Place;
                    before: Snapshot;
                    after: Snapshot;
                    audit: string;
                },
                ctx: Context,
            ) {
                return await writeData(ctx, {
                    changed: verify(
                        args.place,
                        args.before,
                        args.after,
                        args.audit,
                    ),
                });
            },
        },
    },
};
