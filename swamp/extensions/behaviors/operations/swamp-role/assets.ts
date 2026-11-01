import { z } from "npm:zod@4.4.3";
import { Assets as WorkerAssets } from "../node-worker-assets/assets.ts";
import {
    activationPhases,
    allPhases,
    Intent,
    phases,
    swampRolePlan,
} from "./plan.ts";
const Statuses = z.object(
    Object.fromEntries(allPhases.map((name) => [name, z.string()])),
);
type Context = {
    extensionFile(name: string): string | Promise<string>;
    writeResource(s: string, n: string, v: unknown): Promise<unknown>;
};
export const behavior = {
    type: "@nckrtl/node-swamp-role-assets",
    version: "2026.11.01.1",
    resources: {
        assets: {
            schema: z.object({
                directory: z.string(),
                install_json: z.string(),
            }),
            lifetime: "1d",
            garbageCollection: 10,
        },
        result: {
            schema: z.record(z.string(), z.unknown()),
            lifetime: "1d",
            garbageCollection: 10,
        },
    },
    methods: {
        bundle: {
            description:
                "Resolve the public Swamp-role bundle once before target phases",
            arguments: z.object({ intent: Intent, worker: WorkerAssets })
                .strict(),
            execute: async (
                args: {
                    intent: z.infer<typeof Intent>;
                    worker: z.infer<typeof WorkerAssets>;
                },
                ctx: Context,
            ) => {
                const plan = swampRolePlan(args.intent, args.worker);
                const directory = await Deno.makeTempDir({
                    prefix: "orbit-swamp-role-",
                });
                for (const name of [...phases, "common"]) {
                    await Deno.copyFile(
                        await ctx.extensionFile("scripts/" + name + ".py"),
                        directory + "/" + name + ".py",
                    );
                }
                await Deno.writeTextFile(
                    directory + "/plan.json",
                    JSON.stringify(plan),
                );
                const result = {
                    directory,
                    install_json: JSON.stringify(plan.install),
                };
                await ctx.writeResource("assets", "assets", result);
                return result;
            },
        },
        complete: {
            description:
                "Report preparation separately from activated runtime proof",
            arguments: z.object({ intent: Intent, statuses: Statuses })
                .strict(),
            execute: async (
                args: {
                    intent: z.infer<typeof Intent>;
                    statuses: Record<string, string>;
                },
                ctx: Context,
            ) => {
                const required = allPhases.filter((p) =>
                    args.intent.activate ||
                    !activationPhases.includes(
                        p as typeof activationPhases[number],
                    )
                );
                const failed = required.find((p) =>
                    args.statuses[p] !== "succeeded"
                );
                const prepared = phases.filter((p) =>
                    !activationPhases.includes(
                        p as typeof activationPhases[number],
                    )
                )
                    .every((p) => args.statuses[p] === "succeeded");
                const active = args.intent.activate && !failed;
                const result = {
                    schema_version: 1,
                    node_id: args.intent.node_id,
                    assignment_id: args.intent.assignment_id,
                    role: "swamp",
                    created: args.intent.created,
                    prepared,
                    role_proven: active,
                    runtime_proven: active,
                    certificates_proven: active,
                    // Services Route publication belongs to the owning parent, not this target child.
                    publication_proven: false,
                    ...(failed
                        ? {
                            failed_step: failed,
                            error_code: "swamp.role_phase_failed",
                        }
                        : {}),
                };
                await ctx.writeResource("result", "result", result);
                return result;
            },
        },
    },
};
