import type { components } from "./schema";
import { api, get } from "./client";

/** The Operation summary; started and listed records may omit observed steps. */
export type Operation = Omit<Required<components["schemas"]["Operation"]>, "result" | "steps"> & {
    result: Record<string, unknown> | null;
    steps?: components["schemas"]["Operation"]["steps"];
};

/** One operation:list row: the record without input, result and steps. */
export type OperationListItem = Required<components["schemas"]["OperationListItem"]>;

export const TERMINAL: readonly string[] = ["succeeded", "failed", "cancelled", "interrupted"];

export const isTerminal = (operation: Pick<Operation, "status">): boolean =>
    TERMINAL.includes(operation.status);

/** Starts a deploy with the same request as `instance:deploy`; the Gateway answers 202 with the queued operation. */
export const startDeploy = (instanceId: number): Promise<Operation> =>
    api<Operation>("POST", `/api/v1/instances/${instanceId}/deploy`, {});

/** Reads the current parent Operation. */
export const readOperation = (id: number): Promise<Operation> =>
    get<Operation>(`/api/v1/operations/${id}`);

/**
 * Follows a started Operation with `operation:show` until it ends, as the CLI does. Each read waits the
 * `poll_after_seconds` the previous record named; a record without one waits two seconds.
 */
export async function awaitOperation(started: Operation): Promise<Operation> {
    let operation = started;

    while (!isTerminal(operation)) {
        const seconds = operation.poll_after_seconds ?? 2;
        await new Promise((resolve) => setTimeout(resolve, seconds * 1000));
        operation = mergeOperation(operation, await readOperation(operation.id));
    }

    return operation;
}

/** The newest operations on one Instance, newest first. `operation.*` events on `orbit` refresh them. */
export const instanceOperationsQuery = (instanceId: number) =>
    ({
        queryKey: ["operations", "instance", instanceId],
        queryFn: () =>
            get<OperationListItem[]>(
                `/api/v1/operations?target_type=instance&target_id=${instanceId}`,
            ),
    }) as const;

/** Keeps the newest parent record when reads arrive out of order. */
export function mergeOperation(current: Operation | null, read: Operation): Operation {
    return current !== null && read.sequence < current.sequence ? current : read;
}
