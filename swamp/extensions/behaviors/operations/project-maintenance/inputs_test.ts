import {
    assertEquals,
    assertRejects,
    assertStringIncludes,
} from "jsr:@std/assert@1.0.19";
import { createModelTestContext } from "jsr:@swamp-club/swamp-testing@0.20261016.58";
import { behavior } from "./metadata.ts";
import projects from "./projects.json" with { type: "json" };
import { scheduleKey } from "../../_lib/project_schedule.ts";

const project = {
    id: 32,
    repository: "https://github.com/nckrtl/launch.git",
    default_branch: "main",
    check: "composer check",
};
const intent = () => ({
    project: { ...project },
    groups: [] as Record<string, unknown>[],
    admitted_at: "2026-11-01T04:00:00Z",
});

Deno.test("routine names a Project without a maintenance policy", async () => {
    const { context, getWrittenResources } = createModelTestContext();
    await assertRejects(
        () =>
            behavior.methods.routine.execute({
                ...intent(),
                project: {
                    ...project,
                    id: 999,
                    repository: "https://github.com/example/unknown.git",
                },
            }, { ...context, queryData: () => Promise.resolve([]) }),
        Error,
        "project.maintenance_not_configured",
    );
    assertEquals(getWrittenResources(), []);
});

Deno.test("Orbit routine reads its policy and ignores old PR names in ended groups", async () => {
    const { context, getWrittenResources } = createModelTestContext();
    await behavior.methods.routine.execute({
        ...intent(),
        project: {
            ...project,
            id: 46,
            repository: "https://github.com/hardimpactdev/orbit.git",
        },
        groups: [{
            id: 1,
            project_id: 46,
            status: "completed",
            pr_url: "https://github.com/nckrtl/orbit/pull/1025",
            watched_pr_url: null,
        }, {
            id: 2,
            project_id: 46,
            status: "backlog",
            pr_url: "https://github.com/hardimpactdev/orbit/pull/2450",
            watched_pr_url: null,
        }],
    }, { ...context, queryData: () => Promise.resolve([]) });
    assertEquals(
        (getWrittenResources()[0].data.result as { project_id: number })
            .project_id,
        46,
    );
});

Deno.test("routine keeps an unfinished group blocked without reading its PR", async () => {
    const { context, getWrittenResources } = createModelTestContext();
    await behavior.methods.routine.execute({
        ...intent(),
        groups: [{
            id: 1,
            project_id: 32,
            status: "running",
            pr_url: "https://github.com/someone/else/pull/1",
            watched_pr_url: null,
        }],
    }, { ...context, queryData: () => Promise.resolve([]) });
    assertEquals(
        (getWrittenResources()[0].data.result as { requests: unknown[] })
            .requests,
        [],
    );
});

Deno.test("routine rejects a Project mismatch before downstream requests", async () => {
    const { context, getWrittenResources } = createModelTestContext();
    await assertRejects(
        () =>
            behavior.methods.routine.execute({
                ...intent(),
                project: {
                    ...project,
                    check: "a different check",
                },
            }, { ...context, queryData: () => Promise.resolve([]) }),
        Error,
        "project_settings_mismatch",
    );
    assertEquals(getWrittenResources(), []);
});

Deno.test("observe returns public evidence without Gateway calls", async () => {
    const { context, getWrittenResources } = createModelTestContext();
    await behavior.methods.evidence.execute({
        project,
        groups: [],
        urls: [],
        previous_pr: [],
        readings: {},
    }, { ...context, queryData: () => Promise.resolve([]) });
    assertEquals(getWrittenResources()[0].data.result, {
        project_id: 32,
        observations: [],
        resume_group_ids: [],
        requests: [],
    });
});

Deno.test("routine returns the captured Project's weekly backlog request", async () => {
    const { context, getWrittenResources } = createModelTestContext();
    await behavior.methods.routine.execute(intent(), {
        ...context,
        queryData: () => Promise.resolve([]),
    });
    const result = getWrittenResources()[0].data.result as {
        project_id: number;
        key: string;
        requests: {
            action: string;
            input: { project_id: number; status: string };
        }[];
    };
    assertEquals(result.project_id, 32);
    assertStringIncludes(result.key, "32-routine-2026-10-26");
    assertEquals(result.requests.length, 1);
    assertEquals(result.requests[0].action, "create_group");
    assertEquals(result.requests[0].input.project_id, 32);
    assertEquals(result.requests[0].input.status, "backlog");
});

Deno.test("observe maps a bounded PR reading to its captured group", async () => {
    const { context, getWrittenResources } = createModelTestContext();
    const url = "https://github.com/nckrtl/launch/pull/17";
    await behavior.methods.evidence.execute({
        project,
        groups: [{ id: 55, project_id: 32, status: "running", pr_url: url }],
        urls: [url],
        previous_pr: [],
        readings: {
            "pr-0": {
                status: "succeeded",
                outputs: {
                    stdout: JSON.stringify({
                        number: 17,
                        url,
                        state: "MERGED",
                        headRefOid: "a".repeat(40),
                    }),
                },
            },
        },
    }, { ...context, queryData: () => Promise.resolve([]) });
    assertEquals(getWrittenResources()[0].data.result, {
        project_id: 32,
        observations: [{
            group_id: 55,
            url,
            state: "merged",
            head: "a".repeat(40),
        }],
        resume_group_ids: [],
        requests: [],
    });
});

Deno.test("the tick lists every configured Project with its identity and the request keys of the fire, and reads no Gateway state", async () => {
    const { context, getWrittenResources } = createModelTestContext();
    const before = new Date();
    // No queryData is given: a tick that read data would fail here.
    await behavior.methods.schedule.execute({}, context);
    const after = new Date();
    const result = JSON.parse(
        getWrittenResources()[0].data.result_json as string,
    ) as {
        projects: {
            id: number;
            repository: string;
            default_branch: string;
            check: string;
            keys: Record<string, string>;
        }[];
        skipped: unknown[];
    };
    assertEquals(result.skipped, []);
    assertEquals(
        result.projects.map((entry) => entry.id),
        projects.map((entry) => entry.projectId),
    );
    const observe = (id: number, at: Date) =>
        `${id}-observe-${at.toISOString().slice(0, 16)}`;
    for (const entry of result.projects) {
        assertEquals(
            /^github\.com\/[a-z0-9_.-]+\/[a-z0-9_.-]+$/.test(entry.repository),
            true,
        );
        assertEquals(entry.default_branch, "main");
        assertEquals(Object.keys(entry.keys).sort(), [
            "detect",
            "observe",
            "routine",
        ]);
        for (const [mode, key] of Object.entries(entry.keys)) {
            const expected = new Set(
                [before, after].map((at) =>
                    mode === "observe" ? observe(entry.id, at) : scheduleKey(
                        entry.id,
                        mode as "routine" | "detect",
                        at,
                    )
                ),
            );
            assertEquals(expected.has(key), true, `${mode} key ${key}`);
        }
    }
});

Deno.test("the request keys use the formats that the Gateway validates", () => {
    // The Gateway admits a request only when its key has these shapes (ScheduledProjectMaintenanceTest pins the same examples).
    const wednesday = new Date("2026-11-04T10:30:45Z");
    assertEquals(
        scheduleKey(32, "routine", wednesday),
        "32-routine-2026-11-02",
    );
    assertEquals(
        scheduleKey(32, "detect", wednesday),
        "32-detect-2026-11-04T10:00",
    );
    assertEquals(
        `32-observe-${wednesday.toISOString().slice(0, 16)}`,
        "32-observe-2026-11-04T10:30",
    );
    assertEquals(
        scheduleKey(32, "routine", new Date("2026-11-08T23:59:59Z")),
        "32-routine-2026-11-02",
    );
    assertEquals(
        scheduleKey(32, "routine", new Date("2026-11-09T00:00:00Z")),
        "32-routine-2026-11-09",
    );
});
