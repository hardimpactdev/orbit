import { useQuery } from "@tanstack/react-query";
import { useMemo, useState } from "react";
import { instanceQueueQuery } from "../api/queries";
import type { Instance, QueueJob, QueueState } from "../api/types";
import { type Column, Pane } from "../ui/Pane";
import { Properties } from "../ui/Properties";

const STATES: QueueState[] = ["pending", "failed"];

/** The class without its namespace. */
const shortName = (name: string): string => name.slice(name.lastIndexOf("\\") + 1);
const clock = (time: string | null): string =>
    time === null ? "—" : new Date(time).toLocaleTimeString([], { hour12: false });

/**
 * The instance's queue: a tab per job state, the newest jobs in it, and the load of each queue in the
 * border. An instance without a readable queue store has no panel.
 */
export function QueuePanel({ instance }: { instance: Instance }) {
    const [state, setState] = useState<QueueState>("pending");
    const queue = useQuery(instanceQueueQuery(instance.id, state)).data;

    const columns = useMemo<Column<QueueJob>[]>(
        () => [
            { header: "Job", width: 30, value: (job) => shortName(job.name) },
            { header: "Queue", width: 12, fit: true, value: (job) => job.queue },
            ...(state === "failed"
                ? ([
                      { header: "Exception", width: 46, value: (job) => job.exception ?? "—" },
                  ] satisfies Column<QueueJob>[])
                : []),
            {
                header: state === "pending" ? "Pushed" : "Failed",
                width: 12,
                fit: true,
                value: (job) => clock(state === "pending" ? job.pushed_at : job.failed_at),
            },
        ],
        [state],
    );

    if (queue === undefined || !queue.available) {
        return null;
    }

    const tabs = (
        <span role="tablist" onMouseDown={(event) => event.stopPropagation()}>
            {STATES.map((name) => (
                <span
                    key={name}
                    role="tab"
                    aria-selected={name === state}
                    className={`ml-[2ch] cursor-pointer ${name === state ? "text-fg" : "hover:text-fg"} ${name === "failed" && (queue.totals?.failed ?? 0) > 0 ? "text-red" : ""}`}
                    onClick={() => setState(name)}
                >
                    {name} {queue.totals?.[name] ?? "—"}
                </span>
            ))}
        </span>
    );

    return (
        <div className="grid max-h-[30vh] grid-cols-[minmax(0,1fr)_minmax(0,2fr)] gap-x-[1ch]">
            <Properties
                title="Queue"
                properties={[
                    { name: "Pending", value: queue.totals?.pending },
                    {
                        name: "Failed",
                        value: queue.totals?.failed ?? null,
                        warn: (queue.totals?.failed ?? 0) > 0,
                    },
                    ...(queue.queues ?? []).map((entry) => ({
                        name: `Queue ${entry.name}`,
                        // Waiting and delayed jobs on this queue.
                        value: `${entry.length} jobs`,
                    })),
                ]}
            />
            <Pane
                name="queue"
                order={3}
                title="Jobs"
                topRight={tabs}
                columns={columns}
                rows={queue.jobs ?? []}
                rowId={(job) => job.id}
                warn={() => state === "failed"}
                empty={`No ${state} jobs.`}
            />
        </div>
    );
}
