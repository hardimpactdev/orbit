import { assert, assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { DependencyProjectsSchema } from "../extensions/behaviors/_lib/dependency_projects.ts";
import { runStage, type Http, type Job, type StageArgs } from "../extensions/behaviors/_lib/gateway_stage.ts";
import { SECOND_PROJECT, WEBSITE_PROJECT } from "./_support/dependency_projects.ts";
import { startFakeGateway } from "./_support/fake_gateway.ts";

function fixture() {
  const fake = startFakeGateway({ 7: { projectId: SECOND_PROJECT.projectId } });
  fake.projects.set(SECOND_PROJECT.projectId, { id: SECOND_PROJECT.projectId, repository_url: SECOND_PROJECT.repository, task_check: SECOND_PROJECT.check, default_branch: SECOND_PROJECT.defaultBranch });
  const calls: { method: string; path: string; body?: Record<string, unknown>; key?: string }[] = [];
  let job: Job | null = null;
  const http: Http = async (method, path, body, key) => {
    calls.push({ method, path, body, key });
    const r = await fetch(fake.url + path, { method, headers: { "content-type": "application/json", ...(key ? { "Idempotency-Key": key } : {}) }, ...(body === undefined ? {} : { body: JSON.stringify(body) }) });
    return { status: r.status, body: await r.json() };
  };
  const args: StageArgs = { workItem: "tg-7", stage: "script", key: "tg-7.script.c1", routine: true };
  const deps = { http, projects: [WEBSITE_PROJECT, SECOND_PROJECT], readJob: () => Promise.resolve(job), writeJob: (value: Job) => { job = structuredClone(value); return Promise.resolve(); } };
  return { fake, calls, args, deps, restart: () => { job = structuredClone(job); } };
}

Deno.test("dependency project config refuses duplicate IDs and script paths outside the repository", () => {
  assert(DependencyProjectsSchema.safeParse([WEBSITE_PROJECT, SECOND_PROJECT]).success);
  assertEquals(DependencyProjectsSchema.safeParse([WEBSITE_PROJECT, WEBSITE_PROJECT]).success, false);
  assert(DependencyProjectsSchema.safeParse([{ ...SECOND_PROJECT, script: "dependencies.php" }]).success);
  for (const script of ["/tmp/run", "../run", "scripts/../run", "scripts/run;bad", "--script"])
    assertEquals(DependencyProjectsSchema.safeParse([{ ...SECOND_PROJECT, script }]).success, false);
});

Deno.test("unknown projects and each mismatched Gateway setting refuse dispatch on repeated restarted calls", async () => {
  for (const patch of [{ id: 20 }, { repository_url: WEBSITE_PROJECT.repository }, { task_check: WEBSITE_PROJECT.check }, { default_branch: WEBSITE_PROJECT.defaultBranch }, null]) {
    const f = fixture();
    try {
      if (patch) Object.assign(f.fake.projects.get(99)!, patch);
      else f.deps.projects = [WEBSITE_PROJECT];
      for (let i = 0; i < 2; i++) {
        f.restart();
        await assertRejects(() => runStage(f.args, f.deps), Error, patch ? "settings differ" : "not configured");
      }
      assertEquals(f.calls.filter((c) => c.method !== "GET"), []);
      assertEquals(f.fake.operations.size, 0);
    } finally { await f.fake.close(); }
  }
});

Deno.test("a second project's saved literal script request survives lost response and refuses changed config under its key", async () => {
  const f = fixture();
  try {
    let lost = true;
    const http: Http = async (...input) => {
      const r = await f.deps.http(...input);
      if (input[0] === "POST" && lost) { lost = false; return { status: 0, body: null }; }
      return r;
    };
    const deps = { ...f.deps, http };
    await runStage(f.args, deps);
    f.restart();
    await assertRejects(() => runStage(f.args, { ...deps, projects: [{ ...SECOND_PROJECT, script: "scripts/another" }] }), Error, "input conflict");
    await runStage(f.args, deps);
    const posts = f.calls.filter((c) => c.method === "POST");
    assertEquals(posts.length, 2);
    assertEquals(posts[0], posts[1]);
    assertEquals(posts[0].body, { subtask: 701, script: SECOND_PROJECT.script, args: ["routine"] });
    assertEquals(f.fake.operations.size, 1);
  } finally { await f.fake.close(); }
});

Deno.test("second-project repair names its configured script without changing the ordinary check API", async () => {
  const f = fixture();
  try {
    await runStage({ ...f.args, stage: "repair", key: "tg-7.repair.c1", script: { payload: { subtask: 701, outcome: "failed", output: "update failed" }, recordedAt: "2026-10-25T20:00:00Z" } }, f.deps);
    const repair = f.calls.find((c) => c.path.endsWith("/turn"))!;
    assert(String(repair.body!.prompt).includes(`rerun ${SECOND_PROJECT.script} routine`));
    assert(!String(repair.body!.prompt).includes("scripts/dependencies"));
    await runStage({ ...f.args, stage: "handoff", key: "tg-7.handoff.c1" }, { ...f.deps, readJob: () => Promise.resolve(null) });
    const check = f.calls.find((c) => c.path.endsWith("/check"))!;
    assertEquals(check.body, { subtask: 701, phase: "handoff" });
    assert(f.calls.some((c) => c.path === "/api/v1/projects/99"));
    assertEquals(f.fake.startsOf(7, "repair"), 1);
    assertEquals(f.fake.startsOf(7, "handoff"), 1);
  } finally { await f.fake.close(); }
});
