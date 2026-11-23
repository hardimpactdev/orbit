import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { enqueue, type Queue, type Receipt } from "./_lib/queue.ts";
import { baselinePlan, taskPlan } from "./_lib/tasks.ts";
import { project } from "./config.ts";

function setup() {
    const groups: Record<string, unknown>[] = [];
    let receipt: Receipt | null = null;
    const posts: Record<string, unknown>[] = [];
    const state = {
        repo: "https://github.com/nckrtl/horizon-new-dawn.git",
        review: false,
        loseReply: false,
        dropCreate: false,
    };
    const deps: Queue = {
        read: () => Promise.resolve(receipt),
        save: (value) => {
            receipt = value;
            return Promise.resolve();
        },
        http: (method, path, body) => {
            if (path === "/api/v1/projects/123") {
                return Promise.resolve({
                    status: 200,
                    body: {
                        data: {
                            id: 123,
                            repository_url: state.repo,
                            review_and_merge: state.review,
                        },
                    },
                });
            }
            // The Gateway pages the list newest first; this one serves two groups a page.
            const listed = path.match(
                /^\/api\/v1\/tasks\?project_id=123&limit=100(?:&cursor=(\d+))?$/,
            );
            if (method === "GET" && listed) {
                const rows = [...groups].sort((a, b) =>
                    Number(b.id) - Number(a.id)
                ).filter((g) =>
                    listed[1] === undefined || Number(g.id) < Number(listed[1])
                );
                const page = rows.slice(0, 2);
                return Promise.resolve({
                    status: 200,
                    body: {
                        data: page,
                        meta: {
                            next_cursor: rows.length > 2
                                ? String(page[page.length - 1].id)
                                : null,
                        },
                    },
                });
            }
            assertEquals(method, "POST");
            assertEquals(path, "/api/v1/tasks");
            posts.push(body!);
            const group = { ...body, id: 456 };
            if (!state.dropCreate) groups.push(group);
            if (state.loseReply) return Promise.reject(new Error("lost reply"));
            return Promise.resolve({ status: 201, body: { data: group } });
        },
    };
    return { deps, groups, posts, state };
}
const detection = {
    schema: 1 as const,
    synced_tag: "v5.49.0",
    tag: "v5.50.0",
    candidates: [
        {
            kind: "feature" as const,
            tag: "v5.50.0",
            surface: "screen" as const,
            change: "modify" as const,
            id: "resources/js/screens/recentJobs/job-row.vue",
        },
        {
            kind: "feature" as const,
            tag: "v5.50.0",
            surface: "screen" as const,
            change: "modify" as const,
            id: "resources/js/screens/recentJobs/job.vue",
        },
    ],
};
Deno.test("one candidate becomes one ordered backlog task through the Gateway, never a live start", async () => {
    const s = setup(), plan = taskPlan(detection, project);
    const result = await enqueue(plan, 123, project.repository, s.deps);
    assertEquals(result?.groupId, 456);
    assertEquals(s.posts.length, 1);
    assertEquals(s.posts[0].orchestration, "external");
    assertEquals(s.posts[0].status, "backlog");
    assertEquals(
        s.posts[0].subtasks,
        plan.tasks.map(({ title, brief, deliverables }) => ({
            title,
            brief,
            deliverables,
        })),
    );
    await enqueue(plan, 123, project.repository, s.deps);
    assertEquals(s.posts.length, 1);
});
// The Gateway starts this payload: apps/gateway/tests/Feature/Tasks/TaskWithoutSubtaskTest.php creates it, moves it to todo and
// starts its copy.
const baselineFixture = new URL(
    "./fixtures/baseline-task.json",
    import.meta.url,
);
Deno.test("baseline is one task with no subtask and repeated scans create no second baseline", async () => {
    const s = setup(), plan = baselinePlan(project);
    await enqueue(plan, 123, project.repository, s.deps);
    await enqueue(plan, 123, project.repository, s.deps);
    assertEquals(s.posts.length, 1);
    // The Gateway refuses a task with one subtask: the task holds the baseline's brief and deliverables itself.
    assertEquals(s.posts[0].subtasks, undefined);
    assertEquals(s.posts[0].deliverables, plan.tasks[0].deliverables);
    assertEquals(s.posts[0].brief, plan.tasks[0].brief);
    assertEquals(
        s.posts[0],
        JSON.parse(await Deno.readTextFile(baselineFixture)),
    );
});
Deno.test("a tag with one candidate is one task with no subtask", async () => {
    const s = setup(),
        plan = taskPlan({
            ...detection,
            candidates: detection.candidates.slice(0, 1),
        }, project);
    await enqueue(plan, 123, project.repository, s.deps);
    assertEquals(s.posts[0].subtasks, undefined);
    assertEquals(s.posts[0].deliverables, plan.tasks[0].deliverables);
    assertEquals(s.posts[0].brief, plan.tasks[0].brief);
});
Deno.test("a one-task plan whose brief is at the Gateway limit posts a brief within it", async () => {
    const s = setup(), plan = baselinePlan(project);
    plan.tasks[0].brief = "x".repeat(8000);
    await enqueue(plan, 123, project.repository, s.deps);
    assertEquals(String(s.posts[0].brief).length, 8000);
});
Deno.test("queued one-task work keeps its identity: a receipt or a group from before tasks with no subtask is adopted", async () => {
    for (
        const plan of [
            baselinePlan(project),
            taskPlan({
                ...detection,
                candidates: detection.candidates.slice(0, 1),
            }, project),
        ]
    ) {
        const key = plan.baseline
            ? "baseline"
            : `${plan.synced_tag}-${plan.tag}`;
        const brief = `Swamp Horizon sync: ${project.repository}:${key}\n\n${
            plan.baseline
                ? "Record the one-time parity baseline before upstream detection."
                : `Implement every candidate from laravel/horizon ${plan.synced_tag} to ${plan.tag} in order. Only the last task advances synced_tag.`
        }\n\nPull requests wait for Nick. Never merge.`;
        for (const state of ["created", "uncertain"] as const) {
            const s = setup();
            if (state === "uncertain") {
                s.groups.push({
                    id: 456,
                    project_id: 123,
                    orchestration: "external",
                    brief,
                });
            }
            await s.deps.save({
                key,
                projectId: 123,
                brief,
                state,
                groupId: state === "created" ? 456 : null,
            });
            assertEquals(
                (await enqueue(plan, 123, project.repository, s.deps))?.groupId,
                456,
            );
            assertEquals(s.posts.length, 0);
        }
        // No receipt, and the group the earlier shape created: adopted, not posted again.
        const s = setup();
        s.groups.push({
            id: 456,
            project_id: 123,
            orchestration: "external",
            brief,
        });
        assertEquals(
            (await enqueue(plan, 123, project.repository, s.deps))?.groupId,
            456,
        );
        assertEquals(s.posts.length, 0);
    }
});
Deno.test("lost create reply is adopted by exact brief and never posted twice", async () => {
    const s = setup(), plan = taskPlan(detection, project);
    s.state.loseReply = true;
    await assertRejects(
        () => enqueue(plan, 123, project.repository, s.deps),
        Error,
        "lost reply",
    );
    assertEquals(
        (await enqueue(plan, 123, project.repository, s.deps))?.groupId,
        456,
    );
    assertEquals(s.posts.length, 1);
});
Deno.test("a lost create reply is adopted from a later page of the task list", async () => {
    const s = setup(), plan = taskPlan(detection, project);
    s.state.loseReply = true;
    await assertRejects(
        () => enqueue(plan, 123, project.repository, s.deps),
        Error,
        "lost reply",
    );
    s.groups.unshift({ id: 900 }, { id: 901 }, { id: 902 });
    assertEquals(
        (await enqueue(plan, 123, project.repository, s.deps))?.groupId,
        456,
    );
    assertEquals(s.posts.length, 1);
});
Deno.test("uncertain absent creates stop for reconciliation; duplicates also stop", async () => {
    const s = setup(), plan = taskPlan(detection, project);
    s.state.dropCreate = true;
    s.state.loseReply = true;
    await assertRejects(() => enqueue(plan, 123, project.repository, s.deps));
    await assertRejects(
        () => enqueue(plan, 123, project.repository, s.deps),
        Error,
        "uncertain",
    );
    assertEquals(s.posts.length, 1);
    s.groups.push({ ...s.posts[0], id: 1 }, { ...s.posts[0], id: 2 });
    await assertRejects(
        () => enqueue(plan, 123, project.repository, s.deps),
        Error,
        "Duplicate",
    );
});
Deno.test("website Project and automatic merge settings cannot receive parity tasks", async () => {
    const s = setup(), plan = taskPlan(detection, project);
    s.state.repo = "https://github.com/nckrtl/horizon-new-dawn-website.git";
    await assertRejects(
        () => enqueue(plan, 123, project.repository, s.deps),
        Error,
        "match the package",
    );
    s.state.repo = "git@github.com:nckrtl/horizon-new-dawn.git";
    s.state.review = true;
    await assertRejects(
        () => enqueue(plan, 123, project.repository, s.deps),
        Error,
        "wait for Nick",
    );
    assertEquals(s.posts, []);
});
Deno.test("current upstream creates no group and does not call the Gateway", async () => {
    const plan = taskPlan({
        schema: 1,
        synced_tag: "v5.50.1",
        tag: null,
        candidates: [],
    }, project);
    const s = setup();
    s.deps.http = () => {
        throw new Error("must not call");
    };
    assertEquals(await enqueue(plan, 123, project.repository, s.deps), null);
});
