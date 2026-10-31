import { z } from "npm:zod@4.4.3";
import { Input } from "../processes-schedules/process-input.ts";
import { ProcessInstallation } from "../processes-schedules/installation-input.ts";

const Args = z.object({
    control: Input.extend({
        vite_plan: z.unknown().optional(),
        caddy_publication: z.unknown().optional(),
    }),
    installation: ProcessInstallation.optional(),
}).strict().superRefine((args, ctx) => {
    if (
        args.installation && (
            args.installation.process_id !== args.control.process_id ||
            args.installation.runtime !== args.control.runtime ||
            args.installation.name !== args.control.name
        )
    ) {
        ctx.addIssue({
            code: "custom",
            message: "Process member identities differ",
        });
    }
});
const Assets = z.object({
    inspect_script: z.string(),
    control_script: z.string(),
    enable_script: z.string(),
    verify_script: z.string(),
    control_json: z.string(),
    installation_json: z.string(),
});

/** Resolve native additionalFiles and serialize public member inputs; no execution or HTTP. */
export const behavior = {
    type: "@nckrtl/process-command-assets",
    version: "2026.10.31.1",
    resources: {
        assets: { schema: Assets, lifetime: "infinite", garbageCollection: 10 },
    },
    methods: {
        read: {
            description:
                "Resolve reusable Process command files on the placed worker",
            arguments: Args,
            execute: async (args: z.infer<typeof Args>, ctx: {
                extensionFile(name: string): string;
                writeResource(
                    spec: string,
                    name: string,
                    value: unknown,
                ): Promise<unknown>;
            }) => {
                const assets = Assets.parse({
                    inspect_script: ctx.extensionFile("scripts/inspect.py"),
                    control_script: ctx.extensionFile("scripts/control.py"),
                    enable_script: ctx.extensionFile("scripts/enable.py"),
                    verify_script: ctx.extensionFile("scripts/verify.py"),
                    control_json: JSON.stringify(args.control),
                    installation_json: JSON.stringify(
                        args.installation ?? null,
                    ),
                });
                await ctx.writeResource("assets", "assets", assets);
                return assets;
            },
        },
    },
};
