import { z } from "npm:zod@4.4.3";
import {
    MaintenanceInput,
    MaintenanceResult,
    selectMaintenance,
} from "./maintenance_selection.ts";

const Intent = MaintenanceInput.extend({
    routine: z.literal("retention"),
    redis: z.null(),
}).strict();
const Selection = z.object({ result_json: z.string() }).strict();

/** Pure expiry selection. Native stdout owns delivery to the shared terminal projector. */
export const behavior = {
    type: "@nckrtl/observation-retention-selection",
    version: "2026.10.31.1",
    resources: {
        selection: {
            schema: Selection,
            lifetime: "infinite",
            garbageCollection: 10,
        },
    },
    methods: {
        select: {
            description:
                "Select expired records from admitted metadata without reading Gateway state",
            arguments: z.object({ intent: Intent }).strict(),
            execute: async (args: { intent: z.infer<typeof Intent> }, ctx: {
                writeResource: (
                    spec: string,
                    name: string,
                    value: unknown,
                ) => Promise<unknown>;
            }) => {
                const result = MaintenanceResult.parse(
                    selectMaintenance(args.intent),
                );
                const selection = { result_json: JSON.stringify(result) };
                await ctx.writeResource("selection", "selection", selection);
                return selection;
            },
        },
    },
};
