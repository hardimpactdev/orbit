import { z } from "npm:zod@4.4.3";

export const Name = z.string().regex(/^[A-Za-z0-9][A-Za-z0-9._-]{0,253}$/);

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
