import {
    assertEquals,
    assertMatch,
    assertRejects,
} from "jsr:@std/assert@1.0.19";
import { behavior as metadata } from "../extensions/behaviors/operations/project-maintenance/metadata.ts";
import { fixture, original } from "./_support/project_scheduler.ts";

const routineInput = {
    project: {
        id: original.projectId,
        repository: original.repository,
        default_branch: original.defaultBranch,
        check: original.check,
    },
    groups: [] as Record<string, unknown>[],
    admitted_at: "2026-11-01T04:00:00Z",
};
function routineFixture() {
    let saved: Record<string, unknown> = {};
    return {
        ctx: {
            queryData: () => Promise.resolve([]),
            writeResource: (
                _spec: string,
                name: string,
                value: Record<string, unknown>,
            ) => {
                saved = value;
                return Promise.resolve({ name });
            },
        },
        result: () =>
            saved.result as {
                key: string;
                requests: { input: { project_id: number; brief: string } }[];
                blocked: string[];
            },
    };
}

Deno.test("Project routine selects this week's request from the captured intent alone", async () => {
    const f = routineFixture();
    await metadata.methods.routine.execute(routineInput, f.ctx);
    assertEquals(f.result().key, "20-routine-2026-10-26");
    assertEquals(f.result().requests.length, 1);
    assertEquals(f.result().requests[0].input.project_id, 20);
    assertEquals(f.result().blocked, []);
});

Deno.test("Project routine leaves unfinished captured Project work blocked", async () => {
    for (
        const group of [
            { status: "running", taskable_id: null },
            { status: "completed", taskable_id: 745 },
        ]
    ) {
        const f = routineFixture();
        await metadata.methods.routine.execute({
            ...routineInput,
            groups: [{ id: 10, project_id: 20, brief: "other work", ...group }],
        }, f.ctx);
        assertEquals(f.result().requests, []);
        assertEquals(f.result().blocked, ["project_has_unfinished_work"]);
    }
});

Deno.test("Project routine asks again for its own group, which the Gateway reuses by brief", async () => {
    const first = routineFixture();
    await metadata.methods.routine.execute(routineInput, first.ctx);
    const retry = routineFixture();
    await metadata.methods.routine.execute({
        ...routineInput,
        groups: [{
            id: 11,
            project_id: 20,
            status: "running",
            brief: first.result().requests[0].input.brief,
            taskable_id: 745,
        }],
    }, retry.ctx);
    assertEquals(retry.result().requests, first.result().requests);
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

Deno.test("native schedule takes the Project policy from the data file and reads no model data for it", async () => {
    const f = fixture();
    try {
        const queries: string[] = [];
        const ctx = {
            ...f.ctx,
            queryData: (predicate: string) => {
                queries.push(predicate);
                return Promise.resolve([]);
            },
            writeResource: () => Promise.resolve({ name: "output" }),
        };
        await metadata.methods.schedule.execute({
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
        }, ctx);
        assertEquals(queries.filter((q) => q.startsWith("modelType")), []);
    } finally {
        await f.close();
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
        // A due routine comes first; the hourly inspection comes last, so it never starves the others.
        assertEquals(first.requests.length, 1);
        assertEquals(first.requests[0].mode, "routine");
        await metadata.methods.schedule.execute({
            ...args,
            admissions: { "32:routine": { key: first.requests[0].key } },
        }, ctx);
        const next = JSON.parse(result.result_json as string);
        assertEquals(next.requests.length, 1);
        assertEquals(next.requests[0].mode, "detect");
        assertMatch(
            next.requests[0].key,
            /^32-detect-\d{4}-\d{2}-\d{2}T\d{2}:00$/,
        );
        const pending = [{
            id: 7,
            project_id: 32,
            status: "todo",
            maintenance: { admission_pending: true },
        }];
        await metadata.methods.schedule.execute({
            ...args,
            groups: pending,
            admissions: { "32:routine": { key: first.requests[0].key } },
        }, ctx);
        assertEquals(
            JSON.parse(result.result_json as string).requests[0].mode,
            "observe",
        );
        await metadata.methods.schedule.execute({
            ...args,
            admissions: {
                "32:routine": { key: first.requests[0].key },
                "32:detect": { key: next.requests[0].key },
            },
        }, ctx);
        assertEquals(JSON.parse(result.result_json as string).requests, []);
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
