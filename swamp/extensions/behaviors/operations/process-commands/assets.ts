import { z } from "npm:zod@4.4.3";
import {
    LifecycleArgs,
    LifecycleAssets,
    lifecycleAssets,
} from "./lifecycle-input.ts";

/** Public rendering only. Official behaviors own Node effects and checks. */
export const behavior = {
    type: "@nckrtl/process-command-assets",
    version: "2026.11.02.1",
    resources: {
        lifecycle: {
            schema: LifecycleAssets,
            lifetime: "infinite",
            garbageCollection: 10,
        },
    },
    methods: {
        lifecycle: {
            description:
                "Resolve the account home and the named Docker creation gap",
            arguments: LifecycleArgs,
            execute: async (args: z.infer<typeof LifecycleArgs>, ctx: {
                extensionFile(name: string): string;
                writeResource(
                    spec: string,
                    name: string,
                    value: unknown,
                ): Promise<unknown>;
            }) => {
                const result = {
                    ...await lifecycleAssets(args),
                    container_script: args.control.runtime === "docker"
                        ? await Deno.readTextFile(
                            ctx.extensionFile("scripts/lifecycle_container.py"),
                        )
                        : "",
                };
                await ctx.writeResource("lifecycle", "lifecycle", result);
                return result;
            },
        },
    },
};
