import { useQuery } from "@tanstack/react-query";
import { taskSwampRunsQuery } from "../api/swamp";
import { Frame } from "../ui/Frame";

export function SwampRuns({ groupId }: { groupId: number }) {
    const query = useQuery(taskSwampRunsQuery(groupId));
    const snapshot = query.data;
    const runs = snapshot?.runs ?? [];
    const unavailable = query.isError || snapshot?.availability === "unavailable";
    const stale = snapshot?.availability === "stale" || (query.isError && snapshot !== undefined);
    return (
        <Frame
            title="Swamp"
            testId="task-swamp-runs"
            className="min-w-0 shrink-0"
            bodyClassName="max-h-[480px] space-y-[8px]"
            state={unavailable || stale ? "warn" : undefined}
        >
            {query.isPending && <p role="status">Loading workflow runs…</p>}
            {(unavailable || stale) && (
                <p role="status" className="text-warn">
                    {stale
                        ? "Swamp is unavailable. Showing the last successful read."
                        : "Swamp output is unavailable."}
                </p>
            )}
            {snapshot?.observed_at && (
                <p className="text-dim">
                    Last read:{" "}
                    <time dateTime={snapshot.observed_at}>
                        {new Date(snapshot.observed_at).toLocaleString()}
                    </time>
                </p>
            )}
            {!query.isPending && !unavailable && !stale && runs.length === 0 && (
                <p className="text-dim">No Swamp runs for this task group.</p>
            )}
            {snapshot?.truncated && <p className="text-dim">Showing the five latest runs.</p>}
            {runs.map((run, index) => (
                <details key={run.id} open={index === 0} className="min-w-0">
                    <summary className="cursor-pointer break-words [overflow-wrap:anywhere]">
                        {run.workflow} · {run.status} <span className="text-dim">{run.id}</span>
                    </summary>
                    <ol className="mt-[8px] space-y-[8px]">
                        {(run.steps ?? []).map((step) => (
                            <li key={`${step.job}/${step.name}`} className="min-w-0">
                                <p className="break-words [overflow-wrap:anywhere]">
                                    {step.job} / {step.name} · {step.status}
                                </p>
                                {step.output ? (
                                    <details>
                                        <summary className="cursor-pointer text-cyan">
                                            Step output
                                        </summary>
                                        <pre className="selectable max-h-[240px] overflow-auto whitespace-pre-wrap break-words [overflow-wrap:anywhere]">
                                            {step.output}
                                        </pre>
                                    </details>
                                ) : step.status === "running" ? (
                                    <p className="text-dim">
                                        Waiting for output. Worker output may arrive when this step
                                        finishes.
                                    </p>
                                ) : null}
                                {step.truncated && (
                                    <p className="text-dim">Step output truncated.</p>
                                )}
                            </li>
                        ))}
                    </ol>
                    {run.steps_truncated && (
                        <p className="text-dim">Showing the first 100 steps.</p>
                    )}
                    {run.log && (
                        <details className="mt-[8px]">
                            <summary className="cursor-pointer text-cyan">Run log</summary>
                            <pre className="selectable max-h-[240px] overflow-auto whitespace-pre-wrap break-words [overflow-wrap:anywhere]">
                                {run.log}
                            </pre>
                        </details>
                    )}
                    {run.log_truncated && (
                        <p className="text-dim">Run log truncated to its latest output.</p>
                    )}
                </details>
            ))}
        </Frame>
    );
}
