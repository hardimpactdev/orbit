import { WEBSITE_PROJECT } from "./_support/dependency_projects.ts";
import { assert, assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { admitPackage, PackageTargetSchema, type PackageTarget } from "../extensions/behaviors/_lib/package_target.ts";
import { runStage, type Http, type Job, type StageArgs } from "../extensions/behaviors/_lib/gateway_stage.ts";
import { startFakeGateway } from "./_support/fake_gateway.ts";

const target: PackageTarget = { workItem: "tg-7", manager: "composer", package: "livewire/livewire", version: "4.9.9", purpose: "security", advisoryUrls: ["https://github.com/advisories/GHSA-fixture-one", "https://github.com/advisories/GHSA-fixture-two"], advisories: ["PKSA-xhv3-nr94-h8db", "PKSA-bgw4-5zmg-2njg"] };
function fixture() {
  const fake = startFakeGateway({ 7: { prStaysOpen: true }, 8: {} });
  const calls: { method: string; path: string; body?: Record<string, unknown>; key?: string }[] = [];
  const http: Http = async (method, path, body, key) => {
    calls.push({ method, path, body, key });
    const r = await fetch(fake.url + path, { method, headers: { "content-type": "application/json", ...(key ? { "Idempotency-Key": key } : {}) }, ...(body === undefined ? {} : { body: JSON.stringify(body) }) });
    return { status: r.status, body: await r.json() };
  };
  const targets: PackageTarget[] = [];
  const states = new Map<string, { stageId: string; status: string }>();
  let starts = 0;
  const admission = {
    http, targets, projects: [WEBSITE_PROJECT],
    runState: (item: string) => Promise.resolve(states.get(item) ?? null),
    save: (t: PackageTarget) => { if (!targets.some((x) => x.workItem === t.workItem)) targets.push(structuredClone(t)); return Promise.resolve(); },
    start: (item: string) => { starts++; states.set(item, { stageId: "provision", status: "active" }); return Promise.resolve(); },
  };
  const jobs = new Map<string, Job>();
  let now = Date.now();
  const args = (stage: StageArgs["stage"], extra = {}): StageArgs => ({ workItem: target.workItem, key: `${target.workItem}.${stage}.c1`, stage, routine: true, packageTarget: target, ...extra });
  const stageDeps = (key: string, transport = http) => ({ http: transport, projects: [WEBSITE_PROJECT], readJob: () => Promise.resolve(jobs.get(key) ?? null), writeJob: (j: Job) => { jobs.set(key, structuredClone(j)); return Promise.resolve(); }, now: () => now });
  return { fake, http, calls, admission, jobs, states, args, stageDeps, get starts() { return starts; }, tick: () => { now += 60000; } };
}

Deno.test("package admission is immutable and restart-safe; next package waits for merge, clean completion and VM cleanup", async () => {
  const f = fixture();
  try {
    await admitPackage(target, f.admission);
    await admitPackage(structuredClone(target), f.admission);
    assertEquals(f.starts, 1);
    await assertRejects(() => admitPackage({ ...target, package: "other/package" }, f.admission), Error, "immutable");
    const next = { ...target, workItem: "tg-8" };
    const first = f.fake.groups.get(7)!;
    for (const status of ["running", "waiting_for_review", "failed", "cancelled"]) {
      first.status = status;
      await assertRejects(() => admitPackage(next, f.admission), Error, "previous package");
    }
    first.status = "completed"; first.vm_power = "running";
    f.states.set("tg-7", { stageId: "completed", status: "terminal" });
    await assertRejects(() => admitPackage(next, f.admission));
    first.vm_power = "destroyed";
    Object.assign(first, { pr_url: "https://example.test/pull/1", watched_pr_state: "open" });
    await assertRejects(() => admitPackage(next, f.admission));
    first.watched_pr_state = "merged";
    await admitPackage(next, f.admission);
    assertEquals(f.starts, 2);
    assertEquals(f.calls.filter((c) => c.method !== "GET"), [], "admission never creates or alters a Gateway group");
  } finally { await f.fake.close(); }
});

Deno.test("package admission rejects a used VM, wrong project/ownership/children and all observed assistance causes", async () => {
  for (const patch of [{ taskable_id: 1 }, { vm_power: "running" }, { project_id: 46 }, { orchestration: "tick" }, ...["direction", "failure", "removal_failure", "observation"].map((kind) => ({ assistance_requested: true, assistance_kind: kind }))]) {
    const f = fixture();
    try {
      Object.assign(f.fake.groups.get(7)!, patch);
      await assertRejects(() => admitPackage(target, f.admission));
      assertEquals(f.starts, 0); assertEquals(f.admission.targets, []);
      assertEquals(f.calls.filter((c) => c.method !== "GET"), []);
    } finally { await f.fake.close(); }
  }
});

Deno.test("lost admission response between target save and factory start resumes the saved selection once", async () => {
  const f = fixture();
  try {
    await assertRejects(() => admitPackage(target, { ...f.admission, start: () => { throw new Error("interrupted before start"); } }));
    assertEquals(f.admission.targets.length, 1);
    await admitPackage(target, f.admission);
    await admitPackage(target, f.admission);
    assertEquals(f.starts, 1);
  } finally { await f.fake.close(); }
});

Deno.test("one package script replays a lost response with exact literal args and refuses changed target after restart", async () => {
  const f = fixture();
  try {
    const a = f.args("script");
    let lost = true;
    const http: Http = async (...input) => { const r = await f.http(...input); if (input[0] === "POST" && lost) { lost = false; return { status: 0, body: null }; } return r; };
    const deps = f.stageDeps(a.key, http);
    await runStage(a, deps);
    // Fresh memory reconstructed only from the persisted job.
    f.jobs.set(a.key, structuredClone(f.jobs.get(a.key)!));
    await assertRejects(() => runStage({ ...a, packageTarget: { ...target, version: "5.0.0" } }, deps), Error, "input conflict");
    await runStage(a, deps); f.tick();
    const result = await runStage(a, deps);
    assertEquals(result.state, "succeeded");
    assertEquals(f.fake.operations.size, 1);
    const posts = f.calls.filter((x) => x.method === "POST");
    assertEquals(posts.length, 2); assertEquals(posts[0], posts[1]);
    assertEquals(posts[0].body, { subtask: 701, script: "scripts/dependencies", args: ["package", "composer", "livewire/livewire", "4.9.9", "security", ...target.advisories] });
    assertEquals(posts[0].key, "tg-7.script.s701.c1");
  } finally { await f.fake.close(); }
});

Deno.test("package repair preserves the target and advisories with Gateway-shaped nullable defaults; no unrelated upgrade or second turn", async () => {
  const f = fixture();
  try {
    const script = { payload: { subtask: 701, outcome: "failed", exit_code: 2, output: "Composer advisory blocks resolution" }, recordedAt: "2026-10-25T20:00:00Z" };
    const a = f.args("repair", { script }); const deps = f.stageDeps(a.key);
    await runStage(a, deps); f.tick();
    const done = await runStage(a, deps); await runStage(a, deps);
    assertEquals(done.artifacts.change.outcome, "ready_for_review");
    const post = f.calls.find((x) => x.path.endsWith("/turn"))!;
    const prompt = String(post.body!.prompt);
    assert(prompt.includes("livewire/livewire")); assert(prompt.includes(target.advisories[0]));
    assert(prompt.includes("do not upgrade another direct package"));
    assert(!prompt.includes("rerun scripts/dependencies routine"));
    assertEquals(f.fake.startsOf(7, "repair"), 1);
    assertEquals(f.fake.startsOf(7, "publish"), 0);
  } finally { await f.fake.close(); }
});

Deno.test("single-package schema refuses several packages, shell/option inputs and a missing security identity", () => {
  for (const patch of [{ package: "livewire/livewire other/pkg" }, { package: "--force" }, { version: "latest" }, { advisories: [] }, { manager: "npm" }]) {
    assertEquals(PackageTargetSchema.safeParse({ ...target, ...patch }).success, false);
  }
});

Deno.test("post-repair script keeps prior changed-tree evidence without claiming the verification command itself changed files", async () => {
  const fake = startFakeGateway({ 7: { scriptNoChange: true } });
  let job: Job | null = null;
  let now = Date.now();
  const http: Http = async (method, path, body, key) => {
    const r = await fetch(fake.url + path, { method, headers: { "content-type": "application/json", ...(key ? { "Idempotency-Key": key } : {}) }, ...(body ? { body: JSON.stringify(body) } : {}) });
    return { status: r.status, body: await r.json() };
  };
  try {
    const args: StageArgs = { workItem: "tg-7", stage: "script", key: "tg-7.script.c2", routine: true, packageTarget: target,
      script: { payload: { subtask: 701, changed: true, tree: "c".repeat(40) }, recordedAt: "2026-10-25T20:00:00Z" } };
    const deps = { http, projects: [WEBSITE_PROJECT], readJob: () => Promise.resolve(job), writeJob: (j: Job) => { job = structuredClone(j); return Promise.resolve(); }, now: () => now };
    await runStage(args, deps); now += 60000;
    const done = await runStage(args, deps);
    assertEquals(done.artifacts.script.changed, true);
    assertEquals(done.artifacts.script.commandChanged, false);
    assertEquals(fake.operations.size, 1);
  } finally { await fake.close(); }
});

Deno.test("operator cancellation releases the sequence only after cleanup and the existing failure acknowledgement gate", async () => {
  const f = fixture();
  try {
    await admitPackage(target, f.admission);
    const g = f.fake.groups.get(7)!;
    g.status = "cancelled"; g.vm_power = "destroyed"; g.tasks[0].status = "cancelled";
    await assertRejects(() => admitPackage({ ...target, workItem: "tg-8" }, f.admission));
    f.states.set("tg-7", { stageId: "failed", status: "terminal" });
    await admitPackage({ ...target, workItem: "tg-8" }, f.admission);
    assertEquals(f.starts, 2);
    assertEquals(f.calls.filter((c) => c.method !== "GET"), []);
  } finally { await f.fake.close(); }
});
