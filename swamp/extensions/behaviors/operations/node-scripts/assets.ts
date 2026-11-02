import { z } from "npm:zod@4.4.3";
import { assembleFootprint } from "../fleet-recipe/assets.ts";

const ObjectInput = z.record(z.string(), z.unknown());
const Args = z.object({
    script: z.enum(["agent", "agent_key", "authorized_keys"]),
    input: ObjectInput,
}).strict();
const Assets = z.object({ body: z.string(), input: z.string() }).strict();
const Step = z.object({ status: z.string(), outputs: ObjectInput.optional() })
    .passthrough();
const Steps = z.record(z.string(), Step);
const Artifact = z.object({
    name: z.string(),
    required: z.boolean(),
    repair: z.boolean(),
}).passthrough();
const Footprint = z.object({
    artifacts: z.array(Artifact),
    payloads: ObjectInput,
}).passthrough();
const Converge = z.object({
    refusal: z.string().nullable(),
    first_visit: z.boolean(),
    artifacts: z.array(Artifact),
}).passthrough();
type Context = {
    extensionFile(name: string): string;
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
/** The role parts that node.converge applies in its node-converge-role-files child. */
export const ROLE_PARTS = [
    "caddy-package",
    "caddy",
    "private-dns",
    "route-residue",
    "php-runtime",
    "tmpfiles",
];
const ROLE_STEP = "Re-apply role files";

/** The child's role artifacts, from the fleet recipe's step names. */
export function roleResult(raw: unknown) {
    const { footprint, steps } = z.object({ footprint: Footprint, steps: Steps })
        .strict().parse(raw);
    return assembleFootprint(
        {
            ...footprint,
            artifacts: footprint.artifacts.filter((a) =>
                ROLE_PARTS.includes(a.name)
            ),
        } as unknown as Parameters<typeof assembleFootprint>[0],
        steps as Parameters<typeof assembleFootprint>[1],
    );
}

/** The footprint result: the agent and DNS policy from the chart steps, role parts from the child's result. */
export function convergeResult(raw: unknown) {
    const { intent, steps } = z.object({ intent: Converge, steps: Steps })
        .strict().parse(raw);
    if (intent.refusal !== null) {
        return { artifacts: [], footprint_proven: false, error_code: intent.refusal };
    }
    const status = (name: string) => steps[name]?.status;
    let role: Record<string, { status: string; reason?: string }> = {};
    if (status(ROLE_STEP) === "succeeded") {
        const stdout = steps[ROLE_STEP].outputs?.result;
        const parsed = z.object({
            artifacts: z.array(
                z.object({
                    name: z.string(),
                    status: z.string(),
                    reason: z.string().optional(),
                }),
            ),
        }).passthrough().parse(
            JSON.parse(
                String(ObjectInput.parse(stdout ?? {}).stdout ?? "{}"),
            ),
        );
        role = Object.fromEntries(parsed.artifacts.map((a) => [a.name, a]));
    }
    const proven: Record<string, boolean> = {
        agent: status("Configure the Node agent") === "succeeded" &&
            status("Check the footprint") !== "failed",
        "peer-dns": status("Apply private DNS policy") === "succeeded",
    };
    const artifacts: Record<string, unknown>[] = [];
    let failed: string | undefined;
    for (const artifact of intent.artifacts) {
        const fromRole = role[artifact.name];
        if (fromRole && fromRole.status === "skipped") {
            artifacts.push(fromRole);
        } else if (
            !artifact.required || proven[artifact.name] ||
            fromRole?.status === "applied"
        ) {
            artifacts.push({
                name: artifact.name,
                status: artifact.required ? "applied" : "unchanged",
            });
        } else if (artifact.name === "route-residue") {
            artifacts.push({
                name: artifact.name,
                status: "skipped",
                reason: "route_residue_cleanup_failed",
            });
        } else failed ??= artifact.name;
    }
    const caddySkipped = intent.first_visit &&
        artifacts.some((a) => a.name === "caddy" && a.status === "skipped") &&
        intent.artifacts.some((a) => a.name === "caddy" && !a.repair);
    const stepFailed = Object.values(steps).some((s) => s.status === "failed");
    return {
        artifacts,
        footprint_proven: !failed && !caddySkipped,
        ...(failed ? { failed_artifact: failed } : {}),
        ...(caddySkipped
            ? { error_code: "node.footprint_caddy_failed" }
            : stepFailed
            ? { error_code: "operation.native_failed" }
            : failed
            ? { error_code: "node.footprint_failed" }
            : {}),
    };
}
const result = <T>(schema: z.ZodType<T>, build: (a: T) => unknown) => ({
    description: "Assemble the public footprint result from native step statuses",
    arguments: schema,
    execute: async (args: T, ctx: Context) => {
        const value = build(args);
        const out = { stdout: JSON.stringify(value) };
        await ctx.writeResource("result", "result", out);
        return out;
    },
});

/** Reads one shipped Node script and the public intent it takes as JSON; official SSH runs it. */
export const behavior = {
    type: "@nckrtl/node-scripts",
    version: "2026.11.02.4",
    resources: {
        assets: { schema: Assets, lifetime: "1d", garbageCollection: 10 },
        result: {
            schema: z.object({ stdout: z.string() }),
            lifetime: "1d",
            garbageCollection: 10,
        },
    },
    methods: {
        read: {
            description: "Read one shipped Node script and its public input",
            arguments: Args,
            execute: async (args: z.infer<typeof Args>, ctx: Context) => {
                const { script, input } = Args.parse(args);
                const value = Assets.parse({
                    body: await Deno.readTextFile(
                        ctx.extensionFile(`scripts/${script}.py`),
                    ),
                    input: JSON.stringify(input),
                });
                await ctx.writeResource("assets", "assets", value);
                return value;
            },
        },
        role_result: result(
            z.object({ footprint: Footprint, steps: Steps }).strict(),
            roleResult,
        ),
        converge_result: result(
            z.object({ intent: Converge, steps: Steps }).strict(),
            convergeResult,
        ),
    },
};
