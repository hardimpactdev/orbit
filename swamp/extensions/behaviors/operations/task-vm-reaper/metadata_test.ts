import { assertEquals } from "jsr:@std/assert@1.0.19";
import { selectStuckVms } from "./metadata.ts";

const now = new Date("2026-11-01T12:00:00Z");
const vm = {
    task_vm_id: 17,
    group_id: 23 as number | null,
    group_title: "proof node.role_add app-dev@v1" as string | null,
    ssh: null as Record<string, unknown> | null,
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

const ssh = {
    control_node_id: 209,
    identity_file: "/fixture/identity",
    public_key: "ssh-ed25519 public",
    target: {
        name: "target",
        host: "192.0.2.17",
        user: "orbit",
        port: 22,
        host_keys: ["ssh-ed25519 public"],
    },
};
const compactInspections = (steps: unknown[]) => {
    const rows = steps as {
        status: string;
        outputs?: {
            exitCode?: number | null;
            signal?: string | null;
            stdout?: string;
            stderr?: string;
        };
    }[];
    return {
        inspect_statuses: rows.map((row) => row.status),
        inspect_exit_codes: rows.map((row) => row.outputs?.exitCode ?? null),
        inspect_signals: rows.map((row) => row.outputs?.signal ?? null),
        inspect_stdout: rows.map((row) => row.outputs?.stdout ?? ""),
        inspect_stderr: rows.map((row) => row.outputs?.stderr ?? ""),
    };
};
const topology = (patch: Partial<typeof vm> = {}, step: unknown = undefined) =>
    selectStuckVms({
        expectations_hash: "a".repeat(64),
        proof_project_id: 62,
        vms: [{
            ...vm,
            project_id: 62,
            group_title: "topology app-dev@v1 lane21",
            ssh,
            group_created_at: "2026-11-01T10:00:00Z",
            ...patch,
        }],
        ...compactInspections(step === undefined ? [] : [step]),
    }, now).requests;
const stat = (stdout: string, exitCode = 0, stderr = "") => ({
    status: "succeeded",
    outputs: { exitCode, stdout, stderr, signal: null },
});
const missing = stat(
    "",
    1,
    "stat: cannot statx '/var/lib/orbit/swamp-proof/last-run': No such file or directory",
);

Deno.test("persistent topology missing marker uses creation and expires at exactly sixty minutes", () => {
    assertEquals(
        topology({ group_created_at: "2026-11-01T11:00:01Z" }, missing),
        [],
    );
    assertEquals(
        topology({ group_created_at: "2026-11-01T11:00:00Z" }, missing)[0]
            .reason,
        "topology_idle",
    );
});
Deno.test("a fresh native run preserves an older topology until sixty minutes after that run", () => {
    assertEquals(
        topology({}, stat(String(Date.parse("2026-11-01T11:00:01Z") / 1000))),
        [],
    );
    assertEquals(
        topology({}, stat(String(Date.parse("2026-11-01T11:00:00Z") / 1000)))[0]
            .reason,
        "topology_idle",
    );
});
Deno.test("failed SSH, timeout, denied stat and malformed output never prove topology idle", () => {
    for (
        const step of [
            undefined,
            { status: "failed" },
            stat("", 255, "Permission denied (publickey)"),
            stat("", 124),
            stat("", 1, "Permission denied"),
            stat("not an epoch"),
        ]
    ) {
        assertEquals(topology({}, step), []);
    }
});
Deno.test("eight-hour cap needs no native read and wins even after a fresh run", () => {
    assertEquals(topology({ group_created_at: "2026-11-01T04:00:01Z" }), []);
    assertEquals(
        topology({ group_created_at: "2026-11-01T04:00:00Z", ssh: null })[0]
            .reason,
        "topology_max_age",
    );
    assertEquals(
        topology(
            { group_created_at: "2026-11-01T04:00:00Z" },
            stat(String(now.getTime() / 1000)),
        )[0].reason,
        "topology_max_age",
    );
});
Deno.test("topology title is special only inside the proof Project and unknown proof titles are not guessed", () => {
    assertEquals(
        topology({ project_id: 4, progress_at: "2026-11-01T11:00:00Z" })[0]
            .reason,
        "no_progress",
    );
    assertEquals(
        topology({
            group_title: "unrecognized",
            group_created_at: "2026-10-01T00:00:00Z",
        }),
        [],
    );
});
Deno.test("compact inspection results keep their planned VM order without unrelated step context", () => {
    const recorded = {
        ...vm,
        project_id: 62,
        group_title: "topology app-dev@v1 lane21",
        group_created_at: "2026-11-01T10:00:00Z",
        ssh,
    };
    const result = selectStuckVms({
        expectations_hash: "a".repeat(64),
        proof_project_id: 62,
        vms: [recorded, { ...recorded, task_vm_id: 18 }],
        ...compactInspections([stat(String(now.getTime() / 1000)), missing]),
    }, now);
    assertEquals(result.requests.map((request) => request.task_vm_id), [18]);
});
