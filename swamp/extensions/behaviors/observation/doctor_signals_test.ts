import { assertEquals } from "jsr:@std/assert@1.0.19";
import { collectSignals, normalizeReason } from "./doctor_signals.ts";
const activity = (
  id: number,
  code: string | null = "gateway.unhandled",
  exit: number | null = null,
) => ({
  id,
  command: "instance:create",
  error_code: code,
  exit_code: exit,
  request_id: "req-1",
  created_at: "2026-10-31T10:00:00Z",
  path: "/instances",
  message: "[REDACTED]",
});
const input = () => ({
  activity_cursor: 0 as number | null,
  activity_max: 19,
  activities: [] as ReturnType<typeof activity>[],
  assistance: [] as { id: number; reason: string | null }[],
  stored_assistance_ids: [] as number[],
});
Deno.test("first collection remembers the newest Activity without counting history", () => {
  const raw = { ...input(), activity_cursor: null, activities: [activity(1)] };
  const selected = collectSignals(raw);
  assertEquals(selected.activity_cursor_before, null);
  assertEquals(selected.activity_cursor_after, 19);
  assertEquals(selected.activities, []);
});
Deno.test("server failure classification advances over ignored client errors and preserves occurrence times", () => {
  const raw = {
    ...input(),
    activities: [
      activity(1),
      activity(2, "activity.interrupted"),
      activity(3, "process.start_failed"),
      activity(4, "worker.unavailable"),
      activity(5, "http.500"),
      activity(6, "http.599"),
      activity(7, "http.499"),
      activity(8, "http.5000"),
      activity(9, "validation.failed"),
      activity(10, null, 0),
      activity(11, null, 1),
      activity(12, "permission.denied"),
    ],
  };
  const selected = collectSignals(raw);
  assertEquals(
    selected.activities.map((signal) => signal.observation.activity_ids[0]),
    [1, 2, 3, 4, 5, 6, 11],
  );
  assertEquals(selected.activity_cursor_after, 12);
  assertEquals(selected.activities.at(-1), {
    key: "instance:create|exit",
    seen_at: "2026-10-31T10:00:00Z",
    observation: {
      request_ids: ["req-1"],
      activity_ids: [11],
      paths: ["/instances"],
      error_message: "[REDACTED]",
    },
  });
});
Deno.test("empty reads retain the cursor and omit empty optional evidence", () => {
  assertEquals(
    collectSignals({ ...input(), activity_cursor: 42 }).activity_cursor_after,
    42,
  );
  const row = { ...activity(43), request_id: null, path: {}, message: "" };
  assertEquals(
    collectSignals({ ...input(), activities: [row] }).activities[0].observation,
    { request_ids: [], activity_ids: [43], paths: [] },
  );
});
Deno.test("assistance retains already sampled open requests and forgets closed requests", () => {
  const raw = {
    ...input(),
    stored_assistance_ids: [1, 2],
    assistance: [
      { id: 2, reason: "Still open" },
      { id: 3, reason: "Retry 123" },
      { id: 4, reason: null },
      { id: 5, reason: "   " },
    ],
  };
  const selected = collectSignals(raw);
  assertEquals(selected.open_assistance_ids, [2]);
  assertEquals(selected.assistance, [{
    key: "retry #",
    observation: { assistance_task_ids: [3], assistance_reason: "Retry 123" },
  }]);
});
Deno.test("assistance sample limits eligible rows before ignoring blank normalized reasons", () => {
  const raw = {
    ...input(),
    assistance: Array.from(
      { length: 202 },
      (_, i) => ({ id: i + 1, reason: i === 0 ? " " : `Retry ${i}` }),
    ),
  };
  const selected = collectSignals(raw);
  assertEquals(selected.assistance.length, 199);
  assertEquals(selected.assistance.at(-1)?.observation.assistance_task_ids, [
    200,
  ]);
});
Deno.test("assistance identities normalize case Unicode whitespace UUIDs and numbers", () => {
  assertEquals(
    normalizeReason(
      "  RéTRY\t8da50600-b7d3-4b56-9eca-e5305fea40d1\n  ID 934  ",
    ),
    "rétry # id #",
  );
});
