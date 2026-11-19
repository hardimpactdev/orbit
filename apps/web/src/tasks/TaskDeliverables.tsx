import type { Task, TaskDeliverable } from "../api/tasks";
import { Frame } from "../ui/Frame";

const metaClassName = "text-[11px] font-medium uppercase tracking-[0.08em]";

/** What Orbit checks for one deliverable, as the turn prompt names it. */
function deliverableDetail(deliverable: TaskDeliverable): string {
    if (deliverable.type === "file")
        return `${deliverable.path ?? ""}, ${deliverable.change ?? ""}`;
    if (deliverable.type === "command")
        return `${deliverable.command ?? ""} in ${deliverable.directory || "."}`;
    return "Confirmed by the reviewer";
}

/** The deliverables of a task with no subtask, each with what Orbit checks for it. */
export function TaskDeliverables({ work }: { work: Task }) {
    const deliverables = work.deliverables as unknown as TaskDeliverable[];
    return (
        <Frame
            title="Deliverables"
            testId="task-deliverables"
            topRight={deliverables.length}
            className="shrink-0"
            bodyClassName="space-y-[var(--panel-padding)]"
        >
            {deliverables.length === 0 && <p className="text-dim">No deliverables yet.</p>}
            {deliverables.map((deliverable) => (
                <article key={deliverable.id} aria-label={deliverable.id} className="kanban-card">
                    <div
                        className={`mb-[6px] flex justify-between gap-[1ch] text-dim ${metaClassName}`}
                    >
                        <span className="min-w-0 break-words">{deliverable.id}</span>
                        <span className="shrink-0">{deliverable.type}</span>
                    </div>
                    <p className="selectable whitespace-pre-wrap break-words">
                        {deliverable.description}
                    </p>
                    <p className="selectable mt-[6px] break-words text-dim [overflow-wrap:anywhere]">
                        {deliverableDetail(deliverable)}
                    </p>
                </article>
            ))}
        </Frame>
    );
}
