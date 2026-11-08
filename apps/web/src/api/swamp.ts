import { queryOptions } from "@tanstack/react-query";
import { get } from "./client";
import type { components } from "./schema";

export type SwampRuns = components["schemas"]["TaskSwampRuns"];

export const taskSwampRunsQuery = (groupId: number) =>
    queryOptions({
        queryKey: ["tasks", String(groupId), "swamp-runs"],
        queryFn: () => get<SwampRuns>(`/api/v1/tasks/${groupId}/swamp-runs`),
        refetchInterval: 5_000,
        retry: false,
    });
