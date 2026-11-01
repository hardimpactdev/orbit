import projectEnvironmentRuntime from "../task-repository/scripts/project_environment.py" with {
    type: "text",
};
import { z } from "npm:zod@4.4.3";
// @deno-types="npm:@types/semver@7.7.1"
import { gte, satisfies, valid } from "npm:semver@7.7.2";
import { appStepRuntime } from "../instance-deploy/app_step_runtime.ts";
import { VpRuntime } from "../instance-deploy/vp_requirements.ts";

const Public = z.record(z.string(), z.unknown());
const Observation = z.object({ stdout: z.string().max(8 * 1024 * 1024) })
    .passthrough();
const State = z.object({ status: z.string(), outputs: Public.optional() })
    .passthrough();
type Context = {
    extensionFile(name: string): string;
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
export function observation(raw: unknown): Record<string, unknown> {
    return Public.parse(JSON.parse(Observation.parse(raw).stdout));
}
export function checked(raw: unknown) {
    const result = observation(raw);
    if (result.ready !== true) {
        throw new Error(
            z.string().regex(/^[a-z0-9_.-]{1,100}$/).parse(
                result.error_code ?? "operation.native_failed",
            ),
        );
    }
    return result;
}
export function runtime(raw: unknown) {
    const input = checked(raw);
    const pins = VpRuntime.nullable().parse(input.runtime);
    if (pins !== null) {
        const version = valid(
            z.string().parse(input.version).replace(/^vp\s+/, ""),
        );
        if (!version || !gte(version, pins.vp_version)) {
            throw new Error("deployment.vp_store_failed");
        }
        for (
            const [kind, range] of z.array(z.tuple([z.string(), z.string()]))
                .parse(input.constraints)
        ) {
            if (
                !satisfies(
                    kind === "node"
                        ? pins.node_version
                        : pins.package_manager_version,
                    range,
                )
            ) throw new Error("deployment.vp_requirements_invalid");
        }
    }
    return { runtime: pins, runtime_json: JSON.stringify(pins) };
}
export function summary(raw: unknown, instanceId: number) {
    const steps = z.record(z.string(), State).parse(raw);
    const result: Record<string, unknown> = {
        instance_id: instanceId,
        commit: null,
        release: null,
        previous: null,
        selected_release: null,
        selection_confirmed: true,
        warnings: 0,
        failed_step: null,
        output_tail: null,
    };
    for (const [name, step] of Object.entries(steps)) {
        if (!name.startsWith("dev_")) continue;
        if (step.outputs?.stdout !== undefined) {
            try {
                const value = observation(step.outputs);
                if (value.ready === false && result.failed_step === null) {
                    result.failed_step = name;
                    result.error_code = value.error_code;
                }
                if (value.warning === true) {
                    result.warnings = Number(result.warnings) + 1;
                }
                if (name === "dev_inspect" && value.ready === true) {
                    result.commit = value.commit;
                }
            } catch {
                /* A failed native action cannot provide a success receipt. */
            }
        }
        if (step.status === "failed" && result.failed_step === null) {
            result.failed_step = name;
            result.error_code = "operation.native_failed";
        }
    }
    if (
        steps.dev_inspect?.status !== "succeeded" ||
        steps.dev_inspect_check?.status !== "succeeded"
    ) {
        result.failed_step ??= "native_execution";
        result.error_code ??= "operation.native_failed";
    }
    const pins = steps.dev_vp_resolve?.outputs?.runtime;
    if (pins && steps.dev_vp_verify_check?.status === "succeeded") {
        result.vp_environment = { ...Public.parse(pins), status: "active" };
    }
    return result;
}
export const behavior = {
    type: "@nckrtl/development-deploy",
    version: "2026.11.01.1",
    resources: {
        assets: {
            schema: z.object({
                source: z.string(),
                app: z.string(),
                vp: z.string(),
                request: z.string(),
            }),
            lifetime: "workflow",
            garbageCollection: 1,
        },
        checked: { schema: Public, lifetime: "workflow", garbageCollection: 1 },
        runtime: {
            schema: z.object({
                runtime: Public.nullable(),
                runtime_json: z.string(),
            }),
            lifetime: "workflow",
            garbageCollection: 1,
        },
        result: {
            schema: z.object({ stdout: z.string() }),
            lifetime: "workflow",
            garbageCollection: 1,
        },
    },
    methods: {
        assets: {
            arguments: z.object({
                intent: Public,
                operation_id: z.number().int().positive(),
                run_id: z.string().uuid(),
            }).strict(),
            description:
                "Resolve shipped public programs for separate native actions",
            execute: async (
                args: {
                    intent: Record<string, unknown>;
                    operation_id: number;
                    run_id: string;
                },
                ctx: Context,
            ) => {
                const common = projectEnvironmentRuntime + "\n" +
                    (await Deno.readTextFile(
                        ctx.extensionFile("scripts/common.py"),
                    )).replace(
                        /^from project_environment import load_project_environment\n/m,
                        "",
                    );
                const bodies = await Promise.all(
                    ["source", "app", "vp"].map(async (name) => {
                        let body = (await Deno.readTextFile(
                            ctx.extensionFile("scripts/" + name + ".py"),
                        )).replace(
                            /^from common import (?:\([^)]*\)|[^\n]*)\n/m,
                            "",
                        );
                        if (name === "app") {
                            body = body.replace(
                                'APP_RUNTIME = "__APP_STEP_RUNTIME__"',
                                () =>
                                    "APP_RUNTIME = " +
                                    JSON.stringify(appStepRuntime),
                            );
                        }
                        return [name, common + "\n" + body];
                    }),
                );
                const value = {
                    ...Object.fromEntries(bodies),
                    request: JSON.stringify(args),
                };
                return {
                    dataHandles: [
                        await ctx.writeResource("assets", "assets", value),
                    ],
                };
            },
        },
        check: {
            arguments: z.object({ observation: Observation }),
            description: "Require the typed result of one native action",
            execute: async (args: { observation: unknown }, ctx: Context) => ({
                dataHandles: [
                    await ctx.writeResource(
                        "checked",
                        "checked",
                        checked(args.observation),
                    ),
                ],
            }),
        },
        runtime: {
            arguments: z.object({ observation: Observation }),
            description: "Resolve public locked Vite runtime pins",
            execute: async (args: { observation: unknown }, ctx: Context) => ({
                dataHandles: [
                    await ctx.writeResource(
                        "runtime",
                        "runtime",
                        runtime(args.observation),
                    ),
                ],
            }),
        },
        result: {
            arguments: z.object({
                steps: z.record(z.string(), State),
                instance_id: z.number().int().positive(),
            }),
            description:
                "Return bounded development facts from native step outputs",
            execute: async (
                args: { steps: unknown; instance_id: number },
                ctx: Context,
            ) => ({
                dataHandles: [
                    await ctx.writeResource("result", "result", {
                        stdout: JSON.stringify(
                            summary(args.steps, args.instance_id),
                        ),
                    }),
                ],
            }),
        },
    },
};
