// PR1500 response fixtures; no network, task group, or effect dispatch.
import { assertEquals, assertRejects, assertStringIncludes } from "jsr:@std/assert@1.0.19";
import { type AssistanceRecord, type Http, syncAssistance } from "../extensions/behaviors/_lib/gateway_stage.ts";
import { behavior as driver } from "../extensions/behaviors/orbit_driver.ts";
import historyFixture from "./fixtures/group-assistance/default.json" with { type: "json" };
import requestFixture from "./fixtures/group-assistance/requested.json" with { type: "json" };
import resolutionFixture from "./fixtures/group-assistance/resolved.json" with { type: "json" };

const ERA = "2026-10-23T12:00:00.000Z";
const parked = { workItem: "tg-7", parked: true, stage: "publish", cycle: 1, reason: "awaiting human approval 'publish-retry'", gateIds: ["publish-retry"], factory: "orbit-groups" };

function harness() {
  const group = {
    id: 7, orchestration: "external", title: "Deps", brief: "Update deps", status: "running",
    assistance_requested: false, assistance_kind: null as string | null, assistance_question: null as string | null, assistance_reason: null as string | null,
    tasks: [{ id: 11, position: 1, title: "Bump", brief: "Do it", status: "completed", assistance_requested: false, assistance_kind: null as string | null, assistance_question: null as string | null, assistance_reason: null as string | null }],
  };
  type Comment = (typeof historyFixture.body.data)[number];
  const history: Comment[] = [];
  const calls: { method: string; path: string; body?: Record<string, unknown> }[] = [];
  let rec: AssistanceRecord | null = null;
  let era = ERA;
  let approval: Record<string, unknown> | null = null;
  let loseReply = "";
  let failHistory = false;
  let failWriteRecord = false;
  let refuseNextPost = false;
  let afterHistory: (() => void) | null = null;
  const apply = (type: string, body: string, author: string) => {
    const fixture = type === "resolution" ? resolutionFixture : requestFixture;
    const c = { ...fixture.body.data, id: history.length + 1, task_group_id: 7, task_id: 7, type, body, author };
    history.unshift(c);
    if (type === "assistance_requested") {
      group.assistance_requested = true;
      group.assistance_kind = "direction";
      group.assistance_question = group.assistance_reason = body;
    } else if (group.assistance_kind === "direction") {
      group.assistance_requested = false;
      group.assistance_kind = group.assistance_question = group.assistance_reason = null;
    }
    return c;
  };
  const http: Http = (method, path, body) => {
    calls.push({ method, path, body });
    const result = (status: number, data: unknown) => Promise.resolve({ status, body: { data, meta: historyFixture.body.meta } });
    if (method === "GET" && path === "/api/v1/task-groups/7") return result(200, structuredClone(group));
    if (method === "GET" && path === "/api/v1/task-groups/7/comments") {
      if (failHistory) return Promise.resolve({ status: 503, body: { error: { code: "unavailable" } } });
      const snapshot = structuredClone(history);
      afterHistory?.(); afterHistory = null;
      return result(200, snapshot);
    }
    if (method === "POST" && path === "/api/v1/task-groups/7/comments") {
      if (refuseNextPost) {
        refuseNextPost = false;
        return Promise.resolve({ status: 409, body: { error: { code: "tasks.assistance_subtask_open" } } });
      }
      const c = apply(String(body!.type), String(body!.body), String(body!.author));
      if (loseReply === body!.type) {
        loseReply = "";
        return Promise.resolve({ status: 0, body: { error: { code: "transport" } } });
      }
      return result(201, c);
    }
    if (method === "POST" && path === "/api/v1/task-groups/7/tasks/12/comments") {
      const child = group.tasks.find((t) => t.id === 12)!;
      const ownsGroup = child.assistance_requested && group.assistance_requested &&
        child.assistance_kind === group.assistance_kind && child.assistance_question === group.assistance_question && child.assistance_reason === group.assistance_reason;
      child.assistance_requested = body!.type === "assistance_requested";
      child.assistance_kind = child.assistance_requested ? "direction" : null;
      child.assistance_question = child.assistance_reason = child.assistance_requested ? String(body!.body) : null;
      if (child.assistance_requested || ownsGroup) {
        group.assistance_requested = child.assistance_requested;
        group.assistance_kind = child.assistance_kind;
        group.assistance_question = child.assistance_question;
        group.assistance_reason = child.assistance_reason;
      }
      return result(201, { id: calls.length, task_group_id: 7, task_id: 12, ...body });
    }
    throw new Error(`Unexpected call (including effect): ${method} ${path}`);
  };
  return {
    group, history, calls, apply,
    deps: {
      http,
      readRecord: () => Promise.resolve(rec),
      writeRecord: (r: AssistanceRecord) => {
        if (failWriteRecord) { failWriteRecord = false; throw new Error("record interrupted"); }
        rec = structuredClone(r); return Promise.resolve();
      },
      readJob: () => Promise.resolve(null),
      readRun: () => Promise.resolve({ workItem: "tg-7", startedAt: era }),
      readApproval: (_gate: string) => Promise.resolve(approval),
    },
    record: () => rec,
    forget: () => { rec = null; },
    approve: (overrides: Record<string, unknown> = {}) => {
      approval = { workItem: "tg-7", gateId: "publish-retry", stageId: "publish", cycle: 1, actor: "nick", decision: "approved", decidedAt: "2026-10-23T12:01:00.000Z", ...overrides };
    },
    reset: () => { era = "2026-10-23T13:00:00.000Z"; },
    lose: (type: string) => { loseReply = type; },
    historyUnavailable: () => { failHistory = true; },
    interruptRecord: () => { failWriteRecord = true; },
    refuse: () => { refuseNextPost = true; },
    changeAfterHistory: (fn: () => void) => { afterHistory = fn; },
    posts: () => calls.filter((c) => c.method === "POST"),
  };
}

Deno.test("post-child raises publish-retry and resolves only after its exact explicit approval", async () => {
  const h = harness();
  await syncAssistance(parked, h.deps);
  assertEquals(h.posts().map((c) => c.path), ["/api/v1/task-groups/7/comments"]);
  const body = String(h.posts()[0].body!.body);
  for (const text of ["tg-7", "publish", "cycle 1", "publish-retry", ERA]) assertStringIncludes(body, text);
  await syncAssistance(parked, h.deps);
  await syncAssistance({ ...parked, parked: false, stage: "published", gateIds: [] }, h.deps);
  assertEquals(h.posts().length, 1, "unparked without a decision is not approval");
  h.approve();
  await syncAssistance({ ...parked, parked: false, stage: "published", gateIds: [] }, h.deps);
  assertEquals(h.posts().map((c) => c.body!.type), ["assistance_requested", "resolution"]);
  assertEquals(h.group.assistance_requested, false);
  assertEquals(h.group.tasks[0].status, "completed");
});

Deno.test("post-child republish uses the published stage's own gate decision", async () => {
  const h = harness();
  const p = { ...parked, stage: "published", gateIds: ["republish"], reason: "awaiting human approval 'republish'" };
  await syncAssistance(p, h.deps);
  h.approve({ gateId: "republish", stageId: "published" });
  await syncAssistance({ ...p, parked: false, stage: "publish" }, h.deps);
  assertEquals(h.posts().map((c) => c.body!.type), ["assistance_requested", "resolution"]);
});

Deno.test("post-child wrong, rejected and old gate decisions never resolve a request", async () => {
  for (const bad of [{ workItem: "tg-8" }, { gateId: "republish" }, { stageId: "review" }, { cycle: 2 }, { decision: "rejected" }, { decidedAt: "2026-10-23T11:00:00.000Z" }]) {
    const h = harness();
    await syncAssistance(parked, h.deps);
    h.approve(bad);
    await syncAssistance({ ...parked, parked: false }, h.deps);
    assertEquals(h.posts().length, 1, JSON.stringify(bad));
    assertEquals(h.group.assistance_requested, true);
  }
});

Deno.test("post-child operator resolution does not approve or re-raise the factory gate", async () => {
  const h = harness();
  await syncAssistance(parked, h.deps);
  h.apply("resolution", "Here is context; no gate approval.", "nick");
  h.forget();
  await syncAssistance(parked, h.deps);
  await syncAssistance({ ...parked, parked: false }, h.deps);
  assertEquals(h.posts().length, 1);
  assertEquals(h.group.assistance_requested, false);
});

Deno.test("post-child leaves operator replacements and every safety cause alone", async () => {
  for (const kind of ["direction", "failure", "removal_failure", "observation"]) {
    const h = harness();
    await syncAssistance(parked, h.deps);
    h.apply("assistance_requested", "Operator owns this request", "nick");
    h.group.assistance_kind = kind;
    h.approve();
    await syncAssistance(parked, h.deps);
    await syncAssistance({ ...parked, parked: false }, h.deps);
    assertEquals(h.posts().length, 1, kind);
    assertEquals(h.group.assistance_reason, "Operator owns this request");
    assertEquals(h.group.assistance_kind, kind);
  }
});

Deno.test("post-child does not replace a pre-existing safety request to raise Direction", async () => {
  const h = harness();
  h.group.assistance_requested = true;
  h.group.assistance_kind = "removal_failure";
  h.group.assistance_reason = "Workspace holder uncertain";
  await syncAssistance(parked, h.deps);
  assertEquals(h.posts(), []);
});

Deno.test("post-child lost request reply and lost local record reconcile from current request/history", async () => {
  const h = harness();
  h.lose("assistance_requested");
  await assertRejects(() => syncAssistance(parked, h.deps));
  h.forget();
  await syncAssistance(parked, h.deps);
  assertEquals(h.posts().length, 1);
  h.approve();
  h.lose("resolution");
  await assertRejects(() => syncAssistance({ ...parked, parked: false }, h.deps));
  h.forget();
  await syncAssistance({ ...parked, parked: false }, h.deps);
  assertEquals(h.posts().length, 2);
  assertEquals(h.group.assistance_requested, false);
});

Deno.test("post-child permits duplicate audit requests but never treats them as extra effects", async () => {
  const h = harness();
  await syncAssistance(parked, h.deps);
  h.apply("assistance_requested", h.group.assistance_reason!, "swamp");
  h.forget();
  h.approve();
  await syncAssistance({ ...parked, parked: false }, h.deps);
  assertEquals(h.posts().map((c) => c.body!.type), ["assistance_requested", "resolution"]);
});

Deno.test("post-child restart after approval and advance recovers ownership from history", async () => {
  const h = harness();
  await syncAssistance(parked, h.deps);
  h.approve();
  h.forget();
  await syncAssistance({ ...parked, parked: false, stage: "published", gateIds: [] }, h.deps);
  assertEquals(h.group.assistance_requested, false);
  assertEquals(h.posts().length, 2);
});

Deno.test("post-child reset does not adopt or clear the previous era's Direction", async () => {
  const h = harness();
  await syncAssistance(parked, h.deps);
  h.approve();
  h.reset();
  h.forget();
  await syncAssistance(parked, h.deps);
  await syncAssistance({ ...parked, parked: false }, h.deps);
  assertEquals(h.posts().length, 1);
  assertEquals(h.group.assistance_requested, true);
});

Deno.test("post-child unreadable history and interrupted local persistence perform no write", async () => {
  const h = harness();
  h.historyUnavailable();
  await assertRejects(() => syncAssistance(parked, h.deps));
  assertEquals(h.posts(), []);
  const interrupted = harness();
  interrupted.interruptRecord();
  await assertRejects(() => syncAssistance(parked, interrupted.deps));
  assertEquals(interrupted.posts(), []);
  await syncAssistance(parked, interrupted.deps);
  assertEquals(interrupted.posts().length, 1);
});

Deno.test("post-child closed/tick groups and non-ended unknown children never receive group writes", async () => {
  for (const shape of [{ status: "completed" }, { orchestration: "tick" }]) {
    const h = harness();
    Object.assign(h.group, shape);
    await syncAssistance(parked, h.deps);
    assertEquals(h.posts(), []);
  }
  const h = harness();
  h.group.tasks[0].status = "reserved";
  await syncAssistance(parked, h.deps);
  assertEquals(h.posts(), []);
});

Deno.test("post-child retry after refusal rereads history/current state and keeps its persisted body", async () => {
  const h = harness();
  h.refuse();
  await assertRejects(() => syncAssistance(parked, h.deps));
  const intent = h.record()!.groupRequest!.body;
  await syncAssistance({ ...parked, reason: "Changed explanatory output" }, h.deps);
  assertEquals(h.posts().map((c) => c.body!.body), [intent, intent]);
  assertEquals(h.calls.filter((c) => c.path.endsWith("/comments") && c.method === "GET").length, 2);
  assertEquals(h.group.assistance_requested, true);
});

Deno.test("post-child final reads preserve changed requests and newly opened children", async () => {
  for (const change of ["operator", "child", "question", "reason", "inactive-safety"]) {
    const h = harness();
    await syncAssistance(parked, h.deps);
    h.approve();
    h.changeAfterHistory(() => {
      if (change === "operator") h.apply("assistance_requested", "New operator direction", "nick");
      if (change === "child") h.group.tasks[0].status = "todo";
      if (change === "question") h.group.assistance_question = "Edited question";
      if (change === "reason") h.group.assistance_reason = "Edited reason";
      if (change === "inactive-safety") {
        h.group.assistance_requested = false;
        h.group.assistance_kind = "removal_failure";
      }
    });
    await syncAssistance({ ...parked, parked: false }, h.deps);
    assertEquals(h.posts().length, 1, change);
  }
});

Deno.test("post-child foreign or malformed history fails closed", async () => {
  const h = harness();
  await syncAssistance(parked, h.deps);
  h.history[0].task_id = 11;
  h.approve();
  await assertRejects(() => syncAssistance({ ...parked, parked: false }, h.deps));
  assertEquals(h.posts().length, 1);
});

Deno.test("board resolution through driver reconciliation never approves or dispatches a retry", async () => {
  const h = harness();
  const calls: string[] = [];
  const status = {
    started: true, stage: { id: "publish", cycle: 1, terminal: false }, unresolvedBindings: [],
    transitions: [{ name: "retry", to: "publish", manual: false, satisfied: false, cycleLimitBlocked: false,
      gates: [{ type: "human-approval", pass: false, reasons: [parked.reason] }] }],
  };
  const context = {
    globalArgs: driver.globalArguments.parse({ factory: "orbit-groups", assistanceModel: "orbit-gateway" }),
    definition: { name: "orbit-driver-groups" },
    queryData: (predicate: string) => Promise.resolve(
      predicate.includes('name == "status-tg-7"') ? [status] :
      predicate.includes('name == "state-tg-7"') ? [{ workItem: "tg-7", startedAt: ERA }] : []),
    runModel: async (call: { definition: string; method: string; arguments?: Record<string, unknown> }) => {
      calls.push(`${call.definition}.${call.method}`);
      if (call.definition === "orbit-gateway" && call.method === "assistance") {
        await syncAssistance(call.arguments as unknown as typeof parked, h.deps);
      } else if (call.definition !== "orbit-groups" || call.method !== "status") {
        throw new Error(`Unexpected approval/effect dispatch: ${calls.at(-1)}`);
      }
      return { ok: true };
    },
    writeResource: (_spec: string, name: string, _data: Record<string, unknown>) => Promise.resolve({ name }),
    logger: { info: () => {}, warning: () => {} },
  };
  await driver.methods.tick.execute({ workItems: ["tg-7"], maxSteps: 1 }, context);
  h.apply("resolution", "Context only; not permission to retry", "nick");
  h.forget();
  await driver.methods.tick.execute({ workItems: ["tg-7"], maxSteps: 1 }, context);
  await driver.methods.tick.execute({ workItems: ["tg-7"], maxSteps: 1 }, context);
  assertEquals(calls, Array(3).fill(["orbit-groups.status", "orbit-gateway.assistance"]).flat());
  assertEquals(h.posts().length, 1);
  assertEquals(h.group.assistance_requested, false);
});

// Each call gets fresh dependencies and reloads a cloned persisted record, as a
// restarted reconciler does. Keep the stored group identity; do not forget it.
function restartedDeps(h: ReturnType<typeof harness>) {
  return { ...h.deps, readRecord: () => Promise.resolve(structuredClone(h.record())) };
}

const childGate = { ...parked, stage: "baseline", gateIds: ["baseline-override"], reason: "awaiting human approval 'baseline-override'" };
function appendOpenChild(h: ReturnType<typeof harness>) {
  h.group.tasks.push({ ...h.group.tasks[0], id: 12, position: 2, status: "todo" });
}

Deno.test("resolved group ownership releases to a new open child across unparked/parked ticks and restart", async () => {
  for (const interveningUnparked of [true, false]) {
    const h = harness();
    await syncAssistance(parked, h.deps);
    h.apply("resolution", "Board answer only; no factory consent", "nick");
    await syncAssistance(parked, h.deps);
    assertEquals(h.record()!.groupRequest!.gateId, "publish-retry", "retain the reviewer's triggering state");
    appendOpenChild(h);
    if (interveningUnparked) {
      await syncAssistance({ ...childGate, parked: false, gateIds: [] }, restartedDeps(h));
      assertEquals(h.record()!.groupRequest, null);
      assertEquals(h.posts().length, 1, "local release makes no group resolution write");
    }
    await syncAssistance(childGate, restartedDeps(h));
    await syncAssistance(childGate, restartedDeps(h));
    await syncAssistance(childGate, restartedDeps(h));
    assertEquals(h.posts().map((c) => c.path), ["/api/v1/task-groups/7/comments", "/api/v1/task-groups/7/tasks/12/comments"]);
    assertStringIncludes(String(h.posts()[1].body!.body), "baseline-override");
    assertEquals(h.record()!.groupRequest, undefined);
    assertEquals(h.record()!.subtask, 12);
    assertEquals(h.group.tasks.map((t) => [t.id, t.status, t.assistance_requested]), [[11, "completed", false], [12, "todo", true]]);
    await syncAssistance({ ...childGate, parked: false, stage: "implement", gateIds: [] }, restartedDeps(h));
    assertEquals(h.posts().map((c) => [c.path, c.body!.type]), [
      ["/api/v1/task-groups/7/comments", "assistance_requested"],
      ["/api/v1/task-groups/7/tasks/12/comments", "assistance_requested"],
      ["/api/v1/task-groups/7/tasks/12/comments", "resolution"],
    ]);
    assertEquals(h.group.tasks[0].status, "completed");
  }
});

Deno.test("retained group ownership refuses child routing for active own/operator/safety or inconsistent requests", async () => {
  for (const cause of ["own", "operator", "failure", "removal_failure", "observation", "inactive-safety"]) {
    const h = harness();
    await syncAssistance(parked, h.deps);
    if (cause !== "own") {
      h.apply("resolution", "Board answer only", "nick");
      await syncAssistance(parked, h.deps);
      h.apply("assistance_requested", "Preserve this replacement", "nick");
      if (cause !== "operator") h.group.assistance_kind = cause === "inactive-safety" ? "removal_failure" : cause;
      if (cause === "inactive-safety") h.group.assistance_requested = false;
    }
    h.approve(); // Even exact factory consent cannot clear a group with an open child.
    appendOpenChild(h);
    const before = structuredClone(h.group);
    await syncAssistance({ ...childGate, parked: false, gateIds: [] }, restartedDeps(h));
    await syncAssistance(childGate, restartedDeps(h));
    await syncAssistance(childGate, restartedDeps(h));
    assertEquals(h.posts().length, 1, cause);
    assertEquals(h.group, before, cause);
    assertEquals(h.record()!.groupRequest!.gateId, "publish-retry", cause);
  }
});

Deno.test("retained group ownership is not released on unreadable history with an open child", async () => {
  const h = harness();
  await syncAssistance(parked, h.deps);
  h.apply("resolution", "Board answer only", "nick");
  await syncAssistance(parked, h.deps);
  appendOpenChild(h);
  h.historyUnavailable();
  await assertRejects(() => syncAssistance({ ...childGate, parked: false }, restartedDeps(h)));
  await assertRejects(() => syncAssistance(childGate, restartedDeps(h)));
  assertEquals(h.record()!.groupRequest!.gateId, "publish-retry");
  assertEquals(h.posts().length, 1);
});

const causes = ["direction", "failure", "removal_failure", "observation"];
function independentRequest(h: ReturnType<typeof harness>, kind: string) {
  // A supported operator comment on an ended child (or a group safety cause)
  // can set these current fields without touching group-owned comment history.
  h.group.assistance_requested = true;
  h.group.assistance_kind = kind;
  h.group.assistance_question = kind === "direction" ? "Keep independent request" : null;
  h.group.assistance_reason = "Keep independent request";
}
async function resolvedOpenChild() {
  const h = harness();
  await syncAssistance(parked, h.deps);
  h.apply("resolution", "Board answer only; no factory consent", "nick");
  await syncAssistance(parked, h.deps);
  appendOpenChild(h);
  return h;
}

Deno.test("child write boundary preserves every independent cause observed after group identity release", async () => {
  const overwritten: string[] = [];
  for (const kind of causes) {
    const h = await resolvedOpenChild();
    const history = structuredClone(h.history);
    const original = h.deps.http;
    let reads = 0;
    h.deps.http = (method, path, body, key) => {
      if (method === "GET" && path === "/api/v1/task-groups/7" && ++reads === 3) independentRequest(h, kind);
      return original(method, path, body, key);
    };
    await syncAssistance(childGate, restartedDeps(h));
    // The old group identity was released before the fresh child write snapshot.
    assertEquals(h.record()!.groupRequest ?? null, null);
    for (let n = 0; n < 3; n++) await syncAssistance(childGate, restartedDeps(h));
    await syncAssistance({ ...childGate, parked: false }, restartedDeps(h));
    if (h.posts().length !== 1 || h.group.assistance_kind !== kind || h.group.assistance_reason !== "Keep independent request") overwritten.push(kind);
    assertEquals(h.history, history, "fixture emits no forbidden group POST or history entry");
    assertEquals(h.group.tasks.map((t) => [t.status, t.assistance_requested]), [["completed", false], ["todo", false]]);
  }
  assertEquals(overwritten, [], "all four requests were already in the final snapshot before POST");
});

Deno.test("child write boundary retries after an independent request clears instead of latching a skip", async () => {
  const h = await resolvedOpenChild();
  await syncAssistance({ ...childGate, parked: false }, restartedDeps(h));
  independentRequest(h, "direction");
  await syncAssistance(childGate, restartedDeps(h));
  await syncAssistance(childGate, restartedDeps(h));
  assertEquals(h.posts().length, 1);
  h.group.assistance_requested = false;
  h.group.assistance_kind = h.group.assistance_question = h.group.assistance_reason = null;
  await syncAssistance(childGate, restartedDeps(h));
  await syncAssistance(childGate, restartedDeps(h));
  assertEquals(h.posts().map((c) => c.path), ["/api/v1/task-groups/7/comments", "/api/v1/task-groups/7/tasks/12/comments"]);
});

Deno.test("child write boundary preserves observed independent requests on resolution and stage-change paths", async () => {
  for (const kind of causes) {
    for (const parkedAgain of [false, true]) {
      const h = await resolvedOpenChild();
      await syncAssistance(childGate, restartedDeps(h));
      const original = h.deps.http;
      let reads = 0;
      h.deps.http = (method, path, body, key) => {
        if (method === "GET" && path === "/api/v1/task-groups/7" && ++reads === 2) independentRequest(h, kind);
        return original(method, path, body, key);
      };
      const childBefore = structuredClone(h.group.tasks[1]);
      const next = { ...childGate, parked: parkedAgain, stage: "implement", gateIds: [] };
      await syncAssistance(next, restartedDeps(h));
      await syncAssistance(next, restartedDeps(h));
      assertEquals(h.posts().map((c) => c.body!.type), ["assistance_requested", "assistance_requested"], kind);
      assertEquals(h.group.assistance_kind, kind);
      assertEquals(h.group.assistance_reason, "Keep independent request");
      assertEquals(h.group.tasks[1], childBefore, "do not clear the old child's request before refusing the new gate");
    }
  }
});

Deno.test("child write boundary never resolves a safety request copied onto the child", async () => {
  for (const kind of causes.filter((k) => k !== "direction")) {
    const h = await resolvedOpenChild();
    await syncAssistance(childGate, restartedDeps(h));
    independentRequest(h, kind);
    Object.assign(h.group.tasks[1], {
      assistance_requested: true, assistance_kind: kind, assistance_question: null, assistance_reason: h.group.assistance_reason,
    });
    await syncAssistance({ ...childGate, parked: false }, restartedDeps(h));
    await syncAssistance({ ...childGate, parked: false }, restartedDeps(h));
    assertEquals(h.posts().length, 2, kind);
    assertEquals(h.group.assistance_kind, kind);
    assertEquals(h.group.tasks[1].assistance_kind, kind);
  }
});

Deno.test("child write boundary resolutions recover after independent assistance is explicitly cleared", async () => {
  const h = await resolvedOpenChild();
  await syncAssistance(childGate, restartedDeps(h));
  independentRequest(h, "direction");
  await syncAssistance({ ...childGate, parked: false }, restartedDeps(h));
  assertEquals(h.posts().length, 2);
  // Model the operator clearing only the independent group request. The child
  // still has its own Direction, so a legitimate child resolution remains valid.
  h.group.assistance_requested = false;
  h.group.assistance_kind = h.group.assistance_question = h.group.assistance_reason = null;
  await syncAssistance({ ...childGate, parked: false }, restartedDeps(h));
  await syncAssistance({ ...childGate, parked: false }, restartedDeps(h));
  assertEquals(h.posts().map((c) => c.body!.type), ["assistance_requested", "assistance_requested", "resolution"]);
  assertEquals(h.posts().at(-1)!.path, "/api/v1/task-groups/7/tasks/12/comments");
  assertEquals(h.group.tasks[1].assistance_requested, false);
});

Deno.test("child write boundary checks all current fields instead of only flags or matching reasons", async () => {
  for (const field of ["assistance_kind", "assistance_question", "assistance_reason"] as const) {
    for (const clear of [false, true]) {
      const h = await resolvedOpenChild();
      if (clear) await syncAssistance(childGate, restartedDeps(h));
      h.group[field] = "Observed different field";
      const before = structuredClone(h.group);
      const args = clear ? { ...childGate, parked: false } : childGate;
      await syncAssistance(args, restartedDeps(h));
      await syncAssistance(args, restartedDeps(h));
      assertEquals(h.group, before, `${field}: clear=${clear}`);
      assertEquals(h.posts().length, clear ? 2 : 1, field);
    }
  }
});
