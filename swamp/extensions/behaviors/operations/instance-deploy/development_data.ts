import { z } from "npm:zod@4.4.3";
// @deno-types="npm:@types/semver@7.7.1"
import { gte, valid } from "npm:semver@7.7.2";
import { vpRequirements, VpRuntime } from "./vp_requirements.ts";

const Command = z.object({
    name: z.string(),
    phase: z.literal("before_activation"),
    step_name: z.string(),
    required: z.boolean(),
});
const NativeStep = z.object({
    status: z.string(),
    outputs: z.object({
        stdout: z.string().optional(),
        exitCode: z.number().optional(),
    }).passthrough().optional(),
}).passthrough();
const ResultArgs = z.object({
    instance_id: z.number().int().positive(),
    steps: z.record(z.string(), NativeStep),
    commands: z.array(Command),
    runtime: VpRuntime.nullable().optional(),
});
const Pins = z.object({
    store: z.string(),
    node_version: z.string(),
    package_manager: z.string(),
    runtime: VpRuntime.nullable(),
    runtime_json: z.string(),
});
type Context = {
    writeResource(
        spec: string,
        name: string,
        value: Record<string, unknown>,
    ): Promise<unknown>;
};

/** Pure public data shaping. This module executes no command and uses no Gateway client. */
export function developmentResult(raw: z.input<typeof ResultArgs>) {
    const { instance_id, steps, commands, runtime } = ResultArgs.parse(raw);
    const source = steps["resolve-commit"]?.outputs?.stdout;
    const commit = source
        ? z.object({
            commit: z.string().regex(/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/),
        })
            .parse(JSON.parse(source)).commit
        : null;
    let failed_step: string | null = null;
    let error_code: string | null = null;
    let output_tail: string | null = null;
    let warnings = 0;
    const preparation = [
        "inspect-checkout",
        "fetch-source",
        "resolve-commit",
        "select-commit",
        "read-vp-requirements",
        "vp-pins",
        "inspect-vp-manager",
        "verify-vp-manager",
        "verify-vp-environment",
    ];
    for (const name of preparation) {
        if (steps[name]?.status === "failed") {
            failed_step = name;
            error_code = "deployment.preparation_failed";
            break;
        }
    }
    for (const command of commands) {
        const native = steps[command.step_name];
        if (native?.status === "skipped" || !native) continue;
        const stdout = native.outputs?.stdout;
        let parsed: unknown;
        try {
            parsed = stdout ? JSON.parse(stdout) : null;
        } catch {
            parsed = null;
        }
        const output = stdout
            ? z.object({
                exit_code: z.number().int(),
                output_tail: z.string().max(8192),
                truncated: z.boolean(),
            }).safeParse(parsed)
            : null;
        if (
            native.status === "failed" ||
            (native.outputs?.exitCode ?? 0) !== 0 ||
            output?.success && output.data.exit_code !== 0
        ) {
            if (!command.required) {
                warnings++;
                continue;
            }
            failed_step ??= `${command.phase}.${command.name}`;
            error_code ??= "deployment.step_failed";
            output_tail = output?.success ? output.data.output_tail : null;
            break;
        }
    }
    if (failed_step === null && steps["verify-checkout"]?.status === "failed") {
        failed_step = "verify-checkout";
        error_code = "deployment.checkout_changed";
    }
    return {
        instance_id,
        commit,
        release: null,
        selected_release: null,
        selection_confirmed: steps["select-commit"]?.status === "succeeded",
        failed_step,
        error_code,
        output_tail,
        warnings,
        ...(runtime && steps["verify-vp-environment"]?.status === "succeeded"
            ? { vp_environment: { ...runtime, status: "active" } }
            : {}),
    };
}

export const behavior = {
    type: "@nckrtl/development-deploy-data",
    version: "2026.10.31.1",
    resources: {
        manager: {
            description: "Public manager version check",
            schema: z.object({ ok: z.boolean() }),
            lifetime: "infinite",
            garbageCollection: 10,
        },
        pins: {
            description: "Public Vite runtime selection",
            schema: Pins,
            lifetime: "infinite",
            garbageCollection: 10,
        },
        summary: {
            description: "Public terminal deployment JSON",
            schema: z.object({ json: z.string(), ok: z.boolean() }),
            lifetime: "infinite",
            garbageCollection: 10,
        },
    },
    methods: {
        pins: {
            description:
                "Resolve public runtime requirements from the local read step output",
            arguments: z.object({
                configuration: z.string().max(10 * 1024 * 1024),
            }),
            execute: async (args: { configuration: string }, ctx: Context) => {
                const config = z.object({ store: z.string().nullable() })
                    .passthrough()
                    .parse(JSON.parse(args.configuration));
                const runtime = vpRequirements(
                    config,
                    config.store ?? "/unresolved",
                );
                if (runtime !== null && config.store === null) {
                    throw new Error("deployment.vp_store_failed");
                }
                const pins = {
                    runtime,
                    runtime_json: JSON.stringify(runtime),
                    store: config.store ?? "",
                    node_version: runtime?.node_version ?? "",
                    package_manager: runtime === null
                        ? ""
                        : `${runtime.package_manager}@${runtime.package_manager_version}${
                            runtime.package_manager_hash
                                ? `+${runtime.package_manager_hash}`
                                : ""
                        }`,
                };
                await ctx.writeResource("pins", "pins", pins);
                return pins;
            },
        },
        managerVersion: {
            description:
                "Validate the public manager version against the locked Vite version",
            arguments: z.object({
                runtime: VpRuntime.nullable(),
                observed: z.string(),
            }),
            execute: async (
                args: { runtime: VpRuntime | null; observed: string },
                ctx: Context,
            ) => {
                const version = valid(
                    args.observed.trim().replace(/^vp\s+/, ""),
                );
                if (
                    args.runtime !== null &&
                    (version === null || !gte(version, args.runtime.vp_version))
                ) {
                    throw new Error("deployment.vp_store_failed");
                }
                await ctx.writeResource("manager", "manager", { ok: true });
                return { ok: true };
            },
        },
        result: {
            description:
                "Assemble public deployment facts from native step results",
            arguments: ResultArgs,
            execute: async (args: z.infer<typeof ResultArgs>, ctx: Context) => {
                const result = developmentResult(args);
                const summary = {
                    json: JSON.stringify(result),
                    ok: result.error_code === null,
                };
                await ctx.writeResource("summary", "summary", summary);
                return summary;
            },
        },
    },
};
