import { infiniteQueryOptions } from "@tanstack/react-query";
import { get } from "./client";
import type { components } from "./schema";

export type DependencyResults = components["schemas"]["DependencyResults"];
export type DependencyPackage = components["schemas"]["DependencyPackage"];
export type DependencyFinding = components["schemas"]["DependencyFinding"];
export type DependencyReport = components["schemas"]["DependencyReport"];

/** Swamp publishes independently of Orbit's realtime channel. Poll evidence every minute. */
export const dependencyResultsQuery = (
    scope: "instances" | "projects",
    id: number,
    source: string | undefined,
    view: "packages" | "findings",
) =>
    infiniteQueryOptions({
        queryKey: ["dependencies", scope, id, source ?? "first", view],
        initialPageParam: 1,
        queryFn: ({ pageParam }) => {
            const query = new URLSearchParams();
            if (source !== undefined) query.set("source", source);
            if (pageParam > 1) query.set(`${view}_page`, String(pageParam));
            const suffix = query.size === 0 ? "" : `?${query}`;
            return get<DependencyResults>(`/api/v1/${scope}/${id}/dependencies${suffix}`);
        },
        getNextPageParam: (last, pages) => {
            const report = last.report;
            if (report === null || report.publication_id !== pages[0]?.report?.publication_id)
                return undefined;
            const page = view === "packages" ? report.packages_page : report.findings_page;
            const count = view === "packages" ? report.package_count : report.finding_count;
            return page * 100 < count ? page + 1 : undefined;
        },
        refetchInterval: 60_000,
    });
