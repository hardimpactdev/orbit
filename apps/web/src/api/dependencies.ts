import { queryOptions } from "@tanstack/react-query";
import { get } from "./client";

export type DependencyResolution = {
    id: string;
    name: string;
    version: string;
    regular: boolean;
    development: boolean;
};

export type DependencyInventory = {
    ecosystem: "composer" | "npm";
    state: "unknown" | "present" | "absent" | "stale";
    succeeded: boolean | null;
    attempted_at: string | null;
    error_code: string | null;
    snapshot: {
        observed_at: string;
        graph: { resolutions: DependencyResolution[] } | null;
    } | null;
};

export type InstanceDependencies = {
    instance_id: number;
    succeeded: boolean | null;
    composer: DependencyInventory;
    javascript: DependencyInventory;
};

/** Read the saved inventory. This endpoint never starts a scan. */
export const instanceDependenciesQuery = (id: number) =>
    queryOptions({
        queryKey: ["instance-dependencies", id],
        queryFn: () => get<InstanceDependencies>(`/api/v1/instances/${id}/dependencies`),
    });
