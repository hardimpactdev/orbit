import {
    Inspection,
    InspectionArgs,
    Reference,
    storeInspection,
} from "./inspection.ts";
import { z } from "npm:zod@4.4.3";
import {
    LifecycleArgs,
    LifecycleAssets,
    lifecycleAssets,
} from "./lifecycle-input.ts";

/** Public rendering only. Official behaviors own Node effects and checks. */
export const behavior = {
    type: "@nckrtl/process-command-assets",
    version: "2026.11.10.1",
    resources: {
        inspection: {
            schema: Inspection,
            lifetime: "1h",
            garbageCollection: 10,
        },
        reference: { schema: Reference, lifetime: "1h", garbageCollection: 10 },
        lifecycle: {
            schema: LifecycleAssets,
            lifetime: "infinite",
            garbageCollection: 10,
        },
    },
    methods: {
        inspection: {
            description: "Keep a container Process read in the vault",
            arguments: InspectionArgs,
            execute: storeInspection,
        },
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
