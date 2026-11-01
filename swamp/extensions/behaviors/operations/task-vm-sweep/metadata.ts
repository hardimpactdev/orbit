import { z } from "npm:zod@4.4.3";
import { IncusOwnership, selectIncusOrphans } from "./policy.ts";

const Name = z.string().regex(/^[A-Za-z0-9][A-Za-z0-9._-]{0,253}$/);
const Candidate = z.object({
    name: Name,
    uuid: z.uuid(),
    kind: z.enum(["server", "template"]),
    label: z.string(),
    value: z.string(),
    input_json: z.string(),
});
const Resource = z.object({ name: Name, kind: z.enum(["instance", "volume"]) });
const CloudInventory = z.object({
    servers: z.array(
        z.object({
            uuid: z.uuid(),
            title: z.string(),
            labels: z.object({
                label: z.array(
                    z.object({ key: z.string(), value: z.string() }),
                ),
            }),
        }).passthrough(),
    ).max(4096),
    templates: z.array(
        z.object({ uuid: z.uuid(), title: z.string(), access: z.string() }),
    ).max(4096),
});
type Context = {
    logger?: { warn(message: string, facts: Record<string, unknown>): void };
    extensionFile(path: string): string;
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
type Intent = {
    provider: string;
    scope: { live_names: string[]; max_task_vm_id: number };
    image_cutoff: number;
    running_stamps: string[];
};

export function cloudPlan(intent: Intent, stdout: string) {
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
    const servers: z.infer<typeof Candidate>[] = [];
    const observed: string[] = [];
    const seen = new Set<string>();
    for (const row of rows.servers) {
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
                    !/^[1-9][0-9]*$/.test(value) || row.title !== `tvm-${value}`
                ) continue;
                observed.push(row.title);
                if (
                    Number(value) > intent.scope.max_task_vm_id ||
                    intent.scope.live_names.includes(row.title)
                ) continue;
            } else if (
                ![`tvm-image-${value}`, `tvm-image-${value}-smoke`].includes(
                    row.title,
                ) || !stale(value)
            ) continue;
            const item = {
                name: row.title,
                uuid: row.uuid,
                kind: "server" as const,
                label,
                value,
            };
            servers.push({ ...item, input_json: JSON.stringify(item) });
        }
    }
    const templates = rows.templates.flatMap((row) => {
        const match = /^orbit-task-base-([0-9]{14})-unverified$/.exec(
            row.title,
        );
        if (row.access !== "private" || !match || !stale(match[1])) return [];
        const item = {
            name: row.title,
            uuid: row.uuid,
            kind: "template" as const,
            label: "",
            value: match[1],
        };
        return [{ ...item, input_json: JSON.stringify(item) }];
    });
    return { servers, templates, observed_names: [...new Set(observed)] };
}
const Intent = z.object({
    provider: z.enum(["incus", "upcloud"]),
    scope: z.object({
        live_names: z.array(Name),
        max_task_vm_id: z.number().int().nonnegative(),
    }).passthrough(),
    image_cutoff: z.number().int(),
    running_stamps: z.array(z.string()),
}).passthrough();
export const behavior = {
    type: "@nckrtl/task-vm-sweep-input",
    version: "2026.11.01.5",
    resources: {
        assets: {
            lifetime: "infinite",
            garbageCollection: 10,
            schema: z.object({
                script_body: z.string(),
                cloud_inventory: z.string(),
                cloud_remove: z.string(),
            }),
        },
        plan: {
            lifetime: "infinite",
            garbageCollection: 10,
            schema: z.object({
                instances: z.array(Resource),
                volumes: z.array(Resource),
                servers: z.array(Candidate),
                templates: z.array(Candidate),
                observed_names: z.array(Name),
            }),
        },
        terminal: {
            lifetime: "infinite",
            garbageCollection: 10,
            schema: z.object({ result_json: z.string(), ready: z.boolean() }),
        },
    },
    methods: {
        assets: {
            description:
                "Resolve shipped sweep scripts and bind only public Incus intent",
            arguments: z.object({
                scope: z.record(z.string(), z.unknown()),
                resource: Resource.nullable().default(null),
            }),
            async execute(
                args: {
                    scope: Record<string, unknown>;
                    resource: z.infer<typeof Resource> | null;
                },
                ctx: Context,
            ) {
                const input = args.resource
                    ? { ...IncusOwnership.parse(args.scope), ...args.resource }
                    : args.scope;
                const path = ctx.extensionFile(
                    args.resource
                        ? "scripts/incus_remove.py"
                        : "scripts/incus_inventory.py",
                );
                const script = (await Deno.readTextFile(path)).replace(
                    "json.load(sys.stdin)",
                    `json.loads(${JSON.stringify(JSON.stringify(input))})`,
                );
                return {
                    dataHandles: [
                        await ctx.writeResource("assets", "assets", {
                            script_body: script,
                            cloud_inventory: ctx.extensionFile(
                                "scripts/cloud_inventory.py",
                            ),
                            cloud_remove: ctx.extensionFile(
                                "scripts/cloud_remove.py",
                            ),
                        }),
                    ],
                };
            },
        },
        plan: {
            description:
                "Select only orphan resources in the immutable admitted ownership scope",
            arguments: z.object({
                intent: Intent,
                stdout: z.string(),
                report: z.boolean().default(false),
            }),
            async execute(
                args: {
                    intent: z.infer<typeof Intent>;
                    stdout: string;
                    report?: boolean;
                },
                ctx: Context,
            ) {
                let plan;
                if (args.intent.provider === "incus") {
                    const inventory = z.object({
                        instances: z.array(z.unknown()),
                        volumes: z.array(z.unknown()),
                    }).parse(JSON.parse(args.stdout));
                    if (args.report) {
                        const publicInventory = z.object({
                            inventory: z.object({
                                instances: z.array(
                                    z.object({ project: Name, name: Name }),
                                ).max(4096),
                                volumes: z.array(
                                    z.object({
                                        project: Name,
                                        pool: Name,
                                        name: Name,
                                    }),
                                ).max(4096),
                                images: z.array(z.string()),
                            }),
                        }).parse(JSON.parse(args.stdout)).inventory;
                        const scope = IncusOwnership.parse(args.intent.scope);
                        const excluded = (project: string, name: string) =>
                            args.intent.ci_runner && project === "orbit-ci" ||
                            scope.operator_owned.includes(
                                project + "/" + name,
                            ) || scope.operator_owned.includes(project + "/*");
                        publicInventory.instances.forEach((row) => {
                            const build =
                                /^tvm-image-([0-9]+)(?:-attempt-[0-9]+)?(?:-smoke)?$/
                                    .exec(row.name);
                            if (
                                !excluded(row.project, row.name) &&
                                !(row.project === scope.project &&
                                    (/^tvm-[0-9]+$/.test(row.name) ||
                                        build &&
                                            scope.protected_build_ids.includes(
                                                Number(build[1]),
                                            )))
                            ) {
                                ctx.logger?.warn(
                                    "An Incus instance is outside Orbit ownership",
                                    row,
                                );
                            }
                        });
                        for (const row of publicInventory.volumes) {
                            const seed =
                                /^orbit-topology-seed-([a-f0-9]{12})(?:-new)?$/
                                    .exec(row.name);
                            if (
                                !excluded(row.project, row.name) &&
                                !(row.project === scope.project &&
                                    row.pool === scope.pool &&
                                    (/^tvm-[0-9]+-topology-seed$/.test(
                                        row.name,
                                    ) ||
                                        seed &&
                                            publicInventory.images.some((fp) =>
                                                fp.startsWith(seed[1])
                                            )))
                            ) {
                                ctx.logger?.warn(
                                    "An Incus volume is outside Orbit ownership",
                                    row,
                                );
                            }
                        }
                        for (
                            const id of z.array(z.number().int().positive())
                                .parse(args.intent.stray_vm_ids)
                        ) {
                            ctx.logger?.warn("A task VM has no open group", {
                                task_vm_id: id,
                            });
                        }
                    }
                    const selected = selectIncusOrphans(
                        args.intent.scope,
                        JSON.stringify(inventory.instances),
                        JSON.stringify(inventory.volumes),
                    );
                    plan = {
                        ...selected,
                        instances: selected.instances.map((name) => ({
                            name,
                            kind: "instance",
                        })),
                        volumes: selected.volumes.map((name) => ({
                            name,
                            kind: "volume",
                        })),
                        servers: [],
                        templates: [],
                    };
                } else {plan = {
                        ...cloudPlan(args.intent, args.stdout),
                        instances: [],
                        volumes: [],
                    };}
                return {
                    dataHandles: [
                        await ctx.writeResource("plan", "plan", plan),
                    ],
                };
            },
        },
        result: {
            description:
                "Publish the selected child's public receipt or a safe native failure",
            arguments: z.object({
                provider: z.enum(["incus", "upcloud"]),
                steps: z.record(z.string(), z.unknown()),
            }),
            async execute(
                args: { provider: string; steps: Record<string, unknown> },
                ctx: Context,
            ) {
                const child = z.object({
                    status: z.string(),
                    outputs: z.unknown().optional(),
                }).parse(
                    args.steps[args.provider === "incus" ? "incus" : "cloud"],
                );
                const receipt = child.status === "succeeded"
                    ? z.object({
                        ready: z.literal(true),
                        observed_names: z.array(Name).max(4096),
                        deleted_names: z.array(Name).max(4096),
                    }).parse(
                        JSON.parse(
                            z.object({
                                result: z.object({ stdout: z.string() }),
                            }).parse(child.outputs).result.stdout,
                        ),
                    )
                    : {
                        ready: false,
                        error_code: "task_vm.host_command_failed",
                        failed_step: args.provider === "incus"
                            ? "incus"
                            : "cloud",
                    };
                return {
                    dataHandles: [
                        await ctx.writeResource("terminal", "terminal", {
                            ready: receipt.ready,
                            result_json: JSON.stringify(receipt),
                        }),
                    ],
                };
            },
        },
        terminal: {
            description:
                "Shape a successful final provider observation into public sweep JSON",
            arguments: z.object({
                observed_names: z.array(Name),
                deleted_names: z.array(Name),
            }),
            async execute(
                args: { observed_names: string[]; deleted_names: string[] },
                ctx: Context,
            ) {
                return {
                    dataHandles: [
                        await ctx.writeResource("terminal", "terminal", {
                            ready: true,
                            result_json: JSON.stringify({
                                ready: true,
                                observed_names: [
                                    ...new Set(args.observed_names),
                                ],
                                deleted_names: [...new Set(args.deleted_names)],
                            }),
                        }),
                    ],
                };
            },
        },
    },
};
