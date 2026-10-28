import { z } from "npm:zod@4";
import type { Http } from "./gateway_stage.ts";
import type { Admission } from "./project_admission.ts";

export const ParkResultSchema = z.strictObject({
  group_id: z.number().int().positive(),
  task_vm_id: z.number().int().positive(),
  instance_id: z.number().int().positive(),
  status: z.literal("waiting_for_review"),
  power: z.literal("stopped"),
});
export const ParkingSchema = z.strictObject({
  key: z.string(),
  operationId: z.number().int().positive().nullable(),
  nextReadAt: z.number().nullable(),
  confirmed: ParkResultSchema.nullable(),
});
const safe = (g: Record<string, unknown>) =>
  ![
    "assistance_requested",
    "assistance_kind",
    "assistance_question",
    "assistance_reason",
  ].some((k) => g[k]);
export function reviewIdle(g: Record<string, unknown>) {
  return g.orchestration === "external" && Boolean(g.pr_url) && !g.preview &&
    safe(g) &&
    ["running", "reviewing", "settling", "waiting_for_review"].includes(
      String(g.status),
    ) &&
    Array.isArray(g.tasks) &&
    g.tasks.every((t: Record<string, unknown>) =>
      safe(t) && ["completed", "failed", "cancelled"].includes(String(t.status))
    );
}
export function reviewReleased(a: Admission, g: Record<string, unknown>) {
  const proof = a.parking?.confirmed;
  return Boolean(
    proof && proof.group_id === g.id && proof.instance_id === g.taskable_id &&
      reviewIdle(g) && g.status === "waiting_for_review" &&
      g.vm_power === "stopped" &&
      ["open", "merged"].includes(String(g.watched_pr_state)),
  );
}
/** The caller holds the same behavior lock as admission. Save intent before a keyed park start. */
export async function parkForReview(key: string, a: Admission, deps: {
  http: Http;
  save: (a: Admission) => Promise<void>;
  now?: () => number;
}): Promise<{ ready: boolean; reason?: string }> {
  const group = Number(a.workItem?.slice(3));
  const read = await deps.http("GET", `/api/v1/task-groups/${group}`);
  const g = read.body?.data as Record<string, unknown> | undefined;
  if (read.status !== 200 || !g || g.id !== group) {
    throw new Error("Cannot read review parking group; slot retained");
  }
  if (reviewReleased(a, g)) return { ready: true };
  if (
    reviewIdle(g) && ["merged", "closed"].includes(String(g.watched_pr_state))
  ) return { ready: true };
  if (!reviewIdle(g) || g.watched_pr_state !== "open") {
    return {
      ready: false,
      reason: "Review parking awaits an idle group and open PR; slot retained",
    };
  }
  let parking = a.parking;
  const now = deps.now ?? Date.now;
  if (parking?.confirmed) {
    a = { ...a, parkingEpoch: (a.parkingEpoch ?? 0) + 1 };
    parking = undefined;
  }
  const requestKey = `${key}.park.${a.parkingEpoch ?? 0}`;
  const save = async () => {
    a = { ...a, parking };
    await deps.save(a);
  };
  if (!parking) {
    parking = {
      key: requestKey,
      operationId: null,
      nextReadAt: null,
      confirmed: null,
    };
    await save();
  }
  if (parking.nextReadAt !== null && now() < parking.nextReadAt) {
    return { ready: false };
  }
  if (parking.operationId === null) {
    const start = await deps.http(
      "POST",
      `/api/v1/task-groups/${group}/park`,
      {},
      parking.key,
    );
    const started = start.body?.data as Record<string, unknown> | undefined;
    if (start.status !== 202 || !Number.isSafeInteger(started?.id)) {
      parking.nextReadAt = now() + 30_000;
      await save();
      return {
        ready: false,
        reason: "Review parking start unconfirmed; slot retained",
      };
    }
    parking.operationId = Number(started!.id);
    parking.nextReadAt = now() +
      Number(started!.poll_after_seconds ?? 5) * 1000;
    await save();
    return { ready: false };
  }
  const response = await deps.http(
    "GET",
    `/api/v1/operations/${parking.operationId}`,
  );
  const op = response.body?.data as Record<string, unknown> | undefined;
  if (
    response.status !== 200 || !op || op.id !== parking.operationId ||
    op.kind !== "tasks.park" || op.target_type !== "task_group" ||
    op.target_id !== group
  ) throw new Error("Invalid scoped parking evidence; slot retained");
  parking.nextReadAt = now() + Number(op.poll_after_seconds ?? 5) * 1000;
  if (["failed", "cancelled"].includes(String(op.status))) {
    await deps.save({
      ...a,
      parkingEpoch: (a.parkingEpoch ?? 0) + 1,
      parking: undefined,
    });
    return {
      ready: false,
      reason:
        `Review parking ${op.status}; slot retained, retry on the next eligible tick`,
    };
  }
  if (op.status !== "succeeded") {
    await save();
    return { ready: false };
  }
  const result = ParkResultSchema.parse(op.result);
  const fresh = await deps.http("GET", `/api/v1/task-groups/${group}`);
  const current = fresh.body?.data as Record<string, unknown> | undefined;
  const released = { ...a, parking: { ...parking, confirmed: result } };
  if (fresh.status !== 200 || !current || !reviewReleased(released, current)) {
    throw new Error(
      "Parking result differs from current review group; slot retained",
    );
  }
  await deps.save(released);
  return { ready: true };
}
