import { assertEquals } from "jsr:@std/assert@1.0.19";
import {
    AGE_CAP_SECONDS,
    decide,
    heartbeat,
    IDLE_SECONDS,
    lane,
    type ProofGroup,
} from "./reaper.ts";

const NOW = 1_800_000_000;
const group = (
    id: number,
    lane: string | null,
    extra: Partial<ProofGroup> = {},
): ProofGroup => ({
    id,
    title: lane === null
        ? "topology minimal@7 old"
        : `swamp-proof ${lane} minimal@8`,
    started_at: NOW - 600,
    running: true,
    ...extra,
});

Deno.test("an older open group of the same lane is cancelled, the newest is kept", () => {
    const groups = [
        group(10, "beast:minimal"),
        group(12, "beast:minimal", { running: false, started_at: null }),
        group(11, "shark:minimal"),
    ];
    const beats = new Map([[10, NOW], [11, NOW]]);
    assertEquals(decide(groups, beats, NOW), [
        { group_id: 10, reason: "superseded by group 12" },
    ]);
});

Deno.test("a heartbeat older than an hour cancels; an hour or less keeps the group", () => {
    const groups = [group(1, "a:minimal"), group(2, "b:minimal")];
    const beats = new Map([[1, NOW - IDLE_SECONDS - 1], [
        2,
        NOW - IDLE_SECONDS,
    ]]);
    assertEquals(decide(groups, beats, NOW), [
        { group_id: 1, reason: `idle for ${IDLE_SECONDS + 1} s` },
    ]);
});

Deno.test("a missing heartbeat counts from the recorded start; an unreadable one decides nothing", () => {
    const old = NOW - IDLE_SECONDS - 5;
    const groups = [
        group(1, null, { started_at: old }),
        group(2, null, { started_at: NOW - 60 }),
        group(3, null, { started_at: old }),
        group(4, null, { started_at: null }),
    ];
    const beats = new Map<number, number | "missing" | "unknown">([
        [1, "missing"],
        [2, "missing"],
        [3, "unknown"],
        [4, "missing"],
    ]);
    assertEquals(decide(groups, beats, NOW), [
        { group_id: 1, reason: `no heartbeat after ${IDLE_SECONDS + 5} s` },
    ]);
});

Deno.test("a group older than the age cap is cancelled unless a fresh heartbeat renewed it", () => {
    const old = NOW - AGE_CAP_SECONDS - 1;
    const groups = [
        // Group 2551's shape: an old title, no pinned key, so no running VM and no heartbeat.
        group(1, null, { running: false, started_at: old }),
        group(2, "a:minimal", { started_at: old }),
        group(3, "b:minimal", { started_at: old }),
        group(4, null, { running: false, started_at: NOW - 60 }),
    ];
    const beats = new Map<number, number | "missing" | "unknown">([
        [2, "unknown"],
        [3, NOW - 60],
    ]);
    assertEquals(decide(groups, beats, NOW), [
        {
            group_id: 1,
            reason: `older than ${AGE_CAP_SECONDS} s without a renewal`,
        },
        {
            group_id: 2,
            reason: `older than ${AGE_CAP_SECONDS} s without a renewal`,
        },
    ]);
});

Deno.test("heartbeat reads the stat exit code and mtime of each forEach step", () => {
    const steps = {
        "heartbeat-0": { outputs: { exitCode: 0, stdout: `${NOW}\n` } },
        "heartbeat-1": { outputs: { exitCode: 1, stdout: "" } },
        "heartbeat-2": { outputs: { exitCode: 255, stdout: "" } },
        "heartbeat-3": { status: "failed" },
    };
    assertEquals(
        [0, 1, 2, 3, 4].map((i) => heartbeat(steps, i)),
        [NOW, "missing", "unknown", "unknown", "unknown"],
    );
});

Deno.test("the lane is the host and session after the swamp-proof prefix", () => {
    assertEquals(
        [
            lane("swamp-proof beast:minimal minimal@8"),
            lane("topology minimal@7 wave3"),
            lane("swamp-proofx a:b"),
        ],
        ["beast:minimal", null, null],
    );
});
