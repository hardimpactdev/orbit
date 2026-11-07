import { z } from "npm:zod@4.4.3";
import { cloudPlan, Name } from "./policy.ts";

type Context = {
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
const Names = z.array(Name).max(4096);
const Server = z.object({ name: Name, uuid: z.uuid(), state: z.string() });
const Delete = z.object({ name: Name, uuid: z.uuid(), path: z.string() });
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
    version: "2026.11.07.1",
    resources: {
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
