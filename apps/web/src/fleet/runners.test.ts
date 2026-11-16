import { describe, expect, it } from "vite-plus/test";
import type { CiJob, CiNode, CiRunner, CiRunners } from "../api/ci";
import {
    activeJobs,
    formatSeconds,
    jobTone,
    placeCells,
    placesOverbooked,
    placesReading,
    recentJobs,
    runnerStateText,
    runnerStatsRows,
    runnerTone,
} from "./runners";

const runner = (overrides: Partial<CiRunner> = {}): CiRunner => ({
    name: "ci-1",
    state: "running",
    service: "active",
    busy: false,
    version: "2.330.0",
    current_job: null,
    stats: {
        jobs: 0,
        failed: 0,
        queue_wait_seconds_median: null,
        run_seconds_median: null,
        busy_seconds: 0,
    },
    ...overrides,
});

const node = (name: string, runners: CiRunner[]): CiNode => ({
    node_id: name.length,
    node_name: name,
    places: { total: null, runner_jobs: 0, proof_copies: 0, free: null },
    proof_copies: [],
    resources: null,
    runner_state: { observed_at: null, stale: false, error_code: null },
    runners,
});

const job = (id: number, overrides: Partial<CiJob> = {}): CiJob => ({
    job_id: id,
    run_id: 1,
    repository: "acme/shop",
    workflow: "CI",
    job: `php (${id})`,
    runner_name: "ci-1",
    status: "completed",
    conclusion: "success",
    created_at: "2026-11-16T10:00:00Z",
    started_at: "2026-11-16T10:00:10Z",
    completed_at: "2026-11-16T10:05:10Z",
    queue_wait_seconds: 10,
    run_seconds: 300,
    url: null,
    ...overrides,
});

describe("the places bar", () => {
    it("draws runner jobs, then proof copies, then the free places", () => {
        const places = { total: 5, runner_jobs: 2, proof_copies: 1, free: 2 };

        expect(placeCells(places)).toEqual(["runner", "runner", "proof", "free", "free"]);
        expect(placesReading(places)).toBe("3/5 used · 2 free");
        expect(placesOverbooked(places)).toBe(false);
    });

    it("shows only the used places when the Node has no count", () => {
        const places = { total: null, runner_jobs: 1, proof_copies: 2, free: null };

        expect(placeCells(places)).toEqual(["runner", "proof", "proof"]);
        expect(placesReading(places)).toBe("3 used");
        expect(placesOverbooked(places)).toBe(false);
    });

    it("marks a Node that uses more places than its count", () => {
        const places = { total: 2, runner_jobs: 2, proof_copies: 1, free: 0 };

        expect(placeCells(places)).toEqual(["runner", "runner", "proof"]);
        expect(placesReading(places)).toBe("3/2 used · 0 free");
        expect(placesOverbooked(places)).toBe(true);
    });
});

describe("a runner's state", () => {
    it.each([
        [{ state: "stopped", service: "unknown" }, "stopped", "red"],
        [{ state: "frozen", service: "unknown" }, "frozen", "yellow"],
        [{ service: "failed" }, "failed", "red"],
        [{ service: "activating" }, "activating", "yellow"],
        [{ busy: true }, "busy", "cyan"],
        [{}, "idle", "green"],
    ] as const)("reads %o as %s", (overrides, text, tone) => {
        expect(runnerStateText(runner(overrides))).toBe(text);
        expect(runnerTone(runner(overrides))).toBe(tone);
    });
});

describe("the runner stats", () => {
    it("lists every runner of every Node with its failure and busy shares over the window", () => {
        const view = {
            generated_at: "2026-11-16T12:00:00Z",
            window_hours: 48,
            nodes: [
                node("ci-a", [
                    runner({
                        name: "ci-a-1",
                        stats: {
                            jobs: 4,
                            failed: 1,
                            queue_wait_seconds_median: 20,
                            run_seconds_median: 450,
                            busy_seconds: 17_280,
                        },
                    }),
                    runner({ name: "ci-a-2" }),
                ]),
                node("ci-b", []),
            ],
            repositories: [],
            github_error_code: null,
            jobs: [],
        } satisfies CiRunners;

        expect(runnerStatsRows(view)).toEqual([
            {
                id: "4/ci-a-1",
                node: "ci-a",
                runner: "ci-a-1",
                jobs: 4,
                failed: 1,
                failureShare: 0.25,
                queueWaitSeconds: 20,
                runSeconds: 450,
                busyShare: 0.1,
            },
            {
                id: "4/ci-a-2",
                node: "ci-a",
                runner: "ci-a-2",
                jobs: 0,
                failed: 0,
                failureShare: null,
                queueWaitSeconds: null,
                runSeconds: null,
                busyShare: 0,
            },
        ]);
    });
});

describe("the job lists", () => {
    const jobs = [
        job(1, { completed_at: "2026-11-16T10:05:10Z" }),
        job(2, {
            status: "queued",
            conclusion: null,
            runner_name: null,
            created_at: "2026-11-16T11:58:00Z",
            started_at: null,
            completed_at: null,
        }),
        job(3, {
            status: "in_progress",
            conclusion: null,
            started_at: "2026-11-16T11:59:00Z",
            completed_at: null,
        }),
        job(4, { conclusion: "failure", completed_at: "2026-11-16T11:00:00Z" }),
        job(5, {
            status: "queued",
            conclusion: null,
            runner_name: null,
            created_at: "2026-11-16T11:50:00Z",
            started_at: null,
            completed_at: null,
        }),
    ];

    it("puts the running jobs before the queue, each oldest first", () => {
        expect(activeJobs(jobs).map((row) => row.job_id)).toEqual([3, 5, 2]);
    });

    it("lists the completed jobs newest first, up to the limit", () => {
        expect(recentJobs(jobs, 5).map((row) => row.job_id)).toEqual([4, 1]);
        expect(recentJobs(jobs, 1).map((row) => row.job_id)).toEqual([4]);
    });

    it("colours each job by its result", () => {
        expect(jobs.map(jobTone)).toEqual(["green", "yellow", "cyan", "red", "yellow"]);
        expect(jobTone(job(6, { conclusion: "cancelled" }))).toBe("dim");
        expect(jobTone(job(7, { conclusion: "timed_out" }))).toBe("red");
    });
});

describe("formatSeconds", () => {
    it.each([
        [null, "—"],
        [45, "45s"],
        [450, "7m 30s"],
        [600, "10m"],
        [7_500, "2h 5m"],
        [7_200, "2h"],
    ])("writes %s as %s", (seconds, text) => {
        expect(formatSeconds(seconds)).toBe(text);
    });
});
