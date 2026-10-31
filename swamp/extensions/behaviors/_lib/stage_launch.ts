import { canonical } from "./repository_analysis.ts";

/** Durable stage launch intent. Never resend an attempt with an uncertain reply. */
export interface StageLaunch {
  key: string;
  workflow: string;
  launches: number;
  lastLaunchedAt: string;
  log: string;
  runId?: string;
  pending?: boolean;
  inputs?: Record<string, unknown>;
  priorRunIds?: string[];
}

export async function reconcileStageLaunch(
  receipt: StageLaunch,
  search: () => Promise<{ id: string; inputs: Record<string, unknown> }[]>,
  save: (receipt: StageLaunch) => Promise<void>,
  graceSeconds: number,
  now = Date.now(),
): Promise<{ receipt: StageLaunch; waiting: string | null }> {
  if (!receipt.pending) return { receipt, waiting: null };
  const matches = (await search()).filter((run) =>
    run.inputs.key === receipt.key &&
    run.inputs.workItem === receipt.inputs?.workItem &&
    Object.entries(receipt.inputs ?? {}).every(([key, value]) =>
      canonical(run.inputs[key]) === canonical(value)
    ) &&
    !receipt.priorRunIds?.includes(run.id)
  );
  if (matches.length === 1) {
    receipt = { ...receipt, runId: matches[0].id, pending: false };
    await save(receipt);
    return { receipt, waiting: null };
  }
  const age = (now - Date.parse(receipt.lastLaunchedAt)) / 1000;
  return {
    receipt,
    waiting: matches.length > 1 || age >= graceSeconds
      ? `assistance: uncertain launch of ${receipt.workflow} for ${receipt.key}; found ${matches.length} new runs; inspect the saved launch receipt and native history before continuing`
      : `stage launch for ${receipt.key} awaiting its native receipt`,
  };
}
