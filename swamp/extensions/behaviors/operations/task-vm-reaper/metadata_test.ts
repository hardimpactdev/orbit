import { assertEquals } from "jsr:@std/assert@1.0.19";
import { selectStuckVms } from "./metadata.ts";

const now = new Date("2026-11-01T12:00:00Z");
const vm = {
    task_vm_id: 17,
    group_id: 23 as number | null,
    provider_id: "tvm-17",
    name: "tvm-17",
    node_id: 42,
    host_node_id: 12,
    claimed_at: "2026-11-01T11:30:00Z" as string | null,
    group_live: true,
    progress_at: "2026-11-01T11:00:01Z",
    assistance_requested_at: null as string | null,
    project_id: 4,
    group_created_at: "2026-11-01T11:15:01Z" as string | null,
};
const select = (patch: Partial<typeof vm>) =>
    selectStuckVms({
        expectations_hash: "a".repeat(64),
        proof_project_id: 62,
        vms: [{ ...vm, ...patch }],
    }, now).requests;

Deno.test("progress is live until exactly sixty minutes without a recorded change", () => {
    assertEquals(select({}), []);
    assertEquals(
        select({ progress_at: "2026-11-01T11:00:00Z" })[0].reason,
        "no_progress",
    );
});
Deno.test("unanswered assistance expires at two hours even with later ordinary progress", () => {
    assertEquals(
        select({ assistance_requested_at: "2026-11-01T10:00:01Z" }),
        [],
    );
    assertEquals(
        select({ assistance_requested_at: "2026-11-01T10:00:00Z" })[0].reason,
        "assistance_unanswered",
    );
    assertEquals(select({ assistance_requested_at: null }), []);
});
Deno.test("a VM without a live group is selected without waiting for ordinary deadlines", () => {
    assertEquals(
        select({ group_id: null, group_live: false, group_created_at: null })[0]
            .reason,
        "no_live_group",
    );
});
Deno.test("the proof Project uses forty-five minutes since Task creation while ordinary progress remains fresh", () => {
    assertEquals(select({}), []);
    assertEquals(
        select({ project_id: 62, group_created_at: "2026-11-01T11:15:00Z" })[0]
            .reason,
        "proof_age",
    );
    assertEquals(
        select({ project_id: 5, group_created_at: "2026-11-01T10:00:00Z" }),
        [],
    );
});

Deno.test("selection keeps each exact identity and the published expectations hash", () => {
    const result = selectStuckVms({
        expectations_hash: "a".repeat(64),
        proof_project_id: 62,
        vms: [
            { ...vm, task_vm_id: 17, group_live: false },
            { ...vm, task_vm_id: 18, provider_id: "different-provider" },
        ],
    }, now);
    assertEquals(result.requests.map((request) => request.task_vm_id), [17]);
    assertEquals(JSON.parse(result.stdout).expectations_hash, "a".repeat(64));
});

Deno.test("warm pool VMs are excluded even without a live group or recent progress", () => {
    assertEquals(
        select({
            claimed_at: null,
            group_id: null,
            group_live: false,
            group_created_at: null,
            progress_at: "2026-10-01T00:00:00Z",
        }),
        [],
    );
});
Deno.test("an absent proof Project leaves ordinary age rules unchanged", () => {
    const result = selectStuckVms({
        expectations_hash: "a".repeat(64),
        proof_project_id: null,
        vms: [{ ...vm, group_created_at: "2026-10-01T00:00:00Z" }],
    }, now);
    assertEquals(result.requests, []);
});

Deno.test("the proof Project has only its age rule even when ordinary progress or assistance is stale", () => {
    assertEquals(
        select({
            project_id: 62,
            progress_at: "2026-10-01T00:00:00Z",
            assistance_requested_at: "2026-10-01T00:00:00Z",
        }),
        [],
    );
});
