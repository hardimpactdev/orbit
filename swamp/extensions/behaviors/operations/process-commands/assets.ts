import {
    LifecycleArgs,
    LifecycleAssets,
    lifecycleAssets,
} from "./lifecycle-input.ts";
import { z } from "npm:zod@4.4.3";
import { Input } from "../processes-schedules/process-input.ts";
import { ProcessInstallation } from "../processes-schedules/installation-input.ts";

const Args = z.object({
    control: Input.extend({
        vite_plan: z.unknown().optional(),
        caddy_publication: z.unknown().optional(),
        slot: z.enum([
            "canonical",
            "candidate",
            "rollback-running",
            "rollback-stopped",
            "failed",
        ]).optional(),
        expected_spec: z.string().regex(/^[a-f0-9]{64}$/).optional(),
    }),
    installation: ProcessInstallation.optional(),
}).strict().superRefine((args, ctx) => {
    const name = new RegExp(
        "^orbit-process-" + args.control.process_id +
            "-[a-z0-9](?:[a-z0-9-]*[a-z0-9])?" +
            (args.control.runtime === "systemd" ? "\\.service$" : "$"),
    );
    if (
        !name.test(args.control.name) ||
        args.control.runtime === "systemd" && (
                args.control.slot !== undefined &&
                    args.control.slot !== "canonical" ||
                args.control.expected_spec !== undefined
            )
    ) {
        ctx.addIssue({
            code: "custom",
            message: "Process control identity differs",
        });
    }
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
    scripts_directory: z.string(),
    inspect_script: z.string(),
    control_script: z.string(),
    enable_script: z.string(),
    verify_script: z.string(),
    check_release_script: z.string(),
    environment_script: z.string(),
    stage_unit_script: z.string(),
    validate_unit_script: z.string(),
    backup_unit_script: z.string(),
    publish_unit_script: z.string(),
    reload_units_script: z.string(),
    restore_unit_script: z.string(),
    remove_unit_script: z.string(),
    remove_environment_script: z.string(),
    pull_image_script: z.string(),
    create_container_script: z.string(),
    rename_container_script: z.string(),
    remove_container_script: z.string(),
    cleanup_checkpoint_script: z.string(),
    check_health_script: z.string(),
    control_json: z.string(),
    installation_json: z.string(),
});
const Digest = z.string().regex(/^[a-f0-9]{64}$/);
const PublicResult = z.union([
    z.object({
        present: z.boolean(),
        state: z.string().regex(/^[a-z][a-z_-]{0,63}$/),
        spec: Digest.optional(),
    }).strict(),
    z.object({
        ready: z.literal(true),
        state: z.string().regex(/^[a-z][a-z_-]{0,63}$/).optional(),
        content_hash: Digest.optional(),
        previous_hash: Digest.nullable().optional(),
    }).strict(),
]);

/** Resolve native additionalFiles and serialize public member inputs; no execution or HTTP. */
export const behavior = {
    type: "@nckrtl/process-command-assets",
    version: "2026.11.01.1",
    resources: {
        lifecycle: {
            schema: LifecycleAssets,
            lifetime: "infinite",
            garbageCollection: 10,
        },
        assets: { schema: Assets, lifetime: "infinite", garbageCollection: 10 },
        result: {
            schema: PublicResult,
            lifetime: "infinite",
            garbageCollection: 10,
        },
    },
    methods: {
        lifecycle: {
            description:
                "Validate the public Process lifecycle intent and resolve shipped scripts",
            arguments: LifecycleArgs,
            execute: async (args: z.infer<typeof LifecycleArgs>, ctx: {
                extensionFile(name: string): string;
                writeResource(
                    spec: string,
                    name: string,
                    value: unknown,
                ): Promise<unknown>;
            }) => {
                const result = await lifecycleAssets(
                    args,
                    ctx.extensionFile("scripts/control.py").replace(
                        /\/[^/]+$/,
                        "",
                    ),
                );
                await ctx.writeResource("lifecycle", "lifecycle", result);
                return result;
            },
        },
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
                    scripts_directory: ctx.extensionFile("scripts/control.py")
                        .replace(/\/[^/]+$/, ""),
                    inspect_script: ctx.extensionFile("scripts/inspect.py"),
                    control_script: ctx.extensionFile("scripts/control.py"),
                    enable_script: ctx.extensionFile("scripts/enable.py"),
                    verify_script: ctx.extensionFile("scripts/verify.py"),
                    check_release_script: ctx.extensionFile(
                        "scripts/check_release.py",
                    ),
                    environment_script: ctx.extensionFile(
                        "scripts/publish_environment.py",
                    ),
                    stage_unit_script: ctx.extensionFile(
                        "scripts/stage_unit.py",
                    ),
                    validate_unit_script: ctx.extensionFile(
                        "scripts/validate_unit.py",
                    ),
                    backup_unit_script: ctx.extensionFile(
                        "scripts/backup_unit.py",
                    ),
                    publish_unit_script: ctx.extensionFile(
                        "scripts/publish_unit.py",
                    ),
                    reload_units_script: ctx.extensionFile(
                        "scripts/reload_units.py",
                    ),
                    restore_unit_script: ctx.extensionFile(
                        "scripts/restore_unit.py",
                    ),
                    remove_unit_script: ctx.extensionFile(
                        "scripts/remove_unit.py",
                    ),
                    remove_environment_script: ctx.extensionFile(
                        "scripts/remove_environment.py",
                    ),
                    pull_image_script: ctx.extensionFile(
                        "scripts/pull_image.py",
                    ),
                    create_container_script: ctx.extensionFile(
                        "scripts/create_container.py",
                    ),
                    rename_container_script: ctx.extensionFile(
                        "scripts/rename_container.py",
                    ),
                    remove_container_script: ctx.extensionFile(
                        "scripts/remove_container.py",
                    ),
                    cleanup_checkpoint_script: ctx.extensionFile(
                        "scripts/cleanup_checkpoint.py",
                    ),
                    check_health_script: ctx.extensionFile(
                        "scripts/check_health.py",
                    ),
                    control_json: JSON.stringify(args.control),
                    installation_json: JSON.stringify(
                        args.installation ?? null,
                    ),
                });
                await ctx.writeResource("assets", "assets", assets);
                return assets;
            },
        },
        decode: {
            description:
                "Decode one public command result for native guards and step inputs",
            arguments: z.object({ stdout: z.string().max(4096) }).strict(),
            execute: async (args: { stdout: string }, ctx: {
                writeResource(
                    spec: string,
                    name: string,
                    value: unknown,
                ): Promise<unknown>;
            }) => {
                let result: z.infer<typeof PublicResult>;
                try {
                    result = PublicResult.parse(JSON.parse(args.stdout));
                } catch {
                    throw new Error("Invalid public Process command result");
                }
                await ctx.writeResource("result", "result", result);
                return result;
            },
        },
    },
};
