import { z } from "npm:zod@4";

export const GroupRunSchema = z.object({
  workItem: z.string().regex(/^tg-[1-9]\d*$/),
  factory: z.string().min(1),
  generation: z.number().int().positive(),
  key: z.string().min(1),
  runId: z.string().nullable(),
});
export type GroupRun = z.infer<typeof GroupRunSchema>;
export interface NativeGroupRun {
  id: string;
  status: string;
  inputs: Record<string, unknown>;
  awaitingResume?: boolean;
  failureReason?: string;
}
export interface GroupRunControl {
  read: () => Promise<GroupRun | null>;
  save: (intent: GroupRun) => Promise<void>;
  owner: () => Promise<GroupRun | null>;
  search: (key: string) => Promise<NativeGroupRun[]>;
  get: (id: string) => Promise<NativeGroupRun>;
  launch: (intent: GroupRun) => Promise<string>;
  settled: (intent: GroupRun, run: NativeGroupRun) => Promise<boolean>;
}
export const groupRunKey = (workItem: string, generation: number) =>
  `${workItem}.g${generation}`;
const ended = (status: string) =>
  ["succeeded", "failed", "cancelled"].includes(status);

function identity(run: NativeGroupRun, intent: GroupRun) {
  if (
    run.inputs.key !== intent.key ||
    run.inputs.workItem !== intent.workItem ||
    run.inputs.factory !== intent.factory ||
    run.inputs.generation !== intent.generation
  ) throw new Error("group workflow run identity differs from its intent");
}

/** Called under the group's native behavior lock, also used by claimGroupRun. */
export async function ensureGroupRun(
  workItem: string,
  factory: string,
  control: GroupRunControl,
): Promise<{ intent: GroupRun; resume: string | null }> {
  let intent = await control.read();
  if (intent && (intent.workItem !== workItem || intent.factory !== factory)) {
    throw new Error("group workflow factory differs from its saved intent");
  }
  if (intent) {
    // Only the admitted owner's result permits a successor. A refused duplicate
    // may finish while the owner is still running or waiting.
    const owner = await control.owner();
    if (owner?.key === intent.key) {
      if (!owner.runId) throw new Error("group workflow owner has no run id");
      intent = { ...intent, runId: owner.runId };
    }
    let run: NativeGroupRun | undefined;
    if (intent.runId) {
      run = await control.get(intent.runId);
      if (run.id !== intent.runId) {
        throw new Error("group workflow run id differs");
      }
    } else {
      const matches = await control.search(intent.key);
      for (const match of matches) identity(match, intent);
      run = matches[0];
      if (run) intent = { ...intent, runId: run.id };
    }
    if (run) {
      identity(run, intent);
      await control.save(intent);
      if (!ended(run.status)) {
        return {
          intent,
          resume: ["interrupted", "suspended"].includes(run.status)
            ? run.id
            : null,
        };
      }
      if (!await control.settled(intent, run)) return { intent, resume: null };
      intent = {
        workItem,
        factory,
        generation: intent.generation + 1,
        key: groupRunKey(workItem, intent.generation + 1),
        runId: null,
      };
    }
  }
  intent ??= {
    workItem,
    factory,
    generation: 1,
    key: groupRunKey(workItem, 1),
    runId: null,
  };
  // Persist before launch. A lost reply is searched by this key. If the request
  // never reached serve, retry the same generation; its claim admits one owner.
  await control.save(intent);
  const runId = await control.launch(intent);
  intent = { ...intent, runId };
  await control.save(intent);
  return { intent, resume: null };
}

export const RunFailuresSchema = z.object({
  consecutive: z.number().int().nonnegative(),
  lastKey: z.string().nullable(),
  lastOutcome: z.enum(["failed", "succeeded", "retry"]),
  generation: z.number().int().nonnegative(),
  parked: z.boolean(),
  reason: z.string().nullable(),
  updatedAt: z.string(),
});
export type RunFailures = z.infer<typeof RunFailuresSchema>;

/** Count a failed generation once, even after restart or a lost write reply. */
export function failedRun(
  previous: RunFailures | null,
  intent: GroupRun,
  reason: string,
  limit: number,
): RunFailures {
  const consecutive = (previous?.consecutive ?? 0) +
    (previous?.lastKey === intent.key && previous.lastOutcome !== "succeeded"
      ? 0
      : 1);
  return {
    consecutive,
    lastKey: intent.key,
    lastOutcome: "failed",
    generation: intent.generation,
    parked: consecutive >= limit,
    reason,
    updatedAt: new Date().toISOString(),
  };
}

export function clearedFailures(intent: GroupRun): RunFailures {
  return {
    consecutive: 0,
    lastKey: intent.key,
    lastOutcome: "succeeded",
    generation: intent.generation,
    parked: false,
    reason: null,
    updatedAt: new Date().toISOString(),
  };
}

/** The caller holds the SAME native lock as ensureGroupRun. */
export function claimGroupRun(
  intent: GroupRun | null,
  owner: GroupRun | null,
  request: GroupRun & { runId: string },
): boolean {
  return intent !== null && intent.workItem === request.workItem &&
    intent.factory === request.factory && intent.key === request.key &&
    intent.generation === request.generation &&
    (!owner || owner.generation < request.generation ||
      (owner.key === request.key && owner.runId === request.runId));
}
