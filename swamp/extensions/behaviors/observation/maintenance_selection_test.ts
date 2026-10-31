import { assertEquals, assertThrows } from "jsr:@std/assert@1.0.19";
import { selectMaintenance } from "./maintenance_selection.ts";

const now = new Date("2026-10-31T12:00:00Z"), epoch = now.getTime() / 1000;
const input = {
    routine: "maintenance",
    tasks_enabled: true,
    activity_seconds: 900,
    batch_hours: 24,
    failed_hours: 168,
    redis: null,
    activities: [],
    batches: [],
    failed: [],
};
Deno.test("interrupted selection preserves strict cutoff, ID ordering and the 500-row cap", () => {
    const activities = [
        ...Array.from(
            { length: 503 },
            (_, i) => ({ id: 600 - i, created_at: "2026-10-30T00:00:00Z" }),
        ),
        { id: 1, created_at: "2026-10-31T11:45:00Z" },
        { id: 2, created_at: "2026-10-31T11:45:01Z" },
    ];
    const result = selectMaintenance({ ...input, activities }, now);
    assertEquals(result.activity_ids.length, 500);
    assertEquals(result.activity_ids[0], 98);
    assertEquals(result.activity_ids.at(-1), 597);
    assertEquals(result.batch_ids, []);
    assertEquals(result.failed_ids, []);
});
Deno.test("retention uses exact finished, unfinished and cancelled batch predicates with one deletion per row", () => {
    const cutoff = epoch - 86400;
    const batches = [{
        id: "finished",
        created_at: cutoff - 100,
        finished_at: cutoff - 1,
        cancelled_at: null,
    }, {
        id: "boundary",
        created_at: cutoff - 100,
        finished_at: cutoff,
        cancelled_at: null,
    }, {
        id: "unfinished",
        created_at: cutoff - 1,
        finished_at: null,
        cancelled_at: null,
    }, {
        id: "cancelled",
        created_at: cutoff - 1,
        finished_at: epoch,
        cancelled_at: epoch,
    }, {
        id: "fresh-cancelled",
        created_at: cutoff,
        finished_at: null,
        cancelled_at: cutoff - 100,
    }, {
        id: "overlap",
        created_at: cutoff - 1,
        finished_at: cutoff - 1,
        cancelled_at: epoch,
    }];
    const result = selectMaintenance({
        ...input,
        routine: "retention",
        batches,
        failed: [{ id: 7, failed_at: "2026-10-24 11:59:59" }, {
            id: 8,
            failed_at: "2026-10-24 12:00:00",
        }],
    }, now);
    assertEquals(result.batch_ids, [
        "finished",
        "unfinished",
        "cancelled",
        "overlap",
    ]);
    assertEquals(result.failed_ids, [7]);
});
Deno.test("Tasks disabled suppresses batches while preserving failed-job retention and Activity finalization", () => {
    assertEquals(
        selectMaintenance({
            ...input,
            routine: "retention",
            tasks_enabled: false,
            batches: [{
                id: "old",
                created_at: 0,
                finished_at: 1,
                cancelled_at: null,
            }],
            failed: [{ id: 7, failed_at: "2026-01-01T00:00:00Z" }],
        }, now).failed_ids,
        [7],
    );
    assertEquals(
        selectMaintenance({
            ...input,
            routine: "retention",
            tasks_enabled: false,
            batches: [{
                id: "old",
                created_at: 0,
                finished_at: 1,
                cancelled_at: null,
            }],
        }, now).batch_ids,
        [],
    );
    assertEquals(
        selectMaintenance({
            ...input,
            tasks_enabled: false,
            activities: [{ id: 7, created_at: "2026-01-01T00:00:00Z" }],
        }, now).activity_ids,
        [7],
    );
});
Deno.test("invalid timestamps never prove expiry", () => {
    assertThrows(() =>
        selectMaintenance({
            ...input,
            activities: [{ id: 7, created_at: "invalid" }],
        }, now)
    );
});
Deno.test("database expiry boundaries retain whole-second precision with a subsecond native clock", () => {
    const subsecond = new Date("2026-10-31T12:00:00.999Z");
    assertEquals(
        selectMaintenance({
            ...input,
            activities: [{ id: 1, created_at: "2026-10-31T11:45:00Z" }],
        }, subsecond).activity_ids,
        [],
    );
    assertEquals(
        selectMaintenance({
            ...input,
            routine: "retention",
            failed: [{ id: 1, failed_at: "2026-10-24 12:00:00" }],
        }, subsecond).failed_ids,
        [],
    );
});
