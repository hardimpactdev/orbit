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
      if (method === "GET" && path === "/api/v1/task-groups?project_id=123") {
        return Promise.resolve({ status: 200, body: { data: groups } });
      }
      assertEquals(method, "POST");
      assertEquals(path, "/api/v1/task-groups");
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
    s.posts[0].tasks,
    plan.tasks.map(({ title, brief, deliverables }) => ({
      title,
      brief,
      deliverables,
    })),
  );
  await enqueue(plan, 123, project.repository, s.deps);
  assertEquals(s.posts.length, 1);
});
Deno.test("baseline is one task and repeated scans create no second baseline", async () => {
  const s = setup(), plan = baselinePlan(project);
  await enqueue(plan, 123, project.repository, s.deps);
  await enqueue(plan, 123, project.repository, s.deps);
  assertEquals(s.posts.length, 1);
  assertEquals((s.posts[0].tasks as unknown[]).length, 1);
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
