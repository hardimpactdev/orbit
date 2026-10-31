import { z } from "npm:zod@4.4.3";
import {
  Outcome as FootprintOutcome,
  Snapshot,
  Stage as FootprintStage,
} from "./converge_direct_contract.ts";

export function footprintProof(
  snapshot: z.infer<typeof Snapshot>,
  outcomes: z.infer<typeof FootprintOutcome>[],
) {
  if (new Set(outcomes.map((o) => o.stage)).size !== outcomes.length) {
    throw new Error("Native footprint stages are duplicated");
  }
  const failure = outcomes.find((o) => !o.ok);
  if (!failure && outcomes.length !== FootprintStage.options.length) {
    throw new Error("A native footprint outcome is uncertain");
  }
  const artifacts = outcomes.flatMap((o) => o.entry ? [o.entry] : []);
  const names = new Set(artifacts.map((a) => a.name));
  if (
    names.size !== artifacts.length ||
    artifacts.some((a) => !snapshot.artifacts.some((s) => s.name === a.name))
  ) {
    throw new Error("The native footprint artifacts differ from admission");
  }
  const code = failure?.error_code ??
    outcomes.find((o) => o.deferred_error)?.deferred_error;
  if (!code && snapshot.artifacts.some((a) => !names.has(a.name))) {
    throw new Error("Native footprint completion is unproven");
  }
  return {
    footprint_proven: code === undefined,
    artifacts,
    ...(code ? { error_code: code } : {}),
    ...(failure?.failed_artifact
      ? { failed_artifact: failure.failed_artifact }
      : {}),
  };
}
