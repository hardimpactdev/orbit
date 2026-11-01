import { assertEquals, assertThrows } from "jsr:@std/assert@1.0.19";
import { behavior, compare, eligible } from "./maintenance-read/result.ts";

const input = {
    routine: "maintenance" as const,
    tasks_enabled: true,
    evaluated_at: "2026-10-31T12:00:00.999Z",
    activity_seconds: 900,
    batch_hours: 24,
    failed_hours: 168,
    horizon_unit: "orbit-process-7-horizon.service",
    activities: [],
    batches: [],
    failed: [],
};
const read = (stdout: string, exitCode = 0) => ({
    status: "succeeded",
    outputs: { stdout, exitCode },
});
Deno.test("eligibility retains strict whole-second cutoffs and the 500 Activity cap", () => {
    assertEquals(
        eligible({
            ...input,
            activities: [
                { id: 1, created_at: "2026-10-31T11:45:00Z" },
                { id: 2, created_at: "2026-10-31T11:45:01Z" },
            ],
        }).activities,
        0,
    );
    assertEquals(
        eligible({
            ...input,
            activities: Array.from(
                { length: 503 },
                (_, i) => ({ id: i + 1, created_at: "2026-01-01T00:00:00Z" }),
            ),
        }).activities,
        500,
    );
});
Deno.test("retention observes exact finished unfinished and cancelled predicates once per row", () => {
    const cutoff = Date.parse(input.evaluated_at) / 1000 - 86400 - 0.999;
    const batches = [
        {
            id: "finished",
            created_at: cutoff - 100,
            finished_at: cutoff - 1,
            cancelled_at: null,
        },
        {
            id: "boundary",
            created_at: cutoff - 100,
            finished_at: cutoff,
            cancelled_at: null,
        },
        {
            id: "unfinished",
            created_at: cutoff - 1,
            finished_at: null,
            cancelled_at: null,
        },
        {
            id: "cancelled",
            created_at: cutoff - 1,
            finished_at: cutoff + 100,
            cancelled_at: cutoff + 100,
        },
        {
            id: "fresh-cancelled",
            created_at: cutoff,
            finished_at: null,
            cancelled_at: cutoff - 100,
        },
        {
            id: "overlap",
            created_at: cutoff - 1,
            finished_at: cutoff - 1,
            cancelled_at: cutoff + 100,
        },
    ];
    const retention = {
        ...input,
        routine: "retention" as const,
        batches,
        failed: [
            { id: 7, failed_at: "2026-10-24 11:59:59" },
            { id: 8, failed_at: "2026-10-24 12:00:00" },
        ],
    };
    assertEquals(eligible(retention), { activities: 0, batches: 4, failed: 1 });
    assertEquals(eligible({ ...retention, tasks_enabled: false }), {
        activities: 0,
        batches: 0,
        failed: 1,
    });
});
Deno.test("invalid admitted timestamps never prove eligibility", () => {
    assertThrows(() =>
        eligible({ ...input, activities: [{ id: 1, created_at: "invalid" }] })
    );
    assertThrows(() => eligible({ ...input, evaluated_at: "invalid" }));
});
Deno.test("one typed result shapes only host facts and public resource counters", async () => {
    const writes: unknown[] = [];
    const results = {
        "host_metrics-0": read("123.50 456.25\n0.20 1.30 2.40 1/800 37\n"),
        "horizon_metrics-0": read(
            "MemoryCurrent=1000\nCPUUsageNSec=2000\nTasksCurrent=3\nPrivateExtra=hidden\n",
        ),
    };
    const output = await behavior.methods.result.execute({
        intent: input,
        results,
    }, {
        writeResource: async (_spec, _name, value) => {
            writes.push(value);
        },
    });
    assertEquals(JSON.parse(output.stdout), {
        eligible: { activities: 0, batches: 0, failed: 0 },
        uptime_seconds: 123.5,
        load: [0.2, 1.3, 2.4],
        horizon_metrics: {
            memory_bytes: 1000,
            cpu_nanoseconds: 2000,
            tasks: 3,
        },
        unavailable: [],
    });
    assertEquals(writes, [output]);
    assertEquals(output.exitCode, 0);
});
Deno.test("failed malformed or nonzero native reads remain unavailable without leaking stderr", () => {
    for (
        const host_metrics of [
            read("invalid"),
            read("123 456\n0 0 0 0/1 1", 1),
            { status: "failed", outputs: { stderr: "private-host-error" } },
        ]
    ) {
        const result = compare(input, {
            "host_metrics-0": host_metrics,
            "horizon_metrics-0": read(
                "MemoryCurrent=[not set]\nCPUUsageNSec=18446744073709551615\n",
            ),
        });
        assertEquals(result.uptime_seconds, null);
        assertEquals(result.load, null);
        assertEquals(result.horizon_metrics, null);
        assertEquals(result.unavailable, ["host_metrics", "horizon_metrics"]);
    }
});
Deno.test("missing Horizon is not a fabricated zero and retention needs no host read", () => {
    const host_metrics = read("123 456\n0 0 0 0/1 1");
    assertEquals(
        compare({ ...input, horizon_unit: null }, {
            "host_metrics-0": host_metrics,
        })
            .horizon_metrics,
        null,
    );
    assertEquals(
        compare({ ...input, horizon_unit: null }, {
            "host_metrics-0": host_metrics,
        }).unavailable,
        [],
    );
    const result = compare({ ...input, routine: "retention" }, {});
    assertEquals(result.unavailable, []);
    assertEquals(result.horizon_metrics, null);
    assertEquals(result.uptime_seconds, null);
});
