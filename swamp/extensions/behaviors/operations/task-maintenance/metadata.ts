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
    directory: z.string().startsWith("/"),
    files: z.array(z.string()),
});
const Snapshot = z.object({
    directory: z.string(),
    files: z.record(z.string(), z.string()),
    node_modules: z.boolean(),
});
type Place = z.infer<typeof Place>;
type Snapshot = z.infer<typeof Snapshot>;
type Steps = Record<string, unknown>;

/** The manifests one directory may hold, read in this order by `read-<phase>-<index>`. */
export const MANIFESTS = {
    composer: ["composer.json", "composer.lock"],
    js: ["package.json", "package-lock.json", "bun.lock"],
} as const;

/** The captured directory of one configured path, refusing anything outside the checkout. */
export function directory(checkout: string, relative: string): string {
    const root = checkout.replace(/\/+$/, "");
    if (relative === ".") return root;
    if (
        relative.startsWith("/") ||
        relative.split("/").some((part) => ["", ".", ".."].includes(part))
    ) throw Error("maintenance_invalid_path");
    return `${root}/${relative}`;
}

/** Outputs of one official SSH exec step, or null when it did not run. */
function exec(steps: Steps, name: string) {
    const step = steps[name] as
        | { status?: string; outputs?: Record<string, unknown> }
        | undefined;
    if (step?.status !== "succeeded" || !step.outputs) return null;
    return z.object({
        exitCode: z.number().int().nullable().optional(),
        stdout: z.string().default(""),
        stderr: z.string().default(""),
    }).passthrough().parse(step.outputs);
}

/** One directory's manifests from its `cat` reads and the `test -d node_modules` read. */
export function snapshot(p: Place, phase: string, steps: Steps): Snapshot {
    const files: Record<string, string> = {};
    for (const [i, name] of p.files.entries()) {
        const read = exec(steps, `read-${phase}-${i}`);
        if (read === null) throw Error("maintenance_manifest_unreadable");
        if (read.exitCode === 0) files[name] = read.stdout;
        else if (!/No such file or directory/.test(read.stderr)) {
            throw Error("maintenance_manifest_unreadable");
        }
    }
    return {
        directory: p.directory,
        files,
        node_modules: exec(steps, "node-modules")?.exitCode === 0,
    };
}

/** Order-insensitive JSON equality, as the manifest comparison has always been. */
function canonical(value: unknown): string {
    if (Array.isArray(value)) return `[${value.map(canonical).join(",")}]`;
    if (value !== null && typeof value === "object") {
        return `{${
            Object.keys(value).sort().map((key) =>
                `${JSON.stringify(key)}:${
                    canonical((value as Record<string, unknown>)[key])
                }`
            ).join(",")
        }}`;
    }
    return JSON.stringify(value);
}

/** The full manifest 209 writes with `tee`, and whether the directory needs it. */
export function manifestWrite(
    p: Place,
    current: Record<string, unknown>,
    after: Record<string, unknown>,
) {
    return {
        manifest_path: `${p.directory}/${
            p.manager === "composer" ? "composer.json" : "package.json"
        }`,
        manifest_text: `${JSON.stringify(after, null, 2)}\n`,
        manifest_write: canonical(current) !== canonical(after),
    };
}

/** One package command in its directory: `env -C <dir> <argv…>`. */
const inDirectory = (p: Place, argv: string[]) => [
    "env",
    "-C",
    p.directory,
    ...argv,
];

/** The terminal maintenance result from the two base snapshot reads. */
export function result(intent: Record<string, unknown>, steps: Steps) {
    const tracked = exec(steps, "snapshot-tracked"),
        untracked = exec(steps, "snapshot-untracked");
    if (
        tracked === null || ![0, 1].includes(tracked.exitCode ?? -1) ||
        untracked === null || untracked.exitCode !== 0
    ) throw Error("maintenance_base_unavailable");
    return {
        group_id: intent.group_id,
        instance_id: intent.instance_id,
        project_id: intent.project_id,
        subtask: intent.subtask,
        outcome: "passed",
        changed: tracked.exitCode === 1 || untracked.stdout !== "",
    };
}
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
    stdout: string,
): Record<string, { id: string }[]> {
    // The audit step admits exit codes 0 to 3 only; its JSON report is stdout.
    const data = JSON.parse(stdout), w = workspace(p, s);
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
        ? inDirectory(p, [
            w.manager,
            ...(w.manager === "npm"
                ? ["ci", "--ignore-scripts"]
                : ["install", "--frozen-lockfile", "--ignore-scripts"]),
        ])
        : null;
    const update = inDirectory(
        p,
        p.mode === "routine"
            ? w.manager === "composer"
                ? ["composer", "update", "--no-interaction"]
                : [w.vitePlus ? "./node_modules/.bin/vp" : w.manager, "update"]
            : w.manager === "composer"
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
    );
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
        after,
        ...manifestWrite(p, w.manifest, after),
        declarations,
        prepare_argv: prepare,
        update_argv: update,
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
/** Write back the preserved declaration ranges, then resynchronize only the lock. */
export function restore(
    p: Place,
    after: Snapshot,
    declarations: Record<string, unknown>,
) {
    const w = workspace(p, after);
    return {
        ...manifestWrite(p, w.manifest, declarations),
        sync_argv: inDirectory(p, [
            w.manager,
            "install",
            w.manager === "npm" ? "--package-lock-only" : "--lockfile-only",
            "--ignore-scripts",
        ]),
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
    version: "2026.11.02.1",
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
                "Capture configured maintenance paths and their directories",
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
                        directory: directory(args.intent.checkout_path, path),
                        files: [...MANIFESTS[manager]],
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
                return await writeData(ctx, { paths });
            },
        },
        result: {
            description:
                "Report the passed maintenance and whether the checkout differs from its provision base",
            arguments: z.object({
                intent: z.record(z.string(), z.unknown()),
                steps: z.record(z.string(), z.unknown()),
            }),
            async execute(
                args: { intent: Record<string, unknown>; steps: Steps },
                ctx: Context,
            ) {
                return await writeData(ctx, {
                    result_json: JSON.stringify(
                        result(args.intent, args.steps),
                    ),
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
                "Decode one directory's manifest reads and select its fixed audit command",
            arguments: z.object({
                place: Place,
                phase: z.enum(["before", "after", "restored"]),
                steps: z.record(z.string(), z.unknown()),
            }),
            async execute(
                args: { place: Place; phase: string; steps: Steps },
                ctx: Context,
            ) {
                const read = snapshot(args.place, args.phase, args.steps),
                    w = workspace(args.place, read);
                return await writeData(ctx, {
                    snapshot: read,
                    audit_argv: inDirectory(
                        args.place,
                        w.manager === "composer"
                            ? [
                                "composer",
                                "audit",
                                "--locked",
                                "--format=json",
                                "--no-plugins",
                                "--no-scripts",
                            ]
                            : [w.manager, "audit", "--json"],
                    ),
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
                return await writeData(
                    ctx,
                    restore(args.place, args.after, args.declarations),
                );
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
