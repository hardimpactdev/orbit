import { z } from "npm:zod@4.4.3";

const Input = z.object({
  activity_cursor: z.number().int().nonnegative().nullable(),
  activity_max: z.number().int().nonnegative(),
  activities: z.array(z.object({
    id: z.number().int().positive(),
    command: z.string(),
    error_code: z.string().nullable(),
    exit_code: z.number().int().nullable(),
    request_id: z.string().nullable(),
    created_at: z.string(),
    path: z.unknown(),
    message: z.string(),
  })),
  assistance: z.array(
    z.object({
      id: z.number().int().positive(),
      reason: z.string().nullable(),
    }),
  ),
  stored_assistance_ids: z.array(z.number().int().positive()),
});
export function normalizeReason(reason: string): string {
  return reason.trim().toLowerCase()
    .replace(
      /\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/gi,
      "#",
    )
    .replace(/[0-9]+/g, "#").replace(/\s+/gu, " ").trim();
}
/** Gateway reads rows and redacts text; native collection owns signal selection. */
export function collectSignals(raw: unknown) {
  const input = Input.parse(raw);
  const rows = input.activity_cursor === null ? [] : input.activities;
  const open = new Set(input.assistance.map((row) => row.id));
  const kept = input.stored_assistance_ids.filter((id) => open.has(id));
  const seen = new Set(kept);
  return {
    activity_cursor_before: input.activity_cursor,
    activity_cursor_after: input.activity_cursor === null
      ? input.activity_max
      : rows.at(-1)?.id ?? input.activity_cursor,
    activities: rows.filter((row) => {
      const code = row.error_code ?? "";
      return code === "gateway.unhandled" || code === "activity.interrupted" ||
        code.endsWith("_failed") || code.endsWith(".unavailable") ||
        (/^http\.[0-9]{3}$/.test(code) && Number(code.slice(5)) >= 500) ||
        (row.exit_code !== null && row.exit_code !== 0);
    }).map((row) => ({
      key: `${row.command}|${row.error_code || "exit"}`,
      seen_at: row.created_at,
      observation: {
        request_ids: row.request_id ? [row.request_id] : [],
        activity_ids: [row.id],
        paths: typeof row.path === "string" && row.path !== ""
          ? [row.path]
          : [],
        ...(row.message ? { error_message: row.message } : {}),
      },
    })),
    open_assistance_ids: kept,
    assistance: input.assistance.filter((row) =>
      !seen.has(row.id) && row.reason !== null
    )
      .slice(0, 200).flatMap((row) => {
        const key = normalizeReason(row.reason!);
        return key === "" ? [] : [{
          key,
          observation: {
            assistance_task_ids: [row.id],
            assistance_reason: row.reason!,
          },
        }];
      }),
  };
}
