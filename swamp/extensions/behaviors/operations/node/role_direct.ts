import { hasRoleTransport } from "./role_metrics.ts";
import { stripRolePublication } from "./role_publication.ts";
import { z } from "npm:zod@4.4.3";
import { gatewayHttp, type Http } from "../../automatic-deploy/gateway.ts";
import { Globals } from "../_lib/direct.ts";
import { GatewayOperation } from "../_lib/direct.ts";
import { BaselineStage, convergeRoleBaseline } from "./role_direct_local.ts";
import { RoleArgs, RoleSnapshot } from "./role_direct_contract.ts";
import { CliConvergeResult, convergeCli } from "../fleet/cli_converge_local.ts";
import {
    gatewayCaddyOrdering,
    gatewayRoleSystem,
} from "./gateway_role_system.ts";
import { configureRoleClickhouse } from "./role_runtime_local.ts";
import { installRoleRuntime } from "./role_runtime_local.ts";
import {
    convergeParentManager,
    parentManagerResources,
} from "./manager_parent.ts";
import { footprintProof } from "./footprint_proof.ts";
import { convergeFootprintStage } from "./converge_footprint.ts";
import {
    Outcome as FootprintOutcome,
    Stage as FootprintStage,
} from "./converge_direct_contract.ts";

const Code = z.string().regex(/^[a-z][a-z0-9_-]*\.[a-z0-9_.-]{1,90}$/);
const Admission = z.object({
    snapshot: RoleSnapshot,
    started_at: z.string(),
    ready: z.boolean(),
    refusal: Code.nullable(),
}).strict();
const Outcome = z.object({
    stage: z.string(),
    ok: z.boolean(),
    error_code: Code.optional(),
    follow_up: Code.optional(),
}).strict();
const Current = z.object({
    id: z.number().int().positive(),
    kind: z.literal("node.role_converge"),
    node_id: z.number().int().positive(),
    status: z.enum(["queued", "running", "succeeded", "failed", "cancelled"]),
    input: z.preprocess(stripRolePublication, RoleSnapshot),
    cancel_requested: z.boolean(),
});
type Context = {
    extensionFile(path: string): string;
    globalArgs: z.infer<typeof Globals>;
    signal?: AbortSignal;
    writeResource: (
        spec: string,
        name: string,
        value: unknown,
    ) => Promise<unknown>;
    readResource: (name: string) => Promise<unknown>;
};
const EffectArgs = RoleArgs;
const StepArgs = EffectArgs.extend({
    admission: Admission,
    stage: BaselineStage,
});
const FootprintArgs = EffectArgs.extend({
    admission: Admission,
    stage: FootprintStage,
    binary_changed: z.boolean().default(false),
});
const FollowUpArgs = RoleArgs.extend({
    admission: Admission,
    baseline: z.array(Outcome).max(16),
    footprints: z.array(FootprintOutcome).max(9),
});
const CliArgs = FollowUpArgs;
const ProjectArgs = FollowUpArgs.extend({
    cli: CliConvergeResult.nullable(),
    dependencies: z.object({ ready: z.boolean() }).passthrough().nullable()
        .optional(),
});
function baselineProof(outcomes: z.infer<typeof Outcome>[]) {
    if (
        new Set(outcomes.map((o) => o.stage)).size !== outcomes.length ||
        outcomes.some((o) => !BaselineStage.safeParse(o.stage).success)
    ) {
        throw new Error("Role baseline proof is duplicated or foreign");
    }
    const failure = outcomes.find((o) => !o.ok);
    if (!failure && outcomes.length !== BaselineStage.options.length) {
        throw new Error("A role baseline outcome is uncertain");
    }
    return failure;
}
/** Retained convergence aggregation; add and relocation use the native parent. */
export function roleResult(args: z.infer<typeof ProjectArgs>) {
    const snapshot = args.admission.snapshot;
    const installationFailure = args.baseline.length === 0
        ? args.footprints.find((outcome) =>
            outcome.stage === "agent-install" && !outcome.ok
        )
        : undefined;
    const dependencyFailure =
        args.dependencies === null || args.dependencies?.ready === false
            ? {
                stage: "publication",
                error_code: "node_role.publication_failed",
            }
            : undefined;
    const failure = args.admission.refusal
        ? { stage: "admission", error_code: args.admission.refusal }
        : installationFailure ?? baselineProof(args.baseline) ??
            dependencyFailure;
    const common = {
        assignment_id: snapshot.assignment_id,
        created: snapshot.created,
        baseline: args.baseline,
    };
    let result: Record<string, unknown>;
    if (failure) {
        result = {
            ...common,
            role_proven: false,
            error_code: Code.parse(failure.error_code),
            failed_step: failure.stage,
        };
    } else {
        const proof = footprintProof(snapshot.footprint, args.footprints);
        if (args.cli === null) {
            throw new Error("The role CLI follow-up outcome is uncertain");
        }
        const followUps: string[] = args.baseline.flatMap((outcome) =>
            outcome.follow_up ? [outcome.follow_up] : []
        );
        if (proof.error_code) {
            followUps.push(`role-footprint:${proof.error_code}`);
        }
        if ("error_code" in args.cli) {
            followUps.push(`role-cli:${args.cli.error_code}`);
        }
        result = {
            ...common,
            role_proven: true,
            footprint: proof,
            cli: args.cli,
            follow_up: followUps.length ? followUps.join(", ") : null,
        };
    }
    const code = failure?.error_code ?? null;
    const cancelled = code === "operation.cancelled";
    return { result, code, cancelled };
}

/** Same-root native role stages. The owning cutover supplies every required action before activation. */
export function nodeRoleAdd(
    baseline = convergeRoleBaseline,
    makeHttp: (ctx: Context) => Http = (ctx) =>
        gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
    footprint = convergeFootprintStage,
    cli = convergeCli,
    selectSnapshot: (input: unknown) => unknown = (input) => input,
    gatewayActions = { order: gatewayCaddyOrdering, dns: gatewayRoleSystem },
) {
    const gateway = (args: z.infer<typeof RoleArgs>, ctx: Context) =>
        new GatewayOperation(makeHttp(ctx), RoleArgs.parse(args));
    const read = async (args: z.infer<typeof RoleArgs>, ctx: Context) => {
        const data = z.record(z.string(), z.unknown()).parse(
            await gateway(args, ctx).read(ctx.signal),
        );
        if (data.kind !== args.kind) {
            throw new Error("Role read changed the native root kind");
        }
        return Current.parse({ ...data, input: selectSnapshot(data.input) });
    };
    return {
        type: "@nckrtl/node-role-add-direct",
        version: "2026.10.31.1",
        globalArguments: Globals,
        resources: {
            ...parentManagerResources,
            admission: {
                description:
                    "Safe immutable role intent and pre-effect support refusal",
                schema: Admission,
                lifetime: "1d",
                garbageCollection: 10,
            },
            outcome: {
                description: "Bounded result of one fixed local role stage",
                schema: Outcome,
                lifetime: "1d",
                garbageCollection: 10,
            },
            footprint_outcome: {
                description:
                    "Canonical safe footprint outcome inside the original role run",
                schema: FootprintOutcome,
                lifetime: "1d",
                garbageCollection: 10,
            },
            cli_outcome: {
                description:
                    "Canonical bounded CLI follow-up in the original role run",
                schema: CliConvergeResult,
                lifetime: "1d",
                garbageCollection: 10,
            },
        },
        methods: {
            admit: {
                description:
                    "Read the bound admitted role once, before any local effect",
                arguments: RoleArgs,
                execute: async (
                    raw: z.infer<typeof RoleArgs>,
                    ctx: Context,
                ) => {
                    const args = RoleArgs.parse(raw);
                    const current = await read(args, ctx);
                    if (
                        current.id !== args.operation_id ||
                        current.node_id !== args.node_id ||
                        current.input.node_id !== args.node_id
                    ) {
                        throw new Error(
                            "Role intent differs from the native root",
                        );
                    }
                    if (!["queued", "running"].includes(current.status)) {
                        throw new Error("Role intent is already terminal");
                    }
                    const snapshot = current.input;
                    // These applicable dependencies are still held; never perform a partial role and report success.
                    const refusal =
                        current.cancel_requested || ctx.signal?.aborted
                            ? "operation.cancelled"
                            : args.kind === "node.role_converge" &&
                                    snapshot.role === "websocket"
                            ? "operation.kind_unavailable"
                            : snapshot.footprint.refusal
                            ? snapshot.footprint.refusal
                            : snapshot.metrics_fleet?.fleet.some((node) =>
                                    !hasRoleTransport(
                                        node,
                                        snapshot.proxy_node_ids,
                                    )
                                )
                            ? "metrics.no_worker"
                            : null;
                    const admission = {
                        snapshot,
                        started_at: new Date().toISOString(),
                        ready: refusal === null,
                        refusal,
                    };
                    if (admission.ready) {
                        await gateway(args, ctx).project({
                            status: "running",
                            started_at: admission.started_at,
                        });
                    }
                    await ctx.writeResource(
                        "admission",
                        "admission",
                        admission,
                    );
                    return admission;
                },
            },
            prepareFootprint: {
                description:
                    "Capture the canonical footprint after role publication changes its desired files",
                arguments: RoleArgs.extend({ admission: Admission }),
                execute: async (
                    args: z.infer<typeof RoleArgs> & {
                        admission: z.infer<typeof Admission>;
                    },
                    ctx: Context,
                ) => {
                    const current = await read(args, ctx);
                    if (current.cancel_requested) {
                        throw new Error("operation.cancelled");
                    }
                    ctx.signal?.throwIfAborted();
                    await gateway(args, ctx).project({
                        status: "running",
                        started_at: args.admission.started_at,
                        result: { prepare_footprint: true },
                    });
                    const fresh = await read(args, ctx);
                    const prepared = {
                        ...args.admission,
                        snapshot: fresh.input,
                    };
                    await ctx.writeResource(
                        "admission",
                        "footprint_admission",
                        prepared,
                    );
                    return prepared;
                },
            },
            analyticsStorage: {
                description:
                    "Configure the admitted existing ClickHouse Process on its exact placed Node",
                arguments: RoleArgs.extend({ admission: Admission }),
                execute: async (
                    args: z.infer<typeof RoleArgs> & {
                        admission: z.infer<typeof Admission>;
                    },
                    ctx: Context,
                ) => {
                    const runtime = args.admission.snapshot.runtime;
                    ctx.signal?.throwIfAborted();
                    if (runtime?.role !== "analytics") return { ok: true };
                    const result = await configureRoleClickhouse(
                        runtime.clickhouse,
                        undefined,
                        ctx.signal,
                    );
                    if ("error_code" in result) {
                        throw new Error(result.error_code);
                    }
                    return { ok: true };
                },
            },
            baseline: {
                description:
                    "Apply one fixed baseline stage on the exact admitted Node",
                arguments: StepArgs,
                execute: async (
                    raw: z.infer<typeof StepArgs>,
                    ctx: Context,
                ) => {
                    const args = StepArgs.parse(raw);
                    if (
                        !args.admission.ready ||
                        args.admission.snapshot.node_id !== args.node_id
                    ) throw new Error("The baseline has no admitted role");
                    const current = await read(args, ctx);
                    if (
                        current.node_id !== args.node_id ||
                        current.id !== args.operation_id ||
                        JSON.stringify(current.input) !==
                            JSON.stringify(args.admission.snapshot) ||
                        !["queued", "running"].includes(current.status)
                    ) throw new Error("Role observation changed Node");
                    const result =
                        current.cancel_requested || ctx.signal?.aborted
                            ? { ok: false, error_code: "operation.cancelled" }
                            : args.stage === "runtime"
                            ? (args.admission.snapshot.runtime == null ||
                                    args.admission.snapshot.runtime.role ===
                                        "metrics"
                                ? { ok: true }
                                : await installRoleRuntime(
                                    args.admission.snapshot.runtime,
                                    gateway(args, ctx),
                                    ctx.signal,
                                ).then(async (result) => {
                                    if ("error_code" in result) {
                                        return {
                                            ok: false,
                                            error_code: result.error_code,
                                        };
                                    }
                                    if (
                                        args.admission.snapshot.runtime
                                            ?.role === "analytics"
                                    ) {
                                        await gateway(args, ctx).project({
                                            status: "running",
                                            started_at:
                                                args.admission.started_at,
                                            result: { runtime_completed: true },
                                        });
                                    }
                                    return { ok: true };
                                }))
                            : args.stage.startsWith("manager-")
                            ? await (async () => {
                                const manager = args.admission.snapshot.managers
                                    ?.find((value) =>
                                        value.manager === args.stage.slice(8)
                                    );
                                if (!manager) return { ok: true };
                                const result = await convergeParentManager({
                                    parent: {
                                        ...RoleArgs.parse(args),
                                        agent_sha256: args.admission.snapshot
                                            .agent_sha256,
                                    },
                                    manager,
                                }, ctx);
                                return result.outcome.status === "succeeded"
                                    ? { ok: true }
                                    : {
                                        ok: false,
                                        error_code: result.outcome.error_code ??
                                            "tool.materialization_failed",
                                    };
                            })()
                            : args.stage === "gateway-dns"
                            ? await (async () => {
                                if (
                                    args.admission.snapshot.role !== "gateway"
                                ) {
                                    return {
                                        ok: true,
                                    };
                                }
                                const ordering = await gatewayActions.order(
                                    undefined,
                                    ctx.signal,
                                    gatewayActions.order ===
                                            gatewayCaddyOrdering
                                        ? await Deno.readTextFile(
                                            ctx.extensionFile(
                                                "scripts/caddy_ordering.py",
                                            ),
                                        )
                                        : undefined,
                                );
                                if (!ordering.completed) {
                                    return {
                                        ok: false,
                                        error_code: ordering.error_code,
                                    };
                                }
                                if (
                                    args.admission.snapshot.gateway_dns == null
                                ) {
                                    return {
                                        ok: true,
                                    };
                                }
                                const dns = await gatewayActions.dns(
                                    args.admission.snapshot.gateway_dns,
                                    undefined,
                                    ctx.signal,
                                );
                                return dns.completed
                                    ? { ok: true }
                                    : { ok: true, follow_up: dns.error_code };
                            })()
                            : await baseline(
                                args.admission.snapshot,
                                args.stage,
                                ctx.signal,
                                args.stage === "javascript" &&
                                    baseline === convergeRoleBaseline
                                    ? await Deno.readTextFile(
                                        ctx.extensionFile("scripts/recipe.py"),
                                    )
                                    : undefined,
                                args.stage === "prerequisites" &&
                                    baseline === convergeRoleBaseline
                                    ? await Deno.readTextFile(
                                        ctx.extensionFile(
                                            "scripts/role_prerequisites.py",
                                        ),
                                    )
                                    : undefined,
                            );
                    const outcome = Outcome.parse({
                        stage: args.stage,
                        ...result,
                    });
                    await ctx.writeResource("outcome", args.stage, outcome);
                    return { ok: outcome.ok };
                },
            },
            footprint: {
                description:
                    "Compose a canonical footprint action in the role root on its exact Node",
                arguments: FootprintArgs,
                execute: async (
                    raw: z.infer<typeof FootprintArgs>,
                    ctx: Context,
                ) => {
                    const args = FootprintArgs.parse(raw);
                    const snapshot = args.admission.snapshot;
                    if (
                        !args.admission.ready ||
                        snapshot.node_id !== args.node_id
                    ) {
                        throw new Error(
                            "Footprint has no admitted standalone role",
                        );
                    }
                    const current = await read(args, ctx);
                    if (
                        current.id !== args.operation_id ||
                        current.node_id !== args.node_id ||
                        !["queued", "running"].includes(current.status) ||
                        JSON.stringify(current.input) !==
                            JSON.stringify(snapshot)
                    ) {
                        throw new Error(
                            "Footprint observation changed the admitted role",
                        );
                    }
                    const outcome = FootprintOutcome.parse(
                        current.cancel_requested || ctx.signal?.aborted
                            ? {
                                stage: args.stage,
                                ok: false,
                                error_code: "operation.cancelled",
                            }
                            : await footprint(
                                args.stage,
                                snapshot.footprint,
                                gateway(args, ctx),
                                args.binary_changed,
                                ctx.signal,
                                footprint === convergeFootprintStage
                                    ? {
                                        php: await Deno.readTextFile(
                                            ctx.extensionFile(
                                                "scripts/php_runtime.py",
                                            ),
                                        ),
                                        preparation: await Deno.readTextFile(
                                            ctx.extensionFile(
                                                "scripts/role_prerequisites.py",
                                            ),
                                        ),
                                        footprint: await Deno.readTextFile(
                                            ctx.extensionFile(
                                                "scripts/footprint.py",
                                            ),
                                        ),
                                    }
                                    : undefined,
                            ),
                    );
                    if (
                        outcome.stage !== args.stage || outcome.entry &&
                            !snapshot.footprint.artifacts.some((a) =>
                                a.name === outcome.entry!.name
                            )
                    ) {
                        throw new Error(
                            "The role footprint outcome differs from admission",
                        );
                    }
                    await ctx.writeResource(
                        "footprint_outcome",
                        args.stage,
                        outcome,
                    );
                    return { ok: outcome.ok };
                },
            },
            cli: {
                description:
                    "Compose the canonical local CLI action after known role and footprint outcomes",
                arguments: CliArgs,
                execute: async (raw: z.infer<typeof CliArgs>, ctx: Context) => {
                    const args = CliArgs.parse(raw);
                    const snapshot = args.admission.snapshot;
                    if (
                        !args.admission.ready ||
                        snapshot.node_id !== args.node_id ||
                        baselineProof(args.baseline)
                    ) {
                        throw new Error("CLI has no proven role baseline");
                    }
                    footprintProof(snapshot.footprint, args.footprints);
                    const current = await read(args, ctx);
                    if (
                        current.id !== args.operation_id ||
                        current.node_id !== args.node_id ||
                        !["queued", "running"].includes(current.status) ||
                        JSON.stringify(current.input) !==
                            JSON.stringify(snapshot)
                    ) {
                        throw new Error(
                            "CLI observation changed the admitted role",
                        );
                    }
                    const outcome = CliConvergeResult.parse(
                        current.cancel_requested || ctx.signal?.aborted
                            ? { error_code: "operation.cancelled" }
                            : await cli({
                                ...snapshot.cli,
                                agent_sha256: snapshot.agent_sha256,
                            }, {
                                signal: ctx.signal,
                                source: cli === convergeCli
                                    ? await Deno.readTextFile(
                                        ctx.extensionFile("scripts/cli.py"),
                                    )
                                    : undefined,
                            }),
                    );
                    await ctx.writeResource("cli_outcome", "cli", outcome);
                    return { ok: !Object.hasOwn(outcome, "error_code") };
                },
            },
            project: {
                description:
                    "Project bounded assignment state and optional follow-ups without Gateway execution",
                arguments: ProjectArgs,
                execute: async (
                    raw: z.infer<typeof ProjectArgs>,
                    ctx: Context,
                ) => {
                    const args = ProjectArgs.parse(raw);
                    const snapshot = args.admission.snapshot;
                    if (snapshot.node_id !== args.node_id) {
                        throw new Error("Projection changed the admitted Node");
                    }
                    const evaluated = roleResult(args);
                    const code = evaluated.code;
                    const cancelled = evaluated.cancelled;
                    const result = evaluated.result;
                    await gateway(args, ctx).project({
                        status: cancelled
                            ? "cancelled"
                            : code
                            ? "failed"
                            : "succeeded",
                        started_at: args.admission.started_at,
                        finished_at: new Date().toISOString(),
                        result: cancelled ? {} : result,
                        error_code: cancelled ? null : code,
                    });
                    if (code && !cancelled) {
                        throw new Error("node_role.convergence_failed");
                    }
                    return { status: cancelled ? "cancelled" : "succeeded" };
                },
            },
        },
    };
}
