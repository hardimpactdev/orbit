import {
    assertEquals,
    assertMatch,
    assertRejects,
} from "jsr:@std/assert@1.0.19";
import { behavior as metadata } from "../extensions/behaviors/operations/project-maintenance/metadata.ts";
import projects from "../extensions/behaviors/operations/project-maintenance/projects.json" with {
    type: "json",
};
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

Deno.test("native schedule tick takes the Project policy from the data file and reads no model data and makes no Gateway call", async () => {
    const f = fixture();
    try {
        const queries: string[] = [];
        let result: Record<string, unknown> = {};
        const ctx = {
            ...f.ctx,
            queryData: (predicate: string) => {
                queries.push(predicate);
                return Promise.resolve([]);
            },
            writeResource: (_s: string, _n: string, v: unknown) => {
                result = v as Record<string, unknown>;
                return Promise.resolve({ name: "output" });
            },
        };
        await metadata.methods.schedule.execute({}, ctx);
        const tick = JSON.parse(result.result_json as string);
        assertEquals(queries, []);
        assertEquals(
            tick.projects.some((entry: { id: number }) =>
                entry.id === original.projectId
            ),
            true,
        );
        // The three request keys of the fire are the ones the Gateway validates by shape.
        const launch = tick.projects.find((entry: { id: number }) =>
            entry.id === original.projectId
        );
        assertMatch(launch.keys.routine, /^\d+-routine-\d{4}-\d{2}-\d{2}$/);
        assertMatch(
            launch.keys.detect,
            /^\d+-detect-\d{4}-\d{2}-\d{2}T\d{2}:00$/,
        );
        assertMatch(
            launch.keys.observe,
            /^\d+-observe-\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/,
        );
        // The Gateway compares these three with the Project it holds, so they are the ones of the source file.
        const source = projects.find((entry) =>
            entry.projectId === original.projectId
        )!;
        assertEquals(launch.default_branch, source.defaultBranch);
        assertEquals(launch.check, source.check);
        assertEquals(
            launch.repository,
            source.repository.replace(
                /^(?:git@github\.com:|https:\/\/github\.com\/)/,
                "github.com/",
            )
                .replace(/\.git$/, "").toLowerCase(),
        );
    } finally {
        await f.close();
    }
});

Deno.test("the maintenance workflow keeps its id, which is the key of its pause on serve, and puts no pause or input in its file", async () => {
    // Serve keeps a pause in a file named by the workflow id (.swamp/_control/schedule-pauses), apart from the trigger and its inputs.
    const text = await Deno.readTextFile(
        new URL(
            "../workflows/workflow-maintenance-projects.yaml",
            import.meta.url,
        ),
    );
    assertEquals(
        text.split("\n")[0],
        "id: a37dfa1b-1633-475f-90c7-dfd9c83008ca",
    );
    assertMatch(text, /^name: maintenance-projects$/m);
    assertMatch(text, /^trigger:\n {2}schedule: '40 17 \* \* \* \*'\n/m);
    assertEquals(/paus|resum|disabled|inputs:\s*\n\s+type/i.test(text), false);
});
