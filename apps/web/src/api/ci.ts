import { keepPreviousData, queryOptions } from "@tanstack/react-query";
import type { components } from "./schema";
import { get } from "./client";

type Schema<K extends keyof components["schemas"]> = Required<components["schemas"][K]>;

// The spec marks no field as required; the Gateway always sends them (docs/reference/ci-runner-view.md).
export type CiPlaces = Schema<"CiPlaces">;
export type CiUsage = Schema<"CiUsage">;
export type CiNodeResources = Omit<Schema<"CiNodeResources">, "memory" | "disk"> & {
    memory: CiUsage;
    disk: CiUsage | null;
};
export type CiRunnerStats = Schema<"CiRunnerStats">;
export type CiRunner = Omit<Schema<"CiRunner">, "current_job" | "stats"> & {
    current_job: Schema<"CiCurrentJob"> | null;
    stats: CiRunnerStats;
};
export type CiNode = Omit<
    Schema<"CiNode">,
    "places" | "proof_copies" | "resources" | "runner_state" | "runners"
> & {
    places: CiPlaces;
    proof_copies: Schema<"CiProofCopy">[];
    resources: CiNodeResources | null;
    runner_state: Schema<"CiRunnerState">;
    runners: CiRunner[];
};
export type CiJob = Schema<"CiJob">;
export type CiRunners = Omit<Schema<"CiRunners">, "nodes" | "repositories" | "jobs"> & {
    nodes: CiNode[];
    repositories: Schema<"CiRepository">[];
    jobs: CiJob[];
};

/** How often the runner view reloads: the pace of a CI Node's runner state timer. */
export const CI_RUNNERS_POLL_MS = 15_000;

export const ciRunnersQuery = queryOptions({
    queryKey: ["ci-runners"],
    queryFn: () => get<CiRunners>("/api/v1/ci/runners"),
    refetchInterval: CI_RUNNERS_POLL_MS,
    placeholderData: keepPreviousData,
    retry: false,
});
