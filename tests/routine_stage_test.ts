import { impactRows, maintenanceFixture } from "./_support/maintenance_fixture.ts";
import { WEBSITE_PROJECT } from "./_support/dependency_projects.ts";
import { assert, assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import {
  type Http,
  type Job,
  runStage,
  type StageArgs,
} from "../extensions/behaviors/_lib/gateway_stage.ts";
import { startFakeGateway } from "./_support/fake_gateway.ts";

const TREE = "e".repeat(40);
const rec = (payload: Record<string, unknown>) => ({
  payload,
  recordedAt: "2026-10-25T20:00:00Z",
});
const script = rec({
  impactRows,
  subtask: 701,
  outcome: "passed",
  changed: false,
  tree: TREE,
});
const handoff = rec({
  subtask: 701,
  outcome: "passed",
  checkId: 3,
  commit: "a".repeat(40),
  candidate: { tree: TREE },
});

async function fixture(scenario = {}) {
  const fake = startFakeGateway({ 7: scenario });
  const calls: {
    method: string;
    path: string;
    body?: Record<string, unknown>;
    key?: string;
  }[] = [];
  let job: Job | null = null;
  let now = Date.parse("2026-10-25T20:01:00Z");
  const http: Http = async (method, path, body, key) => {
    calls.push({ method, path, body, key });
    const response = await fetch(fake.url + path, {
      method,
      headers: {
        "content-type": "application/json",
        ...(key ? { "Idempotency-Key": key } : {}),
      },
      ...(body === undefined ? {} : { body: JSON.stringify(body) }),
    });
    return { status: response.status, body: await response.json() };
  };
  const worker = maintenanceFixture(fake);
  worker.prepare();
  const deps = {
    maintenance: worker.maintenance,
    http,
    projects: [WEBSITE_PROJECT],
    readJob: () => Promise.resolve(job),
    writeJob: (j: Job) => {
      job = structuredClone(j);
      return Promise.resolve();
    },
    now: () => now,
  };
  const args = (stage: StageArgs["stage"], extra = {}): StageArgs => ({
    workItem: "tg-7",
    stage,
    key: `tg-7.${stage}.c1`,
    routine: true,
    ...extra,
  });
  return {
    fake,
    worker,
    calls,
    deps,
    args,
    get: () => job,
    set: (j: Job | null) => {
      job = j;
    },
    tick: () => {
      now += 60000;
    },
  };
}

Deno.test("recording a handoff's own artifact before a crash does not conflict with its retained request", async () => {
  const f = await fixture();
  try {
    const args = f.args("handoff", { script });
    await runStage(args, f.deps);
    f.tick();
    const done = await runStage(args, f.deps);
    const saved = f.get()!;
    f.set({ ...saved, state: "running", evidence: null, artifacts: {} });
    f.tick();
    const resumed = await runStage({
      ...args,
      handoff: rec(done.artifacts.handoff),
    }, f.deps);
    assertEquals(resumed.artifacts, done.artifacts);
    assertEquals(f.fake.operations.size, 1);
    assertEquals(f.fake.groups.get(7)!.tasks[0].status, "todo");
  } finally {
    await f.fake.close();
  }
});

Deno.test("no-change cleanup reconciles a lost response without publication or a second effect dispatch", async () => {
  const f = await fixture();
  try {
    const g = f.fake.groups.get(7)!;
    g.status = "running";
    g.tasks[0].status = "completed";
    const args = f.args("finish", { script, handoff });
    let lost = true;
    const http: Http = async (...input) => {
      const response = await f.deps.http(...input);
      if (input[1].endsWith("/complete") && lost) {
        lost = false;
        return { status: 0, body: null };
      }
      return response;
    };
    await runStage(args, { ...f.deps, http });
    const retained = structuredClone(f.get());
    f.set(retained);
    const done = await runStage(args, { ...f.deps, http });
    assertEquals(done.state, "succeeded");
    assertEquals(
      f.calls.filter((c) => c.method === "POST").map((c) => c.path),
      ["/api/v1/task-groups/7/complete"],
    );
    assertEquals(f.fake.operations.size, 0);
  } finally {
    await f.fake.close();
  }
});

Deno.test("routine preserves all four observed assistance causes before starting work or no-change cleanup", async () => {
  for (
    const kind of ["direction", "failure", "removal_failure", "observation"]
  ) {
    for (const stage of ["script", "finish"] as const) {
      const f = await fixture();
      try {
        const g = f.fake.groups.get(7)!;
        g.status = "running";
        g.tasks[0].status = stage === "finish" ? "completed" : "running";
        g.assistance_requested = true;
        g.assistance_kind = kind;
        g.assistance_question = g.assistance_reason = "operator request";
        const args = f.args(stage, { script, handoff });
        for (let i = 0; i < 2; i++) {
          await assertRejects(() => runStage(args, f.deps));
          f.set(structuredClone(f.get()));
        }
        assertEquals(f.calls.filter((c) => c.method !== "GET"), []);
        assertEquals(g.assistance_kind, kind);
        assertEquals(f.fake.operations.size, 0);
      } finally {
        await f.fake.close();
      }
    }
  }
});

Deno.test("repair uses one ordinary turn with failing output and no analysis reference", async () => {
  const f = await fixture();
  try {
    await runStage(
      f.args("repair", {
        script: rec({
          subtask: 701,
          outcome: "failed",
          impactRows,
          output: "npm update failed",
        }),
      }),
      f.deps,
    );
    const post = f.calls.find((c) => c.path.endsWith("/turn"))!;
    assertEquals(post.body?.role, "implementer");
    assertEquals(post.body?.thread, "new");
    assert(!("analysis" in post.body!));
    assert(String(post.body?.prompt).includes("npm update failed"));
    assert(
      String(post.body?.prompt).includes(
        "single routine dependency repair turn",
      ),
    );
    assertEquals(f.fake.operations.size, 1);
  } finally {
    await f.fake.close();
  }
});

Deno.test("a retained routine request preserves assistance observed after a lost start reply across restarts", async () => {
  for (
    const kind of ["direction", "failure", "removal_failure", "observation"]
  ) {
    const f = await fixture();
    try {
      const args = f.args("script");
      await runStage(args, {
        ...f.deps,
        http: async (...input) => {
          const r = await f.deps.http(...input);
          return input[0] === "POST" ? { status: 0, body: null } : r;
        },
      });
      const g = f.fake.groups.get(7)!;
      g.assistance_requested = true;
      g.assistance_kind = kind;
      g.assistance_question = g.assistance_reason = "independent request";
      const posts = f.calls.filter((c) => c.method === "POST").length;
      for (let n = 0; n < 2; n++) {
        f.set(structuredClone(f.get()));
        await assertRejects(() => runStage(args, f.deps));
      }
      assertEquals(f.calls.filter((c) => c.method === "POST").length, posts);
      assertEquals(f.fake.operations.size, 0);
      assertEquals(g.assistance_kind, kind);
    } finally {
      await f.fake.close();
    }
  }
});

Deno.test("foreign or malformed routine handoff cannot complete its child or publish", async () => {
  for (const bad of ["group", "input", "commit"]) {
    const f = await fixture();
    try {
      const args = f.args("handoff", { script });
      await runStage(args, f.deps);
      const op = [...f.fake.operations.values()][0];
      if (bad === "group") op.target_id = 8;
      if (bad === "input") op.input = { subtask: 999, phase: "handoff" };
      if (bad === "commit") {
        (op.result as Record<string, unknown>).commit = null;
      }
      f.tick();
      await assertRejects(() => runStage(args, f.deps));
      assertEquals(f.fake.groups.get(7)!.tasks[0].status, "todo");
      assertEquals(f.calls.filter((c) => c.method === "PATCH"), []);
      assertEquals(f.fake.operations.size, 1);
    } finally {
      await f.fake.close();
    }
  }
});

Deno.test("routine completion cannot hide a cleanup failure behind HTTP success", async () => {
  for (const stage of ["finish", "merged"] as const) {
    const f = await fixture();
    try {
      const g = f.fake.groups.get(7)!;
      g.tasks[0].status = "completed";
      g.watched_pr_state = stage === "merged" ? "merged" : null;
      const args = f.args(stage, { script, handoff });
      const http: Http = async (...input) => {
        const response = await f.deps.http(...input);
        if (input[1].endsWith("/complete")) {
          g.status = "settling";
          g.assistance_requested = true;
          g.assistance_kind = "removal_failure";
          g.assistance_question = g.assistance_reason = "VM cleanup failed";
          return { status: 200, body: { data: structuredClone(g) } };
        }
        return response;
      };
      if (stage === "finish") {
        await assertRejects(() => runStage(args, { ...f.deps, http }));
      } else {assertEquals(
          (await runStage(args, { ...f.deps, http })).state,
          "running",
        );}
      assertEquals(f.get()?.evidence ?? null, null);
      const posts = f.calls.filter((c) => c.method === "POST").length;
      f.set(structuredClone(f.get()));
      if (stage === "finish") await assertRejects(() => runStage(args, f.deps));
      else assertEquals((await runStage(args, f.deps)).state, "running");
      assertEquals(f.calls.filter((c) => c.method === "POST").length, posts);
      assertEquals(g.assistance_kind, "removal_failure");
    } finally {
      await f.fake.close();
    }
  }
});

Deno.test("repair accepts Gateway-defaulted null input after restart and reaches one recheck without another turn", async () => {
  const f = await fixture();
  try {
    const failedScript = rec({
      subtask: 701,
      outcome: "failed",
          impactRows,
      output: "npm update failed",
      tree: TREE,
    });
    const args = f.args("repair", { script: failedScript });
    await runStage(args, f.deps);
    const op = [...f.fake.operations.values()][0];
    assertEquals(op.input, {
      ...f.get()!.request!.body,
      model: null,
      check: null,
      analysis: null,
    });
    f.set(structuredClone(f.get()));
    f.tick();
    const repaired = await runStage(args, f.deps);
    assertEquals(repaired.state, "succeeded");
    assertEquals(repaired.artifacts.change.outcome, "ready_for_review");
    assertEquals(repaired.artifacts.change.scriptTree, TREE);
    assertEquals(repaired.artifacts.change.impactRows, impactRows);
    await runStage(args, f.deps);
    f.set(null); // The next stage has its own keyed persisted job.
    const checkArgs = f.args("handoff", {
      script: failedScript,
      change: rec(repaired.artifacts.change),
    });
    await runStage(checkArgs, f.deps);
    f.tick();
    assertEquals(
      (await runStage(checkArgs, f.deps)).artifacts.handoff.outcome,
      "passed",
    );
    assertEquals(f.fake.startsOf(7, "repair"), 1);
    assertEquals(f.fake.startsOf(7, "handoff"), 1);
    assertEquals(f.fake.operations.size, 2);
  } finally {
    await f.fake.close();
  }
});

Deno.test("repair still refuses changed supplied fields and non-null Gateway defaults", async () => {
  for (
    const [field, value] of Object.entries({
      subtask: 999,
      role: "reviewer",
      thread: "77",
      prompt: "changed prompt",
      model: "other",
      check: 42,
      analysis: { operation: 42, plan_sha256: "f".repeat(64) },
    })
  ) {
    const f = await fixture();
    try {
      const args = f.args("repair", {
        script: rec({
          subtask: 701,
          outcome: "failed",
          impactRows,
          output: "npm update failed",
        }),
      });
      await runStage(args, f.deps);
      const op = [...f.fake.operations.values()][0];
      (op.input as Record<string, unknown>)[field] = value;
      f.tick();
      await assertRejects(
        () => runStage(args, f.deps),
        Error,
        "invalid scoped routine repair",
      );
      assertEquals(f.get()!.state, "running");
      assertEquals(f.get()!.artifacts, {});
      assertEquals(f.fake.startsOf(7, "repair"), 1);
      assertEquals(f.fake.startsOf(7, "handoff"), 0);
    } finally {
      await f.fake.close();
    }
  }
});

Deno.test("routine publish uses the summary title and body", async () => {
  const f = await fixture();
  try {
    f.fake.groups.get(7)!.tasks[0].status = "running";
    const base = "b".repeat(40);
    const args = f.args("publish", { handoff, workspace: rec({ startingCommit: base }) });
    const started = await runStage(args, f.deps);
    assertEquals(started.state, "running");
    assertEquals(f.worker.calls[0].request, { cwd: "/vm/repo", mode: "summary", base, projectType: "laravel-app" });
    f.tick();
    const publishing = await runStage(args, f.deps);
    assertEquals(publishing.state, "running");
    const publish = f.calls.find((c) => c.path.endsWith("/publish"))!;
    assertEquals(publish.key, "tg-7.publish.c1");
    const pull = publish.body!.pull_request as { title: string; body: string };
    assertEquals(pull.title, "Update dependencies for October 2026");
    assert(pull.body.includes("| Package | From | To | Change |"));
    assert(pull.body.includes("No code changes needed."));
    assert(!/Tested:|Gateway check|Validation|group \d/.test(pull.body));
    assert(!pull.body.includes("Driven by Swamp"));
    assert(!pull.body.includes("ADR"));
    assertEquals(publish.body!.check, 3);
    const completedAt = f.calls.findIndex((c) => c.method === "PATCH" && c.body?.status === "completed");
    const publishAt = f.calls.findIndex((c) => c.path.endsWith("/publish"));
    assert(completedAt >= 0 && completedAt < publishAt);
    assertEquals(f.fake.groups.get(7)!.tasks[0].status, "completed");
    f.tick();
    const done = await runStage(args, f.deps);
    assertEquals(done.state, "succeeded");
    assertEquals(done.artifacts["pull-request"].number, 1);
    assertEquals(f.calls.filter((c) => c.path.endsWith("/publish")).length, 1);
  } finally {
    await f.fake.close();
  }
});

Deno.test("a lost routine child-completion reply still publishes the saved summary", async () => {
  const f = await fixture();
  try {
    f.fake.groups.get(7)!.tasks[0].status = "running";
    const inner = f.deps.http;
    let dropped = false;
    f.deps.http = async (method, path, body, key) => {
      if (!dropped && method === "PATCH" && body?.status === "completed") {
        const saved = f.get();
        assertEquals(saved?.request?.path.endsWith("/publish"), true);
        assertEquals((saved?.request?.body.pull_request as { title?: string } | undefined)?.title, "Update dependencies for October 2026");
        assert(saved?.inputIdentity?.includes(`"checkId":3`));
        const applied = await inner(method, path, body, key);
        assertEquals(applied.status, 200);
        dropped = true;
        return { status: 0, body: { error: { code: "transport", message: "curl: (56) Failure when receiving data from the peer" } } };
      }
      return inner(method, path, body, key);
    };
    const args = f.args("publish", { handoff, workspace: rec({ startingCommit: "b".repeat(40) }) });
    await runStage(args, f.deps);
    f.tick();
    const publishing = await runStage(args, f.deps);
    assert(dropped);
    assertEquals(publishing.state, "running");
    assertEquals(f.fake.groups.get(7)!.tasks[0].status, "completed");
    const publish = f.calls.filter((c) => c.path.endsWith("/publish"));
    assertEquals(publish.length, 1);
    assertEquals((publish[0].body!.pull_request as { title: string }).title, "Update dependencies for October 2026");
    const completedAt = f.calls.findIndex((c) => c.method === "PATCH" && c.body?.status === "completed");
    const publishAt = f.calls.findIndex((c) => c.path.endsWith("/publish"));
    assert(completedAt >= 0 && completedAt < publishAt);
  } finally {
    await f.fake.close();
  }
});

Deno.test("a routine completion reply that never applies stays retryable and then publishes", async () => {
  const f = await fixture();
  try {
    f.fake.groups.get(7)!.tasks[0].status = "running";
    const inner = f.deps.http;
    let dropped = false;
    f.deps.http = async (method, path, body, key) => {
      if (!dropped && method === "PATCH" && body?.status === "completed") {
        assertEquals(f.get()?.request?.path.endsWith("/publish"), true);
        dropped = true;
        return { status: 0, body: { error: { code: "transport", message: "curl: (28) Operation timed out" } } };
      }
      return inner(method, path, body, key);
    };
    const args = f.args("publish", { handoff });
    await runStage(args, f.deps);
    f.tick();
    const paused = await runStage(args, f.deps);
    assert(dropped);
    assertEquals(paused.state, "running");
    assertEquals(f.fake.groups.get(7)!.tasks[0].status, "running");
    assertEquals(f.calls.filter((c) => c.path.endsWith("/publish")).length, 0);
    f.tick();
    const publishing = await runStage(args, f.deps);
    assertEquals(publishing.state, "running");
    assertEquals(f.fake.groups.get(7)!.tasks[0].status, "completed");
    assertEquals(f.calls.filter((c) => c.path.endsWith("/publish")).length, 1);
  } finally {
    await f.fake.close();
  }
});

Deno.test("routine publish fails closed and never sends the old pull request text", async () => {
  for (const scenario of [{ summaryFails: true }, { summaryTruncated: true }, { summaryGarbage: true }]) {
    const f = await fixture(scenario);
    try {
      const args = f.args("publish", { handoff });
      await runStage(args, f.deps);
      assertEquals(f.worker.calls[0].request.mode, "summary");
      f.tick();
      const failed = await runStage(args, f.deps);
      assertEquals(failed.state, "failed");
      assert(String(failed.lastError).startsWith("dependency summary"));
      assertEquals(f.calls.filter((c) => c.path.endsWith("/publish")).length, 0);
      f.tick();
      assertEquals((await runStage(args, f.deps)).state, "failed");
      assertEquals(f.calls.filter((c) => c.path.endsWith("/publish")).length, 0);
    } finally {
      await f.fake.close();
    }
  }
});

Deno.test("a changed handoff stays open so publish can summarize, then completes the child first", async () => {
  const f = await fixture();
  try {
    const tree = "b".repeat(40);
    f.fake.groups.get(7)!.tasks[0].status = "running";
    const script = rec({ subtask: 701, outcome: "passed", changed: true, tree });
    const handoffArgs = f.args("handoff", { script });
    await runStage(handoffArgs, f.deps);
    f.tick();
    const handed = await runStage(handoffArgs, f.deps);
    assertEquals(handed.artifacts.handoff.outcome, "passed");
    assertEquals(f.fake.groups.get(7)!.tasks[0].status, "running");
    f.set(null);
    const args = f.args("publish", {
      handoff: rec(handed.artifacts.handoff),
      script,
      workspace: rec({ startingCommit: "c".repeat(40) }),
    });
    const started = await runStage(args, f.deps);
    assertEquals(started.state, "running");
    assertEquals(f.fake.groups.get(7)!.tasks[0].status, "running");
    assertEquals(f.worker.calls[0].request.mode, "summary");
    f.tick();
    const publishing = await runStage(args, f.deps);
    assertEquals(publishing.state, "running");
    const completedAt = f.calls.findIndex((c) => c.method === "PATCH" && c.body?.status === "completed");
    const publishAt = f.calls.findIndex((c) => c.path.endsWith("/publish"));
    assert(completedAt >= 0 && completedAt < publishAt);
    assertEquals(f.fake.groups.get(7)!.tasks[0].status, "completed");
  } finally {
    await f.fake.close();
  }
});

Deno.test("an unchanged handoff still completes its child for no-change cleanup", async () => {
  const f = await fixture();
  try {
    const tree = "b".repeat(40);
    f.fake.groups.get(7)!.tasks[0].status = "running";
    const args = f.args("handoff", { script: rec({ subtask: 701, outcome: "passed", changed: false, tree }) });
    await runStage(args, f.deps);
    f.tick();
    await runStage(args, f.deps);
    assertEquals(f.fake.groups.get(7)!.tasks[0].status, "completed");
    assertEquals(f.worker.calls.filter((c) => c.request.mode === "summary").length, 0);
  } finally {
    await f.fake.close();
  }
});

Deno.test("publish does not summarize an ended child when no summary was saved", async () => {
  const f = await fixture();
  try {
    f.fake.groups.get(7)!.tasks[0].status = "completed";
    await assertRejects(
      () => runStage(f.args("publish", { handoff }), f.deps),
      Error,
      "no saved summary",
    );
    assertEquals(f.calls.filter((c) => c.method === "POST").length, 0);
    assertEquals(f.fake.operations.size, 0);
  } finally {
    await f.fake.close();
  }
});

Deno.test("a later publish cycle reuses the saved summary and does not summarize the completed child", async () => {
  const f = await fixture({ publishFailOnce: true });
  const jobs = new Map<string, Job>();
  const deps = (key: string) => ({
    maintenance: f.worker.maintenance,
    http: f.deps.http,
    projects: f.deps.projects,
    now: f.deps.now,
    readJob: () => Promise.resolve(jobs.get(key) ?? null),
    writeJob: (j: Job) => {
      jobs.set(j.key, structuredClone(j));
      return Promise.resolve();
    },
    readPriorPublish: () => Promise.resolve([...jobs.values()].filter((j) => j.stage === "publish" && j.key !== key)),
  });
  try {
    const tree = "b".repeat(40);
    f.fake.groups.get(7)!.tasks[0].status = "running";
    const script = rec({ subtask: 701, outcome: "passed", changed: true, tree });
    const handoffArgs = f.args("handoff", { script });
    await runStage(handoffArgs, deps(handoffArgs.key));
    f.tick();
    const handed = await runStage(handoffArgs, deps(handoffArgs.key));
    const handoff = rec(handed.artifacts.handoff);
    const publish1 = f.args("publish", { handoff, workspace: rec({ startingCommit: "c".repeat(40) }) });
    await runStage(publish1, deps(publish1.key));
    f.tick();
    await runStage(publish1, deps(publish1.key));
    f.tick();
    const failed = await runStage(publish1, deps(publish1.key));
    assertEquals(failed.state, "failed");
    assertEquals(f.fake.groups.get(7)!.tasks[0].status, "completed");
    assertEquals(f.worker.calls.filter((c) => c.request.mode === "summary").length, 2);
    const publish2 = { ...publish1, key: "tg-7.publish.c2" };
    const started = await runStage(publish2, deps(publish2.key));
    assertEquals(started.state, "running");
    assertEquals(f.worker.calls.filter((c) => c.request.mode === "summary").length, 2);
    const posts = f.calls.filter((c) => c.path.endsWith("/publish"));
    assertEquals(posts.map((c) => c.key), ["tg-7.publish.c1", "tg-7.publish.c2"]);
    assertEquals((posts[1].body!.pull_request as { title: string }).title, (posts[0].body!.pull_request as { title: string }).title);
    f.tick();
    const done = await runStage(publish2, deps(publish2.key));
    assertEquals(done.state, "succeeded");
    assertEquals(done.artifacts["pull-request"].number, 1);
  } finally {
    await f.fake.close();
  }
});

Deno.test("completion is durable before best-effort worker revocation, and replay does not repeat cleanup", async () => {
  for (const stage of ["finish", "merged"] as const) {
    const f = await fixture();
    try {
      const group = f.fake.groups.get(7)!;
      group.tasks[0].status = "completed";
      group.watched_pr_state = stage === "merged" ? "merged" : null;
      let revokes = 0;
      const deps = {
        ...f.deps,
        revokeWorker: () => {
          revokes++;
          assertEquals(
            f.get()!.state,
            "succeeded",
            "save completion before contacting Swamp",
          );
          throw new Error("Swamp unavailable; minute sweep will retry");
        },
      };
      const args = f.args(stage, { script, handoff });
      assertEquals((await runStage(args, deps)).state, "succeeded");
      assertEquals((await runStage(args, deps)).state, "succeeded");
      assertEquals(revokes, 1);
      assertEquals(
        f.calls.filter((call) => call.path.endsWith("/complete")).length,
        1,
      );
    } finally {
      await f.fake.close();
    }
  }
});

Deno.test("a pre-retirement publish job reuses its checked summary after the child ended", async () => {
  const stored: Job = JSON.parse(await Deno.readTextFile(new URL("./fixtures/project-extensions/publish-job-v1.json", import.meta.url)));
  const identity = JSON.parse(stored.inputIdentity!);
  const f = await fixture();
  try {
    f.fake.groups.get(7)!.tasks[0].status = "completed";
    const started = await runStage(f.args("publish", { key: "tg-7.publish.c2", handoff: identity.handoff }), {
      ...f.deps,
      readPriorPublish: () => Promise.resolve([stored]),
    });
    assertEquals(started.state, "running");
    assertEquals(started.request!.body.pull_request, stored.request!.body.pull_request);
    assertEquals(f.worker.calls, []);
    assertEquals(f.fake.startsOf(7, "publish"), 1);
  } finally { await f.fake.close(); }
});

Deno.test("publish carries the scoped repair report to the worker and preserves its checked body on replay", async () => {
  const f = await fixture();
  try {
    f.fake.groups.get(7)!.tasks[0].status = "running";
    const report = JSON.stringify({ updates: [{ manager: "composer", path: ".", package: "livewire/livewire", codeChanged: false, effect: "", files: [] }] });
    const change = rec({ subtask: 701, outcome: "ready_for_review", summary: report, impactRows, scriptTree: TREE, candidate: { tree: TREE } });
    const args = f.args("publish", { handoff, change, workspace: rec({ startingCommit: "b".repeat(40) }) });
    await runStage(args, f.deps);
    assertEquals(f.worker.calls[0].request.impact, { report, inventory: impactRows, scriptTree: TREE, repairTree: TREE, checkedTree: TREE });
    assertEquals(f.get()!.inputIdentity!.includes(report.replaceAll('"', '\\"')), true);
    f.tick();
    await runStage(args, f.deps);
    const published = f.calls.find((c) => c.path.endsWith("/publish"))!;
    assertEquals(published.body!.check, 3);
    assertEquals((published.body!.pull_request as { body: string }).body.includes("Tested:"), false);
    f.tick();
    await runStage(args, f.deps);
    assertEquals(f.calls.filter((c) => c.path.endsWith("/publish")).length, 1);
    assertEquals(f.worker.calls.filter((c) => c.request.mode === "summary").length, 2);
    assertEquals(f.calls.some((c) => c.path.endsWith("/turn")), false);
  } finally { await f.fake.close(); }
});

Deno.test("an in-flight pre-deploy publish without a change key resumes after a lost start reply", async () => {
  const stored: Job = JSON.parse(await Deno.readTextFile(new URL("./fixtures/project-extensions/publish-job-v1.json", import.meta.url)));
  const identity = JSON.parse(stored.inputIdentity!);
  assertEquals(Object.hasOwn(identity, "change"), false);
  const f = await fixture();
  const deps = { ...f.deps, projects: [{ ...WEBSITE_PROJECT, worker: identity.dependencyProject.worker }] };
  try {
    f.fake.groups.get(7)!.tasks[0].status = "completed";
    f.set({ ...stored, state: "running", operationId: null });
    const args = f.args("publish", { handoff: identity.handoff, change: null });
    const lost = await runStage(args, { ...deps, http: async (...input) => {
      const response = await f.deps.http(...input);
      return input[0] === "POST" ? { status: 0, body: null } : response;
    } });
    assertEquals(lost.state, "running");
    assertEquals(lost.operationId, null);
    f.set(structuredClone(f.get())); f.tick();
    await runStage(args, deps);
    f.tick();
    assertEquals((await runStage(args, deps)).state, "succeeded");
    assertEquals(f.fake.operations.size, 1);
    assertEquals(f.worker.calls, []);
    assertEquals(f.calls.filter((c) => c.path.endsWith("/publish")).map((c) => c.body), [stored.request!.body, stored.request!.body]);
  } finally { await f.fake.close(); }
});

import { render } from "../extensions/behaviors/_lib/maintenance/summary.ts";
import { repairReport } from "../extensions/behaviors/_lib/maintenance/impact.ts";
import { type AssistanceRecord, syncAssistance } from "../extensions/behaviors/_lib/gateway_stage.ts";

Deno.test("the checked golden body reaches publish exactly; an unsafe report routes to human assistance", async () => {
  const data = JSON.parse(await Deno.readTextFile(new URL("./fixtures/pr-text/website.json", import.meta.url)));
  const expected = await Deno.readTextFile(new URL("./fixtures/pr-text/website.md", import.meta.url));
  for (const invalid of [false, true]) {
    const f = await fixture();
    try {
      f.fake.groups.get(7)!.tasks[0].status = "running";
      const updates = structuredClone(data.updates);
      if (invalid) updates[0].effect = "<!-- cc @nckrtl fixes #12";
      const report = JSON.stringify({ updates });
      const change = rec({ subtask: 701, outcome: "ready_for_review", summary: report, impactRows, scriptTree: TREE, candidate: { tree: TREE } });
      const args = f.args("publish", { handoff, change });
      const job = await runStage(args, { ...f.deps, maintenance: async (_args, _group, request) => {
        assertEquals(request.impact, { report, inventory: impactRows, scriptTree: TREE, repairTree: TREE, checkedTree: TREE });
        try {
          const parsed = repairReport((request.impact as { report: string }).report);
          const effects = new Map(parsed.updates.map((u) => [`${u.manager}\0${u.path}\0${u.package}`, u]));
          const summary = render(data.changes, "laravel-app", effects);
          return { state: "succeeded", result: { outcome: "passed", changed: false, head: "a".repeat(40), tree: TREE, output: summary.text } };
        } catch (error) { return { state: "failed", lastError: String(error) }; }
      } });
      if (invalid) {
        assertEquals(job.state, "failed");
        assertEquals(f.calls.some((c) => c.path.endsWith("/publish")), false);
        let record: AssistanceRecord | null = null;
        await syncAssistance({ workItem: "tg-7", parked: true, stage: "publish", cycle: 1, reason: "awaiting human approval 'publish-retry'" }, {
          http: f.deps.http, readJob: () => Promise.resolve(f.get()),
          readRecord: () => Promise.resolve(record), writeRecord: (r) => { record = r; return Promise.resolve(); },
        });
        assertEquals(f.fake.groups.get(7)!.assistance_requested, true);
        assertEquals(f.fake.groups.get(7)!.tasks[0].status, "running");
        assert(f.fake.comments[0].body.includes("Invalid repair impact report"));
      } else {
        const publish = f.calls.find((c) => c.path.endsWith("/publish"))!;
        assertEquals((publish.body!.pull_request as { body: string }).body, expected.trimEnd());
        assertEquals(f.fake.startsOf(7, "publish"), 1);
      }
    } finally { await f.fake.close(); }
  }
});

Deno.test("repair receives the exact inventory rows for Composer transitive and JS direct advisory owners", async () => {
  const f = await fixture();
  try {
    const rows = [
      { ...impactRows[0], package: "laravel/framework" },
      { ...impactRows[0], package: "symfony/http-foundation" },
      { ...impactRows[0], manager: "js", path: "web", package: "shadcn" },
    ];
    await runStage(f.args("repair", { script: rec({ subtask: 701, outcome: "failed", output: "failed", impactRows: rows }) }), f.deps);
    const prompt = String(f.calls.find(c => c.path.endsWith("/turn"))!.body!.prompt);
    assert(prompt.includes(`Exact update rows: ${JSON.stringify(rows.map(({manager, path, package: name}) => ({manager, path, package: name})))}`));
    assert(!prompt.includes("controlling direct dependency"));
  } finally { await f.fake.close(); }
});

Deno.test("a repair without exact inventory stops before launching the agent", async () => {
  const f = await fixture();
  try {
    await assertRejects(() => runStage(f.args("repair", { script: rec({ subtask: 701, outcome: "failed", output: "old worker" }) }), f.deps), Error, "exact update inventory");
    assertEquals(f.calls.filter(c => c.method === "POST"), []);
  } finally { await f.fake.close(); }
});

Deno.test("a pre-deploy publish cannot adopt an unbound old repair receipt or rewrite its PR", async () => {
  const stored: Job = JSON.parse(await Deno.readTextFile(new URL("./fixtures/project-extensions/publish-job-v1.json", import.meta.url)));
  const identity = JSON.parse(stored.inputIdentity!);
  const f = await fixture();
  try {
    f.set({ ...stored, state: "running", operationId: null });
    await assertRejects(() => runStage(f.args("publish", { handoff: identity.handoff, change: rec({ subtask: 701, summary: "Pre-deploy prose", scriptTree: TREE, candidate: { tree: TREE } }) }), { ...f.deps, projects: [{ ...WEBSITE_PROJECT, worker: identity.dependencyProject.worker }] }), Error, "input conflict");
    assertEquals(f.calls.filter(c => c.method === "POST"), []);
    assertEquals(f.worker.calls, []);
    assertEquals(f.get()!.request, stored.request);
  } finally { await f.fake.close(); }
});
