import { z } from "npm:zod@4.4.3";
import {
    cloudPlan,
    cloudRemaining,
    imagePrefix,
    IncusOwnership,
    incusRemaining,
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
const Template = z.object({ name: Name, uuid: z.uuid() });
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
const Terminal = z.object({ result_json: z.string(), ready: z.boolean() });

/** The public receipt; a deleted resource that is still listed fails the step. */
async function terminal(
    ctx: Context,
    observed: string[],
    deleted: string[],
    remaining: unknown[],
) {
    if (remaining.length > 0) throw new Error("task_vm.host_command_failed");
    return {
        dataHandles: [
            await ctx.writeResource("terminal", "terminal", {
                ready: true,
                result_json: JSON.stringify({
                    ready: true,
                    observed_names: observed,
                    deleted_names: [...new Set(deleted)],
                }),
            }),
        ],
    };
}

export const behavior = {
    type: "@nckrtl/task-vm-sweep-input",
    version: "2026.11.02.1",
    resources: {
        incus_plan: {
            lifetime: "infinite",
            garbageCollection: 10,
            schema: z.object({
                instances: Names,
                volumes: Names,
                observed_names: Names,
            }),
        },
        cloud_plan: {
            lifetime: "infinite",
            garbageCollection: 10,
            schema: z.object({
                servers: z.array(Server),
                stops: z.array(Server),
                templates: z.array(Template),
                observed_names: Names,
            }),
        },
        terminal: {
            lifetime: "infinite",
            garbageCollection: 10,
            schema: Terminal,
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
        incus_result: {
            description:
                "Check the final project inventory and shape the public sweep JSON",
            arguments: z.object({
                scope: IncusOwnership,
                instances: Names,
                volumes: Names,
                stdout: z.string(),
            }),
            async execute(
                args: {
                    scope: z.infer<typeof IncusOwnership>;
                    instances: string[];
                    volumes: string[];
                    stdout: string;
                },
                ctx: Context,
            ) {
                const end = incusRemaining(
                    args.scope,
                    args.instances,
                    args.stdout,
                );
                return await terminal(ctx, end.observed_names, [
                    ...args.instances,
                    ...args.volumes,
                ], end.remaining);
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
        cloud_result: {
            description:
                "Check the final UpCloud inventory and shape the public sweep JSON",
            arguments: z.object({
                servers: z.array(Server),
                templates: z.array(Template),
                stdout: z.string(),
            }),
            async execute(
                args: {
                    servers: z.infer<typeof Server>[];
                    templates: z.infer<typeof Template>[];
                    stdout: string;
                },
                ctx: Context,
            ) {
                const deleted = [...args.servers, ...args.templates];
                const end = cloudRemaining(deleted, args.stdout);
                return await terminal(
                    ctx,
                    end.observed_names,
                    deleted.map((row) => row.name),
                    end.remaining,
                );
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
                        observed_names: Names,
                        deleted_names: Names,
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
    },
};
