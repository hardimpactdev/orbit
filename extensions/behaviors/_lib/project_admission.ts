import { z } from "npm:zod@4";
import { type Http } from "./gateway_stage.ts";
export const AdmissionSchema = z.strictObject({
  key: z.string(),
  projectId: z.number().int().positive(),
  mode: z.enum(["routine", "detect", "package"]),
  workItem: z.string().nullable(),
  released: z.boolean(),
  admittedAt: z.string(),
});
export type Admission = z.infer<typeof AdmissionSchema>;
export type AdmissionIntent = Pick<
  Admission,
  "key" | "projectId" | "mode" | "workItem"
>;
const unsafe = (g: Record<string, unknown>) =>
  Boolean(
    g.assistance_requested || g.assistance_kind || g.assistance_reason ||
      g.assistance_question,
  );
export function released(g: Record<string, unknown>) {
  return ["completed", "cancelled"].includes(String(g.status)) &&
    (g.vm_power === "destroyed" ||
      (g.vm_power === null && g.taskable_id === null)) &&
    !unsafe(g) && Array.isArray(g.tasks) &&
    g.tasks.every((t: Record<string, unknown>) =>
      ["completed", "failed", "cancelled"].includes(String(t.status)) &&
      !unsafe(t)
    ) &&
    (!(g.pr_url || g.watched_pr_url) || g.watched_pr_state === "merged" ||
      g.status === "cancelled");
}
/** Only the behavior lock serializes admissions. Orbit alone allocates actual VM capacity. */
export async function reserveMaintenance(
  intent: AdmissionIntent,
  deps: {
    http: Http;
    admissions: Admission[];
    existing: AdmissionIntent[];
    save: (a: Admission) => Promise<void>;
    now?: () => string;
  },
): Promise<{ admitted: boolean; reason?: string; admission?: Admission }> {
  const prior = deps.admissions.find((a) => a.key === intent.key);
  const response = await deps.http("GET", "/api/v1/task-groups");
  if (response.status !== 200 || !Array.isArray(response.body?.data)) {
    throw new Error("Cannot read Orbit maintenance capacity evidence");
  }
  const groups = response.body.data as Record<string, unknown>[];
  const held = new Map<string, AdmissionIntent>();
  for (
    const a of [
      ...deps.existing,
      ...deps.admissions.filter((a) => !a.released),
    ]
  ) {
    const g = a.workItem
      ? groups.find((g) => `tg-${g.id}` === a.workItem)
      : undefined;
    if (g && released(g)) {
      const saved = deps.admissions.find((v) => v.key === a.key);
      if (saved && !saved.released) {
        saved.released = true;
        await deps.save(saved);
      }
      continue;
    }
    held.set(a.workItem ?? a.key, a);
  }
  if (prior?.released) {
    return {
      admitted: false,
      reason: "Admission already completed and cleaned up",
    };
  }
  if (prior && !prior.released) return { admitted: true, admission: prior };
  // A pre-existing run already holds a place, even if deployed before admission receipts.
  const same = held.get(intent.workItem ?? intent.key);
  if (!same) {
    if (
      [...held.values()].some((a) =>
        a.projectId === intent.projectId && a.key !== intent.key &&
        (intent.mode === "package" || a.mode === "package")
      )
    ) {
      return {
        admitted: false,
        reason: "Project already has unfinished maintenance work",
      };
    }
    if (held.size >= 4) {
      return {
        admitted: false,
        reason:
          `Maintenance VM limit reached (${held.size}/4 held or reserved)`,
      };
    }
  }
  const admission: Admission = {
    ...intent,
    released: false,
    admittedAt: deps.now?.() ?? new Date().toISOString(),
  };
  await deps.save(admission);
  return { admitted: true, admission };
}
export function orderProjects<
  T extends { projectId: number; security: boolean },
>(queued: T[], history: Admission[]) {
  const last = (id: number) =>
    history.filter((a) => a.projectId === id).map((a) => a.admittedAt).sort()
      .at(-1) ?? "";
  return [...queued].sort((a, b) =>
    Number(b.security) - Number(a.security) ||
    last(a.projectId).localeCompare(last(b.projectId)) ||
    a.projectId - b.projectId
  );
}
