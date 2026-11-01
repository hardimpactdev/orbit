import { z } from "npm:zod@4.4.3";
import {
    vpRequirements,
    VpRuntime,
} from "../instance-deploy/vp_requirements.ts";
// @deno-types="npm:@types/semver@7.7.1"
import { gte, valid } from "npm:semver@7.7.2";
type Context = {
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
const Observation = z.object({ stdout: z.string() }).passthrough();
export const behavior = {
    type: "@nckrtl/instance-vp-requirements",
    version: "2026.11.01.1",
    resources: {
        result: {
            schema: z.object({
                runtime: VpRuntime.nullable(),
                manager_required: z.boolean().optional(),
                ready: z.literal(true),
            }),
            lifetime: "1d",
            garbageCollection: 10,
        },
    },
    methods: {
        capture: {
            description:
                "Select canonical pinned VP requirements from a private manifest file",
            arguments: z.object({ path: z.string() }),
            execute: async ({ path }: { path: string }, ctx: Context) => {
                const info = await Deno.lstat(path);
                if (!info.isFile || info.isSymlink || info.size > 67108864) {
                    throw new Error("tool.project_invalid");
                }
                const material = JSON.parse(await Deno.readTextFile(path));
                const store = z.string().startsWith("/").parse(material.store);
                const runtime = vpRequirements(material, store);
                await Deno.remove(path);
                const result = {
                    ready: true as const,
                    runtime,
                    manager_required: z.boolean().parse(
                        material.manager_required,
                    ),
                };
                await ctx.writeResource("result", "result", result);
                return result;
            },
        },
        result: {
            description:
                "Require the observed no-op or exact CLI installation and version proof",
            arguments: z.object({
                name: z.string(),
                steps: z.record(
                    z.string(),
                    z.object({
                        status: z.string(),
                        outputs: z.record(z.string(), z.unknown()).optional(),
                    }).passthrough(),
                ),
            }),
            execute: async (
                args: {
                    name: string;
                    steps: Record<
                        string,
                        { status: string; outputs?: Record<string, unknown> }
                    >;
                },
                ctx: Context,
            ) => {
                const requirements = args.steps[args.name + "-requirements"];
                if (
                    requirements?.status !== "succeeded" ||
                    requirements.outputs?.ready !== true
                ) throw new Error("deployment.vp_requirements_invalid");
                const runtime = VpRuntime.nullable().parse(
                    requirements.outputs.runtime,
                );
                if (
                    runtime !== null &&
                    args.steps[args.name + "-verify-0"]?.status !== "succeeded"
                ) throw new Error("deployment.vp_store_failed");
                const result = { ready: true as const, runtime };
                await ctx.writeResource("result", "result", result);
                return result;
            },
        },
        cli: {
            description:
                "Require a compatible existing CLI before any project installation",
            arguments: z.object({
                runtime: VpRuntime,
                observation: Observation,
            }),
            execute: async (
                args: {
                    runtime: z.infer<typeof VpRuntime>;
                    observation: { stdout: string };
                },
                ctx: Context,
            ) => {
                const version = valid(
                    args.observation.stdout.trim().replace(/^vp\s+/, ""),
                );
                if (
                    version === null || !gte(version, args.runtime.vp_version)
                ) throw new Error("deployment.vp_store_failed");
                const result = { ready: true as const, runtime: args.runtime };
                await ctx.writeResource("result", "result", result);
                return result;
            },
        },
        verify: {
            description:
                "Verify exact installed runtimes through the stock read-only inventory",
            arguments: z.object({ runtime: VpRuntime, inventory: Observation }),
            execute: async (
                args: {
                    runtime: z.infer<typeof VpRuntime>;
                    inventory: { stdout: string };
                },
                ctx: Context,
            ) => {
                const list = z.object({
                    node: z.array(z.object({ version: z.string() })),
                    package_managers: z.record(
                        z.string(),
                        z.array(z.object({ version: z.string() })),
                    ),
                }).parse(JSON.parse(args.inventory.stdout));
                if (
                    !list.node.some((row) =>
                        row.version === args.runtime.node_version
                    ) ||
                    !list.package_managers[args.runtime.package_manager]?.some(
                        (row) =>
                            row.version ===
                                args.runtime.package_manager_version,
                    )
                ) throw new Error("deployment.vp_store_failed");
                const result = { ready: true as const, runtime: args.runtime };
                await ctx.writeResource("result", "result", result);
                return result;
            },
        },
    },
};
