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
) {
  const resolved = await resolveBase(root, base, signal);
  const changes: Change[] = [];
  for (const place of places) {
    changes.push(...await composerRows(root, place, resolved, signal));
  }
  return summaryResult(changes);
}
