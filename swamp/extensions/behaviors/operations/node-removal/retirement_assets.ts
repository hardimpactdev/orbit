import { z } from "npm:zod@4.4.3";

const Assets = z.object({
    verify_admitted_target: z.string(),
    verify_node_identity: z.string(),
    disable_worker: z.string(),
    stop_worker: z.string(),
    verify_worker_inactive: z.string(),
    stop_observer: z.string(),
    disable_observer: z.string(),
    verify_observer_inactive: z.string(),
    remove_artifacts: z.string(),
    reload_systemd: z.string(),
    reset_observer_failure: z.string(),
}).strict();

/** Resolve native cached script files; this behavior performs no operational action. */
export const behavior = {
    type: "@nckrtl/node-removal-retirement-assets",
    version: "2026.10.31.1",
    resources: {
        assets: { schema: Assets, lifetime: "infinite", garbageCollection: 10 },
    },
    methods: {
        read: {
            description:
                "Resolve the one-action retirement scripts on the placed worker",
            arguments: z.object({}).strict(),
            execute: async (_args: Record<string, never>, ctx: {
                extensionFile: (name: string) => string;
                writeResource: (
                    spec: string,
                    name: string,
                    value: unknown,
                ) => Promise<unknown>;
            }) => {
                const assets = Assets.parse({
                    verify_admitted_target: ctx.extensionFile(
                        "scripts/verify_admitted_target.py",
                    ),
                    verify_node_identity: ctx.extensionFile(
                        "scripts/verify_node_identity.py",
                    ),
                    disable_worker: ctx.extensionFile(
                        "scripts/disable_worker.py",
                    ),
                    stop_worker: ctx.extensionFile("scripts/stop_worker.py"),
                    verify_worker_inactive: ctx.extensionFile(
                        "scripts/verify_worker_inactive.py",
                    ),
                    stop_observer: ctx.extensionFile(
                        "scripts/stop_observer.py",
                    ),
                    disable_observer: ctx.extensionFile(
                        "scripts/disable_observer.py",
                    ),
                    verify_observer_inactive: ctx.extensionFile(
                        "scripts/verify_observer_inactive.py",
                    ),
                    remove_artifacts: ctx.extensionFile(
                        "scripts/remove_artifacts.py",
                    ),
                    reload_systemd: ctx.extensionFile(
                        "scripts/reload_systemd.py",
                    ),
                    reset_observer_failure: ctx.extensionFile(
                        "scripts/reset_observer_failure.py",
                    ),
                });
                await ctx.writeResource("assets", "assets", assets);
                return assets;
            },
        },
    },
};
