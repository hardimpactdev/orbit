import { z } from "npm:zod@4.4.3";

const Id = z.number().int().positive();
export const Name = z.string().regex(/^[A-Za-z0-9][A-Za-z0-9._-]{0,253}$/);
export const IncusOwnership = z.object({
    project: Name,
    pool: Name,
    operator_owned: z.array(z.string()).max(4096),
    live_names: z.array(Name).max(4096),
    max_task_vm_id: z.number().int().nonnegative(),
    max_build_id: z.number().int().nonnegative(),
    recorded_build_ids: z.array(Id).max(4096),
    retired_build_ids: z.array(Id).max(4096),
    protected_build_ids: z.array(Id).max(4096),
}).strict();
type Scope = z.infer<typeof IncusOwnership>;
export type IncusRow = { project: string; name: string };

/** Rows of an `incus query` URL list; a URL without `?project=` is in `default`. */
export function incusRows(stdout: string, prefix: string): IncusRow[] {
    return z.array(z.string()).max(4096).parse(JSON.parse(stdout)).map(
        (url) => {
            if (!url.startsWith(prefix)) {
                throw new Error("task_vm.host_command_failed");
            }
            const [path, query = ""] = url.slice(prefix.length).split("?");
            return {
                name: Name.parse(decodeURIComponent(path)),
                project: Name.parse(
                    new URLSearchParams(query).get("project") ?? "default",
                ),
            };
        },
    );
}

export const instancePrefix = "/1.0/instances/";
export const volumePrefix = (pool: string) =>
    `/1.0/storage-pools/${pool}/volumes/custom/`;
export const imagePrefix = "/1.0/images/";

const projectNames = (scope: Scope, rows: IncusRow[]) => [
    ...new Set(
        rows.filter((row) => row.project === scope.project).map((row) =>
            row.name
        ),
    ),
];

/**
 * Selects only the admitted orphans in the host's project. A topology volume
 * goes when its VM is absent or selected for removal before it.
 */
export function selectIncusOrphans(
    raw: unknown,
    instanceRows: IncusRow[],
    volumeRows: IncusRow[],
) {
    const scope = IncusOwnership.parse(raw);
    const operatorOwns = (name: string) =>
        scope.operator_owned.includes(`${scope.project}/${name}`) ||
        scope.operator_owned.includes(`${scope.project}/*`);
    const orphanVm = (name: string) => {
        const match = /^tvm-([0-9]+)$/.exec(name);
        return match !== null && Number(match[1]) <= scope.max_task_vm_id &&
            !scope.live_names.includes(name);
    };
    const orphanInstance = (name: string) => {
        if (operatorOwns(name) || scope.live_names.includes(name)) return false;
        if (orphanVm(name)) return true;
        const build = /^tvm-image-([0-9]+)(?:-attempt-[0-9]+)?(?:-smoke)?$/
            .exec(name);
        if (build === null) return false;
        const id = Number(build[1]);
        return id <= scope.max_build_id &&
            !scope.protected_build_ids.includes(id) &&
            (scope.retired_build_ids.includes(id) ||
                !scope.recorded_build_ids.includes(id));
    };
    const observed = projectNames(scope, instanceRows);
    const instances = observed.filter(orphanInstance);
    const volumes = projectNames(scope, volumeRows).filter((name) => {
        const match = /^(tvm-[0-9]+)-topology-seed$/.exec(name);
        return match !== null && !operatorOwns(name) &&
            orphanVm(match[1]) &&
            (!observed.includes(match[1]) || instances.includes(match[1]));
    });
    return {
        instances,
        volumes,
        // The run's end check proves the removed names are gone, so the rest is what the host keeps.
        observed_names: observed.filter((name) => !instances.includes(name)),
        deleted_names: [...instances, ...volumes],
    };
}

/** Instances and sweep-pool volumes on the host that Orbit does not own; reported, never changed. */
export function incusStrays(
    raw: unknown,
    ciRunner: boolean,
    instanceRows: IncusRow[],
    volumeRows: IncusRow[],
    fingerprints: string[],
) {
    const scope = IncusOwnership.parse(raw);
    const excluded = (project: string, name: string) =>
        ciRunner && project === "orbit-ci" ||
        scope.operator_owned.includes(`${project}/${name}`) ||
        scope.operator_owned.includes(`${project}/*`);
    return {
        instances: instanceRows.filter((row) => {
            const build = /^tvm-image-([0-9]+)(?:-attempt-[0-9]+)?(?:-smoke)?$/
                .exec(
                    row.name,
                );
            return !excluded(row.project, row.name) &&
                !(row.project === scope.project &&
                    (/^tvm-[0-9]+$/.test(row.name) ||
                        build !== null &&
                            scope.protected_build_ids.includes(
                                Number(build[1]),
                            )));
        }),
        volumes: volumeRows.filter((row) => {
            const seed =
                /^orbit-topology-seed-([a-f0-9]{12})(?:-new|-refresh|-previous)?$/
                    .exec(
                        row.name,
                    );
            return !excluded(row.project, row.name) &&
                !(row.project === scope.project &&
                    (/^tvm-[0-9]+-topology-seed$/.test(row.name) ||
                        seed !== null &&
                            fingerprints.some((fp) => fp.startsWith(seed[1]))));
        }).map((row) => ({ ...row, pool: scope.pool })),
    };
}

const Server = z.object({
    uuid: z.uuid(),
    title: z.string(),
    state: z.string(),
    labels: z.object({
        label: z.array(z.object({ key: z.string(), value: z.string() })),
    }),
}).passthrough();
const Servers = z.object({
    servers: z.object({ server: z.array(Server).max(4096) }),
});
export const CloudInventory = z.object({
    vms: Servers,
    images: Servers,
    templates: z.object({
        storages: z.object({
            storage: z.array(
                z.object({
                    uuid: z.uuid(),
                    title: z.string(),
                    access: z.string(),
                }).passthrough(),
            ).max(4096),
        }),
    }),
});
export type CloudIntent = {
    scope: { live_names: string[]; max_task_vm_id: number };
    image_cutoff: number;
    running_stamps: string[];
};

/** The labelled task VM servers, by title. */
function cloudObserved(rows: z.infer<typeof CloudInventory>) {
    const servers = [...rows.vms.servers.server, ...rows.images.servers.server];
    const observed: string[] = [];
    for (const row of servers) {
        const matches = row.labels.label.filter((v) =>
            v.key === "orbit-task-vm"
        );
        if (matches.length > 1) throw new Error("task_vm.host_command_failed");
        const value = matches[0]?.value;
        if (
            value !== undefined && /^[1-9][0-9]*$/.test(value) &&
            row.title === `tvm-${value}`
        ) observed.push(row.title);
    }
    return [...new Set(observed)];
}

/** Selects only admitted orphan task VM IDs and inactive old image stamps. */
export function cloudPlan(intent: CloudIntent, stdout: string) {
    const rows = CloudInventory.parse(JSON.parse(stdout));
    const stale = (stamp: string) => {
        if (
            !/^[0-9]{14}$/.test(stamp) || intent.running_stamps.includes(stamp)
        ) return false;
        const date = new Date(
            `${stamp.slice(0, 4)}-${stamp.slice(4, 6)}-${stamp.slice(6, 8)}T${
                stamp.slice(8, 10)
            }:${stamp.slice(10, 12)}:${stamp.slice(12, 14)}Z`,
        );
        return Number.isFinite(date.getTime()) &&
            date.toISOString().replace(/[-:TZ]/g, "").slice(0, 14) === stamp &&
            date.getTime() / 1000 < intent.image_cutoff;
    };
    const servers: { name: string; uuid: string; state: string }[] = [];
    const seen = new Set<string>();
    for (
        const row of [...rows.vms.servers.server, ...rows.images.servers.server]
    ) {
        if (seen.has(row.uuid)) continue;
        seen.add(row.uuid);
        for (const label of ["orbit-task-vm", "orbit-task-image"]) {
            const matches = row.labels.label.filter((v) => v.key === label);
            if (matches.length > 1) {
                throw new Error("task_vm.host_command_failed");
            }
            const value = matches[0]?.value;
            if (value === undefined) continue;
            if (label === "orbit-task-vm") {
                if (
                    !/^[1-9][0-9]*$/.test(value) ||
                    row.title !== `tvm-${value}` ||
                    Number(value) > intent.scope.max_task_vm_id ||
                    intent.scope.live_names.includes(row.title)
                ) continue;
            } else if (
                ![`tvm-image-${value}`, `tvm-image-${value}-smoke`].includes(
                    row.title,
                ) || !stale(value)
            ) continue;
            servers.push({ name: row.title, uuid: row.uuid, state: row.state });
        }
    }
    const templates = rows.templates.storages.storage.flatMap((row) => {
        const match = /^orbit-task-base-([0-9]{14})-unverified$/.exec(
            row.title,
        );
        return row.access === "private" && match && stale(match[1])
            ? [{ name: row.title, uuid: row.uuid }]
            : [];
    });
    const deleted = [...servers, ...templates].map((row) => row.name);
    return {
        stops: servers.filter((row) => row.state === "started"),
        // One delete per server with its storage and per unverified template.
        deletes: [
            ...servers.map((row) => ({
                name: row.name,
                uuid: row.uuid,
                path: `server/${row.uuid}?storages=1&backups=delete`,
            })),
            ...templates.map((row) => ({
                ...row,
                path: `storage/${row.uuid}`,
            })),
        ],
        // The run's end check proves the deleted servers are gone, so the rest is what the provider keeps.
        observed_names: cloudObserved(rows).filter((name) =>
            !deleted.includes(name)
        ),
        deleted_names: [...new Set(deleted)],
    };
}
