import type { components } from "./schema";
import { api, get } from "./client";

/** An operation as `operation:show` returns it (docs/reference/operations.md). A list item has no steps. */
export type OperationStep = Required<components["schemas"]["OperationStep"]>;
export type Operation = Omit<Required<components["schemas"]["Operation"]>, "result"> & {
    result: Record<string, unknown> | null;
    steps?: OperationStep[];
};

export const TERMINAL: readonly string[] = ["succeeded", "failed", "cancelled"];

export const isTerminal = (operation: Pick<Operation, "status">): boolean =>
    TERMINAL.includes(operation.status);

/** Starts a deploy with the same request as `instance:deploy`; the Gateway answers 202 with the queued operation. */
export const startDeploy = (instanceId: number): Promise<Operation> =>
    api<Operation>("POST", `/api/v1/instances/${instanceId}/deploy`, {});

/** One read of an operation: every step, or with `afterSequence` only the steps that changed after it. */
export const readOperation = (id: number, afterSequence: number | null): Promise<Operation> =>
    get<Operation>(
        afterSequence === null
            ? `/api/v1/operations/${id}`
            : `/api/v1/operations/${id}?after_sequence=${afterSequence}`,
    );

/** The newest operations on one Instance, newest first. `operation.*` events on `orbit` refresh them. */
export const instanceOperationsQuery = (instanceId: number) =>
    ({
        queryKey: ["operations", "instance", instanceId],
        queryFn: () =>
            get<Operation[]>(`/api/v1/operations?target_type=instance&target_id=${instanceId}`),
    }) as const;

/**
 * Applies a read to what the page has. A read older than the page's sequence changes nothing, because the
 * database is the truth and a late answer may carry an older state. A newer read replaces the record and the
 * steps it returned, and keeps the steps it did not return.
 */
export function mergeOperation(current: Operation | null, read: Operation): Operation {
    if (current !== null && read.sequence < current.sequence) {
        return current;
    }

    const steps = new Map((current?.steps ?? []).map((step) => [step.name, step]));

    for (const step of read.steps ?? []) {
        steps.set(step.name, step);
    }

    return {
        ...read,
        steps: [...steps.values()].sort((left, right) => left.position - right.position),
    };
}

/** The step the operation runs, or the one that ended it. Its output tail is what the panel shows. */
export function focusStep(operation: Operation): OperationStep | null {
    const steps = operation.steps ?? [];

    return (
        steps.find((step) => step.status === "running") ??
        steps.find((step) => step.name === operation.failed_step) ??
        [...steps].reverse().find((step) => step.output !== null) ??
        null
    );
}
