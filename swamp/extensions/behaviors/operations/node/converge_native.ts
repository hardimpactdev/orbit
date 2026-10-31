import { z } from "npm:zod@4.4.3";
import { Outcome, Snapshot } from "./converge_direct_contract.ts";
import { footprintProof } from "./footprint_proof.ts";

const Args = z.object({
    operation_id: z.number().int().positive(),
    node_id: z.number().int().positive(),
    kind: z.literal("node.converge"),
    intent: Snapshot,
    run_id: z.uuid(),
}).strict().refine(
    (v) => v.node_id === v.intent.node_id,
    "Converge Node differs from its intent",
);
const Shell = z.object({
    status: z.string(),
    outputs: z.object({
        exitCode: z.number().int().optional(),
        stdout: z.string().max(65536).optional(),
    }).passthrough().optional(),
}).passthrough();
const SummaryArgs = Args.safeExtend({ steps: z.record(z.string(), Shell) });
const VerifyArgs = z.object({
    required: z.boolean(),
    names: z.array(z.string()),
    steps: z.record(z.string(), Shell),
});

/** Check only public evidence. Native dependencies decide whether to continue. */
export function verifyCommands(raw: unknown) {
    const args = VerifyArgs.parse(raw);
    if (!args.required) return { ready: true };
    for (const name of args.names) {
        const step = args.steps[name];
        if (
            !step || step.status !== "succeeded" || step.outputs?.exitCode !== 0
        ) {
            throw new Error("The required footprint command did not complete");
        }
        const value = z.record(z.string(), z.unknown()).parse(
            JSON.parse(step.outputs.stdout ?? ""),
        );
        const tolerated = name === "caddy" &&
            ((value.error_code === "caddy.publication_failed" &&
                value.stage === "validate") ||
                (value.error_code === "caddy.render_failed" &&
                    value.stage === "render"));
        if (value.ok !== true && !tolerated) {
            throw new Error(
                "The required footprint command refused its intent",
            );
        }
    }
    return { ready: true };
}
const Metadata = z.object({
    intent_file: z.string().startsWith("/tmp/orbit-node-converge-"),
    allowed: z.boolean(),
    chunks: z.array(
        z.object({
            index: z.number().int().nonnegative(),
            data: z.string().max(49152),
        }).strict(),
    ).max(512),
    sha256: z.string().length(64),
}).strict();
type Context = {
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
const actionNames: Record<string, (snapshot: Snapshot) => string[]> = {
    "agent-install": () => ["agent_install"],
    "caddy-package": () => ["caddy_package"],
    caddy: () => ["caddy"],
    "peer-dns": () => ["peer_dns"],
    "private-dns": () => ["dns_listener", "dns_publish", "dns_verify"],
    "route-residue": () => ["residue_complete"],
    "php-runtime": (s) =>
        z.object({ configurations: z.array(z.object({ version: z.string() })) })
            .parse(s.payloads["php-runtime"]).configurations.map((c) =>
                "php_runtime-" + c.version
            ),
    tmpfiles: () => ["tmpfiles"],
    agent: () => ["agent_configure", "worker_configure"],
};

/** Read only typed public command outputs. Host and secret policies live in placed actions. */
export function summarizeConverge(raw: unknown) {
    const args = SummaryArgs.parse(raw);
    const snapshot = args.intent;
    if (snapshot.refusal !== null) {
        return {
            footprint_proven: false,
            artifacts: [],
            error_code: snapshot.refusal,
        };
    }
    const outcomes: z.infer<typeof Outcome>[] = [];
    for (const [stage, namesFor] of Object.entries(actionNames)) {
        const artifact = snapshot.artifacts.find((a) =>
            a.name === (stage === "agent-install" ? "agent" : stage)
        );
        if (!artifact) {
            outcomes.push(Outcome.parse({ stage, ok: true }));
            continue;
        }
        if (!artifact.required) {
            outcomes.push(
                Outcome.parse({
                    stage,
                    ok: true,
                    ...(stage === "agent-install"
                        ? { binary_changed: false }
                        : {
                            entry: { name: artifact.name, status: "unchanged" },
                        }),
                }),
            );
            continue;
        }
        let changed = false;
        let failed: string | undefined = snapshot.refusal ?? undefined;
        let skip: string | undefined;
        let deferred: string | undefined;
        for (const name of namesFor(snapshot)) {
            if (failed) break;
            const step = args.steps[name];
            if (
                !step || step.status !== "succeeded" || !step.outputs ||
                step.outputs.exitCode !== 0 ||
                typeof step.outputs.stdout !== "string"
            ) {
                failed = "node.footprint_failed";
                break;
            }
            const value = z.record(z.string(), z.unknown()).parse(
                JSON.parse(step.outputs.stdout),
            );
            if (value.ok !== true && typeof value.error_code !== "string") {
                failed = "node.footprint_failed";
                break;
            }
            if (typeof value.error_code === "string") {
                if (
                    stage === "caddy" &&
                    value.error_code === "caddy.render_failed" &&
                    value.stage === "render"
                ) {
                    skip = "caddy_render_refused";
                } else if (
                    stage === "caddy" &&
                    value.error_code === "caddy.publication_failed" &&
                    value.stage === "validate"
                ) {
                    skip = "caddy_validate_refused";
                    if (snapshot.first_visit && !artifact.repair) {
                        deferred = "node.footprint_caddy_failed";
                    }
                } else if (stage === "route-residue") {
                    skip = "route_residue_cleanup_failed";
                } else failed = value.error_code;
            }
            changed = changed || stage === "peer-dns" ||
                value.changed === true ||
                value.result === "published";
        }
        outcomes.push(
            Outcome.parse(
                failed
                    ? {
                        stage,
                        ok: false,
                        error_code: failed,
                        failed_artifact: artifact.name,
                    }
                    : stage === "agent-install"
                    ? { stage, ok: true, binary_changed: changed }
                    : {
                        stage,
                        ok: true,
                        entry: {
                            name: artifact.name,
                            status: skip
                                ? "skipped"
                                : changed
                                ? "applied"
                                : "unchanged",
                            ...(skip ? { reason: skip } : {}),
                        },
                        ...(deferred ? { deferred_error: deferred } : {}),
                    },
            ),
        );
        if (failed) break;
    }
    return footprintProof(snapshot, outcomes);
}

export const behavior = {
    type: "@nckrtl/node-converge-native",
    version: "2026.10.31.1",
    resources: {
        metadata: { schema: Metadata, lifetime: "1d", garbageCollection: 10 },
        result: {
            schema: z.object({ json: z.string(), proven: z.boolean() })
                .strict(),
            lifetime: "1d",
            garbageCollection: 10,
        },
        verification: {
            schema: z.object({ ready: z.boolean() }).strict(),
            lifetime: "1d",
            garbageCollection: 10,
        },
    },
    methods: {
        verify: {
            description: "Validate one action's public command result",
            arguments: VerifyArgs,
            execute: async (args: z.infer<typeof VerifyArgs>, ctx: Context) => {
                const result = verifyCommands(args);
                await ctx.writeResource("verification", "verification", result);
                return result;
            },
        },
        prepare: {
            description:
                "Validate and materialize public intent without reading the Gateway or a secret",
            arguments: Args,
            execute: async (raw: z.infer<typeof Args>, ctx: Context) => {
                const args = Args.parse(raw);
                if (args.node_id === 1 || args.node_id === 18) {
                    throw new Error(
                        "The shared native proxy transport is not available",
                    );
                }
                const bytes = new TextEncoder().encode(
                    JSON.stringify(args.intent),
                );
                if (bytes.length > 16 * 1024 * 1024) {
                    throw new Error(
                        "The public footprint exceeds its admitted limit",
                    );
                }
                const chunks: { index: number; data: string }[] = [];
                for (let offset = 0; offset < bytes.length; offset += 32768) {
                    const chunk = bytes.subarray(offset, offset + 32768);
                    chunks.push({
                        index: chunks.length,
                        data: btoa(String.fromCharCode(...chunk)),
                    });
                }
                const sha256 = Array.from(
                    new Uint8Array(
                        await crypto.subtle.digest("SHA-256", bytes),
                    ),
                    (b) => b.toString(16).padStart(2, "0"),
                ).join("");
                const metadata = {
                    intent_file:
                        `/tmp/orbit-node-converge-${args.run_id}/intent.json`,
                    allowed: args.intent.refusal === null,
                    chunks,
                    sha256,
                };
                await ctx.writeResource("metadata", "metadata", metadata);
                return metadata;
            },
        },
        summarize: {
            description:
                "Build the bounded terminal public result from native command outputs",
            arguments: SummaryArgs,
            execute: async (
                args: z.infer<typeof SummaryArgs>,
                ctx: Context,
            ) => {
                const summary = summarizeConverge(args);
                const result = {
                    json: JSON.stringify(summary),
                    proven: summary.footprint_proven,
                };
                await ctx.writeResource("result", "result", result);
                return result;
            },
        },
    },
};
