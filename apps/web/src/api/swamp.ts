import { queryOptions } from "@tanstack/react-query";
import { get } from "./client";
import type { components } from "./schema";

export type SwampRuns = components["schemas"]["TaskSwampRuns"];

export const taskSwampRunsQuery = (groupId: number) =>
    queryOptions({
        queryKey: ["task-groups", String(groupId), "swamp-runs"],
        queryFn: () => get<SwampRuns>(`/api/v1/task-groups/${groupId}/swamp-runs`),
        refetchInterval: 5_000,
        retry: false,
    });
