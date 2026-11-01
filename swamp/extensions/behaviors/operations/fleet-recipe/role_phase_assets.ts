import { z } from "npm:zod@4.4.3";
import { observerHomePolicy, RoleConvergeIntent } from "./observer_assets.ts";
const Sources = z.object({
    account: z.string().min(1),
    observer_install: z.string().min(1),
    packages: z.string().min(1),
}).strict();
const Assets = z.object({
    scripts_directory: z.string(),
    input_json: z.string(),
});
type Context = {
    extensionFile(name: string): string;
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
/** Resolve each canonical supplier once. This method has no host effects. */
export const behavior = {
    type: "@nckrtl/node-role-phase-assets",
    version: "2026.11.01.1",
    resources: {
        assets: { schema: Assets, lifetime: "1d", garbageCollection: 10 },
    },
    methods: {
        bundle: {
            description:
                "Stage the canonical role phase files before any fan-out",
            arguments: z.object({
                intent: RoleConvergeIntent,
                sources: Sources,
                prepared_tools: z.boolean(),
            }).strict(),
            execute: async (args: {
                intent: z.infer<typeof RoleConvergeIntent>;
                sources: z.infer<typeof Sources>;
                prepared_tools: boolean;
            }, ctx: Context) => {
                const directory = await Deno.makeTempDir({
                    prefix: "orbit-role-phases-",
                });
                for (const [name, source] of Object.entries(args.sources)) {
                    await Deno.writeTextFile(
                        directory + "/" + name + ".py",
                        source,
                    );
                }
                for (
                    const name of [
                        "prerequisites",
                        "observer",
                        "php_runtime",
                        "cli",
                        "common",
                        "firewall",
                        "caddy_service",
                    ]
                ) {
                    await Deno.copyFile(
                        ctx.extensionFile("role_phases/" + name + ".py"),
                        directory + "/phase_" + name + ".py",
                    );
                }
                for (
                    const name of [
                        "role_prerequisites",
                        "recipe",
                        "caddy_ordering",
                        "php_runtime",
                        "file",
                        "observer_identity",
                        "observer_checkout",
                        "cli",
                    ]
                ) {
                    await Deno.copyFile(
                        ctx.extensionFile("scripts/" + name + ".py"),
                        directory + "/canonical_" + name + ".py",
                    );
                }
                const result = {
                    scripts_directory: directory,
                    input_json: JSON.stringify({
                        intent: args.intent,
                        prepared_tools: args.prepared_tools,
                        observer_policy: observerHomePolicy,
                    }),
                };
                await ctx.writeResource("assets", "assets", result);
                return result;
            },
        },
    },
};
