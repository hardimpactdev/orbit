import type { components } from "./schema";
import { api, get } from "./client";

/** The parent intent and native result returned by `operation:show`. */
export type Operation = Omit<Required<components["schemas"]["Operation"]>, "result"> & {
    result: Record<string, unknown> | null;
};

export const TERMINAL: readonly string[] = ["succeeded", "failed", "cancelled"];

export const isTerminal = (operation: Pick<Operation, "status">): boolean =>
    TERMINAL.includes(operation.status);

/** Starts a deploy with the same request as `instance:deploy`; the Gateway answers 202 with the queued operation. */
export const startDeploy = (instanceId: number): Promise<Operation> =>
    api<Operation>("POST", `/api/v1/instances/${instanceId}/deploy`, {});

/** Reads the current parent Operation. */
export const readOperation = (id: number): Promise<Operation> =>
    get<Operation>(`/api/v1/operations/${id}`);

/** The newest operations on one Instance, newest first. `operation.*` events on `orbit` refresh them. */
export const instanceOperationsQuery = (instanceId: number) =>
    ({
        queryKey: ["operations", "instance", instanceId],
        queryFn: () =>
            get<Operation[]>(`/api/v1/operations?target_type=instance&target_id=${instanceId}`),
    }) as const;

/** Keeps the newest parent record when reads arrive out of order. */
export function mergeOperation(current: Operation | null, read: Operation): Operation {
    return current !== null && read.sequence < current.sequence ? current : read;
}
