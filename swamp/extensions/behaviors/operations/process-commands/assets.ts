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
                "Resolve the account home and shape public Docker argv",
            arguments: LifecycleArgs,
            execute: async (args: z.infer<typeof LifecycleArgs>, ctx: {
                writeResource(
                    spec: string,
                    name: string,
                    value: unknown,
                ): Promise<unknown>;
            }) => {
                const result = await lifecycleAssets(args);
                await ctx.writeResource("lifecycle", "lifecycle", result);
                return result;
            },
        },
    },
};
