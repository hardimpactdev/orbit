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
