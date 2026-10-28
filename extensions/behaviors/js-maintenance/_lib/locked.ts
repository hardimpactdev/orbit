import { type Candidate } from "../../_lib/maintenance/batch.ts";
import { type Resolution } from "../../_lib/dependency_results_schema.ts";
import { releases } from "./batch.ts";
import { majorChange } from "../../_lib/maintenance/summary.ts";

/** pnpm inventory is readable; unsupported update planning remains blocked data. */
export async function scanPnpm(
  dir: string,
  path: string,
  packages: Resolution[],
  signal?: AbortSignal,
): Promise<Candidate[]> {
  const result = new Map<string, Candidate>();
  for (const p of packages.filter((p) => p.direct && p.origin === "registry")) {
    const latest = (await releases({ package: p.name }, dir, signal)).filter((
      r,
    ) => /^\d+\.\d+\.\d+$/.test(r.version)).at(-1)?.version;
    if (latest && majorChange(p.version, latest)) {
      result.set(p.name, {
        path,
        manager: "js",
        package: p.name,
        current: p.version,
        latest,
        reasons: ["major"],
        advisories: [],
      });
    }
  }
  const registry = packages.filter((p) => p.origin === "registry");
  for (let i = 0; i < registry.length; i += 100) {
    const chunk = registry.slice(i, i + 100);
    const response = await fetch("https://api.osv.dev/v1/querybatch", {
      method: "POST",
      signal,
      headers: { "content-type": "application/json" },
      body: JSON.stringify({
        queries: chunk.map((p) => ({
          package: { name: p.name, ecosystem: "npm" },
          version: p.version,
        })),
      }),
    });
    if (!response.ok) throw new Error("advisory_service_unavailable");
    const data = await response.json();
    if (!Array.isArray(data.results) || data.results.length !== chunk.length) {
      throw new Error("advisory_result_incomplete");
    }
    for (let j = 0; j < chunk.length; j++) {
      const vulns = data.results[j].vulns ?? [];
      if (!Array.isArray(vulns)) throw new Error("advisory_result_invalid");
      if (!vulns.length) continue;
      const p = chunk[j],
        candidate = result.get(p.name) ??
          {
            path,
            manager: "js" as const,
            package: p.name,
            current: p.version,
            reasons: [],
            advisories: [],
          };
      if (!candidate.reasons.includes("security")) {
        candidate.reasons.push("security");
      }
      for (const v of vulns) {
        if (typeof v.id !== "string" || !/^[A-Za-z0-9_-]+$/.test(v.id)) {
          throw new Error("advisory_result_invalid");
        }
        if (!candidate.advisories.some((a) => a.id === v.id)) {
          candidate.advisories.push({
            id: v.id,
            url: `https://osv.dev/vulnerability/${v.id}`,
          });
        }
      }
      result.set(p.name, candidate);
    }
  }
  return [...result.values()];
}
