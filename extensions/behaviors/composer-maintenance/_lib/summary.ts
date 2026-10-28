import { type ImpactContext, impacts } from "../../_lib/maintenance/impact.ts";
import type { ProjectType } from "../../_lib/maintenance/project_type.ts";
import { composerRows } from "../../_lib/maintenance/composer_summary.ts";
import type { Place } from "../../_lib/maintenance/io.ts";
import {
  type Change,
  resolveBase,
  summaryResult,
} from "../../_lib/maintenance/summary.ts";
export { ChangeSchema } from "../../_lib/maintenance/summary.ts";

export async function summarize(
  root: string,
  places: Place[],
  base?: string,
  signal?: AbortSignal,
  projectType: ProjectType = "laravel-app",
  impact?: ImpactContext | null,
) {
  const resolved = await resolveBase(root, base, signal);
  const changes: Change[] = [];
  for (const place of places) {
    changes.push(...await composerRows(root, place, resolved, signal));
  }
  const effects = await impacts(
    root,
    places,
    resolved,
    changes,
    impact,
    signal,
  );
  return summaryResult(changes, projectType, effects);
}
