import { useInfiniteQuery } from "@tanstack/react-query";
import { useEffect, useRef, useState } from "react";
import {
    dependencyResultsQuery,
    type DependencyFinding,
    type DependencyPackage,
    type DependencyReport,
} from "../api/dependencies";
import { Frame, Note } from "../ui/Frame";
import { Pane, type Column } from "../ui/Pane";

const packageColumns: Column<DependencyPackage>[] = [
    {
        header: "Package",
        width: 3,
        value: (p) => p.name,
        cell: (p) => <span className="whitespace-normal break-all">{p.name}</span>,
    },
    {
        header: "Version",
        width: 2,
        value: (p) => p.version,
        cell: (p) => <span className="whitespace-normal break-all">{p.version}</span>,
    },
    {
        header: "Relation",
        width: 1,
        value: (p) => (p.direct ? "Direct" : "Transitive"),
        hideOnMobile: true,
    },
    { header: "Origin", width: 1, value: (p) => p.origin, hideOnMobile: true },
    { header: "Scope", width: 1, value: (p) => p.scopes.join(", "), hideOnMobile: true },
];
const findingColumns: Column<DependencyFinding>[] = [
    {
        header: "Package",
        width: 3,
        value: (f) => f.package,
        cell: (f) => <span className="whitespace-normal break-words">{f.package}</span>,
    },
    {
        header: "Target",
        width: 2,
        value: (f) => f.target_version ?? "No verified target",
        cell: (f) => (
            <span className="whitespace-normal break-words">
                {f.target_version ?? "No verified target"}
            </span>
        ),
    },
    { header: "State", width: 1, value: (f) => f.state },
    {
        header: "Evidence",
        width: 4,
        value: (f) =>
            `${f.kind} · ${f.current_versions.join(", ")} · ${f.advisory_ids.join(", ")} · ${f.reason.text}`,
        hideOnMobile: true,
        cell: (f) => (
            <span className="whitespace-normal break-words">
                {f.kind} · {f.current_versions.join(", ")} · {f.advisory_ids.join(", ")} ·{" "}
                {f.reason.text}
                {f.links !== null && (
                    <>
                        {" "}
                        ·{" "}
                        <a href={`/tasks/${f.links.group_id}`} className="underline">
                            Group {f.links.group_id}
                        </a>{" "}
                        · Run {f.links.run_id}
                    </>
                )}
            </span>
        ),
    },
];

function provenance(report: DependencyReport, instanceId?: number): string {
    if (report.basis === "locked") return "Locked repository versions";
    if (report.observed_on === "task_worker") return "Worker installation observation";
    if (
        instanceId !== undefined &&
        report.observed_on === "instance" &&
        report.instance_id === instanceId
    )
        return "Installed versions on this Instance";
    return "Project observation · Instance installation unknown";
}

export function InstanceDependenciesPage({ instanceId }: { instanceId: number }) {
    return <Dependencies scope="instances" id={instanceId} />;
}
export function ProjectDependenciesPage({ projectId }: { projectId: number }) {
    return <Dependencies scope="projects" id={projectId} />;
}

function Dependencies({ scope, id }: { scope: "instances" | "projects"; id: number }) {
    const [source, setSource] = useState<string>();
    const [view, setView] = useState<"packages" | "findings">("packages");
    const query = useInfiniteQuery(dependencyResultsQuery(scope, id, source, view));
    const rootRef = useRef<HTMLDivElement>(null);
    useEffect(() => {
        if (!query.hasNextPage || query.isFetchingNextPage) return;
        const rows = rootRef.current?.querySelectorAll('[role="row"]');
        const last = rows?.item(rows.length - 1);
        if (last === null || last === undefined) return;
        const observer = new IntersectionObserver((entries) => {
            if (entries.some((entry) => entry.isIntersecting))
                void query.fetchNextPage({ cancelRefetch: false });
        });
        observer.observe(last);
        return () => observer.disconnect();
    }, [query.data, query.hasNextPage, query.isFetchingNextPage, query.fetchNextPage, view]);

    if (query.isPending)
        return (
            <Frame title="Dependencies">
                <Note>Loading Swamp dependencies…</Note>
            </Frame>
        );
    if (query.isError && query.data === undefined)
        return (
            <Frame title="Dependencies">
                <p role="alert" className="whitespace-normal text-red">
                    Could not load dependencies: {query.error.message}
                </p>
            </Frame>
        );
    const data = query.data?.pages[0];
    if (data === undefined) return null;
    const report = data.report;
    const pages = query.data.pages.filter(
        (p) => p.report?.publication_id === report?.publication_id,
    );
    const packages = pages.flatMap((p) => p.report?.packages ?? []);
    const findings = pages.flatMap((p) => p.report?.findings ?? []);
    const changed = pages.length !== query.data.pages.length;
    const pr = data.batch_pr;
    const selectedSource = data.sources.find((s) => s.id === (source ?? data.sources[0]?.id));
    const empty =
        data.availability === "unavailable"
            ? "Swamp dependency results are unavailable."
            : report === null
              ? "No successful dependency observation is available."
              : view === "packages"
                ? "No packages in this report."
                : "No findings in this report.";
    return (
        <div
            className="flex h-full min-h-0 min-w-0 flex-col gap-y-[4px]"
            data-testid="dependency-results"
            ref={rootRef}
        >
            <div className="flex min-w-0 items-center gap-[1ch]">
                <select
                    aria-label="Dependency source"
                    className="min-w-0 flex-1 bg-panel"
                    value={source ?? data.sources[0]?.id ?? ""}
                    onChange={(event) => setSource(event.target.value)}
                >
                    {data.sources.map((s) => (
                        <option key={s.id} value={s.id}>
                            {s.path} · {s.package_manager} · {s.freshness}
                        </option>
                    ))}
                    {data.sources.length === 0 && <option value="">No source evidence</option>}
                </select>
                {(["packages", "findings"] as const).map((tab) => (
                    <button
                        key={tab}
                        type="button"
                        aria-pressed={view === tab}
                        className={view === tab ? "text-bright" : "text-dim"}
                        onClick={() => setView(tab)}
                    >
                        {tab === "packages" ? "Versions" : "Findings"}
                    </button>
                ))}
            </div>
            <p
                className={`whitespace-normal break-words ${data.availability !== "available" || report?.freshness === "stale" ? "text-red" : "text-dim"}`}
            >
                Swamp: {data.availability}
                {data.last_attempt !== null && ` · ${data.last_attempt.status}`}
                {data.last_attempt?.error_code !== null &&
                    data.last_attempt?.error_code !== undefined &&
                    ` · ${data.last_attempt.error_code}`}
            </p>
            {selectedSource?.last_attempt.error_code && (
                <p className="text-red whitespace-normal break-words">
                    {selectedSource.last_attempt.error_code}
                </p>
            )}
            {report !== null && (
                <p className="whitespace-normal break-words text-dim" title={report.source_commit}>
                    {provenance(report, scope === "instances" ? id : undefined)} ·{" "}
                    {report.source_commit.slice(0, 12)} · {report.observed_at}
                    {report.freshness === "stale" && " · stale"}
                </p>
            )}
            <p className="whitespace-normal break-words text-dim" data-testid="dependency-batch-pr">
                Batch PR:{" "}
                {pr.status === "absent"
                    ? "none open (confirmed)"
                    : pr.status === "unknown"
                      ? "unknown"
                      : "open"}
                {pr.value !== null && (
                    <>
                        {" "}
                        ·{" "}
                        <a
                            className="text-bright underline"
                            href={pr.value.url}
                            target="_blank"
                            rel="noreferrer"
                        >
                            {pr.status === "unknown" ? "Last known " : ""}#{pr.value.number}
                        </a>
                    </>
                )}
                {pr.value !== null && (
                    <>
                        {" "}
                        ·{" "}
                        <a href={`/tasks/${pr.value.group_id}`} className="underline">
                            Group {pr.value.group_id}
                        </a>
                    </>
                )}
                {pr.status === "unknown" && pr.last_success_at !== null && (
                    <> · last verified {pr.last_success_at}</>
                )}
            </p>
            {changed && (
                <button className="text-red" onClick={() => void query.refetch()}>
                    Results changed. Refresh to continue.
                </button>
            )}
            {query.isFetchNextPageError && (
                <button className="text-red" onClick={() => void query.fetchNextPage()}>
                    Could not load more. Retry.
                </button>
            )}
            {view === "packages" ? (
                <Pane
                    name="dependency-versions"
                    order={1}
                    title="Versions"
                    testId="dependency-versions"
                    columns={packageColumns}
                    rows={packages}
                    rowId={(p) => p.id}
                    detail={(p) => (
                        <span>
                            {p.direct ? "Direct" : "Transitive"} · {p.scopes.join(", ")} ·{" "}
                            {p.origin}
                        </span>
                    )}
                    empty={empty}
                    topRight={
                        report === null ? "Unknown" : `${packages.length}/${report.package_count}`
                    }
                    className="dependency-rows min-h-[160px]! min-w-0 flex-1"
                />
            ) : (
                <Pane
                    name="dependency-findings"
                    order={1}
                    title="Findings"
                    testId="dependency-findings"
                    columns={findingColumns}
                    rows={findings}
                    rowId={(f) => f.id}
                    warn={(f) => f.state === "blocked"}
                    detail={(f) => (
                        <span className="whitespace-normal break-words">
                            {f.kind} · {f.current_versions.join(", ")} · {f.advisory_ids.join(", ")}{" "}
                            · {f.reason.text}
                            {f.links !== null && (
                                <>
                                    {" "}
                                    ·{" "}
                                    <a href={`/tasks/${f.links.group_id}`} className="underline">
                                        Group {f.links.group_id}
                                    </a>{" "}
                                    · Run {f.links.run_id}
                                </>
                            )}
                        </span>
                    )}
                    empty={empty}
                    topRight={
                        report === null ? "Unknown" : `${findings.length}/${report.finding_count}`
                    }
                    className="dependency-rows min-h-[160px]! min-w-0 flex-1"
                />
            )}
        </div>
    );
}
