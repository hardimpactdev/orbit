import { z } from "npm:zod@4.4.3";
import type { Http } from "./gateway.ts";
import {
    automaticRetry,
    type Check,
    eligibleFailure,
    failureStatus,
    isAutomaticDeployment,
    mergeCheckResult,
    type Operation,
    PolicyError,
    type PolicyEvent,
    RowSchema,
    sha,
    terminalOperation,
} from "./development-policy.ts";

import {
    DevelopmentRetries,
    observeRetryHistory,
    recordRetryFailure,
} from "./development-retries.ts";

export const DevelopmentState = z.object({
    sequence: z.number().int().nonnegative().default(0),
    pending: z.object({
        commit: sha,
        key: z.string(),
        operation_id: z.number().int().positive().nullable(),
        manual_after: z.number().int().nonnegative().default(0),
    }).nullable().default(null),
    repair: z.object({
        route_id: z.number().int().positive(),
        key: z.string(),
        operation_id: z.number().int().positive().nullable(),
    }).nullable().default(null),
    // Receipt evidence awaiting history linkage; totals live only in the Gateway ledger.
    unrecorded_failures: z.array(z.object({
        key: z.string(),
        commit: sha,
        deployment_id: z.number().int().positive().nullable(),
        manual_after: z.number().int().nonnegative(),
        finished_at: z.string().datetime({ offset: true }),
    })).default([]),
    result: z.string().nullable().default(null),
    commit: sha.nullable().default(null),
    since: z.string().nullable().default(null),
});
export type DevelopmentContext = {
    readResource(name: string): Promise<Record<string, unknown> | null>;
    writeResource(
        spec: string,
        name: string,
        value: Record<string, unknown>,
    ): Promise<{ name: string }>;
};
/** Adds one policy event to the fire's terminal result; the Gateway records it. */
export type Emit = (instance: number, key: string, body: PolicyEvent) => void;
type Admissions = {
    checks(instance: number, commit: string, name: string): Promise<Check[]>;
    deploy(instance: number, commit: string, key: string): Promise<Operation>;
    receipt(
        instance: number,
        commit: string,
        key: string,
    ): Promise<Operation | null>;
    resolve(instance: number, commit: string, key: string): Promise<Operation>;
    operation(id: number): Promise<Operation>;
};
const Instance = z.object({
    id: z.number().int().positive(),
    node_id: z.number().int().positive(),
    seed_commit: sha.nullable(),
    development_projection_route_id: z.number().int().positive().nullable(),
    auto_deploy: z.object({
        policy_owner: z.string(),
        development_eligible: z.boolean(),
        gate: z.string().nullable(),
        resumed_after: z.number().int(),
        history_version: z.string(),
        development_retries: DevelopmentRetries,
    }),
});

/** Development policy uses public records and normal keyed admissions; it executes no Node action. */
export async function tickDevelopment(
    http: Http,
    ctx: DevelopmentContext,
    admissions: Admissions,
    now: string,
    emit: Emit,
    filter: number[],
) {
    const request = async (
        method: "GET" | "POST" | "PUT" | "PATCH",
        path: string,
        body?: unknown,
        key?: string,
    ): Promise<unknown> => {
        const response = await http(method, path, body, key);
        if (response.status < 200 || response.status >= 300) {
            const code = z.object({
                error: z.object({
                    code: z.string().regex(/^[a-z0-9_.-]{1,100}$/),
                }),
            }).safeParse(response.body);
            throw new PolicyError(
                code.success
                    ? code.data.error.code
                    : "gateway.read_unavailable",
                "Development policy request failed",
            );
        }
        return z.object({ data: z.unknown() }).parse(response.body).data;
    };
    const discovery = Instance.pick({
        id: true,
        node_id: true,
    }).extend({
        auto_deploy: z.object({
            policy_owner: z.string(),
            development_eligible: z.boolean().optional().default(false),
        }),
    });
    const records = z.array(discovery).parse(
        await request("GET", "/api/v1/instances"),
    );
    const handles = new Map<string, { name: string }>();
    const failures: string[] = [];
    for (const listed of records) {
        if (
            !filter.includes(listed.id) ||
            !listed.auto_deploy.development_eligible ||
            listed.auto_deploy.policy_owner !== "swamp"
        ) continue;
        try {
            const id = listed.id, name = `development-${id}`;
            const state = DevelopmentState.parse(
                await ctx.readResource(name) ?? {},
            );
            // Reading a receipt can reconcile a native terminal and change the seed/history.
            // Observe the Instance only after that read, as production does.
            const pendingOperation = state.pending
                ? state.pending.operation_id === null
                    ? await admissions.receipt(
                        id,
                        state.pending.commit,
                        state.pending.key,
                    ) ??
                        await admissions.resolve(
                            id,
                            state.pending.commit,
                            state.pending.key,
                        )
                    : await admissions.operation(state.pending.operation_id)
                : null;
            const record = await request(
                "GET",
                `/api/v1/instances/${listed.id}`,
            );
            const current = discovery.parse(record);
            if (
                !current.auto_deploy.development_eligible ||
                current.id !== listed.id ||
                current.node_id !== listed.node_id ||
                current.auto_deploy.policy_owner !== "swamp"
            ) continue;
            const instance = Instance.parse(record);
            const retries = instance.auto_deploy.development_retries;
            let savedRetries = JSON.stringify(retries);
            let saveRetries: (() => Promise<void>) | undefined;
            let saved = JSON.stringify(state);
            const save = async () => {
                // Persist retry evidence before dropping or advancing its checkpoint intent.
                await saveRetries?.();
                if (JSON.stringify(state) === saved) return;
                handles.set(
                    name,
                    await ctx.writeResource("development", name, state),
                );
                saved = JSON.stringify(state);
            };
            const history = z.array(RowSchema).parse(
                await request("GET", `/api/v1/instances/${id}/deployments`),
            );
            const head = z.object({ head: sha }).parse(
                await request(
                    "GET",
                    `/api/v1/instances/${id}/deployment-source/head`,
                ),
            ).head;
            const project = async (
                result: string,
                routeOperationId: number | null = null,
                commit = head,
            ) => {
                return await request(
                    "PUT",
                    `/api/v1/instances/${id}/auto-deploy/policy`,
                    {
                        development_retries: retries,
                        resumed_after: instance.auto_deploy.resumed_after,
                        last_deployment_id: Math.max(
                            0,
                            ...history.map((r) => r.id),
                        ),
                        history_version: instance.auto_deploy.history_version,
                        result,
                        commit,
                        head,
                        since: state.since ?? now,
                        error_code: result === "failed"
                            ? "deployment.automatic_failed"
                            : null,
                        message: null,
                        pause: null,
                        ...(routeOperationId === null
                            ? {}
                            : { route_operation_id: routeOperationId }),
                    },
                );
            };
            saveRetries = async () => {
                if (JSON.stringify(retries) === savedRetries) return;
                await project(
                    state.result ?? "busy",
                    null,
                    state.commit ?? head,
                );
                savedRetries = JSON.stringify(retries);
            };
            const publish = async (
                result: string,
                operationId: number | null = null,
                routeOperationId: number | null = null,
                commit = head,
            ) => {
                const changed = state.result !== result ||
                    state.commit !== commit;
                if (changed) state.since = now;
                const activity = changed &&
                    !["up_to_date", "deploying", "gate_pending"].includes(
                        result,
                    );
                if (activity) state.sequence++;
                state.result = result;
                state.commit = commit;
                await project(result, routeOperationId, commit);
                savedRetries = JSON.stringify(retries);
                await save();
                if (activity) {
                    emit(id, `dev.policy.${id}.${state.sequence}`, {
                        event: "activity",
                        result,
                        commit,
                        operation_id: operationId,
                        error_code: result === "failed"
                            ? "deployment.automatic_failed"
                            : null,
                        message: null,
                    });
                }
                return operationId;
            };
            // The enable watermark resumes production pause policy; it is not
            // evidence of a manual deployment and must never erase attempts.
            // A receipt initially without history can acquire a row during reconciliation.
            // Link it before importing that row, so it never becomes a second attempt.
            for (const entry of retries.commits) {
                for (const evidence of entry.evidence) {
                    if (
                        evidence.key === null ||
                        evidence.deployment_id !== null ||
                        !history.some((r) =>
                            r.commit === entry.commit && eligibleFailure(r) &&
                            (r.id > retries.observed_through ||
                                retries.unsettled.includes(r.id))
                        )
                    ) continue;
                    const operation = await admissions.receipt(
                        id,
                        entry.commit,
                        evidence.key,
                    );
                    evidence.deployment_id = operation?.result?.deployment_id ??
                        null;
                }
            }
            observeRetryHistory(retries, history);
            const manual = retries.manual_after;
            // Only history carrying the admission SHA replaces retained failure evidence.
            // A terminal row with a null SHA cannot account for that commit.
            state.unrecorded_failures = state.unrecorded_failures.filter((f) =>
                f.manual_after >= manual &&
                !history.some((r) =>
                    r.id === f.deployment_id && r.status !== "running" &&
                    r.commit === f.commit && isAutomaticDeployment(r)
                )
            );
            for (const failure of state.unrecorded_failures) {
                recordRetryFailure(
                    retries,
                    failure.commit,
                    failure.key,
                    failure.deployment_id,
                    failure.finished_at,
                );
            }
            const recordFailure = (
                intent: NonNullable<typeof state.pending>,
                deploymentId: number | null,
                operationId: number,
            ) => {
                if (
                    !recordRetryFailure(
                        retries,
                        intent.commit,
                        intent.key,
                        deploymentId,
                        now,
                        operationId,
                    )
                ) return;
                if (
                    !state.unrecorded_failures.some((f) => f.key === intent.key)
                ) {
                    state.unrecorded_failures.push({
                        key: intent.key,
                        commit: intent.commit,
                        deployment_id: deploymentId,
                        manual_after: intent.manual_after,
                        finished_at: now,
                    });
                }
            };
            if (state.pending) {
                const intent = state.pending;
                const operation = pendingOperation!;
                intent.operation_id = operation.id;
                if (!terminalOperation(operation.status)) {
                    await publish("deploying", operation.id);
                    continue;
                }
                const historyEvidence = history.some((r) =>
                    r.id === operation.result?.deployment_id &&
                    r.status !== "running" && r.commit === intent.commit &&
                    isAutomaticDeployment(r)
                );
                if (
                    !historyEvidence && failureStatus(operation.status) &&
                    operation.error_code !== "deployment.paused" &&
                    intent.manual_after >= manual
                ) {
                    recordFailure(
                        intent,
                        operation.result?.deployment_id ?? null,
                        operation.id,
                    );
                }
                if (
                    historyEvidence && failureStatus(operation.status) &&
                    operation.error_code !== "deployment.paused" &&
                    intent.manual_after >= manual && history.some((r) =>
                        r.id === operation.result?.deployment_id &&
                        eligibleFailure(r)
                    )
                ) {
                    recordRetryFailure(
                        retries,
                        intent.commit,
                        intent.key,
                        operation.result!.deployment_id!,
                        now,
                        operation.id,
                    );
                }
                state.pending = null;
                // A later manual deployment supersedes this automatic attempt.
                // Its boundary already discarded retry evidence; evaluate the live seed/head.
                if (intent.manual_after >= manual) {
                    if (operation.status !== "succeeded") {
                        await publish(
                            "failed",
                            operation.id,
                            null,
                            intent.commit,
                        );
                        // Ended failure never permits an immediate same-head effect.
                        if (
                            intent.commit === head &&
                            operation.error_code !== "deployment.paused"
                        ) {
                            continue;
                        }
                    } else if (instance.seed_commit === intent.commit) {
                        await publish(
                            "up_to_date",
                            operation.id,
                            null,
                            intent.commit,
                        );
                        if (intent.commit === head) {
                            continue;
                        }
                    }
                }
                // A stale success cannot prove the current container's seed; observe normally.
            }
            let holder: Operation | undefined;
            for (const status of ["queued", "running", "waiting"]) {
                const rows = z.array(
                    z.object({
                        id: z.number().int(),
                        kind: z.string(),
                        status: z.string(),
                        target_type: z.string(),
                        target_id: z.number().int(),
                    }),
                ).parse(
                    await request(
                        "GET",
                        `/api/v1/operations?target_type=instance&target_id=${id}&status=${status}`,
                    ),
                );
                holder = rows.find((r) =>
                    r.target_type === "instance" && r.target_id === id &&
                    r.status === status
                ) as Operation | undefined;
                if (holder) break;
            }
            if (holder) {
                await publish("busy", holder.id);
                continue;
            }
            if (instance.development_projection_route_id !== null) {
                if (
                    state.repair?.route_id !==
                        instance.development_projection_route_id
                ) {
                    state.repair = {
                        route_id: instance.development_projection_route_id,
                        key: `dev.route.${id}.${++state.sequence}`,
                        operation_id: null,
                    };
                    await save();
                }
                const repair = state.repair;
                const operation = repair.operation_id === null
                    ? z.object({
                        id: z.number().int().positive(),
                        status: z.string(),
                    })
                        .parse(
                            await request(
                                "PATCH",
                                `/api/v1/routes/${repair.route_id}`,
                                {},
                                repair.key,
                            ),
                        )
                    : await admissions.operation(repair.operation_id);
                repair.operation_id = operation.id;
                await save();
                if (operation.status === "succeeded") {
                    await publish("busy", operation.id, operation.id);
                    state.repair = null;
                    await save();
                } else {
                    await publish(
                        terminalOperation(operation.status) ? "failed" : "busy",
                        operation.id,
                    );
                    if (terminalOperation(operation.status)) {
                        state.repair = null;
                        await save();
                    }
                }
                continue;
            }
            if (head === instance.seed_commit) {
                await publish("up_to_date");
                continue;
            }
            if (instance.auto_deploy.gate !== null) {
                const check = mergeCheckResult(
                    await admissions.checks(
                        id,
                        head,
                        instance.auto_deploy.gate,
                    ),
                    head,
                    instance.auto_deploy.gate,
                );
                if (check !== "passed") {
                    await publish(
                        check === "failed" ? "gate_failed" : "gate_pending",
                    );
                    continue;
                }
            }
            const entry = retries.commits.find((e) => e.commit === head);
            const retry = entry?.stopped
                ? "commit_failed"
                : entry?.last_failure_at &&
                    automaticRetry(entry.attempts, entry.last_failure_at, now);
            if (retry) {
                await publish(retry);
                continue;
            }
            state.pending = {
                commit: head,
                key: `dev.deploy.${id}.${++state.sequence}.${head}`,
                operation_id: null,
                manual_after: manual,
            };
            await save();
            // A lost response retains this exact intent. Later ticks use the shared receipt/resolution contract.
            let operation: Operation;
            try {
                operation = await admissions.deploy(
                    id,
                    head,
                    state.pending.key,
                );
            } catch (error) {
                if (
                    error instanceof PolicyError &&
                    error.code === "operation.target_busy"
                ) {
                    state.pending = null;
                    await save();
                    await publish("busy", error.operationId);
                    continue;
                }
                throw error;
            }
            state.pending.operation_id = operation.id;
            await save();
            await publish("started", operation.id);
        } catch (error) {
            if (
                error instanceof PolicyError &&
                error.code === "instance.auto_deploy_observation_changed"
            ) continue;
            failures.push(
                `Instance ${listed.id}: ${
                    error instanceof PolicyError
                        ? error.code
                        : "deployment.policy_unavailable"
                }`,
            );
        }
    }
    if (failures.length) throw new Error(failures.join("; "));
    return [...handles.values()];
}
