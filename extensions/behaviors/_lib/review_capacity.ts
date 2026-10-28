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
  failures: z.number().int().nonnegative().optional(),
  assistance: z.string().optional(),
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
  const now = deps.now ?? Date.now;
  let parking = a.parking;
  const save = async () => {
    a = { ...a, parking };
    await deps.save(a);
  };
  if (parking?.assistance) {
    const path = `/api/v1/task-groups/${group}/comments`;
    const history = await deps.http("GET", path);
    if (history.status !== 200 || !Array.isArray(history.body?.data)) {
      return {
        ready: false,
        reason: "Parking assistance history unreadable; slot retained",
      };
    }
    const comments = history.body.data as Record<string, unknown>[];
    const own = comments.filter((c) =>
      c.task_group_id === group && c.task_id === group &&
      c.type === "assistance_requested" && c.author === "swamp" &&
      c.body === parking!.assistance &&
      Number.isSafeInteger(c.id)
    ).sort((x, y) =>
      Number(y.id) - Number(x.id)
    )[0];
    const resolved = own && comments.some((c) =>
      c.task_group_id === group && c.task_id === group &&
      c.type === "resolution" && typeof c.body === "string" &&
      c.body.trim() !== "" &&
      Number.isSafeInteger(c.id) && Number(c.id) > Number(own.id)
    );
    if (safe(g) && resolved) {
      parking = {
        ...parking,
        failures: 0,
        assistance: undefined,
        nextReadAt: null,
      };
      await save();
    } else {
      // Save intent before posting; history recovers a lost comment reply. Never replace another request.
      if (!own && safe(g) && reviewIdle(g) && g.watched_pr_state === "open") {
        const response = await deps.http("POST", path, {
          type: "assistance_requested",
          body: parking.assistance,
          author: "swamp",
        });
        if (response.status !== 201) {
          return {
            ready: false,
            reason: "Parking assistance post unconfirmed; slot retained",
          };
        }
      }
      return {
        ready: false,
        reason: "Review parking needs an operator resolution; slot retained",
      };
    }
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
  if (parking?.confirmed) {
    a = { ...a, parkingEpoch: (a.parkingEpoch ?? 0) + 1 };
    parking = undefined;
  }
  const requestKey = `${key}.park.${a.parkingEpoch ?? 0}`;
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
    const failures = (parking.failures ?? 0) + 1;
    a = { ...a, parkingEpoch: (a.parkingEpoch ?? 0) + 1 };
    parking = {
      key: `${key}.park.${a.parkingEpoch}`,
      operationId: null,
      confirmed: null,
      failures,
      nextReadAt: now() + Math.min(120_000, 30_000 * 2 ** (failures - 1)),
      ...(failures >= 3
        ? {
          assistance:
            `Review parking failed ${failures} times for tg-${group}. The slot remains held. Resolve the provider or group eligibility problem, then post a resolution to retry. Parking operation ${op.id}; request ${parking.key}.`,
        }
        : {}),
    };
    await save();
    return {
      ready: false,
      reason: `Review parking ${op.status}; slot retained, retry after backoff`,
    };
  }
  if (op.status !== "succeeded") {
    await save();
    return { ready: false };
  }
  const result = ParkResultSchema.parse(op.result);
  const fresh = await deps.http("GET", `/api/v1/task-groups/${group}`);
  const current = fresh.body?.data as Record<string, unknown> | undefined;
  const released = {
    ...a,
    parking: { ...parking, confirmed: result, failures: 0 },
  };
  if (fresh.status !== 200 || !current || !reviewReleased(released, current)) {
    throw new Error(
      "Parking result differs from current review group; slot retained",
    );
  }
  await deps.save(released);
  return { ready: true };
}
