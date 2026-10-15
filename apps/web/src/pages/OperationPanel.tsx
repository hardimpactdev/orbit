import { useQuery } from "@tanstack/react-query";
import { useEffect, useState } from "react";
import {
    focusStep,
    instanceOperationsQuery,
    isTerminal,
    type Operation,
    type OperationStep,
} from "../api/operations";
import type { Instance } from "../api/types";
import { useFallbackPoll } from "../realtime/liveness";
import { useOperation } from "../realtime/operation";
import { LogPane } from "../ui/LogPane";
import { type Column, Pane } from "../ui/Pane";

const stepColumns: Column<OperationStep>[] = [
    { header: "Step", width: 34, value: (step) => step.name },
    { header: "Status", width: 12, fit: true, value: (step) => step.status },
    { header: "Error", width: 30, value: (step) => step.error_code ?? "—" },
];

const headline = (operation: Operation): string =>
    operation.failed_step === null
        ? `Operation ${operation.id} · ${operation.status}`
        : `Operation ${operation.id} · ${operation.status} at ${operation.failed_step}`;

/**
 * The newest deploy of the Instance and its steps, followed live over the operation's own channel
 * (docs/reference/web-app.md). It shows while a deploy runs, also one that another client started, and keeps
 * showing the result of a deploy this page followed. The steps and the output tail come from the Gateway's record.
 */
export function OperationPanel({ instance }: { instance: Instance }) {
    const operations = useQuery({
        ...instanceOperationsQuery(instance.id),
        refetchInterval: useFallbackPoll(),
    });
    const newest = operations.data?.[0] ?? null;
    // Follow a deploy once it is seen running, and keep its result on the page after it ends.
    const [tracked, setTracked] = useState<number | null>(null);
    useEffect(() => {
        if (newest !== null && !isTerminal(newest)) {
            setTracked(newest.id);
        }
    }, [newest]);
    const operation = useOperation(tracked);

    if (operation === null) {
        return null;
    }

    const step = focusStep(operation);
    const lines =
        step?.output === null || step === null ? [] : step.output.replace(/\n$/, "").split("\n");

    return (
        <div
            data-testid="operation-panel"
            className="w-full min-w-0 max-w-full flex flex-col md:grid md:max-h-[30vh] md:grid-cols-2 gap-x-[1ch]"
        >
            <Pane
                name="operation"
                order={0.5}
                title="Deploy"
                testId="operation-steps"
                className="w-full min-h-[120px] max-h-[30vh] md:max-h-none"
                columns={stepColumns}
                rows={operation.steps ?? []}
                rowId={(row) => row.name}
                warn={(row) => row.status === "failed"}
                topRight={headline(operation)}
                empty={operation.status === "queued" ? "Waiting for a worker." : "No steps yet."}
            />
            <LogPane
                title={step === null ? "Output" : `Output of ${step.name}`}
                testId="operation-output"
                className="min-h-[120px]"
                lines={lines}
                loading={false}
                live={!isTerminal(operation)}
            />
        </div>
    );
}
