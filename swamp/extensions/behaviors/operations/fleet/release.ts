import { z } from "npm:zod@4.4.3";
import { gatewayHttp, type Http } from "../../automatic-deploy/gateway.ts";
import { DirectArgs, GatewayOperation, Globals } from "../_lib/direct.ts";
import { Code, NodeRelease, publishObserver } from "./release_local.ts";
import { CliNativeRun } from "./cli_converge_local.ts";

const Kind = z.enum(["fleet.rollout", "fleet.cli_converge"]);
const Args = DirectArgs.extend({
    kind: Kind,
    node_id: z.number().int().positive(),
});
const Input = z.object({
    nodes: z.array(NodeRelease).max(1000),
    remaining: z.number().int().nonnegative().default(0),
    rollout_id: z.number().int().positive().optional(),
    commit: z.string().optional(),
}).strict();
const Current = z.object({
    id: z.number(),
    kind: Kind,
    node_id: z.number(),
    input: Input,
    status: z.enum(["queued", "running", "succeeded", "failed", "cancelled"]),
    cancel_requested: z.boolean(),
});
const Admission = z.object({
    ready: z.boolean(),
    started_at: z.string(),
    nodes: z.array(NodeRelease),
    status: z.enum(["running", "succeeded", "failed", "cancelled"]),
});
export const NodeResult = z.object({
    node_id: z.number().int().positive(),
    outcome: z.enum([
        "converged",
        "unchanged",
        "deferred",
        "skipped",
        "failed",
        "cancelled",
        "pending",
    ]),
    error_code: Code.nullable(),
    cli_version: z.string().nullable(),
    agent_version: z.string().nullable(),
    step: z.enum(["admission", "cli", "agent", "verify"]),
    ok: z.boolean(),
});
const Gate = z.object({
    install: z.boolean(),
    result: NodeResult,
    ssh: NodeRelease.shape.ssh,
    cli: z.object({
        user: NodeRelease.shape.user,
        release: NodeRelease.shape.release,
        profile: NodeRelease.shape.profile,
    }).strict(),
}).strict();
const initial = (node_id: number): z.infer<typeof NodeResult> => ({
    node_id,
    outcome: "unchanged",
    error_code: null,
    cli_version: null,
    agent_version: null,
    step: "admission",
    ok: true,
});
/** A deferred refusal waits for a later run; anything else stops the fleet. */
function refused(
    result: z.infer<typeof NodeResult>,
    code: string,
    step: z.infer<typeof NodeResult>["step"],
): z.infer<typeof NodeResult> {
    const deferred = ["fleet.window_closed", "node.update_busy", "cli.update_busy"].includes(code);
    return {
        ...result,
        step,
        error_code: Code.parse(code),
        outcome: deferred ? "deferred" : code === "operation.cancelled" ? "cancelled" : "failed",
        ok: deferred,
    };
}
/** A rollout Node whose CLI succeeded also publishes the observer; every other Node ends at its record. */
const observes = (record: z.infer<typeof NodeResult>) =>
    record.ok && record.step === "cli";
/** The final outcome of one fleet-release-node child, or undefined while it is uncertain. */
function outcome(kind: z.infer<typeof Kind>, raw: unknown) {
    const step = z.object({
        outputs: z.object({ record: NodeResult, observer: NodeResult }).partial()
            .optional(),
    }).safeParse(raw);
    const record = step.success ? step.data.outputs?.record : undefined;
    return record && kind === "fleet.rollout" && observes(record)
        ? step.data!.outputs?.observer
        : record;
}
type Context = {
    extensionFile(path: string): string;
    globalArgs: z.infer<typeof Globals>;
    signal?: AbortSignal;
    writeResource: (
        spec: string,
        name: string,
        value: unknown,
    ) => Promise<unknown>;
};

const terminal = (status: string) =>
    ["succeeded", "failed", "cancelled"].includes(status);
export function windowOpen(daytime: boolean, at: Date): boolean {
    const hour = Number(new Intl.DateTimeFormat("en-GB", {
        timeZone: "Europe/Amsterdam",
        hour: "2-digit",
        hourCycle: "h23",
    }).format(at));
    return daytime || hour >= 3 && hour < 6;
}

/** Native dependencies, placed Node steps and durable outputs own the release lifecycle. */
export function fleetRelease(
    observerInstall: typeof publishObserver = publishObserver,
    makeHttp: (ctx: Context) => Http = (ctx) =>
        gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
    now = () => new Date(),
) {
    async function read(args: z.infer<typeof Args>, ctx: Context) {
        const gateway = new GatewayOperation(makeHttp(ctx), args);
        const current = Current.parse(await gateway.read(ctx.signal));
        if (
            current.id !== args.operation_id || current.kind !== args.kind ||
            current.node_id !== args.node_id
        ) {
            throw new Error(
                "Fleet release intent differs from this native root",
            );
        }
        return { gateway, current };
    }
    return {
        type: "@nckrtl/fleet-release",
        version: "2026.10.31.1",
        globalArguments: Globals,
        resources: {
            admission: {
                description: "Immutable fleet release participants",
                schema: Admission,
                lifetime: "1d",
                garbageCollection: 10,
            },
            gate: {
                description: "Admission decision and CLI intent for one Node",
                schema: Gate,
                lifetime: "1d",
                garbageCollection: 10,
            },
            node: {
                description: "Bounded publication outcome on one Node",
                schema: NodeResult,
                lifetime: "1d",
                garbageCollection: 10,
            },
        },
        methods: {
            prepare: {
                description:
                    "Read admitted participants and start their single native release run",
                arguments: Args,
                execute: async (raw: z.infer<typeof Args>, ctx: Context) => {
                    const args = Args.parse(raw);
                    const { gateway, current } = await read(args, ctx);
                    const started_at = now().toISOString();
                    let status: z.infer<typeof Admission>["status"] = "running";
                    if (terminal(current.status)) {
                        status = current.status as typeof status;
                    } else if (
                        current.cancel_requested || ctx.signal?.aborted
                    ) {
                        status = "cancelled";
                        await gateway.project({
                            status,
                            started_at,
                            finished_at: started_at,
                            result: {},
                        });
                    } else await gateway.project({ status, started_at });
                    const result = Admission.parse({
                        ready: status === "running",
                        started_at,
                        status,
                        nodes: status === "running" ? current.input.nodes : [],
                    });
                    await ctx.writeResource("admission", "admission", result);
                    return result;
                },
            },
            gate: {
                description:
                    "Decide on Node 209 whether this Node publishes now; earlier Nodes must have succeeded",
                arguments: Args.extend({
                    target_node_id: z.number().int().positive(),
                    previous: z.record(z.string(), z.unknown()),
                }),
                execute: async (
                    raw: z.infer<typeof Args> & {
                        target_node_id: number;
                        previous: Record<string, unknown>;
                    },
                    ctx: Context,
                ) => {
                    const args = Args.parse(raw);
                    const { current } = await read(args, ctx);
                    const node = current.input.nodes.find((v) =>
                        v.node_id === raw.target_node_id
                    );
                    if (!node) {
                        throw new Error(
                            "Node has no open fleet release intent",
                        );
                    }
                    let result = initial(node.node_id);
                    // The native forEach concurrency cap is one. Earlier outputs must exist
                    // before a later Node can act; an uncertain or refused effect stops the fleet.
                    const index = current.input.nodes.indexOf(node);
                    for (let prior = 0; prior < index; prior++) {
                        if (!outcome(args.kind, raw.previous[`node-${prior}`])?.ok) {
                            result = { ...result, outcome: "pending", ok: false };
                            break;
                        }
                    }
                    if (result.outcome !== "pending") {
                        if (
                            terminal(current.status) ||
                            current.cancel_requested || ctx.signal?.aborted
                        ) {
                            result = refused(result, "operation.cancelled", "admission");
                        } else if (node.skip_reason !== null) {
                            result = { ...result, outcome: "skipped" };
                        } else if (node.plan_error !== null) {
                            result = refused(result, node.plan_error, "admission");
                        } else if (!windowOpen(node.daytime, now())) {
                            result = refused(result, "fleet.window_closed", "admission");
                        }
                    }
                    if (result.outcome === "unchanged" && node.ssh === null) {
                        throw new Error("Installing Node has no admitted SSH target");
                    }
                    const gate = Gate.parse({
                        install: result.outcome === "unchanged" && result.ok,
                        result,
                        ssh: node.ssh,
                        cli: { user: node.user, release: node.release, profile: node.profile },
                    });
                    await ctx.writeResource("gate", `gate-${node.node_id}`, gate);
                    return gate;
                },
            },
            record: {
                description:
                    "Shape the native CLI outcome on Node 209; no Node effect",
                arguments: Args.extend({
                    target_node_id: z.number().int().positive(),
                    gate: Gate,
                    native_cli: CliNativeRun,
                }),
                execute: async (
                    raw: z.infer<typeof Args> & {
                        target_node_id: number;
                        gate: z.infer<typeof Gate>;
                        native_cli: z.infer<typeof CliNativeRun>;
                    },
                    ctx: Context,
                ) => {
                    const gate = Gate.parse(raw.gate);
                    let result = gate.result;
                    if (result.node_id !== raw.target_node_id) {
                        throw new Error("Fleet gate belongs to another Node");
                    }
                    if (gate.install) {
                        const cli = CliNativeRun.parse(raw.native_cli);
                        result = cli.status !== "succeeded" || cli.result === null
                            ? refused(result, "cli.install_failed", "cli")
                            : {
                                ...result,
                                cli_version: cli.result.version,
                                outcome: cli.result.installed !== null || cli.result.configured
                                    ? "converged"
                                    : "unchanged",
                                step: "cli",
                            };
                    }
                    const safe = NodeResult.parse(result);
                    await ctx.writeResource("node", `record-${safe.node_id}`, safe);
                    return safe;
                },
            },
            observer: {
                description:
                    "Publish the pinned observer binary on this placed Node after its CLI succeeded",
                arguments: Args.extend({
                    target_node_id: z.number().int().positive(),
                    record: NodeResult,
                }),
                execute: async (
                    raw: z.infer<typeof Args> & {
                        target_node_id: number;
                        record: z.infer<typeof NodeResult>;
                    },
                    ctx: Context,
                ) => {
                    const args = Args.parse(raw);
                    let result = NodeResult.parse(raw.record);
                    if (args.kind !== "fleet.rollout" || !observes(result) || result.node_id !== raw.target_node_id) {
                        throw new Error("Only a rollout Node whose CLI succeeded publishes the observer");
                    }
                    const { current } = await read(args, ctx);
                    const node = current.input.nodes.find((v) =>
                        v.node_id === raw.target_node_id
                    )!;
                    if (current.cancel_requested || ctx.signal?.aborted === true) {
                        result = refused(result, "operation.cancelled", "agent");
                    } else if (!windowOpen(node.daytime, now())) {
                        result = refused(result, "fleet.window_closed", "agent");
                    } else {
                        if (!node.agent) {
                            throw new Error("Rollout has no pinned agent delivery");
                        }
                        const agent = await observerInstall(node, undefined, ctx.signal);
                        if ("error_code" in agent) {
                            result = refused(result, agent.error_code, "agent");
                        } else if (!("changed" in agent)) {
                            throw new Error("Agent publication returned no observer result");
                        } else {
                            result = {
                                ...result,
                                agent_version: agent.version,
                                step: "verify",
                                ...(agent.changed ? { outcome: "converged" as const } : {}),
                            };
                        }
                    }
                    const safe = NodeResult.parse(result);
                    await ctx.writeResource("node", `node-${safe.node_id}`, safe);
                    return safe;
                },
            },
            finish: {
                description:
                    "Record the native fleet outcome; uncertain effects have no invented completion",
                arguments: Args.extend({
                    admission: Admission,
                    node_results: z.record(z.string(), z.unknown()),
                }),
                execute: async (
                    raw: z.infer<typeof Args> & {
                        admission: z.infer<typeof Admission>;
                        node_results: Record<string, unknown>;
                    },
                    ctx: Context,
                ) => {
                    const args = Args.parse(raw);
                    if (!raw.admission.ready) {
                        return { status: raw.admission.status };
                    }
                    const { gateway, current } = await read(args, ctx);
                    const nodes: z.infer<typeof NodeResult>[] = [];
                    for (const [index, node] of current.input.nodes.entries()) {
                        const output = outcome(args.kind, raw.node_results[`node-${index}`]);
                        if (output && output.node_id !== node.node_id) {
                            throw new Error(
                                "Native Node output differs from admitted order",
                            );
                        }
                        nodes.push(
                            output ?? {
                                node_id: node.node_id,
                                outcome: "failed",
                                ok: false,
                                error_code: "fleet.release_uncertain",
                                cli_version: null,
                                agent_version: null,
                                step: "admission",
                            },
                        );
                    }
                    const failure = nodes.find((node) =>
                        !node.ok && node.outcome !== "pending"
                    );
                    if (
                        !failure &&
                        nodes.some((node) => node.outcome === "pending")
                    ) {
                        throw new Error(
                            "Native fleet has no bounded predecessor result",
                        );
                    }
                    const cancelled = failure?.outcome === "cancelled";
                    const status = cancelled
                        ? "cancelled"
                        : failure
                        ? "failed"
                        : "succeeded";
                    const running = current.input.remaining > 0 ||
                        nodes.some((node) => node.outcome === "deferred");
                    const result = {
                        nodes,
                        ...(failure ? { error_code: failure.error_code } : {}),
                        ...(args.kind === "fleet.rollout"
                            ? {
                                rollout_status: failure
                                    ? "halted"
                                    : running
                                    ? "running"
                                    : "completed",
                                halted_node_id: failure?.node_id ?? null,
                            }
                            : {}),
                    };
                    await gateway.project({
                        status,
                        started_at: raw.admission.started_at,
                        finished_at: now().toISOString(),
                        result,
                        error_code: cancelled
                            ? null
                            : failure?.error_code ?? null,
                    });
                    if (failure && !cancelled) {
                        throw new Error(failure.error_code!);
                    }
                    return { status };
                },
            },
        },
    };
}
