import { z } from "npm:zod@4.4.3";
import { ExecutorError } from "../../operation-runner/mod.ts";
import { type Failure, StepListResult } from "./contract.ts";
import type { Completion, Receipt, Session } from "./runtime.ts";
export type { Child, Completion, Receipt, Session } from "./runtime.ts";

export class Refused extends Error {
    constructor(readonly code: string, readonly result: Receipt = {}) {
        super(code);
    }
}

function number(input: Receipt, field: string): number {
    return z.number().int().positive().parse(input[field]);
}
async function run(s: Session, key: string): Promise<Receipt> {
    const result = await s.run(key);
    if (typeof result.error_code === "string") {
        throw new Refused(result.error_code, result);
    }
    return result;
}
async function child(
    s: Session,
    key: string,
    kind: string,
    nodeId: number,
    input: Receipt,
): Promise<Receipt> {
    const result = await s.child({ key, kind, node_id: nodeId, input });
    if (typeof result.error_code === "string") {
        throw new Refused(result.error_code, result);
    }
    return result;
}
async function finish(s: Session): Promise<Receipt> {
    return await run(s, "lifecycle-finish");
}

async function route(
    s: Session,
    instanceId: number,
    routeId: unknown,
    key = "route-converge",
): Promise<void> {
    if (routeId === null || routeId === undefined) return;
    await child(s, key, "route.converge", s.nodeId, {
        route_id: z.number().int().positive().parse(routeId),
        instance_id: instanceId,
    });
}
async function steps(
    s: Session,
    phase: "setup" | "teardown",
    instanceId: number,
    digest: unknown,
    key: string = phase,
): Promise<Failure | null> {
    const receipt = await s.child({
        key,
        kind: "instance.run_steps",
        node_id: s.nodeId,
        input: {
            instance_id: instanceId,
            list: phase,
            source: "project",
            configuration_digest: z.string().regex(/^[0-9a-f]{64}$/).parse(
                digest,
            ),
        },
    });
    if (receipt.error_code === "operation.cancelled") {
        throw new ExecutorError("operation.cancelled");
    }
    const result = StepListResult.parse(receipt);
    if (result.outcome === "succeeded") return null;
    return {
        outcome: result.outcome,
        error_code: result.error_code ?? `instance.${phase}_step_failed`,
    };
}
function list(input: Receipt, field: string): Receipt[] {
    return z.array(z.record(z.string(), z.unknown())).parse(input[field]);
}
function mayCleanupRefusal(error: Refused): boolean {
    if (
        typeof error.result.outcome === "string" &&
        !["failed", "missing"].includes(error.result.outcome)
    ) return false;
    return ![
        "operation.cancelled",
        "instance.lifecycle_busy",
        "instance.source_busy",
        "operation.execution_uncertain",
        "operation.execution_busy",
        "operation.execution_failed",
        "operation.outcome_unknown",
        "operation.receipt_unavailable",
        "operation.child_unproven",
        "operation.lease_lost",
    ].includes(error.code);
}

async function rename(s: Session): Promise<Receipt> {
    await run(s, "rename-inspect");
    if (s.input.domain !== null) {
        await child(s, "route-update", "route.update", s.nodeId, {
            route_id: number(s.input, "route_id"),
            domain: s.input.domain,
        });
    }
    return await finish(s);
}

async function register(s: Session): Promise<Receipt> {
    for (
        const key of [
            "registration-relocate-prepare",
            "registration-relocate-cleanup",
        ]
    ) {
        await run(s, key);
    }
    for (const member of list(s.input, "members")) {
        const id = number(member, "instance_id");
        let receiptPrepared = false;
        try {
            if (member.completed !== true) {
                await run(s, `registration-${id}-receipt-prepare`);
                receiptPrepared = true;
                await run(s, `registration-${id}-profile`);
                await run(s, `registration-${id}-configuration`);
            }
            await route(s, id, member.route_id, `route-${id}`);
            if (member.completed !== true) {
                await run(s, `registration-${id}-activate`);
            }
        } catch (error) {
            if (
                receiptPrepared && error instanceof Refused &&
                mayCleanupRefusal(error)
            ) {
                await run(s, `registration-${id}-receipt-restore`);
            }
            throw error;
        }
        await run(s, `registration-${id}-receipt-discard`);
    }
    if (s.input.run_setup === true) {
        const failure = await steps(
            s,
            "setup",
            number(s.input, "instance_id"),
            s.input.setup_digest,
        );
        if (failure) {
            throw new Refused(failure.error_code, { outcome: failure.outcome });
        }
    }
    return await finish(s);
}

async function clone(s: Session): Promise<Receipt> {
    if (s.input.initial_completed === true) return await finish(s);
    for (
        const key of [
            "clone-user",
            "clone-source-prepare",
            "clone-source-resolve",
            "clone-source-profile",
            "clone-caddy-access",
            "clone-environment",
        ]
    ) {
        await run(s, key);
    }
    if (s.input.sqlite_source_path !== null) {
        const prepared = await run(s, "clone-sqlite-intent");
        if (z.boolean().parse(prepared.seed_required)) {
            await child(
                s,
                "clone-sqlite",
                "database.sqlite_seed",
                s.nodeId,
                {},
            );
        }
        await run(s, "clone-sqlite-attach");
    }
    await child(
        s,
        "clone-environment-sync",
        "instance.sync_environment",
        s.nodeId,
        {
            instance_id: number(s.input, "instance_id"),
        },
    );
    await run(s, "clone-definitions");
    await child(
        s,
        "clone-processes",
        "process.install_instance_definitions",
        s.nodeId,
        {
            instance_id: number(s.input, "instance_id"),
        },
    );
    await child(
        s,
        "clone-schedules",
        "schedule.install_instance_definitions",
        s.nodeId,
        {
            instance_id: number(s.input, "instance_id"),
        },
    );
    await route(s, number(s.input, "instance_id"), s.input.route_id);
    return await finish(s);
}

async function transfer(s: Session): Promise<Receipt> {
    if (s.input.initial_completed === true) return await finish(s);
    if (s.input.initial_cutover !== true) {
        let stopped = false;
        try {
            await child(
                s,
                "transfer-stop-source",
                "process.instance_runtime",
                s.nodeId,
                {
                    instance_id: number(s.input, "instance_id"),
                    action: "transfer_pause",
                },
            );
            stopped = true;
            await run(s, "transfer-capture");
            await run(s, "transfer-sqlite-intent");
            await child(
                s,
                "transfer-destination",
                "instance.transfer_destination",
                number(s.input, "destination_node_id"),
                {
                    instance_id: number(s.input, "instance_id"),
                    transfer_id: s.input.transfer_id,
                },
            );
            await run(s, "transfer-cutover");
        } catch (caught) {
            const error = caught instanceof ExecutorError &&
                    caught.code === "operation.cancelled"
                ? new Refused(caught.code)
                : caught;
            // Uncertain transport outcomes escape. A confirmed refusal may roll back only before the atomic cutover.
            if (
                stopped && error instanceof Refused &&
                error.result.rollback_pending !== true &&
                (mayCleanupRefusal(error) ||
                    error.code === "operation.cancelled")
            ) {
                await child(
                    s,
                    "transfer-rollback",
                    "instance.transfer_rollback",
                    s.nodeId,
                    {
                        instance_id: number(s.input, "instance_id"),
                        transfer_id: s.input.transfer_id,
                    },
                );
            }
            throw error;
        }
    }
    await child(
        s,
        "transfer-activate-destination",
        "instance.transfer_activate",
        number(s.input, "destination_node_id"),
        {
            instance_id: number(s.input, "instance_id"),
            transfer_id: s.input.transfer_id,
        },
    );
    await run(s, "transfer-destination-activated");
    await child(s, "transfer-retire-source", "route.retire_source", s.nodeId, {
        transfer_id: s.input.transfer_id,
    });
    await child(
        s,
        "transfer-retire-runtime",
        "process.instance_runtime",
        s.nodeId,
        {
            instance_id: number(s.input, "instance_id"),
            action: "transfer_retire_source",
        },
    );
    await run(s, "transfer-cleanup-source");
    await run(s, "transfer-finish");
    return await finish(s);
}

async function transferRollback(s: Session): Promise<Receipt> {
    const recorded = await run(s, "rollback-record");
    await child(
        s,
        "transfer-restore-source",
        "process.instance_runtime",
        s.nodeId,
        {
            instance_id: number(s.input, "instance_id"),
            action: "transfer_restore",
        },
    );
    if (z.boolean().parse(recorded.seed_abandon_required)) {
        await child(
            s,
            "transfer-abandon-sqlite",
            "database.sqlite_seed_abandon",
            number(recorded, "seed_abandon_node_id"),
            {},
        );
    }
    await child(
        s,
        "transfer-discard-destination",
        "instance.transfer_discard",
        number(recorded, "destination_node_id"),
        {},
    );
    await run(s, "rollback-state");
    return await run(s, "compensation-proof");
}

/** Swamp selects the sequence; Gateway descriptors enforce each effect's admitted inputs and prerequisites. */
export async function lifecycle(s: Session): Promise<Completion> {
    try {
        const execute = {
            "instance.rename": rename,
            "instance.clone": clone,
            "instance.register": register,
            "instance.transfer": transfer,
            "instance.transfer_rollback": transferRollback,
        }[s.kind];
        if (!execute) throw new Error("Wrong instance lifecycle kind");
        return {
            status: "succeeded",
            result: await execute(s),
            error_code: null,
        };
    } catch (error) {
        if (
            error instanceof ExecutorError &&
            error.code === "operation.cancelled"
        ) {
            return { status: "cancelled", result: {}, error_code: error.code };
        }
        if (!(error instanceof Refused)) throw error;
        return {
            status: error.code === "operation.cancelled"
                ? "cancelled"
                : "failed",
            result: error.result,
            error_code: error.code,
        };
    }
}
