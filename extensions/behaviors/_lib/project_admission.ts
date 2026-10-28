import { ParkingSchema, reviewReleased } from "./review_capacity.ts";
import { z } from "npm:zod@4";
import { type Http } from "./gateway_stage.ts";
import {
  findScheduledGroup,
  type ScheduleReceipt,
} from "./project_schedule.ts";
export const AdmissionSchema = z.strictObject({
  key: z.string(),
  projectId: z.number().int().positive(),
  mode: z.enum(["routine", "detect", "package"]),
  workItem: z.string().nullable(),
  released: z.boolean(),
  admittedAt: z.string(),
  parkingEpoch: z.number().int().nonnegative().optional(),
  parking: ParkingSchema.optional(),
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
const UNBOUND_GRACE_MS = 15 * 60 * 1000;

/** Reconcile even an idle queue; failed creates must not reserve capacity forever. */
export async function reconcileAdmissions(deps: {
  admissions: Admission[];
  receipts: ScheduleReceipt[];
  http: Http;
  save: (a: Admission) => Promise<void>;
  saveReceipt: (r: ScheduleReceipt) => Promise<void>;
  assistance: (a: Admission, reason: string | null) => Promise<void>;
  now?: () => number;
}) {
  for (let admission of deps.admissions.filter((a) => !a.released)) {
    try {
      const receipt = deps.receipts.find((r) => r.key === admission.key);
      if (!admission.workItem && receipt?.workItem) {
        admission = { ...admission, workItem: receipt.workItem };
        await deps.save(admission);
      }
      if (!admission.workItem) {
        if (
          (deps.now?.() ?? Date.now()) - Date.parse(admission.admittedAt) <
            UNBOUND_GRACE_MS
        ) continue;
        if (receipt?.attempted) {
          const workItem = await findScheduledGroup(receipt, deps.http);
          if (workItem) {
            await deps.saveReceipt({ ...receipt, workItem });
            admission = { ...admission, workItem };
            await deps.save(admission);
          }
        }
        if (!admission.workItem) {
          // Save assistance and retire the immutable intent before freeing capacity.
          await deps.assistance(
            admission,
            `Reservation ${admission.key} expired after 15 minutes without an Orbit group. ${
              receipt?.attempted
                ? "No group matches its saved request."
                : "Group creation was never attempted."
            } Intent retired; slot released. Inspect the saved request before starting new work.`,
          );
          if (receipt) await deps.saveReceipt({ ...receipt, completed: true });
          await deps.save({ ...admission, released: true });
          continue;
        }
      }
      const response = await deps.http(
        "GET",
        `/api/v1/task-groups/${admission.workItem!.slice(3)}`,
      );
      const group = response.body?.data as Record<string, unknown> | undefined;
      if (response.status !== 200 || !group) {
        throw new Error(
          `Cannot reconcile reservation ${admission.workItem}: Orbit group evidence is unreadable; slot retained`,
        );
      }
      if (
        group.status === "completed" && group.watched_pr_state === "closed" &&
        (group.pr_url || group.watched_pr_url)
      ) {
        await deps.assistance(
          admission,
          `${admission.workItem} completed but its PR ${
            group.watched_pr_url || group.pr_url
          } was closed without merge. Maintenance slot retained; reopen or reconcile the PR.`,
        );
        continue;
      }
      if (released(group)) await deps.save({ ...admission, released: true });
      else if (
        admission.parking?.confirmed && !reviewReleased(admission, group)
      ) {
        admission = {
          ...admission,
          parking: undefined,
          parkingEpoch: (admission.parkingEpoch ?? 0) + 1,
        };
        await deps.save(admission);
      }
      await deps.assistance(admission, null);
    } catch (error) {
      await deps.assistance(
        admission,
        error instanceof Error ? error.message : String(error),
      );
    }
  }
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
  let prior = deps.admissions.find((a) => a.key === intent.key);
  const admissions = [...deps.admissions];
  const response = await deps.http("GET", "/api/v1/task-groups");
  if (response.status !== 200 || !Array.isArray(response.body?.data)) {
    throw new Error("Cannot read Orbit maintenance capacity evidence");
  }
  const groups = response.body.data as Record<string, unknown>[];
  for (let i = 0; i < admissions.length; i++) {
    const a = admissions[i];
    const g = groups.find((g) => `tg-${g.id}` === a.workItem);
    if (g && a.parking?.confirmed && !reviewReleased(a, g)) {
      const held = {
        ...a,
        parking: undefined,
        parkingEpoch: (a.parkingEpoch ?? 0) + 1,
      };
      admissions[i] = held;
      if (prior?.key === a.key) prior = held;
      await deps.save(held);
    }
  }
  const held = new Map<string, AdmissionIntent>();
  const sleeping = new Set<string>();
  for (
    const a of [
      ...deps.existing,
      ...admissions.filter((a) => !a.released),
    ]
  ) {
    const g = a.workItem
      ? groups.find((g) => `tg-${g.id}` === a.workItem)
      : undefined;
    if (g && released(g)) {
      const saved = admissions.find((v) => v.key === a.key);
      if (saved && !saved.released) {
        const completed = { ...saved, released: true };
        if (prior?.key === saved.key) prior = completed;
        await deps.save(completed);
      }
      continue;
    }
    held.set(a.workItem ?? a.key, a);
    const saved = admissions.find((v) => v.key === a.key);
    if (g && saved && reviewReleased(saved, g)) {
      sleeping.add(a.workItem ?? a.key);
    }
  }
  if (prior?.released) {
    return {
      admitted: false,
      reason: "Admission already completed and cleaned up",
      admission: prior,
    };
  }
  const used = held.size - sleeping.size;
  if (prior?.parking?.confirmed && !sleeping.has(prior.workItem ?? prior.key)) {
    return {
      admitted: false,
      reason:
        "Review parking evidence differs from current group; slot retained",
    };
  }
  if (prior && !prior.released && !sleeping.has(prior.workItem ?? prior.key)) {
    return { admitted: true, admission: prior };
  }
  // A pre-existing run already holds a place, even if deployed before admission receipts.
  const same = held.get(intent.workItem ?? intent.key);
  if (!same) {
    if (
      [...held.values()].some((a) =>
        a.projectId === intent.projectId && a.key !== intent.key
      )
    ) {
      return {
        admitted: false,
        reason: "Project already has unfinished maintenance work",
      };
    }
    if (used >= 4) {
      return {
        admitted: false,
        reason: `Maintenance VM limit reached (${used}/4 held or reserved)`,
      };
    }
  }
  if (sleeping.has(intent.workItem ?? intent.key) && used >= 4) {
    return {
      admitted: false,
      reason: "Maintenance VM limit reached (4/4 held or reserved)",
    };
  }
  const admission: Admission = {
    ...intent,
    ...(prior ? { parkingEpoch: (prior.parkingEpoch ?? 0) + 1 } : {}),
    released: false,
    admittedAt: prior?.admittedAt ?? deps.now?.() ?? new Date().toISOString(),
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
