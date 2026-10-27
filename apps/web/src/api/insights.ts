import { keepPreviousData, queryOptions } from "@tanstack/react-query";
import type { components } from "./schema";
import { get } from "./client";

type Schemas = components["schemas"];
export type InsightsPeriod = "7d" | "30d";
export type InsightsSummary = Omit<
    Required<Schemas["TaskInsightsSummary"]>,
    "questions_by_cause"
> & {
    questions_by_cause: Record<string, number>;
};
export type InsightsWeek = Required<Schemas["TaskInsightsWeek"]>;
export type InsightsDay = Required<Schemas["TaskInsightsDay"]>;
export type InsightsBreakdownRow = Required<Schemas["TaskInsightsBreakdownRow"]>;
export type InsightsDimension = "model" | "project" | "release";
export type TaskInsights = {
    period: InsightsPeriod;
    from: string;
    to: string;
    current: InsightsSummary;
    previous: InsightsSummary;
    weekly: InsightsWeek[];
    daily: InsightsDay[];
    breakdowns: Record<InsightsDimension, InsightsBreakdownRow[]>;
};

/** How often the strip reloads. Task events do not refetch it: one task moves these numbers only a little. */
export const INSIGHTS_POLL_MS = 300_000;

export const taskInsightsQuery = (period: InsightsPeriod, projectId: number | null) =>
    queryOptions({
        queryKey: ["task-insights", period, projectId],
        queryFn: () =>
            get<TaskInsights>(
                `/api/v1/task-insights?period=${period}${projectId === null ? "" : `&project_id=${projectId}`}`,
            ),
        refetchInterval: INSIGHTS_POLL_MS,
        placeholderData: keepPreviousData,
        retry: false,
    });
