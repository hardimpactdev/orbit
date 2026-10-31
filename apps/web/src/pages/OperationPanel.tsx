import { useQuery } from "@tanstack/react-query";
import { useEffect, useState } from "react";
import {
    instanceOperationsQuery,
    isTerminal,
    type Operation,
} from "../api/operations";
import type { Instance } from "../api/types";
import { useFallbackPoll } from "../realtime/liveness";
import { useOperation } from "../realtime/operation";
import { type Column, Pane } from "../ui/Pane";

const columns: Column<Operation>[] = [
    { header: "Operation", width: 12, value: (record) => String(record.id) },
    { header: "Status", width: 12, fit: true, value: (record) => record.status },
    { header: "Error", width: 30, value: (record) => record.error_code ?? "—" },
];

/** The pane title for each operation kind that runs on an Instance. */
const TITLES: Record<string, string> = {
    "instance.deploy": "Deploy",
    "instance.rollback": "Rollback",
};

/** Follows the newest parent operation and displays its native result status. */
export function OperationPanel({ instance }: { instance: Instance }) {
    const operations = useQuery({
        ...instanceOperationsQuery(instance.id),
        refetchInterval: useFallbackPoll(),
    });
    const newest = operations.data?.[0] ?? null;
    // Follow an operation once it is seen running, and keep its result on the page after it ends.
    const [tracked, setTracked] = useState<number | null>(null);
    useEffect(() => {
        if (newest !== null && !isTerminal(newest)) {
            setTracked(newest.id);
        }
    }, [newest]);
    const { operation } = useOperation(tracked);

    if (operation === null) {
        return null;
    }

    return (
        <div
            data-testid="operation-panel"
            className="w-full min-w-0 max-w-full flex flex-col md:grid md:max-h-[30vh] md:grid-cols-2 gap-x-[1ch]"
        >
            <Pane
                name="operation"
                order={0.5}
                title={TITLES[operation.kind] ?? "Operation"}
                testId="operation-record"
                className="w-full min-h-[120px] max-h-[30vh] md:max-h-none"
                columns={columns}
                rows={[operation]}
                rowId={(row) => String(row.id)}
                warn={(row) => row.status === "failed"}
                topRight={[
                    `Operation ${operation.id} · ${operation.status}`,
                    operation.triggered_by,
                    operation.caller_node_name ??
                        (operation.caller_node_id == null
                            ? null
                            : `Removed Node (${operation.caller_node_id})`),
                ]
                    .filter(Boolean)
                    .join(" · ")}
                empty="No operation."
            />
        </div>
    );
}
