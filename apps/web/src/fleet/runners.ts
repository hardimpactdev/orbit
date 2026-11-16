import type { CiJob, CiPlaces, CiRunner, CiRunners } from "../api/ci";

/** What one place of a CI Node holds. */
export type PlaceKind = "runner" | "proof" | "free";

/** A colour of the terminal palette. */
export type Tone = "green" | "cyan" | "yellow" | "red" | "dim";

/**
 * The places bar: one cell per place, runner jobs first, then proof copies, then the free places.
 * A Node without a `ci.places` count has no free count, so its bar shows only the used places.
 */
export function placeCells(places: CiPlaces): PlaceKind[] {
    return [
        ...Array<PlaceKind>(places.runner_jobs).fill("runner"),
        ...Array<PlaceKind>(places.proof_copies).fill("proof"),
        ...Array<PlaceKind>(places.free ?? 0).fill("free"),
    ];
}

/**
 * The reading beside the bar: the used places, out of how many when the Node has a count, and
 * whether the Node keeps its places for proof copies. The Gateway counts no runner job there.
 */
export function placesReading(places: CiPlaces): string {
    const used = places.runner_jobs + places.proof_copies;
    const reading =
        places.total === null
            ? `${used} used`
            : `${used}/${places.total} used · ${places.free ?? 0} free`;

    return places.proof_only ? `${reading} · proof copies only` : reading;
}

/** More places are in use than the Node's count allows. */
export const placesOverbooked = (places: CiPlaces): boolean =>
    places.total !== null && places.runner_jobs + places.proof_copies > places.total;

/** A runner's state in one word: the container state, else the service state, else busy or idle. */
export function runnerStateText(runner: CiRunner): string {
    if (runner.state !== "running") return runner.state;
    if (runner.service !== "active") return runner.service;

    return runner.busy ? "busy" : "idle";
}

export function runnerTone(runner: CiRunner): Tone {
    const text = runnerStateText(runner);

    if (text === "busy") return "cyan";
    if (text === "idle") return "green";

    return text === "stopped" || text === "error" || text === "failed" ? "red" : "yellow";
}

/** A job's result: its conclusion once it completed, else its status. */
export const jobResult = (job: CiJob): string => job.conclusion ?? job.status;

/** A job's colour: green success, cyan running, yellow queued, dim when it did not run to an end, else red. */
export function jobTone(job: CiJob): Tone {
    if (job.status !== "completed") return job.status === "in_progress" ? "cyan" : "yellow";
    if (job.conclusion === "success") return "green";

    return job.conclusion === "cancelled" ||
        job.conclusion === "skipped" ||
        job.conclusion === "neutral"
        ? "dim"
        : "red";
}

/** The running and queued jobs: running ones first, then the queue, each oldest first. */
export function activeJobs(jobs: readonly CiJob[]): CiJob[] {
    const rank = (job: CiJob): number => (job.status === "in_progress" ? 0 : 1);

    return jobs
        .filter((job) => job.status !== "completed")
        .sort(
            (a, b) =>
                rank(a) - rank(b) ||
                Date.parse(a.started_at ?? a.created_at) - Date.parse(b.started_at ?? b.created_at),
        );
}

/** The completed jobs, newest first, at most `limit`. */
export function recentJobs(jobs: readonly CiJob[], limit: number): CiJob[] {
    return jobs
        .filter((job) => job.status === "completed")
        .sort((a, b) => Date.parse(b.completed_at ?? "") - Date.parse(a.completed_at ?? ""))
        .slice(0, limit);
}

/** One runner's line in the stats over the window. */
export type RunnerStatsRow = {
    id: string;
    node: string;
    runner: string;
    jobs: number;
    failed: number;
    /** Failed jobs over all jobs; null with no jobs. */
    failureShare: number | null;
    queueWaitSeconds: number | null;
    runSeconds: number | null;
    /** The time spent on completed jobs over the window. */
    busyShare: number;
};

/** Every runner of every CI Node with its stats over the window, as the Gateway counted them. */
export function runnerStatsRows(view: CiRunners): RunnerStatsRow[] {
    const window = view.window_hours * 3600;

    return view.nodes.flatMap((node) =>
        node.runners.map((runner) => ({
            id: `${node.node_id}/${runner.name}`,
            node: node.node_name,
            runner: runner.name,
            jobs: runner.stats.jobs,
            failed: runner.stats.failed,
            failureShare: runner.stats.jobs === 0 ? null : runner.stats.failed / runner.stats.jobs,
            queueWaitSeconds: runner.stats.queue_wait_seconds_median,
            runSeconds: runner.stats.run_seconds_median,
            busyShare: window > 0 ? Math.min(1, runner.stats.busy_seconds / window) : 0,
        })),
    );
}

/** A length of time in its two largest units: "45s", "7m 30s", "2h 5m". A dash when unknown. */
export function formatSeconds(seconds: number | null): string {
    if (seconds === null || !Number.isFinite(seconds) || seconds < 0) return "—";

    const whole = Math.round(seconds);
    if (whole < 60) return `${whole}s`;

    const minutes = Math.floor(whole / 60);
    if (minutes < 60) return whole % 60 === 0 ? `${minutes}m` : `${minutes}m ${whole % 60}s`;

    const hours = Math.floor(minutes / 60);
    return minutes % 60 === 0 ? `${hours}h` : `${hours}h ${minutes % 60}m`;
}

/** The time from a moment until `now`, in seconds; null without the moment. */
export function secondsSince(at: string | null, now: number): number | null {
    const time = Date.parse(at ?? "");

    return Number.isFinite(time) ? Math.max(0, (now - time) / 1000) : null;
}

/** Bytes in GiB with one decimal. */
export const formatGiB = (bytes: number): string => (bytes / 1024 ** 3).toFixed(1);

/** A share as a whole percentage; a dash when unknown. */
export const formatShare = (share: number | null): string =>
    share === null ? "—" : `${Math.round(share * 100)}%`;
