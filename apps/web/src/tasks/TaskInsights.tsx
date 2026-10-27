import { useQuery } from "@tanstack/react-query";
import { useState, type ReactNode } from "react";
import {
    taskInsightsQuery,
    type InsightsDimension,
    type InsightsPeriod,
    type InsightsSummary,
} from "../api/insights";
import { lists } from "../api/queries";
import { Frame } from "../ui/Frame";
import { DailyOutcomes, WeeklyTrend } from "./InsightsCharts";
import {
    change,
    formatCauses,
    formatCount,
    formatRatio,
    formatShare,
    formatSpan,
    formatUsd,
    type Better,
} from "./insights-format";

/** The browser remembers whether the strip is open. */
export const INSIGHTS_EXPANDED_KEY = "orbit.tasks.insights.expanded";

function useExpanded(): [boolean, (open: boolean) => void] {
    const [open, setOpen] = useState(() => {
        try {
            return window.localStorage.getItem(INSIGHTS_EXPANDED_KEY) === "1";
        } catch {
            return false;
        }
    });

    return [
        open,
        (next) => {
            setOpen(next);
            try {
                window.localStorage.setItem(INSIGHTS_EXPANDED_KEY, next ? "1" : "0");
            } catch {
                // A browser without storage keeps the choice for this page only.
            }
        },
    ];
}

const toneClass = { good: "text-green", bad: "text-red", flat: "text-dim" } as const;

function Tile({
    label,
    value,
    detail,
    current,
    previous,
    better,
    share = false,
}: {
    label: string;
    value: string;
    detail?: string;
    current: number | null;
    previous: number | null;
    better: Better;
    share?: boolean;
}) {
    const delta = change(current, previous, better, share);

    return (
        <div data-testid="insights-tile" className="min-w-0">
            <div className="truncate text-dim">{label}</div>
            <div className="flex flex-wrap items-baseline gap-x-[1ch]">
                <span className="font-bold">{value}</span>
                {delta !== null && <span className={toneClass[delta.tone]}>{delta.label}</span>}
                {detail !== undefined && <span className="text-dim">{detail}</span>}
            </div>
        </div>
    );
}

function Toggle({
    pressed,
    onClick,
    children,
    testId,
}: {
    pressed: boolean;
    onClick: () => void;
    children: ReactNode;
    testId?: string;
}) {
    return (
        <button
            type="button"
            aria-pressed={pressed}
            data-testid={testId}
            className={pressed ? "text-cyan" : "text-dim hover:text-fg"}
            onClick={onClick}
        >
            {children}
        </button>
    );
}

/** Queue, work, and merge wait as one bar, each part as wide as its median. */
function LeadTimeBar({ summary }: { summary: InsightsSummary }) {
    const parts = [
        { label: "queue", ms: summary.queue_ms, color: "var(--dim)" },
        { label: "work", ms: summary.work_ms, color: "var(--cyan)" },
        { label: "merge wait", ms: summary.merge_wait_ms, color: "var(--yellow)" },
    ];
    const total = parts.reduce((sum, part) => sum + (part.ms ?? 0), 0);

    return (
        <div data-testid="insights-lead-time">
            <div className="text-dim">
                Lead time p50 {formatSpan(summary.lead_time_ms)}
                {summary.excluded > 0 && ` · ${summary.excluded} merged with no lead time`}
            </div>
            {total > 0 && (
                <div className="my-[4px] flex h-[8px] w-full overflow-hidden rounded-[2px]">
                    {parts.map((part) => (
                        <span
                            key={part.label}
                            style={{ flexGrow: part.ms ?? 0, background: part.color }}
                        />
                    ))}
                </div>
            )}
            <div className="flex flex-wrap gap-x-[2ch]">
                {parts.map((part) => (
                    <span key={part.label}>
                        <span style={{ color: part.color }}>■</span> {part.label}{" "}
                        {formatSpan(part.ms)}
                    </span>
                ))}
            </div>
        </div>
    );
}

function Diagnostic({ label, value, detail }: { label: string; value: string; detail?: string }) {
    return (
        <div className="min-w-0">
            <div className="truncate text-dim">{label}</div>
            <div>{value}</div>
            {detail !== undefined && <div className="text-dim">{detail}</div>}
        </div>
    );
}

const dimensions: { key: InsightsDimension; label: string }[] = [
    { key: "model", label: "Model" },
    { key: "project", label: "Project" },
    { key: "release", label: "Release" },
];

/** The KPI strip above the task board ([Task insights](/reference/tasks#task-insights)). */
export function TaskInsightsStrip() {
    const [period, setPeriod] = useState<InsightsPeriod>("30d");
    const [projectId, setProjectId] = useState<number | null>(null);
    const [dimension, setDimension] = useState<InsightsDimension>("model");
    const [expanded, setExpanded] = useExpanded();
    const insights = useQuery(taskInsightsQuery(period, projectId));
    const projects = useQuery(lists.projects);
    const data = insights.data;
    const now = data?.current;
    const before = data?.previous;

    return (
        <Frame
            title="Insights"
            testId="task-insights"
            topRight={
                <span className="flex gap-x-[1ch]">
                    {(["30d", "7d"] as const).map((option) => (
                        <Toggle
                            key={option}
                            pressed={period === option}
                            onClick={() => setPeriod(option)}
                        >
                            {option.toUpperCase()}
                        </Toggle>
                    ))}
                </span>
            }
            bottomRight={
                <Toggle
                    pressed={expanded}
                    onClick={() => setExpanded(!expanded)}
                    testId="task-insights-toggle"
                >
                    {expanded ? "less ▴" : "more ▾"}
                </Toggle>
            }
        >
            <div className="mb-[6px] flex flex-wrap items-center gap-x-[2ch] text-dim">
                <span>
                    {now === undefined ? "" : `${now.tasks} tasks · ${now.merged} merged · `}vs the
                    previous {period === "7d" ? "7 days" : "30 days"}
                </span>
                <label className="flex items-center gap-x-[1ch]">
                    Project
                    <select
                        aria-label="Project"
                        className="bg-bg text-fg"
                        value={projectId ?? ""}
                        onChange={(event) =>
                            setProjectId(
                                event.target.value === "" ? null : Number(event.target.value),
                            )
                        }
                    >
                        <option value="">All</option>
                        {(projects.data ?? []).map((project) => (
                            <option key={project.id} value={project.id}>
                                {project.name}
                            </option>
                        ))}
                    </select>
                </label>
            </div>
            {insights.isPending && <p role="status">Loading insights…</p>}
            {insights.error && (
                <p className="text-red">Insights could not load: {insights.error.message}</p>
            )}
            {now !== undefined && before !== undefined && (
                <>
                    <div className="grid grid-cols-2 gap-x-[2ch] gap-y-[8px] md:grid-cols-5">
                        <Tile
                            label="Tokens / merged PR"
                            value={formatCount(now.tokens_per_merged_pr)}
                            detail={formatUsd(now.cost_per_merged_pr)}
                            current={now.tokens_per_merged_pr}
                            previous={before.tokens_per_merged_pr}
                            better="lower"
                        />
                        <Tile
                            label="Lead time p50"
                            value={formatSpan(now.lead_time_ms)}
                            current={now.lead_time_ms}
                            previous={before.lead_time_ms}
                            better="lower"
                        />
                        <Tile
                            label="Yield"
                            value={formatShare(now.yield)}
                            current={now.yield}
                            previous={before.yield}
                            better="higher"
                            share
                        />
                        <Tile
                            label="PR first pass"
                            value={formatShare(now.pr_first_pass)}
                            current={now.pr_first_pass}
                            previous={before.pr_first_pass}
                            better="higher"
                            share
                        />
                        <Tile
                            label="Wasted tokens"
                            value={formatShare(now.wasted_share)}
                            current={now.wasted_share}
                            previous={before.wasted_share}
                            better="lower"
                            share
                        />
                    </div>
                    {expanded && data !== undefined && (
                        <div data-testid="insights-details" className="mt-[12px] space-y-[12px]">
                            <div className="grid grid-cols-1 gap-[16px] lg:grid-cols-2">
                                <WeeklyTrend weeks={data.weekly} releases={data.releases} />
                                <DailyOutcomes days={data.daily} />
                            </div>
                            <LeadTimeBar summary={now} />
                            <div className="grid grid-cols-2 gap-x-[2ch] gap-y-[8px] md:grid-cols-6">
                                <Diagnostic
                                    label="Subtask first pass"
                                    value={formatShare(now.subtask_first_pass)}
                                />
                                <Diagnostic
                                    label="Review rounds"
                                    value={formatRatio(now.review_rounds)}
                                />
                                <Diagnostic
                                    label="Handoff 1st try"
                                    value={formatShare(now.handoff_first_try)}
                                />
                                <Diagnostic
                                    label="Questions / task"
                                    value={formatRatio(now.questions_per_task)}
                                    detail={formatCauses(now.questions_by_cause)}
                                />
                                <Diagnostic label="Cache hit" value={formatShare(now.cache_hit)} />
                                <Diagnostic
                                    label="Tokens / 100 lines"
                                    value={formatCount(now.tokens_per_hundred_lines)}
                                />
                            </div>
                            <div data-testid="insights-breakdown">
                                <div className="mb-[4px] flex gap-x-[2ch]">
                                    <span className="text-dim">By</span>
                                    {dimensions.map((option) => (
                                        <Toggle
                                            key={option.key}
                                            pressed={dimension === option.key}
                                            onClick={() => setDimension(option.key)}
                                        >
                                            {option.label}
                                        </Toggle>
                                    ))}
                                </div>
                                <div className="overflow-x-auto">
                                    <table className="w-full min-w-[480px] text-left">
                                        <thead className="text-dim">
                                            <tr>
                                                <th className="font-normal">
                                                    {
                                                        dimensions.find(
                                                            (option) => option.key === dimension,
                                                        )?.label
                                                    }
                                                </th>
                                                <th className="font-normal">Tasks</th>
                                                <th className="font-normal">Merged</th>
                                                <th className="font-normal">Tokens / PR</th>
                                                <th className="font-normal">Lead time</th>
                                                <th className="font-normal">Yield</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {data.breakdowns[dimension].map((row) => (
                                                <tr key={row.key}>
                                                    <td className="truncate">{row.label}</td>
                                                    <td>{row.tasks}</td>
                                                    <td>{row.merged}</td>
                                                    <td>{formatCount(row.tokens_per_merged_pr)}</td>
                                                    <td>{formatSpan(row.lead_time_ms)}</td>
                                                    <td>{formatShare(row.yield)}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    )}
                </>
            )}
        </Frame>
    );
}
