import { z } from "npm:zod@4.4.3";

/** Public data contracts; importing them never loads an execution client. */
export const DirectArgs = z.object({
  operation_id: z.number().int().positive(),
  kind: z.string().regex(/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/),
  node_id: z.number().int().positive().nullable(),
  run_id: z.string().uuid(),
});
export const DirectResult = z.preprocess(
  (value) => Array.isArray(value) && value.length === 0 ? {} : value,
  z.record(z.string(), z.unknown()),
);
export type DirectIntent = z.infer<typeof DirectArgs>;
export type Result = z.infer<typeof DirectResult>;

/** An action may cancel a read-only wait only after confirming no effect is running. */
export class DirectOperationCancelled extends Error {
  constructor(evidence: { effectRunning: false }) {
    super("Direct Operation cancelled with no effect running");
    if (evidence.effectRunning !== false) {
      throw new Error(
        "Cancellation requires confirmation that no effect is running",
      );
    }
  }
}
