import { z } from "npm:zod@4.4.3";
import {
    cloudPlan,
    imagePrefix,
    IncusOwnership,
    incusRows,
    incusStrays,
    instancePrefix,
    Name,
    selectIncusOrphans,
    volumePrefix,
} from "./policy.ts";

type Context = {
    logger?: { warn(message: string, facts: Record<string, unknown>): void };
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
const Names = z.array(Name).max(4096);
const Server = z.object({ name: Name, uuid: z.uuid(), state: z.string() });
const Delete = z.object({ name: Name, uuid: z.uuid(), path: z.string() });
const IncusIntent = z.object({
    ci_runner: z.boolean(),
    stray_vm_ids: z.array(z.number().int().positive()).max(4096),
    scope: IncusOwnership,
}).passthrough();
const CloudIntent = z.object({
    scope: z.object({
        live_names: Names,
        max_task_vm_id: z.number().int().nonnegative(),
    }).passthrough(),
    image_cutoff: z.number().int(),
    running_stamps: z.array(z.string()),
}).passthrough();
export const behavior = {
    type: "@nckrtl/task-vm-sweep-input",
    version: "2026.11.02.2",
    resources: {
        incus_plan: {
            lifetime: "infinite",
            garbageCollection: 10,
            schema: z.object({
                instances: Names,
                volumes: Names,
                observed_names: Names,
                deleted_names: Names,
            }),
        },
        cloud_plan: {
            lifetime: "infinite",
            garbageCollection: 10,
            schema: z.object({
                stops: z.array(Server),
                deletes: z.array(Delete),
                observed_names: Names,
                deleted_names: Names,
            }),
        },
    },
    methods: {
        incus_plan: {
            description:
                "Select only orphan Incus resources in the admitted ownership scope and log strays",
            arguments: z.object({
                intent: IncusIntent,
                instances: z.string(),
                volumes: z.string(),
                images: z.string(),
            }),
            async execute(
                args: {
                    intent: z.infer<typeof IncusIntent>;
                    instances: string;
                    volumes: string;
                    images: string;
                },
                ctx: Context,
            ) {
                const intent = IncusIntent.parse(args.intent);
                const instances = incusRows(args.instances, instancePrefix);
                const volumes = incusRows(
                    args.volumes,
                    volumePrefix(intent.scope.pool),
                );
                const fingerprints = incusRows(args.images, imagePrefix).map((
                    row,
                ) => row.name);
                const strays = incusStrays(
                    intent.scope,
                    intent.ci_runner,
                    instances,
                    volumes,
                    fingerprints,
                );
                for (const row of strays.instances) {
                    ctx.logger?.warn(
                        "An Incus instance is outside Orbit ownership",
                        row,
                    );
                }
                for (const row of strays.volumes) {
                    ctx.logger?.warn(
                        "An Incus volume is outside Orbit ownership",
                        row,
                    );
                }
                for (const id of intent.stray_vm_ids) {
                    ctx.logger?.warn("A task VM has no open group", {
                        task_vm_id: id,
                    });
                }
                return {
                    dataHandles: [
                        await ctx.writeResource(
                            "incus_plan",
                            "incus_plan",
                            selectIncusOrphans(
                                intent.scope,
                                instances,
                                volumes,
                            ),
                        ),
                    ],
                };
            },
        },
        cloud_plan: {
            description:
                "Select only admitted orphan UpCloud servers and stale unverified templates",
            arguments: z.object({ intent: CloudIntent, stdout: z.string() }),
            async execute(
                args: { intent: z.infer<typeof CloudIntent>; stdout: string },
                ctx: Context,
            ) {
                return {
                    dataHandles: [
                        await ctx.writeResource(
                            "cloud_plan",
                            "cloud_plan",
                            cloudPlan(
                                CloudIntent.parse(args.intent),
                                args.stdout,
                            ),
                        ),
                    ],
                };
            },
        },
    },
};
