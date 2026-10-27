import { maintenanceFixture } from "./_support/maintenance_fixture.ts";
import { WEBSITE_PROJECT } from "./_support/dependency_projects.ts";
import { assert, assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { admitPackage, PackageTargetSchema, type PackageTarget } from "../extensions/behaviors/_lib/package_target.ts";
import { runStage, type Http, type Job, type StageArgs } from "../extensions/behaviors/_lib/gateway_stage.ts";
import { startFakeGateway } from "./_support/fake_gateway.ts";

const target: PackageTarget = { workItem: "tg-7", targets: [{ path:".", manager: "composer", package: "livewire/livewire", version: "4.9.9", purpose: "security", advisoryUrls: ["https://github.com/advisories/GHSA-fixture-one", "https://github.com/advisories/GHSA-fixture-two"], advisories: ["PKSA-xhv3-nr94-h8db", "PKSA-bgw4-5zmg-2njg"] }] };
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
  const worker = maintenanceFixture(fake);
  const jobs = new Map<string, Job>();
  let now = Date.now();
  const args = (stage: StageArgs["stage"], extra = {}): StageArgs => ({ workItem: target.workItem, key: `${target.workItem}.${stage}.c1`, stage, routine: true, packageTarget: target, ...extra });
  const stageDeps = (key: string, transport = http) => { worker.prepare(); return ({ maintenance: worker.maintenance, http: transport, projects: [WEBSITE_PROJECT], readJob: () => Promise.resolve(jobs.get(key) ?? null), writeJob: (j: Job) => { jobs.set(key, structuredClone(j)); return Promise.resolve(); }, now: () => now }); };
  return { fake, worker, http, calls, admission, jobs, states, args, stageDeps, get starts() { return starts; }, tick: () => { now += 60000; } };
}

Deno.test("package admission is immutable and restart-safe; next package waits for merge, clean completion and VM cleanup", async () => {
  const f = fixture();
  try {
    await admitPackage(target, f.admission);
    await admitPackage(structuredClone(target), f.admission);
    assertEquals(f.starts, 1);
    await assertRejects(() => admitPackage({ ...target, targets:[{...target.targets[0],package:"other/package"}] }, f.admission), Error, "immutable");
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

Deno.test("package worker request survives restart and refuses a changed target", async () => {
  const f = fixture();
  try {
    const args = f.args("script");
    const deps = f.stageDeps(args.key);
    await runStage(args, deps);
    f.jobs.set(args.key, structuredClone(f.jobs.get(args.key)!));
    await assertRejects(() => runStage({ ...args, packageTarget: { ...target, targets:[{...target.targets[0],version:"5.0.0"}] } }, deps), Error, "input conflict");
    const done = await runStage(args, deps);
    assertEquals(done.state, "succeeded");
    assertEquals(f.worker.calls[0], f.worker.calls[1]);
    assertEquals(f.worker.calls[0].args.packageTarget, target);
    assertEquals(f.worker.calls[0].request.mode, "package");
    assertEquals(f.fake.operations.size, 0);
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
    assert(prompt.includes("livewire/livewire")); assert(prompt.includes(target.targets[0].advisories[0]));
    assert(prompt.includes("Swamp reruns this exact batch"));
    assert(!prompt.includes("rerun scripts/dependencies routine"));
    assertEquals(f.fake.startsOf(7, "repair"), 1);
    assertEquals(f.fake.startsOf(7, "publish"), 0);
  } finally { await f.fake.close(); }
});

Deno.test("single-package schema refuses several packages, shell/option inputs and a missing security identity", () => {
  for (const patch of [{ package: "livewire/livewire other/pkg" }, { package: "--force" }, { version: "latest" }, { advisories: [] }, { manager: "npm" }]) {
    assertEquals(PackageTargetSchema.safeParse({ ...target, targets:[{...target.targets[0],...patch}] }).success, false);
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
    const worker = maintenanceFixture(fake);
    worker.prepare();
    const deps = { maintenance: worker.maintenance, http, projects: [WEBSITE_PROJECT], readJob: () => Promise.resolve(job), writeJob: (j: Job) => { job = structuredClone(j); return Promise.resolve(); }, now: () => now };
    await runStage(args, deps); now += 60000;
    const done = await runStage(args, deps);
    assertEquals(done.artifacts.script.changed, true);
    assertEquals(done.artifacts.script.commandChanged, false);
    assertEquals(fake.operations.size, 0);
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

Deno.test("package handoff leaves the child open until the summary succeeds", async () => {
  const f = fixture();
  try {
    const tree = "b".repeat(40);
    f.fake.groups.get(7)!.tasks[0].status = "running";
    const script = { payload: { subtask: 701, outcome: "passed", changed: true, tree }, recordedAt: "2026-10-25T20:00:00Z" };
    const handoffArgs = f.args("handoff", { script });
    await runStage(handoffArgs, f.stageDeps(handoffArgs.key));
    f.tick();
    const handed = await runStage(handoffArgs, f.stageDeps(handoffArgs.key));
    assertEquals(handed.artifacts.handoff.outcome, "passed");
    assertEquals(f.fake.groups.get(7)!.tasks[0].status, "running");
    const publishArgs = f.args("publish", {
      handoff: { payload: handed.artifacts.handoff, recordedAt: "2026-10-25T20:00:00Z" },
      workspace: { payload: { startingCommit: "c".repeat(40) }, recordedAt: "2026-10-25T20:00:00Z" },
    });
    const started = await runStage(publishArgs, f.stageDeps(publishArgs.key));
    assertEquals(started.state, "running");
    assertEquals(f.fake.groups.get(7)!.tasks[0].status, "running");
    f.tick();
    const publishing = await runStage(publishArgs, f.stageDeps(publishArgs.key));
    assertEquals(publishing.state, "running");
    const completedAt = f.calls.findIndex((c) => c.method === "PATCH" && c.body?.status === "completed");
    const publishAt = f.calls.findIndex((c) => c.path.endsWith("/publish"));
    assert(completedAt >= 0 && completedAt < publishAt);
    assertEquals(f.fake.groups.get(7)!.tasks[0].status, "completed");
    assertEquals(f.calls.filter((c) => c.path.endsWith("/publish")).length, 1);
  } finally { await f.fake.close(); }
});

Deno.test("a lost package child-completion reply still publishes the saved summary", async () => {
  const f = fixture();
  try {
    const tree = "b".repeat(40);
    f.fake.groups.get(7)!.tasks[0].status = "running";
    const script = { payload: { subtask: 701, outcome: "passed", changed: true, tree }, recordedAt: "2026-10-25T20:00:00Z" };
    const handoffArgs = f.args("handoff", { script });
    await runStage(handoffArgs, f.stageDeps(handoffArgs.key));
    f.tick();
    const handed = await runStage(handoffArgs, f.stageDeps(handoffArgs.key));
    const publishArgs = f.args("publish", {
      handoff: { payload: handed.artifacts.handoff, recordedAt: "2026-10-25T20:00:00Z" },
      workspace: { payload: { startingCommit: "c".repeat(40) }, recordedAt: "2026-10-25T20:00:00Z" },
    });
    let dropped = false;
    const http: Http = async (method, path, body, key) => {
      if (!dropped && method === "PATCH" && body?.status === "completed") {
        const saved = f.jobs.get(publishArgs.key);
        assertEquals(saved?.request?.path.endsWith("/publish"), true);
        assertEquals(typeof (saved?.request?.body.pull_request as { title?: unknown } | undefined)?.title, "string");
        assert(saved?.inputIdentity?.includes(handed.artifacts.handoff.commit as string));
        const applied = await f.http(method, path, body, key);
        assertEquals(applied.status, 200);
        dropped = true;
        return { status: 0, body: { error: { code: "transport", message: "curl: (56) Failure when receiving data from the peer" } } };
      }
      return f.http(method, path, body, key);
    };
    await runStage(publishArgs, f.stageDeps(publishArgs.key, http));
    f.tick();
    const publishing = await runStage(publishArgs, f.stageDeps(publishArgs.key, http));
    assert(dropped);
    assertEquals(publishing.state, "running");
    assertEquals(f.fake.groups.get(7)!.tasks[0].status, "completed");
    const publish = f.calls.filter((c) => c.path.endsWith("/publish"));
    assertEquals(publish.length, 1);
    assertEquals((publish[0].body!.pull_request as { body: string }).body.includes("| Package |"), true);
    const completedAt = f.calls.findIndex((c) => c.method === "PATCH" && c.body?.status === "completed");
    const publishAt = f.calls.findIndex((c) => c.path.endsWith("/publish"));
    assert(completedAt >= 0 && completedAt < publishAt);
  } finally { await f.fake.close(); }
});

Deno.test("a package completion reply that never applies stays retryable and then publishes", async () => {
  const f = fixture();
  try {
    const tree = "b".repeat(40);
    f.fake.groups.get(7)!.tasks[0].status = "running";
    const script = { payload: { subtask: 701, outcome: "passed", changed: true, tree }, recordedAt: "2026-10-25T20:00:00Z" };
    const handoffArgs = f.args("handoff", { script });
    await runStage(handoffArgs, f.stageDeps(handoffArgs.key));
    f.tick();
    const handed = await runStage(handoffArgs, f.stageDeps(handoffArgs.key));
    const publishArgs = f.args("publish", {
      handoff: { payload: handed.artifacts.handoff, recordedAt: "2026-10-25T20:00:00Z" },
      workspace: { payload: { startingCommit: "c".repeat(40) }, recordedAt: "2026-10-25T20:00:00Z" },
    });
    let dropped = false;
    const http: Http = async (method, path, body, key) => {
      if (!dropped && method === "PATCH" && body?.status === "completed") {
        assertEquals(f.jobs.get(publishArgs.key)?.request?.path.endsWith("/publish"), true);
        dropped = true;
        return { status: 0, body: { error: { code: "transport", message: "curl: (28) Operation timed out" } } };
      }
      return f.http(method, path, body, key);
    };
    await runStage(publishArgs, f.stageDeps(publishArgs.key, http));
    f.tick();
    const paused = await runStage(publishArgs, f.stageDeps(publishArgs.key, http));
    assert(dropped);
    assertEquals(paused.state, "running");
    assertEquals(f.fake.groups.get(7)!.tasks[0].status, "running");
    assertEquals(f.calls.filter((c) => c.path.endsWith("/publish")).length, 0);
    f.tick();
    const publishing = await runStage(publishArgs, f.stageDeps(publishArgs.key, http));
    assertEquals(publishing.state, "running");
    assertEquals(f.fake.groups.get(7)!.tasks[0].status, "completed");
    assertEquals(f.calls.filter((c) => c.path.endsWith("/publish")).length, 1);
  } finally { await f.fake.close(); }
});

Deno.test("package publish does not summarize an ended child when no summary was saved", async () => {
  const f = fixture();
  try {
    f.fake.groups.get(7)!.tasks[0].status = "completed";
    const handoff = { payload: { subtask: 701, outcome: "passed", checkId: 3, commit: "a".repeat(40), candidate: { tree: "b".repeat(40) } }, recordedAt: "2026-10-25T20:00:00Z" };
    await assertRejects(() => runStage(f.args("publish", { handoff }), f.stageDeps("tg-7.publish.c1")), Error, "no saved summary");
    assertEquals(f.calls.filter((c) => c.method === "POST").length, 0);
    assertEquals(f.fake.operations.size, 0);
  } finally { await f.fake.close(); }
});

Deno.test("a later package publish reuses the saved summary and does not summarize the completed child", async () => {
  const f = fixture();
  f.fake.groups.get(7)!.scenario.publishFailOnce = true;
  const deps = (key: string) => ({
    ...f.stageDeps(key),
    readPriorPublish: () => Promise.resolve([...f.jobs.values()].filter((j) => j.stage === "publish" && j.key !== key)),
  });
  try {
    const tree = "b".repeat(40);
    f.fake.groups.get(7)!.tasks[0].status = "running";
    const script = { payload: { subtask: 701, outcome: "passed", changed: true, tree }, recordedAt: "2026-10-25T20:00:00Z" };
    const handoffArgs = f.args("handoff", { script });
    await runStage(handoffArgs, deps(handoffArgs.key));
    f.tick();
    const handed = await runStage(handoffArgs, deps(handoffArgs.key));
    const publish1 = f.args("publish", {
      handoff: { payload: handed.artifacts.handoff, recordedAt: "2026-10-25T20:00:00Z" },
      workspace: { payload: { startingCommit: "c".repeat(40) }, recordedAt: "2026-10-25T20:00:00Z" },
    });
    await runStage(publish1, deps(publish1.key));
    f.tick();
    await runStage(publish1, deps(publish1.key));
    f.tick();
    const failed = await runStage(publish1, deps(publish1.key));
    assertEquals(failed.state, "failed");
    assertEquals(f.fake.groups.get(7)!.tasks[0].status, "completed");
    const publish2 = { ...publish1, key: "tg-7.publish.c2" };
    await runStage(publish2, deps(publish2.key));
    assertEquals(f.worker.calls.filter((c) => c.request.mode === "summary").length, 2);
    assertEquals(f.calls.filter((c) => c.path.endsWith("/publish")).map((c) => c.key), ["tg-7.publish.c1", "tg-7.publish.c2"]);
    f.tick();
    const done = await runStage(publish2, deps(publish2.key));
    assertEquals(done.state, "succeeded");
  } finally { await f.fake.close(); }
});
