// Gateway HTTP and stage-job types that dependency-merge admission still reads.

export type Stage =
    | "provision"
    | "baseline"
    | "implement"
    | "handoff"
    | "review"
    | "publish"
    | "merged"
    | "script"
    | "repair"
    | "finish";

export interface HttpResponse {
    status: number;
    body: Record<string, unknown> | null;
}
export type Http = (
    method: "GET" | "POST" | "PATCH",
    path: string,
    body?: Record<string, unknown>,
    idempotencyKey?: string,
) => Promise<HttpResponse>;

export interface PlannedRequest {
    path: string;
    body: Record<string, unknown>;
    idempotencyKey: string;
}

export interface Job {
    key: string;
    workItem: string;
    stage: Stage;
    group: number;
    subtask: number | null;
    state: "running" | "succeeded" | "failed";
    launches: number;
    launchedNow: boolean;
    request: PlannedRequest | null;
    operationId: number | null;
    operationStatus: string | null;
    lastError: string | null;
    inputIdentity: string | null;
    nextReadAt: string | null;
    artifacts: Record<string, Record<string, unknown>>;
    evidence: Record<string, unknown> | null;
    invalidated?: boolean;
}
