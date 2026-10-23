// The Gateway stage logic against an in-memory fake Gateway: ~/.swamp/deno/deno test tests/
// Fixtures follow the Gateway's real shapes: a turn result names its thread by id
// (TaskTurnOperation), and an accepted start carries poll_after_seconds.
import { assert, assertEquals, assertRejects, assertStringIncludes } from "jsr:@std/assert@1.0.19";
import {
  assistanceBody,
  counters,
  currentSubtask,
  groupOf,
  type Http,
  type HttpResponse,
  idempotencyKey,
  type Job,
  runStage,
  type StageArgs,
  syncAssistance,
} from "../extensions/behaviors/_lib/gateway_stage.ts";

interface Call {
  method: string;
  path: string;
  body?: Record<string, unknown>;
  raw?: string;
  key?: string;
}

function fakeGateway(opts: {
  groupStatus?: string;
  tasks?: { id: number; position: number; status: string }[];
  start?: (path: string) => HttpResponse;
  operation?: Record<string, unknown>;
  watched?: string | null;
}) {
  const calls: Call[] = [];
  const tasks = (opts.tasks ?? [{ id: 11, position: 1, status: "todo" }]).map((t) => ({
    ...t, title: `Subtask ${t.id}`, brief: "Update dependencies.", deliverables: [{ id: "lock", type: "file", description: "composer.lock modified" }],
    assistance_requested: false,
  }));
  let groupStatus = opts.groupStatus ?? "backlog";
  const http: Http = (method, path, body, key) => {
    calls.push({ method, path, body, raw: body === undefined ? undefined : JSON.stringify(body), key });
    const ok = (data: unknown, status = 200): Promise<HttpResponse> => Promise.resolve({ status, body: { data, meta: {} } });
    if (method === "GET" && path === "/api/v1/task-groups/7") {
      return ok({ id: 7, title: "Deps", brief: "Group brief.", status: groupStatus, watched_pr_state: opts.watched ?? null, tasks });
    }
    if (method === "PATCH" && path === "/api/v1/task-groups/7") {
      groupStatus = String(body!.status);
      return ok({});
    }
    if (method === "PATCH" && path.startsWith("/api/v1/task-groups/7/tasks/")) {
      const t = tasks.find((x) => path.endsWith(`/${x.id}`))!;
      t.status = String(body!.status);
      return ok({});
    }
    if (method === "POST" && path === "/api/v1/task-groups/7/complete") return ok({});
    if (method === "POST") {
      return Promise.resolve(opts.start?.(path) ?? { status: 202, body: { data: { id: 900, status: "queued", poll_after_seconds: 5 } } });
    }
    if (method === "GET" && path === "/api/v1/operations/900") return ok(opts.operation ?? { id: 900, status: "waiting", poll_after_seconds: 10 });
    return Promise.resolve({ status: 404, body: { error: { code: "not_found" } } });
  };
  return { http, calls, tasks };
}

/** A job store and a clock: each `run` call is one driver tick, one minute after the last. */
function harness(initial: Job | null = null) {
  let job = initial;
  let t = Date.parse("2026-10-23T08:00:00Z");
  return {
    deps: (http: Http) => ({
      http,
      readJob: () => Promise.resolve(job),
      writeJob: (j: Job) => {
        job = j;
        return Promise.resolve();
      },
      now: () => t,
    }),
    tick: (seconds = 60) => {
      t += seconds * 1000;
    },
    get: () => job,
  };
}

/** Start the stage, then read it one tick later. */
async function settle(a: StageArgs, http: Http, h = harness()) {
  await runStage(a, h.deps(http));
  h.tick();
  return runStage(a, h.deps(http));
}

const args = (stage: StageArgs["stage"], extra: Partial<StageArgs> = {}): StageArgs => ({
  workItem: "tg-7", stage, key: `tg-7.${stage}.c1`, ...extra,
});
const env = (payload: Record<string, unknown>, recordedAt: string) => ({ attributes: { payload, recordedAt } });
const turnResult = (receipt: Record<string, unknown> | null, thread: number) => ({
  receipt, thread, state: "done", error: null, error_code: null, before: {}, after: {}, difference: null, after_reset: null, metrics: {},
});

Deno.test("keys and work items follow the approved shape", () => {
  assertEquals(groupOf("tg-1234"), 1234);
  assertEquals(idempotencyKey("tg-7.implement.c2", 11), "tg-7.implement.s11.c2");
  assertEquals(idempotencyKey("tg-7.provision.c1", null), "tg-7.provision.c1");
});

Deno.test("the current subtask is the open one with the lowest position", () => {
  const t = (id: number, position: number, status: string) => ({ id, position, status, title: "", brief: "" });
  const g = { id: 7, title: "", brief: "", status: "running", tasks: [t(3, 3, "todo"), t(1, 1, "completed"), t(2, 2, "running")] };
  assertEquals(currentSubtask(g)?.id, 2);
});

Deno.test("the first call returns once the accepted operation id is stored", async () => {
  const gw = fakeGateway({});
  const h = harness();
  const first = await runStage(args("provision"), h.deps(gw.http));
  assertEquals(first.state, "running");
  assertEquals(first.operationId, 900);
  assertEquals(first.nextReadAt, "2026-10-23T08:00:05.000Z");
  assertEquals(gw.calls.filter((c) => c.path.startsWith("/api/v1/operations/")).length, 0);
  assertEquals(h.get()!.operationId, 900);
});

Deno.test("reads wait for poll_after_seconds", async () => {
  const gw = fakeGateway({});
  const h = harness();
  await runStage(args("provision"), h.deps(gw.http));
  h.tick(3);
  await runStage(args("provision"), h.deps(gw.http));
  assertEquals(gw.calls.filter((c) => c.path.startsWith("/api/v1/operations/")).length, 0);
  h.tick(3);
  const read = await runStage(args("provision"), h.deps(gw.http));
  assertEquals(gw.calls.filter((c) => c.path.startsWith("/api/v1/operations/")).length, 1);
  assertEquals(read.operationStatus, "waiting");
  assertEquals(read.nextReadAt, "2026-10-23T08:00:16.000Z");
  h.tick(5);
  await runStage(args("provision"), h.deps(gw.http));
  assertEquals(gw.calls.filter((c) => c.path.startsWith("/api/v1/operations/")).length, 1);
  assertEquals(gw.calls.filter((c) => c.method === "POST").length, 1);
});

Deno.test("provision moves a backlog group to todo, starts once with its key, then maps the result", async () => {
  const gw = fakeGateway({ operation: { id: 900, status: "succeeded", result: { instance_id: 5, node_id: 6, starting_commit: "abc" } } });
  const job = await settle(args("provision"), gw.http);
  assertEquals(job.state, "succeeded");
  assertEquals(job.artifacts.workspace, { instanceId: 5, nodeId: 6, startingCommit: "abc" });
  assertEquals(job.evidence, { status: "succeeded", runId: "900", operationId: 900 });
  const start = gw.calls.find((c) => c.method === "POST")!;
  assertEquals(start.path, "/api/v1/task-groups/7/provision");
  assertEquals(start.key, "tg-7.provision.c1");
  assert(gw.calls.some((c) => c.method === "PATCH" && c.body?.status === "todo"));
});

Deno.test("a stored request is replayed byte for byte, without planning again", async () => {
  const gw = fakeGateway({ groupStatus: "running" });
  const stored = {
    path: "/api/v1/task-groups/7/turn",
    body: { subtask: 11, role: "implementer", prompt: "STORED prompt\nwith lines", thread: "44" },
    idempotencyKey: "tg-7.implement.s11.c2",
  };
  const planned: Job = {
    key: "tg-7.implement.c2", workItem: "tg-7", stage: "implement", group: 7, subtask: 11, state: "running",
    launches: 0, launchedNow: false, operationId: null, operationStatus: null, lastError: null,
    inputIdentity: '{"handoff":null,"review":null}', nextReadAt: null, artifacts: {}, evidence: null, request: stored,
  };
  const h = harness(planned);
  const job = await runStage(args("implement", { key: "tg-7.implement.c2" }), h.deps(gw.http));
  assertEquals(job.operationId, 900);
  const posts = gw.calls.filter((c) => c.method === "POST");
  assertEquals(posts.length, 1);
  assertEquals(posts[0].raw, JSON.stringify(stored.body));
  assertEquals(posts[0].path, stored.path);
  assertEquals(posts[0].key, stored.idempotencyKey);
  assertEquals(gw.calls.filter((c) => c.method !== "POST").length, 0);
});

Deno.test("the same key with other input is refused as a conflict", async () => {
  const gw = fakeGateway({ groupStatus: "running", tasks: [{ id: 11, position: 1, status: "running" }] });
  const h = harness();
  const a = env({ subtask: 11, outcome: "passed", checkId: 31, commit: "aaa" }, "2026-10-23T07:00:00Z");
  const b = env({ subtask: 11, outcome: "passed", checkId: 32, commit: "bbb" }, "2026-10-23T07:30:00Z");
  await runStage(args("review", { handoff: a }), h.deps(gw.http));
  await assertRejects(() => runStage(args("review", { handoff: b }), h.deps(gw.http)), Error, "input conflict");
  h.tick();
  const same = await runStage(args("review", { handoff: a }), h.deps(gw.http));
  assertEquals(same.operationId, 900);
  assertEquals(gw.calls.filter((c) => c.method === "POST").length, 1);
});

Deno.test("an implement entry ignores its own recorded change on the next call", async () => {
  const gw = fakeGateway({ groupStatus: "running", operation: { id: 900, status: "succeeded", result: turnResult({ outcome: "ready_for_review", summary: "Done." }, 44) } });
  const h = harness();
  const done = await settle(args("implement"), gw.http, h);
  const own = env(done.artifacts.change, "2026-10-23T08:02:00Z");
  const again = await runStage(args("implement", { change: own }), h.deps(gw.http));
  assertEquals(again.state, "succeeded");
});

Deno.test("a busy target keeps the stage running, a refusal fails it", async () => {
  const busy = fakeGateway({ start: () => ({ status: 409, body: { error: { code: "operation.target_busy" } } }) });
  const b = await runStage(args("provision"), harness().deps(busy.http));
  assertEquals(b.state, "running");
  assertStringIncludes(b.lastError!, "target_busy");
  const refused = fakeGateway({ start: () => ({ status: 409, body: { error: { code: "tasks.not_todo" } } }) });
  const r = await runStage(args("provision"), harness().deps(refused.http));
  assertEquals(r.state, "failed");
  assertEquals(r.evidence?.status, "failed");
  assertEquals(r.evidence?.runId, "tg-7.provision.c1");
});

Deno.test("a failed operation records failed evidence with its error", async () => {
  const gw = fakeGateway({ operation: { id: 900, status: "failed", error_code: "task_vm.invalid_config", failed_step: "claim" } });
  const job = await settle(args("provision"), gw.http);
  assertEquals(job.evidence, {
    status: "failed", runId: "900", operationId: 900, error: "failed task_vm.invalid_config at claim", groupStatus: "todo", groupEnded: false,
  });
});

Deno.test("baseline moves the current subtask to running and checks it", async () => {
  const gw = fakeGateway({
    groupStatus: "running",
    operation: { id: 900, status: "succeeded", result: { outcome: "passed", deliverables: [], check_id: 31, commit: null, head: "h", tree: "t", output: "ok" } },
  });
  const job = await settle(args("baseline"), gw.http);
  assertEquals(gw.tasks[0].status, "running");
  assertEquals(gw.calls.find((c) => c.method === "POST")!.body, { subtask: 11, phase: "baseline" });
  assertEquals(gw.calls.find((c) => c.method === "POST")!.key, "tg-7.baseline.s11.c1");
  assertEquals(job.artifacts.baseline.outcome, "passed");
  assertEquals(job.artifacts.baseline.checkId, 31);
});

Deno.test("implement opens a new thread, then continues it with the reviewer's findings", async () => {
  const gw = fakeGateway({ groupStatus: "running", tasks: [{ id: 11, position: 1, status: "reviewing" }] });
  await runStage(args("implement"), harness().deps(gw.http));
  const first = gw.calls.find((c) => c.method === "POST")!.body!;
  assertEquals(first.thread, "new");
  assertEquals(first.role, "implementer");
  assertEquals(gw.tasks[0].status, "running");

  const gw2 = fakeGateway({ groupStatus: "running", tasks: [{ id: 11, position: 1, status: "reviewing" }] });
  await runStage(args("implement", {
    key: "tg-7.implement.c2",
    change: env({ subtask: 11, threadId: 44, outcome: "ready_for_review" }, "2026-10-23T01:00:00Z"),
    review: env({ subtask: 11, outcome: "changes_requested", summary: "Pin carbon." }, "2026-10-23T02:00:00Z"),
  }), harness().deps(gw2.http));
  const second = gw2.calls.find((c) => c.method === "POST")!;
  assertEquals(second.body!.thread, "44");
  assertEquals(second.key, "tg-7.implement.s11.c2");
  assertStringIncludes(String(second.body!.prompt), "Pin carbon.");
});

Deno.test("a turn result's thread id becomes the change artifact's thread", async () => {
  const gw = fakeGateway({ groupStatus: "running", operation: { id: 900, status: "succeeded", result: turnResult(null, 44) } });
  const job = await settle(args("implement"), gw.http);
  assertEquals(job.artifacts.change.outcome, "none");
  assertEquals(job.artifacts.change.threadId, 44);
  assertEquals(job.artifacts.change.threadState, "done");
});

Deno.test("review needs a passed handoff of the current subtask and passes its check", async () => {
  const gw = fakeGateway({ groupStatus: "running", tasks: [{ id: 11, position: 1, status: "running" }] });
  await runStage(args("review", { handoff: env({ subtask: 11, outcome: "passed", checkId: 31, commit: "def" }, "t") }), harness().deps(gw.http));
  const start = gw.calls.find((c) => c.method === "POST")!.body!;
  assertEquals(start.check, 31);
  assertEquals(start.role, "reviewer");
  assertEquals(gw.tasks[0].status, "reviewing");
});

Deno.test("an approval completes the subtask and reports whether another remains", async () => {
  const gw = fakeGateway({
    groupStatus: "running",
    tasks: [{ id: 11, position: 1, status: "reviewing" }, { id: 12, position: 2, status: "todo" }],
    operation: { id: 900, status: "succeeded", result: turnResult({ outcome: "approved", summary: "Fine." }, 45) },
  });
  const job = await settle(args("review", { handoff: env({ subtask: 11, outcome: "passed", checkId: 31 }, "t") }), gw.http);
  assertEquals(gw.tasks[0].status, "completed");
  assertEquals(job.artifacts.review.outcome, "approved");
  assertEquals(job.artifacts.review.threadId, 45);
  assertEquals(job.artifacts.review.hasNext, true);
});

Deno.test("publish sends the passed check and maps the pull request", async () => {
  const gw = fakeGateway({
    groupStatus: "running",
    operation: { id: 900, status: "succeeded", result: { branch: "task-7", commit: "def", pull_request_number: 12, pull_request_url: "https://x/pull/12" } },
  });
  const job = await settle(args("publish", { handoff: env({ subtask: 11, outcome: "passed", checkId: 31 }, "t") }), gw.http);
  const start = gw.calls.find((c) => c.method === "POST")!;
  assertEquals(start.body!.check, 31);
  assertEquals((start.body!.pull_request as { title: string }).title, "Deps");
  assertEquals(job.artifacts["pull-request"].url, "https://x/pull/12");
});

Deno.test("merged waits for the merge, then completes the group once", async () => {
  const open = fakeGateway({ groupStatus: "running", watched: "open" });
  const waiting = await runStage(args("merged"), harness().deps(open.http));
  assertEquals(waiting.state, "running");
  assertEquals(open.calls.filter((c) => c.method === "POST").length, 0);

  const done = fakeGateway({ groupStatus: "running", watched: "merged" });
  const h = harness();
  const completed = await runStage(args("merged"), h.deps(done.http));
  assertEquals(completed.state, "succeeded");
  await runStage(args("merged"), h.deps(done.http));
  assertEquals(done.calls.filter((c) => c.path.endsWith("/complete")).length, 1);
});

Deno.test("deadlines start when a slow response arrives, not when the call began", async () => {
  let t = Date.parse("2026-10-23T08:00:00Z");
  const reads: number[] = [];
  let job: Job | null = null;
  // Every Gateway request takes 2.2 s on the fake clock; reads fail once, then succeed.
  const slow: Http = async (method, path) => {
    t += 2200;
    if (method === "GET" && path === "/api/v1/task-groups/7") {
      return { status: 200, body: { data: { id: 7, title: "", brief: "", status: "running", tasks: [] } } };
    }
    if (method === "POST") return { status: 202, body: { data: { id: 900, status: "queued", poll_after_seconds: 2 } } };
    reads.push(t);
    return reads.length === 1
      ? { status: 503, body: { error: { code: "http.503" } } }
      : { status: 200, body: { data: { id: 900, status: "waiting", poll_after_seconds: 2 } } };
  };
  const deps = { http: slow, now: () => t, readJob: () => Promise.resolve(job), writeJob: (j: Job) => { job = j; return Promise.resolve(); } };
  const started = await runStage(args("provision"), deps);
  // The POST answered at 08:00:04.4 (after the group read); its 2 s run from then.
  assertEquals(started.nextReadAt, "2026-10-23T08:00:06.400Z");
  await runStage(args("provision"), deps);
  assertEquals(reads.length, 0);
  t = Date.parse("2026-10-23T08:00:06.400Z");
  const failed = await runStage(args("provision"), deps);
  assertEquals(reads.length, 1);
  assertEquals(failed.nextReadAt, "2026-10-23T08:00:38.600Z");
  t = Date.parse("2026-10-23T08:00:38.600Z");
  const read = await runStage(args("provision"), deps);
  assertEquals(reads.length, 2);
  assertEquals(read.nextReadAt, "2026-10-23T08:00:42.800Z");
});

Deno.test("counters count attempts per subtask and reset the handoff streak on a pass", () => {
  const e = (payload: Record<string, unknown>) => env(payload, "t");
  assertEquals(counters("implement", 11, "ready_for_review", {}), { attempt: 1 });
  assertEquals(counters("implement", 11, "ready_for_review", { change: e({ subtask: 11, attempt: 2 }) }), { attempt: 3 });
  assertEquals(counters("implement", 12, "ready_for_review", { change: e({ subtask: 11, attempt: 5 }) }), { attempt: 1 });
  const change = e({ subtask: 11, attempt: 2 });
  assertEquals(counters("handoff", 11, "failed", { change }), { consecutiveFailures: 1, implementAttempts: 2 });
  assertEquals(counters("handoff", 11, "failed", { change, handoff: e({ subtask: 11, outcome: "failed", consecutiveFailures: 3 }) }), { consecutiveFailures: 4, implementAttempts: 2 });
  assertEquals(counters("handoff", 11, "passed", { change, handoff: e({ subtask: 11, outcome: "failed", consecutiveFailures: 3 }) }), { consecutiveFailures: 0, implementAttempts: 2 });
  assertEquals(counters("handoff", 11, "failed", { change, handoff: e({ subtask: 11, outcome: "passed", consecutiveFailures: 0 }) }), { consecutiveFailures: 1, implementAttempts: 2 });
  assertEquals(counters("handoff", 12, "failed", { handoff: e({ subtask: 11, outcome: "failed", consecutiveFailures: 3 }) }), { consecutiveFailures: 1, implementAttempts: 0 });
  assertEquals(counters("review", 11, "changes_requested", { change, review: e({ subtask: 11, attempt: 2 }) }), { attempt: 3, implementAttempts: 2 });
  assertEquals(counters("review", 12, "approved", { review: e({ subtask: 11, attempt: 2 }) }), { attempt: 1, implementAttempts: 0 });
});

Deno.test("a failed provision records whether it ended the Orbit group", async () => {
  const cancelled = fakeGateway({ groupStatus: "todo", operation: { id: 900, status: "cancelled" } });
  const open = await settle(args("provision"), cancelled.http);
  assertEquals(open.evidence?.groupEnded, false);
  assertEquals(open.evidence?.groupStatus, "todo");
  const failed = fakeGateway({ groupStatus: "failed", operation: { id: 900, status: "failed", error_code: "task_vm.invalid_config" } });
  const ended = await settle(args("provision"), failed.http);
  assertEquals(ended.evidence?.groupEnded, true);
  const refused = fakeGateway({ groupStatus: "running", start: () => ({ status: 409, body: { error: { code: "tasks.already_claimed" } } }) });
  const claimed = await runStage(args("provision"), harness().deps(refused.http));
  assertEquals(claimed.evidence?.groupEnded, false);
});

Deno.test("a parked run raises assistance once with a plain reason, and clears it when it moves on", async () => {
  const gw = fakeGateway({ groupStatus: "running", tasks: [{ id: 11, position: 1, status: "running" }] });
  const posts: Record<string, unknown>[] = [];
  const http: Http = async (method, path, body, key) => {
    if (method === "POST" && path.endsWith("/comments")) {
      posts.push(body!);
      gw.tasks[0].assistance_requested = body!.type === "assistance_requested";
      return { status: 201, body: { data: {} } };
    }
    return gw.http(method, path, body, key);
  };
  let rec: import("../extensions/behaviors/_lib/gateway_stage.ts").AssistanceRecord | null = null;
  const failedBaseline: Job = {
    key: "tg-7.baseline.c1", workItem: "tg-7", stage: "baseline", group: 7, subtask: 11, state: "succeeded", launches: 1, launchedNow: false,
    request: null, operationId: 600, operationStatus: "succeeded", lastError: null, inputIdentity: "{}", nextReadAt: null,
    artifacts: { baseline: { subtask: 11, outcome: "failed", checkId: 2039 } }, evidence: { status: "succeeded" },
  };
  const deps = {
    http,
    readRecord: () => Promise.resolve(rec),
    writeRecord: (r: typeof rec) => { rec = r; return Promise.resolve(); },
    readJob: (key: string) => Promise.resolve(key === "tg-7.baseline.c1" ? failedBaseline : null),
  };
  const parked = { workItem: "tg-7", parked: true, stage: "baseline", cycle: 1, reason: "awaiting human approval 'baseline-override'" };
  await syncAssistance(parked, deps);
  await syncAssistance(parked, deps);
  assertEquals(posts.length, 1);
  assertEquals(posts[0].type, "assistance_requested");
  assertEquals(posts[0].body, "Swamp parked tg-7 at baseline, cycle 1: the baseline check 2039 ended failed. Waiting for: awaiting human approval 'baseline-override'");
  assertEquals(rec!.subtask, 11);
  await syncAssistance({ ...parked, parked: false, stage: "implement", reason: "" }, deps);
  assertEquals(posts.map((p) => p.type), ["assistance_requested", "resolution"]);
  assertEquals(rec!.subtask, null);
  await syncAssistance({ ...parked, parked: false, stage: "implement", reason: "" }, deps);
  assertEquals(posts.length, 2);
});

Deno.test("assistance already on the subtask is not posted again after a lost record", async () => {
  const gw = fakeGateway({ groupStatus: "running", tasks: [{ id: 11, position: 1, status: "running" }] });
  gw.tasks[0].assistance_requested = true;
  const posts: unknown[] = [];
  const http: Http = (method, path, body, key) => {
    if (method === "POST" && path.endsWith("/comments")) posts.push(body);
    return gw.http(method, path, body, key);
  };
  const rec = await syncAssistance(
    { workItem: "tg-7", parked: true, stage: "handoff", cycle: 4, reason: "x" },
    { http, readRecord: () => Promise.resolve(null), writeRecord: () => Promise.resolve(), readJob: () => Promise.resolve(null) },
  );
  assertEquals(posts, []);
  assertEquals(rec.subtask, 11);
  assertEquals(assistanceBody({ workItem: "tg-7", parked: true, stage: "publish", cycle: 1, reason: "r" }, ""), "Swamp parked tg-7 at publish, cycle 1. Waiting for: r");
});

Deno.test("known limitation: a gate reached after every subtask ended raises no board assistance (no group-level route yet)", async () => {
  const gw = fakeGateway({ groupStatus: "running", tasks: [{ id: 11, position: 1, status: "completed" }] });
  const posts: unknown[] = [];
  const http: Http = (method, path, body, key) => {
    if (method === "POST" && path.endsWith("/comments")) posts.push(body);
    return gw.http(method, path, body, key);
  };
  let rec: import("../extensions/behaviors/_lib/gateway_stage.ts").AssistanceRecord | null = null;
  const deps = { http, readRecord: () => Promise.resolve(rec), writeRecord: (r: typeof rec) => { rec = r; return Promise.resolve(); }, readJob: () => Promise.resolve(null) };
  const parked = { workItem: "tg-7", parked: true, stage: "publish", cycle: 1, reason: "awaiting human approval 'publish-retry'" };
  await syncAssistance(parked, deps);
  await syncAssistance(parked, deps);
  await syncAssistance({ ...parked, parked: false, stage: "published" }, deps);
  assertEquals(posts, []);
  assertEquals(rec!.subtask, null);
});

Deno.test("merged clears the error of a retried complete once the group is completed", async () => {
  const gw = fakeGateway({ groupStatus: "running", watched: "merged" });
  let completed = false;
  let timeouts = 1;
  // The first complete call times out on the client while the Gateway goes on to complete the group.
  const http: Http = async (method, path, body, key) => {
    if (method === "POST" && path.endsWith("/complete")) {
      completed = true;
      if (timeouts-- > 0) return { status: 0, body: { error: { code: "transport", message: "curl: (28) Operation timed out" } } };
      return { status: 200, body: { data: {} } };
    }
    const r = await gw.http(method, path, body, key);
    if (method === "GET" && completed && r.body?.data) (r.body.data as Record<string, unknown>).status = "completed";
    return r;
  };
  const h = harness();
  const first = await runStage(args("merged"), h.deps(http));
  assertEquals(first.state, "running");
  assertStringIncludes(first.lastError!, "timed out");
  const second = await runStage(args("merged"), h.deps(http));
  assertEquals(second.state, "succeeded");
  assertEquals(second.lastError, null);

  // A complete call that succeeds after an earlier failed one clears that error too.
  const flaky = fakeGateway({ groupStatus: "running", watched: "merged" });
  let fail = true;
  const http2: Http = (method, path, body, key) => {
    if (method === "POST" && path.endsWith("/complete") && fail) {
      fail = false;
      return Promise.resolve({ status: 503, body: { error: { code: "http.503" } } });
    }
    return flaky.http(method, path, body, key);
  };
  const h2 = harness();
  assertStringIncludes((await runStage(args("merged"), h2.deps(http2))).lastError!, "503");
  assertEquals((await runStage(args("merged"), h2.deps(http2))).lastError, null);
});
