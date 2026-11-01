import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { behavior } from "../extensions/behaviors/orbit_gateway.ts";
import { behavior as metadata } from "../extensions/behaviors/operations/project-maintenance/metadata.ts";
import { indexName } from "../extensions/behaviors/_lib/dependency_results_schema.ts";
import { projectFindings } from "../extensions/behaviors/_lib/project_schedule.ts";
import {
    fixture,
    original,
    reports,
    seedServicePlan,
} from "./_support/project_scheduler.ts";

Deno.test("Project routine selects a request from admitted time and groups without admitting work", async () => {
    const f = fixture();
    try {
        await behavior.methods.schedule_project.execute({
            project: original,
            groups: [],
            admitted_at: "2026-11-01T04:00:00Z",
        }, f.ctx);
        const result = JSON.parse(
            (f.data.get("orbit-gateway/routine-result") as {
                result_json: string;
            }).result_json,
        );
        assertEquals(result.key, "20-routine-2026-10-26");
        assertEquals(result.requests.length, 1);
        assertEquals(result.requests[0].input.project_id, 20);
        assertEquals(f.posts(), 0);
        assertEquals(f.starts(), 0);
        assertEquals(f.groups.size, 0);
    } finally {
        await f.close();
    }
});

Deno.test("Project routine respects admitted unfinished work and uncertain legacy creation", async () => {
    const f = fixture();
    try {
        const input = {
            project: original,
            groups: [{
                id: 10,
                project_id: 20,
                status: "running",
                brief: "other work",
                taskable_id: 745,
            }],
            admitted_at: "2026-11-01T04:00:00Z",
        };
        await behavior.methods.schedule_project.execute(input, f.ctx);
        const result = JSON.parse(
            (f.data.get("orbit-gateway/routine-result") as {
                result_json: string;
            }).result_json,
        );
        assertEquals(result.requests, []);
        assertEquals(result.blocked, [
            "project_has_unfinished_or_uncertain_work",
        ]);
        assertEquals(f.posts(), 0);
    } finally {
        await f.close();
    }
});

Deno.test("native scan admission binds the exact run before accepting its current head", async () => {
    const f = fixture();
    try {
        const findings = await seedServicePlan(
            f,
            projectFindings(
                original,
                "owned-scan",
                reports,
                reports.map(() => ({ schema: 1, targets: [], blocked: [] })),
            ),
        );
        await behavior.methods.assert_scan_head.execute({
            project: original,
            runId: findings.source,
            head: "a".repeat(40) + "\trefs/heads/main\n",
            scan_succeeded: true,
        }, f.ctx);
        await assertRejects(
            () =>
                behavior.methods.assert_scan_head.execute({
                    project: original,
                    runId: "different-scan",
                    head: "a".repeat(40) + "\trefs/heads/main\n",
                    scan_succeeded: true,
                }, f.ctx),
            Error,
            "fresh_service_detection_required",
        );
        assertEquals(f.posts(), 0);
        assertEquals(f.starts(), 0);
    } finally {
        await f.close();
    }
});

Deno.test("changed source head revokes the native plan before Gateway terminal admission", async () => {
    const f = fixture();
    try {
        await seedServicePlan(
            f,
            projectFindings(
                original,
                "owned-scan",
                reports,
                reports.map(() => ({ schema: 1, targets: [], blocked: [] })),
            ),
        );
        await assertRejects(
            () =>
                behavior.methods.assert_scan_head.execute({
                    project: original,
                    runId: "owned-scan",
                    head: "b".repeat(40) + "\trefs/heads/main\n",
                    scan_succeeded: true,
                }, f.ctx),
            Error,
            "source_head_changed_detect_again",
        );
        const index = f.data.get(
            "orbit-gateway/" + await indexName(20, "refs/heads/main"),
        ) as { last_attempt: { status: string; error_code: string } };
        assertEquals(index.last_attempt.status, "failed");
        assertEquals(index.last_attempt.error_code, "source_head_changed");
        assertEquals(f.posts(), 0);
    } finally {
        await f.close();
    }
});

Deno.test("native PR reads reject a different repository before exposing the vault credential", async () => {
    for (
        const url of [
            "https://github.com/nckrtl/other/pull/17",
            "https://other.invalid/nckrtl/launch/pull/17",
        ]
    ) {
        let writes = 0;
        await assertRejects(
            () =>
                metadata.methods.read.execute({
                    operation_id: 1,
                    node_id: 209,
                    kind: "project.detect",
                    intent: {
                        project: {
                            id: 32,
                            repository: "https://github.com/nckrtl/launch.git",
                            default_branch: "main",
                            check: "composer check",
                        },
                        groups: [{
                            id: 7,
                            project_id: 32,
                            pr_url: url,
                            watched_pr_url: url,
                        }],
                        admitted_at: "2026-11-01T04:00:00Z",
                    },
                }, {
                    queryData: () => Promise.resolve([]),
                    writeResource: () => {
                        writes++;
                        return Promise.resolve({});
                    },
                }),
            Error,
            "pr_identity_mismatch",
        );
        assertEquals(writes, 0);
    }
});

Deno.test("native schedule selects from full public intent and skips the recorded slot without Gateway calls", async () => {
    const f = fixture();
    try {
        let result: Record<string, unknown> = {};
        const ctx = {
            ...f.ctx,
            queryData: () => Promise.resolve([]),
            writeResource: async (_s: string, _n: string, v: unknown) => {
                result = v as Record<string, unknown>;
                return { name: "output" };
            },
        };
        const args = {
            revision: 1,
            snapshot_hash: "a".repeat(64),
            projects: [{
                id: 32,
                repository: "https://github.com/nckrtl/launch.git",
                default_branch: "main",
                check: "composer check",
            }],
            groups: [],
            operations: [],
            admissions: {},
        };
        await metadata.methods.schedule.execute(args, ctx);
        const first = JSON.parse(result.result_json as string);
        assertEquals(first.requests.length, 1);
        assertEquals(first.requests[0].mode, "detect");
        await metadata.methods.schedule.execute({
            ...args,
            admissions: { "32:detect": { key: first.requests[0].key } },
        }, ctx);
        const next = JSON.parse(result.result_json as string);
        assertEquals(next.requests.length, 1);
        assertEquals(next.requests[0].mode, "routine");
        await metadata.methods.schedule.execute({
            ...args,
            groups: [{
                id: 7,
                project_id: 32,
                status: "todo",
                maintenance: { admission_pending: true },
            }],
            admissions: {
                "32:detect": { key: first.requests[0].key },
                "32:routine": { key: next.requests[0].key },
            },
        }, ctx);
        assertEquals(
            JSON.parse(result.result_json as string).requests[0].mode,
            "observe",
        );
        await metadata.methods.schedule.execute({
            ...args,
            operations: [{ target_id: 32, status: "running" }],
        }, ctx);
        assertEquals(JSON.parse(result.result_json as string).requests, []);
        assertEquals(f.posts(), 0);
        assertEquals(f.starts(), 0);
    } finally {
        await f.close();
    }
});
