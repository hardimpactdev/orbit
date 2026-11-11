import { z } from "npm:zod@4.4.3";
import {
    eligibleFailure,
    isAutomaticDeployment,
    type Row,
    sha,
} from "./development-policy.ts";

const Evidence = z.object({
    key: z.string().nullable(),
    deployment_id: z.number().int().positive().nullable(),
});
const Entry = z.object({
    commit: sha,
    attempts: z.number().int().min(0).max(3),
    last_failure_at: z.string().datetime({ offset: true }).nullable(),
    stopped: z.boolean(),
    evidence: z.array(Evidence).max(3),
    reset_operation_id: z.number().int().nonnegative().default(0),
    reset_after: z.number().int().nonnegative().default(0),
    reset_at: z.string().datetime({ offset: true }).nullable().default(null),
});
/** A decision-sized view of Gateway per-SHA records and the bounded history cursor. */
export const DevelopmentRetries = z.object({
    manual_after: z.number().int().nonnegative().default(0),
    observed_through: z.number().int().nonnegative().default(0),
    unsettled: z.array(z.number().int().positive()).max(50).default([]),
    commits: z.array(Entry).default([]),
});
export type RetryLedger = z.infer<typeof DevelopmentRetries>;
export type FailureEvidence = {
    key: string;
    commit: string;
    deployment_id: number | null;
    manual_after: number;
    finished_at: string;
};

export function recordRetryFailure(
    ledger: RetryLedger,
    commit: string,
    key: string | null,
    deploymentId: number | null,
    finishedAt: string,
    operationId: number | null = null,
) {
    let entry = ledger.commits.find((e) => e.commit === commit);
    if (!entry) {
        entry = {
            commit,
            attempts: 0,
            last_failure_at: null,
            stopped: false,
            evidence: [],
            reset_operation_id: 0,
            reset_after: 0,
            reset_at: null,
        };
        ledger.commits.push(entry);
    }
    if (
        (operationId !== null && operationId <= entry.reset_operation_id) ||
        (deploymentId !== null && deploymentId <= entry.reset_after) ||
        (deploymentId === null && entry.reset_at !== null &&
            Date.parse(finishedAt) <= Date.parse(entry.reset_at))
    ) return false;
    const existing = entry.evidence.find((e) =>
        (key !== null && e.key === key) ||
        (deploymentId !== null && e.deployment_id === deploymentId)
    );
    if (existing) {
        existing.key ??= key;
        existing.deployment_id ??= deploymentId;
        return true;
    }
    if (entry.stopped) return false;
    entry.evidence.push({
        key,
        deployment_id: deploymentId,
    });
    entry.attempts++;
    entry.stopped = entry.attempts === 3;
    if (
        entry.last_failure_at === null ||
        Date.parse(finishedAt) > Date.parse(entry.last_failure_at)
    ) entry.last_failure_at = finishedAt;
    return true;
}

/** Import each terminal row once; running and unpinned rows may settle later. */
export function observeRetryHistory(ledger: RetryLedger, history: Row[]) {
    const manual = Math.max(
        ledger.manual_after,
        0,
        ...history.filter((r) =>
            r.kind === "deploy" && !isAutomaticDeployment(r)
        ).map((r) => r.id),
    );
    if (manual > ledger.manual_after) {
        ledger.commits = [];
        ledger.manual_after = manual;
    }
    const fresh = history.filter((r) =>
        r.id > ledger.observed_through || ledger.unsettled.includes(r.id)
    ).sort((a, b) => a.id - b.id);
    for (const row of fresh) {
        if (row.id <= manual) continue;
        if (eligibleFailure(row)) {
            recordRetryFailure(
                ledger,
                row.commit!,
                null,
                row.id,
                row.finished_at ?? row.started_at,
            );
        } else if (
            row.kind === "deploy" && row.status === "succeeded" &&
            row.commit !== null
        ) {
            const entry = ledger.commits.find((e) => e.commit === row.commit);
            if (entry) {
                entry.attempts = 0;
                entry.last_failure_at = null;
                entry.stopped = false;
                entry.evidence = [];
                entry.reset_after = row.id;
                entry.reset_at = row.finished_at ?? row.started_at;
            }
        }
    }
    ledger.observed_through = Math.max(
        ledger.observed_through,
        0,
        ...history.map((r) => r.id),
    );
    ledger.unsettled = history.filter((r) =>
        r.id > manual && (r.status === "running" || r.commit === null)
    ).map((r) => r.id);
}
