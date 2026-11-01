import { z } from "npm:zod@4.4.3";
// @deno-types="npm:@types/semver@7.7.1"
import { gte, satisfies, valid } from "npm:semver@7.7.2";
import { VpRuntime } from "../instance-deploy/vp_requirements.ts";

const Public = z.record(z.string(), z.unknown());
const Observation = z.object({ stdout: z.string().max(8 * 1024 * 1024) })
    .passthrough();
type Context = {
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
export const behavior = {
    type: "@nckrtl/development-deploy",
    version: "2026.11.01.3",
    resources: {
        checked: { schema: Public, lifetime: "workflow", garbageCollection: 1 },
        runtime: {
            schema: z.object({
                runtime: Public.nullable(),
                runtime_json: z.string(),
            }),
            lifetime: "workflow",
            garbageCollection: 1,
        },
    },
    methods: {
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
    },
};
