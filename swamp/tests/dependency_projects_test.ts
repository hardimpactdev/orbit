import { impactRows, maintenanceFixture } from "./_support/maintenance_fixture.ts";
import { assert, assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { DependencyProjectsSchema } from "../extensions/behaviors/_lib/dependency_projects.ts";
import { runStage, type Http, type Job, type StageArgs } from "../extensions/behaviors/_lib/gateway_stage.ts";
import { SECOND_PROJECT, WEBSITE_PROJECT } from "./_support/dependency_projects.ts";
import { startFakeGateway } from "./_support/fake_gateway.ts";

function fixture() {
  const fake = startFakeGateway({ 7: { projectId: SECOND_PROJECT.projectId } });
  fake.projects.set(SECOND_PROJECT.projectId, { id: SECOND_PROJECT.projectId, repository_url: SECOND_PROJECT.repository, type: "laravel-app", task_check: SECOND_PROJECT.check, default_branch: SECOND_PROJECT.defaultBranch });
  const calls: { method: string; path: string; body?: Record<string, unknown>; key?: string }[] = [];
  let job: Job | null = null;
  const http: Http = async (method, path, body, key) => {
    calls.push({ method, path, body, key });
    const r = await fetch(fake.url + path, { method, headers: { "content-type": "application/json", ...(key ? { "Idempotency-Key": key } : {}) }, ...(body === undefined ? {} : { body: JSON.stringify(body) }) });
    return { status: r.status, body: await r.json() };
  };
  const args: StageArgs = { workItem: "tg-7", stage: "script", key: "tg-7.script.c1", routine: true };
  const worker = maintenanceFixture(fake);
  worker.prepare();
  const deps = { maintenance: worker.maintenance, http, projects: [WEBSITE_PROJECT, SECOND_PROJECT], readJob: () => Promise.resolve(job), writeJob: (value: Job) => { job = structuredClone(value); return Promise.resolve(); } };
  return { fake, worker, calls, args, deps, restart: () => { job = structuredClone(job); } };
}

Deno.test("dependency project config requires worker paths and rejects the retired script field", () => {
  assert(DependencyProjectsSchema.safeParse([WEBSITE_PROJECT, SECOND_PROJECT]).success);
  assertEquals(DependencyProjectsSchema.safeParse([WEBSITE_PROJECT, WEBSITE_PROJECT]).success, false);
  const { worker: _worker, ...missing } = WEBSITE_PROJECT;
  assertEquals(DependencyProjectsSchema.safeParse([missing]).success, false);
  assertEquals(DependencyProjectsSchema.safeParse([{ ...WEBSITE_PROJECT, script: "scripts/dependencies" }]).success, false);
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

Deno.test("a second project's worker request survives restart and refuses changed config under its key", async () => {
  const f = fixture();
  try {
    await runStage(f.args, f.deps);
    f.restart();
    await assertRejects(() => runStage(f.args, { ...f.deps, projects: [{ ...SECOND_PROJECT, worker: { ...SECOND_PROJECT.worker, jsPaths: [] } }] }), Error, "input conflict");
    const done = await runStage(f.args, f.deps);
    assertEquals(done.state, "succeeded");
    assertEquals(f.worker.calls[0], f.worker.calls[1]);
    assertEquals(f.worker.calls[0].args.dependencyProject, SECOND_PROJECT);
    assertEquals(f.fake.operations.size, 0);
  } finally { await f.fake.close(); }
});

Deno.test("second-project repair uses its worker without changing the ordinary check API", async () => {
  const f = fixture();
  try {
    await runStage({ ...f.args, stage: "repair", key: "tg-7.repair.c1", script: { payload: { subtask: 701, outcome: "failed", impactRows, output: "update failed" }, recordedAt: "2026-10-25T20:00:00Z" } }, f.deps);
    const repair = f.calls.find((c) => c.path.endsWith("/turn"))!;
    assert(String(repair.body!.prompt).includes("Swamp will rerun the extension"));
    assert(!String(repair.body!.prompt).includes("scripts/dependencies"));
    await runStage({ ...f.args, stage: "handoff", key: "tg-7.handoff.c1" }, { ...f.deps, readJob: () => Promise.resolve(null) });
    const check = f.calls.find((c) => c.path.endsWith("/check"))!;
    assertEquals(check.body, { subtask: 701, phase: "handoff" });
    assert(f.calls.some((c) => c.path === "/api/v1/projects/99"));
    assertEquals(f.fake.startsOf(7, "repair"), 1);
    assertEquals(f.fake.startsOf(7, "handoff"), 1);
  } finally { await f.fake.close(); }
});

Deno.test("worker configuration refuses escaping, duplicate and unconfigured policy paths", () => {
  const worker = { composerPaths: ["."], jsPaths: [], policy: { ".": { minimumReleaseAgeDays: 7 } } };
  assert(DependencyProjectsSchema.safeParse([{ ...WEBSITE_PROJECT, worker }]).success);
  for (const patch of [{ composerPaths: [] }, { composerPaths: ["../other"] }, { composerPaths: ["/host"] }, { composerPaths: [".", "."] }, { policy: { "unknown": { minimumReleaseAgeDays: 7 } } }]) {
    assertEquals(DependencyProjectsSchema.safeParse([{ ...WEBSITE_PROJECT, worker: { ...worker, ...patch } }]).success, false);
  }
});
