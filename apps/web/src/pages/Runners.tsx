import { useQuery } from "@tanstack/react-query";
import { type CSSProperties, useMemo } from "react";
import {
    type CiJob,
    type CiNode,
    type CiNodeResources,
    type CiRunner,
    ciRunnersQuery,
} from "../api/ci";
import {
    activeJobs,
    formatGiB,
    formatSeconds,
    formatShare,
    jobResult,
    jobTone,
    type PlaceKind,
    placeCells,
    placesOverbooked,
    placesReading,
    recentJobs,
    type RunnerStatsRow,
    runnerStateText,
    runnerStatsRows,
    runnerTone,
    secondsSince,
    type Tone,
} from "../fleet/runners";
import { formatRelativeTime } from "../api/tasks";
import { Bar } from "../ui/Bar";
import { Frame, Note } from "../ui/Frame";
import { openInNewTab } from "../ui/newTab";
import { PageHeader } from "../ui/PageHeader";
import { type Column, Pane } from "../ui/Pane";

/** How many completed jobs the recent list shows. */
const RECENT_JOBS = 50;

const TONE: Record<Tone, string> = {
    green: "text-green",
    cyan: "text-cyan",
    yellow: "text-yellow",
    red: "text-red",
    dim: "text-dim",
};

const PLACE: Record<PlaceKind, { tone: string; label: string }> = {
    runner: { tone: "text-cyan", label: "runner job" },
    proof: { tone: "text-blue", label: "proof copy" },
    free: { tone: "text-line", label: "free" },
};

/** One cell per place in the colour of what holds it, then the reading. */
function PlacesBar({ node }: { node: CiNode }) {
    const reading = placesReading(node.places);

    return (
        <span className="flex min-w-0 items-center gap-[1ch] whitespace-nowrap">
            <span role="img" aria-label={`Places: ${reading}`} className="tracking-[0.2ch]">
                {placeCells(node.places).map((kind, index) => (
                    <span key={index} className={PLACE[kind].tone} title={PLACE[kind].label}>
                        ■
                    </span>
                ))}
            </span>
            <span
                className={placesOverbooked(node.places) ? "text-yellow" : "text-dim"}
                title={node.places.total === null ? "The Node has no ci.places count." : undefined}
            >
                {reading}
            </span>
        </span>
    );
}

function Line({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="row" style={{ gridTemplateColumns: "7ch minmax(0, 1fr)" }}>
            <span className="text-dim">{label}</span>
            {children}
        </div>
    );
}

const ratio = (used: number, total: number): number => (total > 0 ? used / total : 0);

/** What the frame's border says when a Node reading is missing or old. */
function staleness(node: CiNode): string | undefined {
    if (node.runner_state.error_code !== null) return "no runner state";
    if (node.runner_state.stale) return "runner state stale";
    if (node.resources?.possibly_outdated === true) return "metrics outdated";

    return undefined;
}

/** A runner's current job; a busy runner without one runs a job that the Gateway did not read. */
function currentJob(runner: CiRunner): string {
    const job = runner.current_job;

    if (job !== null) return `${job.repository} · ${job.workflow} · ${job.job}`;

    return runner.busy ? "a job not read from GitHub" : "—";
}

const RUNNER_COLUMNS = "max-content max-content max-content minmax(0, 1fr) max-content";

function RunnerRows({ node, now }: { node: CiNode; now: number }) {
    if (node.runners.length === 0) {
        return (
            <Note>
                {node.runner_state.error_code === null ? "No runners." : "No runner state."}
            </Note>
        );
    }

    return (
        <div
            role="table"
            aria-label={`${node.node_name} runners`}
            className="table-grid mt-[var(--lh)]"
            style={
                {
                    "--grid-cols-mobile": "max-content max-content minmax(0, 1fr)",
                    "--grid-cols-desktop": RUNNER_COLUMNS,
                } as CSSProperties
            }
        >
            <div className="row" role="row" data-head>
                <span role="columnheader">Runner</span>
                <span role="columnheader">State</span>
                <span role="columnheader">Version</span>
                <span role="columnheader" className="hidden md:block">
                    Current job
                </span>
                <span role="columnheader" className="hidden text-right md:block">
                    Since
                </span>
            </div>
            {node.runners.map((runner) => {
                const job = runner.current_job;
                const since = job === null ? "" : formatSeconds(secondsSince(job.started_at, now));
                // A phone has no room for the job beside the runner, so it reads on its own line.
                const detail =
                    job === null && !runner.busy ? null : `${currentJob(runner)} ${since}`;

                return (
                    <div
                        key={runner.name}
                        className={detail === null ? "row" : "row has-detail"}
                        role="row"
                        data-link={job?.url ? "" : undefined}
                        onClick={job?.url ? () => openInNewTab(job.url ?? "") : undefined}
                    >
                        <span role="cell">{runner.name}</span>
                        <span role="cell" className={TONE[runnerTone(runner)]}>
                            {runnerStateText(runner)}
                        </span>
                        <span role="cell" className="text-dim">
                            {runner.version ?? "—"}
                        </span>
                        <span role="cell" className="hidden md:block" title={currentJob(runner)}>
                            {currentJob(runner)}
                        </span>
                        <span role="cell" className="hidden text-right md:block">
                            {since}
                        </span>
                        {detail !== null && (
                            <span className="row-detail pl-[2ch] text-dim">{detail.trim()}</span>
                        )}
                    </div>
                );
            })}
        </div>
    );
}

/** CPU pressure, memory and disk, with their readings at one width so the meters end together. */
function Resources({ resources }: { resources: CiNodeResources }) {
    const usage = (used: number, total: number) => `${formatGiB(used)}/${formatGiB(total)} GiB`;
    const { memory, disk } = resources;
    const readings = [
        `${resources.cpu_pressure.toFixed(1)}% pressure`,
        usage(memory.used_bytes, memory.total_bytes),
        disk === null ? "" : usage(disk.used_bytes, disk.total_bytes),
    ];
    const width = Math.max(...readings.map((reading) => reading.length));
    const [cpu = "", mem = "", root = ""] = readings.map((reading) => reading.padStart(width));

    return (
        <>
            <Line label="CPU">
                <Bar ratio={resources.cpu_pressure / 100} reading={cpu} />
            </Line>
            <Line label="Mem">
                <Bar ratio={ratio(memory.used_bytes, memory.total_bytes)} reading={mem} />
            </Line>
            <Line label="Disk">
                {disk === null ? (
                    <span className="text-dim">—</span>
                ) : (
                    <Bar
                        ratio={ratio(disk.used_bytes, disk.total_bytes)}
                        reading={root}
                        thresholds={[80, 90]}
                    />
                )}
            </Line>
        </>
    );
}

/** One CI Node: its places, its CPU pressure, memory and disk, and its runners. */
function NodeFrame({ node, now }: { node: CiNode; now: number }) {
    const stale = staleness(node);
    const resources = node.resources;

    return (
        <Frame
            title={node.node_name}
            testId="runners-node"
            state={stale === undefined ? undefined : "warn"}
            topRight={
                stale === undefined ? undefined : <span className="text-yellow">{stale}</span>
            }
            className="w-full"
        >
            <Line label="Places">
                <PlacesBar node={node} />
            </Line>
            {resources === null ? (
                <Note>No metrics snapshot yet.</Note>
            ) : (
                <Resources resources={resources} />
            )}
            {node.proof_copies.length > 0 && (
                <Line label="Proof">
                    <span className="text-blue">
                        {node.proof_copies.map((copy) => copy.instance_name).join(", ")}
                    </span>
                </Line>
            )}
            <RunnerRows node={node} now={now} />
        </Frame>
    );
}

const toned = (job: CiJob) => <span className={TONE[jobTone(job)]}>{jobResult(job)}</span>;

const jobColumns = (now: number, recent: boolean): Column<CiJob>[] => [
    { header: "Repository", width: 18, value: (job) => job.repository, hideOnMobile: true },
    { header: "Workflow", width: 14, value: (job) => job.workflow, hideOnMobile: true },
    { header: "Job", width: 22, value: (job) => job.job },
    { header: "Runner", width: 14, fit: true, value: (job) => job.runner_name ?? "—" },
    { header: recent ? "Result" : "State", width: 12, fit: true, value: jobResult, cell: toned },
    recent
        ? {
              header: "Wait",
              width: 8,
              fit: true,
              align: "right",
              hideOnMobile: true,
              value: (job) => formatSeconds(job.queue_wait_seconds),
              sort: (job) => job.queue_wait_seconds ?? -1,
          }
        : {
              header: "Since",
              width: 8,
              fit: true,
              align: "right",
              value: (job) => formatSeconds(secondsSince(job.started_at ?? job.created_at, now)),
              sort: (job) => Date.parse(job.started_at ?? job.created_at),
          },
    ...(recent
        ? ([
              {
                  header: "Run",
                  width: 8,
                  fit: true,
                  align: "right",
                  value: (job) => formatSeconds(job.run_seconds),
                  sort: (job) => job.run_seconds ?? -1,
              },
              {
                  header: "Ended",
                  width: 9,
                  fit: true,
                  value: (job) => formatRelativeTime(job.completed_at, now) ?? "—",
                  sort: (job) => Date.parse(job.completed_at ?? ""),
              },
          ] satisfies Column<CiJob>[])
        : []),
];

const failedText = (row: RunnerStatsRow): string =>
    row.jobs === 0 ? "—" : `${row.failed} (${formatShare(row.failureShare)})`;

const STATS_COLUMNS: Column<RunnerStatsRow>[] = [
    { header: "Runner", width: 16, fit: true, value: (row) => row.runner },
    { header: "Node", width: 12, value: (row) => row.node, hideOnMobile: true },
    {
        header: "Jobs",
        width: 6,
        fit: true,
        align: "right",
        value: (row) => String(row.jobs),
        sort: (row) => row.jobs,
    },
    {
        header: "Failed",
        width: 8,
        fit: true,
        align: "right",
        value: failedText,
        cell: (row) => (
            <span className={row.failed > 0 ? "text-red" : undefined}>{failedText(row)}</span>
        ),
        sort: (row) => row.failureShare ?? -1,
    },
    {
        header: "Queue wait",
        width: 10,
        fit: true,
        align: "right",
        value: (row) => formatSeconds(row.queueWaitSeconds),
        sort: (row) => row.queueWaitSeconds ?? -1,
    },
    {
        header: "Run time",
        width: 10,
        fit: true,
        align: "right",
        value: (row) => formatSeconds(row.runSeconds),
        sort: (row) => row.runSeconds ?? -1,
    },
    {
        header: "Busy",
        width: 6,
        fit: true,
        value: (row) => formatShare(row.busyShare),
        sort: (row) => row.busyShare,
        hideOnMobile: true,
    },
];

const openJob = (job: CiJob): void => {
    if (job.url !== null) openInNewTab(job.url);
};

/**
 * The runner view (docs/reference/ci-runner-view.md): one frame per CI Node with its places, resources
 * and runners, then the running and queued jobs, each runner's stats over the window and the recent jobs.
 */
export function RunnersPage() {
    const query = useQuery(ciRunnersQuery);
    const view = query.data;
    const now = view === undefined ? Date.now() : Date.parse(view.generated_at);
    const active = useMemo(() => activeJobs(view?.jobs ?? []), [view]);
    const recent = useMemo(() => recentJobs(view?.jobs ?? [], RECENT_JOBS), [view]);
    const stats = useMemo(() => (view === undefined ? [] : runnerStatsRows(view)), [view]);
    const span = `last ${view?.window_hours ?? 48} h`;
    const github = view?.github_error_code ?? null;
    const unread = (view?.repositories ?? []).filter(
        (repository) => repository.error_code !== null,
    );
    const truncated = (view?.repositories ?? []).filter((repository) => repository.truncated);
    const jobsNote =
        github !== null
            ? `No jobs read: ${github}`
            : unread.length > 0
              ? `Not read: ${unread.map((repository) => repository.repository).join(", ")}`
              : truncated.length > 0
                ? `Partly read: ${truncated.map((repository) => repository.repository).join(", ")}`
                : undefined;
    const jobsLabel =
        jobsNote === undefined ? undefined : <span className="text-yellow">{jobsNote}</span>;

    if (query.error !== null && view === undefined) {
        return (
            <Frame title="Runners" state="warn">
                <Note>{query.error.message}</Note>
            </Frame>
        );
    }

    return (
        <div data-testid="runners-page" className="flex flex-col gap-y-[var(--panel-gap)]">
            <PageHeader trail={[{ label: "Runners" }]} titleTestId="runners-title" />
            {view === undefined ? (
                <Frame title="CI Nodes">
                    <Note>Loading runners…</Note>
                </Frame>
            ) : view.nodes.length === 0 ? (
                <Frame title="CI Nodes">
                    <Note>No Node has an active ci-runner role.</Note>
                </Frame>
            ) : (
                <div className="grid grid-cols-1 gap-[var(--panel-gap)] xl:grid-cols-2">
                    {view.nodes.map((node) => (
                        <NodeFrame key={node.node_id} node={node} now={now} />
                    ))}
                </div>
            )}
            <div className="flex flex-wrap gap-x-[2ch] px-[1ch] text-dim" aria-hidden="true">
                {(Object.keys(PLACE) as PlaceKind[]).map((kind) => (
                    <span key={kind}>
                        <span className={PLACE[kind].tone}>■</span> {PLACE[kind].label}
                    </span>
                ))}
            </div>
            <Pane
                name="ci-active"
                testId="runners-active"
                order={0}
                title="Running and queued"
                className="w-full max-h-[40vh]"
                columns={jobColumns(now, false)}
                rows={active}
                rowId={(job) => String(job.job_id)}
                onRowClick={openJob}
                bottomLeft={jobsLabel}
                empty={view === undefined ? "Loading…" : "No running or queued jobs."}
            />
            <Pane
                name="ci-stats"
                testId="runners-stats"
                order={1}
                title={`Runners, ${span}`}
                className="w-full max-h-[40vh]"
                columns={STATS_COLUMNS}
                rows={stats}
                rowId={(row) => row.id}
                bottomLeft={jobsLabel}
                empty={view === undefined ? "Loading…" : "No runners."}
            />
            <Pane
                name="ci-recent"
                testId="runners-recent"
                order={2}
                title={`Recent jobs, ${span}`}
                className="w-full max-h-[50vh]"
                columns={jobColumns(now, true)}
                rows={recent}
                rowId={(job) => String(job.job_id)}
                onRowClick={openJob}
                bottomLeft={jobsLabel}
                empty={view === undefined ? "Loading…" : "No completed jobs."}
            />
        </div>
    );
}
