import { z } from "npm:zod@4.4.3";

/*
 * The image builder reaper. The Gateway publishes each active task VM Incus
 * host as the `task-vm-image-reaper` schedule's inputs: project, pool,
 * operator-owned names, the build ids of active image builds on it, and its
 * pinned SSH target. Official `@swamp/ssh exec` steps list, delete and check
 * storage on the host; `pick` and `check` below only read their outputs.
 * Nothing here calls the Gateway or a host.
 */

/** A builder younger than this is never deleted, so a starting build keeps it. */
export const AGE_MS = 2 * 60 * 60 * 1000;
const Builder =
    /^tvm-image-([1-9][0-9]*)(?:-attempt-([1-9][0-9]*))?(?:-builder|-smoke)?$/;
const Id = z.number().int().positive();
const Host = z.object({
    node_id: Id,
    project: z.string(),
    pool: z.string(),
    operator_owned: z.array(z.string()),
    protected_builds: z.array(Id),
}).passthrough();
export type ImageReaperHost = z.infer<typeof Host>;
const Vm = z.object({
    name: z.string(),
    type: z.string(),
    status: z.string(),
    created_at: z.string(),
    expanded_devices: z.record(
        z.string(),
        z.object({
            type: z.string(),
            path: z.string().optional(),
            pool: z.string().optional(),
        }).passthrough(),
    ),
}).passthrough();
type Vm = z.infer<typeof Vm>;

/** An old builder on its host's pool that the operator does not own. */
export function eligible(host: ImageReaperHost, vm: Vm, now: number): boolean {
    const roots = Object.values(vm.expanded_devices).filter((d) =>
        d.type === "disk" && d.path === "/"
    );
    const created = Date.parse(vm.created_at);
    return Builder.test(vm.name) && vm.type === "virtual-machine" &&
        !host.operator_owned.some((name) =>
            name === `${host.project}/*` ||
            name === `${host.project}/${vm.name}`
        ) &&
        ["Running", "Stopped"].includes(vm.status) && roots.length === 1 &&
        roots[0].pool === host.pool && Number.isFinite(created) &&
        now - created > AGE_MS;
}

const Exec = z.object({
    outputs: z.object({
        exitCode: z.number().int().nullable().optional(),
        stdout: z.string().optional(),
    }).passthrough(),
}).passthrough();
/** Stdout of a forEach item that exited 0, or null for a failed or missing step. */
function stdout(steps: Record<string, unknown>, name: string): string | null {
    const step = Exec.safeParse(steps[name]);
    return step.success && step.data.outputs.exitCode === 0
        ? step.data.outputs.stdout ?? ""
        : null;
}

export type Deletion = ImageReaperHost & { vm: string; created_at: string };
export type Pick = {
    deletions: Deletion[];
    hosts: ImageReaperHost[];
    protected: string[];
    failed: number[];
};

/** Choose the builders to delete from each host's `incus list` (step `list-<i>`). */
export function pick(
    hosts: ImageReaperHost[],
    steps: Record<string, unknown>,
    now: number,
): Pick {
    const out: Pick = { deletions: [], hosts: [], protected: [], failed: [] };
    hosts.forEach((host, index) => {
        const listed = stdout(steps, `list-${index}`);
        let rows: Vm[];
        try {
            rows = z.array(Vm).max(4096).parse(JSON.parse(listed ?? "null"));
        } catch {
            out.failed.push(host.node_id);
            return;
        }
        if (new Set(rows.map((vm) => vm.name)).size !== rows.length) {
            out.failed.push(host.node_id);
            return;
        }
        let swept = false;
        for (const vm of rows.filter((vm) => eligible(host, vm, now))) {
            if (
                host.protected_builds.includes(
                    Number(Builder.exec(vm.name)![1]),
                )
            ) {
                out.protected.push(vm.name);
                continue;
            }
            out.deletions.push({
                ...host,
                vm: vm.name,
                created_at: vm.created_at,
            });
            swept = true;
        }
        if (swept) out.hosts.push(host);
    });
    return out;
}

export type Sweep = {
    node_id: number;
    deleted: string[];
    failed: string[];
};

/**
 * Read each delete (`delete-<j>`) and each swept host's storage volumes
 * (`volumes-<k>`): a deletion counts only when its root volume and snapshots
 * are gone.
 */
export function check(
    picked: Pick,
    steps: Record<string, unknown>,
): Sweep[] {
    const volumes = z.array(z.object({ type: z.string(), name: z.string() }))
        .max(65536);
    return picked.hosts.map((host, k) => {
        const sweep: Sweep = { node_id: host.node_id, deleted: [], failed: [] };
        let left: z.infer<typeof volumes> | null;
        try {
            left = volumes.parse(
                JSON.parse(stdout(steps, `volumes-${k}`) ?? "null"),
            );
        } catch {
            left = null;
        }
        picked.deletions.forEach((deletion, j) => {
            if (deletion.node_id !== host.node_id) return;
            const gone = stdout(steps, `delete-${j}`) !== null &&
                left !== null &&
                !left.some((v) =>
                    v.type === "virtual-machine" &&
                    (v.name === deletion.vm ||
                        v.name.startsWith(deletion.vm + "/"))
                );
            (gone ? sweep.deleted : sweep.failed).push(deletion.vm);
        });
        return sweep;
    });
}

type Context = {
    writeResource(
        spec: string,
        name: string,
        value: Record<string, unknown>,
    ): Promise<{ name: string }>;
};
const Steps = z.record(z.string(), z.unknown());
const PickArgs = z.object({ hosts: z.array(Host), steps: Steps });
const PickResult = z.object({
    deletions: z.array(z.object({}).passthrough()),
    hosts: z.array(z.object({}).passthrough()),
    protected: z.array(z.string()),
    failed: z.array(Id),
});
const CheckArgs = z.object({
    pick: PickResult.extend({
        deletions: z.array(
            Host.extend({ vm: z.string(), created_at: z.string() }),
        ),
        hosts: z.array(Host),
    }),
    steps: Steps,
});
const failed = (detail: string) =>
    new Error(`task_vm.image_reaper_failed: ${detail}`);

export const behavior = {
    type: "@nckrtl/task-vm-image-reaper",
    version: "2026.11.02.1",
    resources: {
        pick: {
            description:
                "Builders to delete, protected builders and unlisted hosts",
            schema: PickResult,
            lifetime: "7d",
            garbageCollection: 1,
        },
        sweep: {
            description: "Per-host deleted and failed builders",
            schema: z.object({
                hosts: z.array(z.object({
                    node_id: Id,
                    deleted: z.array(z.string()),
                    failed: z.array(z.string()),
                })),
                unlisted: z.array(Id),
            }),
            lifetime: "7d",
            garbageCollection: 1,
        },
    },
    methods: {
        pick: {
            description:
                "Choose old unreferenced builders from each host's Incus list",
            arguments: PickArgs,
            async execute(args: z.infer<typeof PickArgs>, ctx: Context) {
                return {
                    dataHandles: [
                        await ctx.writeResource(
                            "pick",
                            "pick",
                            pick(args.hosts, args.steps, Date.now()),
                        ),
                    ],
                };
            },
        },
        check: {
            description:
                "Confirm each deletion and its absent storage; fail on any host failure",
            arguments: CheckArgs,
            async execute(args: z.infer<typeof CheckArgs>, ctx: Context) {
                const hosts = check(args.pick, args.steps);
                const handle = await ctx.writeResource("sweep", "sweep", {
                    hosts,
                    unlisted: args.pick.failed,
                });
                const bad = [
                    ...args.pick.failed.map((id) => `node ${id} unlisted`),
                    ...hosts.flatMap((h) =>
                        h.failed.map((n) => `${h.node_id}/${n}`)
                    ),
                ];
                if (bad.length) throw failed(bad.join(", "));
                return { dataHandles: [handle] };
            },
        },
    },
};
