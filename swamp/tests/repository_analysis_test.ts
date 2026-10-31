import { assert, assertEquals, assertRejects, assertThrows } from "jsr:@std/assert@1.0.19";
import {
  type AnalysisResult,
  briefSource,
  canonical,
  planConsentNote,
  type ReportEnvelope,
  sha256,
  strictJson,
  validateAnalysis,
  validatePlan,
} from "../extensions/behaviors/_lib/repository_analysis.ts";
import { analysisCandidate, type Http, type Job, runStage, type StageArgs } from "../extensions/behaviors/_lib/gateway_stage.ts";
import { analysisFixture, bound, HEAD, TREE } from "./_support/analysis_fixture.ts";

const group = {
  id: 7,
  title: "Feature",
  brief: "Documentation first.",
  status: "running",
  tasks: [{ id: 11, position: 1, title: "Documentation", brief: "Write the contract.", status: "running", deliverables: [] }],
};
const f = await analysisFixture(group);
const expected = { operation: 899, group: 7, subtask: 11, plan: f.plan, candidate: { head: HEAD, tree: TREE }, instanceId: 1, nodeId: 2 };

Deno.test("fresh analysis keeps reviewer retries distinct from author rework and next-child work", () => {
  const candidate = { head: HEAD, tree: TREE };
  const base: StageArgs = {
    workItem: "tg-7",
    key: "tg-7.analyze.c1",
    stage: "analyze",
    handoff: bound({ subtask: 11, checkId: 1, commit: HEAD, candidate, outcome: "passed" }, "2026-10-24T10:00:00Z"),
  };
  assertEquals(analysisCandidate(base).purpose, "review");
  for (const outcome of ["blocked", "none", "changes_requested", "approved"]) {
    const args = { ...base, review: bound({ subtask: 11, candidate, outcome }, "2026-10-24T10:01:00Z") };
    assertEquals(analysisCandidate(args).purpose, ["blocked", "none"].includes(outcome) ? "review" : "implement");
  }
});
async function reportResult(edit: (v: ReportEnvelope) => void) {
  const r = structuredClone(f.result);
  const e: ReportEnvelope = JSON.parse(r.artifact.content);
  edit(e);
  r.artifact.content = JSON.stringify(e);
  r.artifact.bytes = new TextEncoder().encode(r.artifact.content).length;
  r.artifact.sha256 = await sha256(r.artifact.content);
  r.outcome = e.status;
  return r;
}

Deno.test("complete typed plan: native digest, rename endpoints, cumulative revisions and exact brief", async () => {
  const entries = [{ change: "delete" as const, path: "deleted.txt" }, { change: "rename" as const, from: "old.txt", path: "renamed.txt" }, {
    change: "add" as const,
    path: "新.txt",
  }];
  const v = await analysisFixture(group, entries);
  assertEquals(await validatePlan(v.plan, briefSource(group), "tg-7"), v.plan);
  for (
    const entry of [{ change: "add", path: "../escape" }, { change: "rename", from: "old.txt", path: "old.txt" }, { change: "add", path: " new.txt" }, {
      change: "add",
      path: "dir/",
    }]
  ) {
    const bad = { ...v.plan, manifest: { ...v.plan.manifest, paths: [entry] } };
    bad.plan_sha256 = await sha256(canonical(bad.manifest));
    await assertRejects(() => validatePlan(bad, briefSource(group), "tg-7"));
  }
  await assertRejects(() => validatePlan(v.plan, "changed brief", "tg-7"));
  const changed = structuredClone(v.plan);
  changed.manifest.revision++;
  changed.manifest.paths.pop();
  changed.plan_sha256 = await sha256(canonical(changed.manifest));
  await assertRejects(() => validatePlan(changed, briefSource(group), "tg-7", v.plan));
  const reordered = structuredClone(v.plan);
  reordered.manifest.paths.reverse();
  reordered.plan_sha256 = await sha256(canonical(reordered.manifest));
  await assertRejects(() => validatePlan(reordered, briefSource(group), "tg-7"));
});

Deno.test("consent identifies the manifest and every plan scope field without delimiter ambiguity", () => {
  const note = planConsentNote(f.plan);
  for (
    const edit of [{ plan_sha256: "c".repeat(64) }, { base: "d".repeat(40) }, { repository: `${f.plan.repository}:extra` }, { branch: "other" }, {
      docsSubtask: 12,
    }]
  ) {
    assert(note !== planConsentNote({ ...f.plan, ...edit }));
  }
  assert(planConsentNote({ ...f.plan, repository: "a", branch: "b:c" }) !== planConsentNote({ ...f.plan, repository: "a:b", branch: "c" }));
});

Deno.test("full report rejects duplicate/missing/malformed/truncated/oversize/wrong-candidate evidence", async () => {
  assertEquals((await validateAnalysis(f.result, expected)).outcome, "ready");
  for (const raw of ['{"x":1,"x":2}', '{"x":1', '{"x":1} trailing', '{"x":1e999}', '{"x":"\\ud800"}']) assertThrows(() => strictJson(raw, 65536));
  for (
    const edit of [
      (r: AnalysisResult) => {
        r.artifact.content = r.artifact.content.slice(0, -1);
      },
      (r: AnalysisResult) => {
        Reflect.deleteProperty(r, "artifact");
      },
      (r: AnalysisResult) => {
        r.provenance.group = 8;
      },
      (r: AnalysisResult) => {
        r.provenance.before.tree = "c".repeat(40);
      },
      (r: AnalysisResult) => {
        r.provenance.plan_revision++;
      },
      (r: AnalysisResult) => {
        r.provenance.instance_id++;
      },
      (r: AnalysisResult) => {
        r.artifact.bytes = 65537;
      },
    ]
  ) {
    const r = structuredClone(f.result);
    edit(r);
    await assertRejects(() => validateAnalysis(r, expected));
  }
  const missing = await reportResult((e) => {
    e.report.data.paths = [];
  });
  await assertRejects(() => validateAnalysis(missing, expected));
  const changed = structuredClone(f.result);
  changed.provenance.after.tree = "c".repeat(40);
  changed.outcome = "changed";
  assertEquals((await validateAnalysis(changed, expected)).outcome, "changed");
});

Deno.test("exit zero is insufficient; errors, unresolved ownership and generators block; omission requires revised plan", async () => {
  for (
    const edit of [
      (e: ReportEnvelope) => {
        e.report.data.errors = ["unresolved surface"];
      },
      (e: ReportEnvelope) => {
        e.report.data.surfaces = [{ path: "new.txt", kind: "api", owner: "unowned", reason: "unresolved", generator_status: null }];
      },
      ...(["failed", "missing", "stale"] as const).map((status) => (e: ReportEnvelope) => {
        e.report.data.surfaces_handled_by_generator = [{ generator: "bin/docs-openapi", paths: ["new.txt"], status, current: false }];
      }),
    ]
  ) {
    const r = await reportResult((e) => {
      edit(e);
      e.status = "blocked";
    });
    assertEquals((await validateAnalysis(r, expected)).outcome, "blocked");
    r.outcome = "ready";
    await assertRejects(() => validateAnalysis(r, expected));
  }
  const omitted = await reportResult((e) => {
    e.report.data.paths = ["extra-untracked.txt", "new.txt"];
  });
  assertEquals((await validateAnalysis(omitted, expected)).omitted, ["extra-untracked.txt"]);
  assertEquals((await validateAnalysis(omitted, expected)).outcome, "plan_required");
});

function stageHarness(
  responses: {
    start?: "lost" | "busy";
    status?: string;
    result?: unknown;
    code?: string;
    group?: typeof group;
    docsReview?: Record<string, unknown>;
    operation?: Record<string, unknown>;
    operationId?: number;
  } = {},
) {
  let job: Job | null = null;
  const calls: { method: string; path: string; body?: Record<string, unknown>; key?: string }[] = [];
  let starts = 0, time = 0;
  const http: Http = (method, path, body, key) => {
    calls.push({ method, path, body: structuredClone(body), key });
    const ok = (data: unknown, status = 200) => Promise.resolve({ status, body: { data } });
    if (method === "GET" && path.endsWith("/task-groups/7")) return ok(responses.group ?? group);
    if (method === "GET" && path.endsWith("/operations/898")) return ok(responses.docsReview ?? {});
    if (method === "POST") {
      starts++;
      if (starts === 1 && responses.start === "lost") return Promise.resolve({ status: 0, body: null });
      if (starts === 1 && responses.start === "busy") return Promise.resolve({ status: 409, body: { error: { code: "operation.target_busy" } } });
      return ok({ id: responses.operationId ?? 899, status: "queued", poll_after_seconds: 1 }, 202);
    }
    return ok(
      responses.operation ??
        { id: responses.operationId ?? 899, kind: "tasks.analyze", target_type: "task_group", target_id: 7,
          input: calls.find((c) => c.method === "POST")?.body, status: responses.status ?? "succeeded", result: responses.result ?? f.result, error_code: responses.code },
    );
  };
  const deps = {
    http,
    readJob: () => Promise.resolve(job),
    writeJob: (j: Job) => {
      job = structuredClone(j);
      return Promise.resolve();
    },
    now: () => time += 10000,
  };
  const args: StageArgs = { workItem: "tg-7", stage: "analyze", key: "tg-7.analyze.c1", ...f.inputs };
  return { args, deps, calls, get: () => job };
}

Deno.test("lost response/restart recovers exact persisted analysis key and request; changed plan drains original", async () => {
  const h = stageHarness({ start: "lost" });
  await runStage(h.args, h.deps);
  const original = structuredClone(h.get()!.request);
  await runStage(h.args, h.deps); // new invocation, only persisted job survives
  assertEquals(h.get()!.request, original);
  assertEquals(h.calls.filter((c) => c.method === "POST").map((c) => c.key), [original!.idempotencyKey, original!.idempotencyKey]);
  const newer = structuredClone(f.plan);
  newer.manifest.revision++;
  newer.plan_sha256 = await sha256(canonical(newer.manifest));
  const done = await runStage({ ...h.args, plan: bound(newer) }, h.deps);
  assertEquals(done.artifacts.analysis.outcome, "stale");
  assertEquals(done.artifacts.analysis.result, f.result);
  assertEquals(h.calls.filter((c) => c.method === "POST").length, 2);
});

Deno.test("failed and cancelled turns retain only scoped terminal candidates, with no receipt approval or snapshot fallback", async () => {
  const edited = { head: HEAD, tree: "c".repeat(40) }, reset = { head: HEAD, tree: "d".repeat(40) };
  for (const stage of ["implement", "review"] as const) {
    for (const status of ["failed", "cancelled"]) {
      const operation = {
        id: 899,
        kind: "tasks.turn",
        target_type: "task_group",
        target_id: 7,
        status,
        input: { subtask: 11, role: stage === "implement" ? "implementer" : "reviewer", thread: "new" },
        result: { thread: 44, state: "failed", receipt: { outcome: "approved" }, after: edited, after_reset: reset },
      };
      const cases = [
        { operation, candidate: stage === "implement" ? edited : reset },
        ...[
          { result: null },
          { result: { ...operation.result, after: {}, after_reset: null } },
          { id: 900 },
          { kind: "tasks.check" },
          { target_id: 8 },
          { target_type: "instance" },
          { input: { ...operation.input, subtask: 12 } },
          { input: { ...operation.input, role: "consult" } },
          { input: { ...operation.input, thread: "45" } },
        ].map((edit) => ({ operation: { ...operation, ...edit }, candidate: null })),
      ];
      for (const c of cases) {
        const h = stageHarness({ operation: c.operation });
        const args: StageArgs = {
          ...h.args,
          stage,
          key: `tg-7.${stage}.c1`,
          analysis: f.analysis(stage),
          ...(stage === "review"
            ? { handoff: bound({ subtask: 11, outcome: "passed", checkId: 88, commit: HEAD, candidate: { head: HEAD, tree: TREE } }) }
            : {}),
        };
        // The turn role is "implement" in the factory and "implementer" on the wire.
        args.analysis = f.analysis(stage === "review" ? "review" : "implement");
        await runStage(args, h.deps);
        const failed = await runStage(args, h.deps);
        assertEquals(failed.state, "failed");
        assertEquals(failed.evidence!.status, "failed");
        assertEquals(failed.evidence!.operation, c.operation);
        const artifact = failed.artifacts[stage === "implement" ? "change" : "review"];
        assertEquals(artifact.outcome, "blocked");
        assertEquals(artifact.candidate, c.candidate);
        assertEquals(artifact.attempt, 1);
        assertEquals(artifact.operationId, 899);
        assertEquals(artifact.subtask, 11);
        assertEquals(artifact.role, operation.input.role);
        assertEquals(artifact.threadId, c.candidate ? 44 : null);
        assert(!h.calls.some((x) => x.method === "PATCH" && x.body?.status === "completed"));
        const next = { ...args, stage: "analyze" as const, [stage === "implement" ? "change" : "review"]: bound(artifact, "2026-10-24T11:00:00Z") };
        if (c.candidate) assertEquals(analysisCandidate(next), { candidate: c.candidate, purpose: stage });
        else assertThrows(() => analysisCandidate(next), Error, "complete native candidate");
        await runStage(args, h.deps);
        assertEquals(h.calls.filter((x) => x.method === "POST").length, 1);
      }
    }
  }
  const waiting = stageHarness({ operation: { status: "waiting", result: { after: edited } } });
  const args: StageArgs = { ...waiting.args, stage: "implement", key: "tg-7.implement.c1", analysis: f.analysis() };
  await runStage(args, waiting.deps);
  const pending = await runStage(args, waiting.deps);
  assertEquals(pending.state, "running");
  assertEquals(pending.artifacts, {}); // An unproven termination never releases a snapshot.
  const refused = stageHarness();
  const predispatch = await runStage({ ...args, analysis: null }, refused.deps);
  assertEquals(predispatch.state, "failed");
  assertEquals(predispatch.operationId, null);
  assertEquals(predispatch.artifacts, {});
  assert(!refused.calls.some((x) => x.method === "POST"));
});

Deno.test("failed author edits recover through fresh analysis, preserving counters, thread and original same-key send", async () => {
  const after = { head: HEAD, tree: "c".repeat(40) };
  const operation = {
    id: 900,
    kind: "tasks.turn",
    target_type: "task_group",
    target_id: 7,
    status: "failed",
    error_code: "tasks.turn_failed",
    input: { subtask: 11, role: "implementer", thread: "new" },
    result: { receipt: null, thread: 44, state: "failed", after, after_reset: null },
  };
  const h = stageHarness({ start: "lost", operationId: 900, operation });
  const args: StageArgs = { ...h.args, stage: "implement", key: "tg-7.implement.c1", analysis: f.analysis() };
  await runStage(args, h.deps); // Server may have admitted the original send.
  const persisted = structuredClone(h.get()!.request);
  await runStage(args, h.deps); // Recovery uses only the persisted request/key.
  const failed = await runStage(args, h.deps);
  assertEquals(failed.state, "failed");
  assertEquals(failed.evidence!.operation, operation);
  assertEquals(failed.artifacts.change.candidate, after);
  assertEquals(failed.artifacts.change.attempt, 1);
  assertEquals(failed.artifacts.change.threadId, 44);
  await runStage(args, h.deps);
  const sends = h.calls.filter((x) => x.method === "POST");
  assertEquals(sends.length, 2);
  assertEquals(sends[0], sends[1]);
  assertEquals(h.get()!.request, persisted);
  // The real-factory regression proves implement-retry requires approval.
  // Simulate its next stage here, with the terminal artifact recorded by that driver.
  const change = bound(failed.artifacts.change, "2026-10-24T11:00:00Z");
  const analysisArgs: StageArgs = { ...h.args, key: "tg-7.analyze.c2", change };
  assertEquals(analysisCandidate(analysisArgs).candidate, after);
  const result = await reportResult((e) => {
    e.tree = after.tree;
  });
  result.provenance.operation_id = 901;
  result.provenance.before = after;
  result.provenance.after = after;
  const analysis = stageHarness({ operationId: 901, result });
  await runStage(analysisArgs, analysis.deps);
  assertEquals(analysis.calls.find((x) => x.method === "POST")!.body!.tree, after.tree);
  const usable = await runStage(analysisArgs, analysis.deps);
  assertEquals(usable.artifacts.analysis.outcome, "ready");
  for (const evidence of [null, f.analysis(), bound({ ...usable.artifacts.analysis, outcome: "blocked" })]) {
    const invalid = stageHarness();
    const refused = await runStage({ ...args, key: "tg-7.implement.c2", change, analysis: evidence }, invalid.deps);
    assertEquals(refused.state, "failed");
    assert(!invalid.calls.some((x) => x.method === "POST"));
  }
  const retry = stageHarness({
    operationId: 902,
    start: "lost",
    operation: {
      id: 902,
      status: "succeeded",
      result: { thread: 44, state: "done", receipt: { outcome: "ready_for_review" }, after },
    },
  });
  const retryArgs = { ...args, key: "tg-7.implement.c2", change, analysis: bound(usable.artifacts.analysis) };
  await runStage(retryArgs, retry.deps);
  const original = structuredClone(retry.get()!.request);
  await runStage(retryArgs, retry.deps);
  const done = await runStage(retryArgs, retry.deps);
  assertEquals(done.state, "succeeded");
  assertEquals(done.artifacts.change.attempt, 2);
  assertEquals(done.artifacts.change.threadId, 44);
  const posts = retry.calls.filter((x) => x.method === "POST");
  assertEquals(posts.length, 2);
  assertEquals(posts[0], posts[1]);
  assertEquals(posts[0].key, "tg-7.implement.s11.c2");
  assertEquals(posts[0].body!.thread, "44");
  assertEquals(posts[0].body!.analysis, { operation: 901, plan_sha256: f.plan.plan_sha256 });
  assertEquals(retry.get()!.request, original);
});

Deno.test("terminal check snapshots stay blocked and scoped; missing or cancelled results never become passes", async () => {
  for (const stage of ["baseline", "handoff"] as const) {
    for (const status of ["failed", "cancelled"]) {
      const operation = {
        id: 899,
        kind: "tasks.check",
        target_type: "task_group",
        target_id: 7,
        status,
        input: { subtask: 11, phase: stage },
        result: { check_id: 70, outcome: "passed", commit: "c".repeat(40), head: HEAD, tree: TREE },
      };
      for (const op of [operation, { ...operation, result: null }, { ...operation, input: { subtask: 12, phase: stage } }]) {
        const h = stageHarness({ operation: op });
        const args: StageArgs = { ...h.args, stage, key: `tg-7.${stage}.c1` };
        await runStage(args, h.deps);
        const done = await runStage(args, h.deps);
        assertEquals(done.state, "failed");
        assertEquals(done.evidence!.status, "failed");
        assertEquals(done.artifacts[stage].outcome, "blocked");
        assertEquals(done.artifacts[stage].commit, null);
        assertEquals(done.artifacts[stage].candidate, op === operation ? { head: "c".repeat(40), tree: TREE } : null);
        assertEquals(done.artifacts[stage].operationId, 899);
        assertEquals(h.calls.filter((c) => c.method === "POST").length, 1);
      }
    }
  }
});

Deno.test("unknown termination holds the client stage; cancellation/unavailable/oversize retain typed failure without green fallback", async () => {
  const pending = stageHarness({ status: "waiting" });
  await runStage(pending.args, pending.deps);
  assertEquals((await runStage(pending.args, pending.deps)).state, "running");
  assertEquals(pending.calls.filter((c) => c.method === "POST").length, 1);
  const busy = stageHarness({ start: "busy" });
  assertEquals((await runStage(busy.args, busy.deps)).state, "running");
  await runStage(busy.args, busy.deps);
  assertEquals(busy.calls.filter((c) => c.method === "POST").map((c) => c.key), ["tg-7.analyze.s11.c1", "tg-7.analyze.s11.c1"]);
  for (const code of ["tasks.analysis_unavailable", "tasks.analysis_report_too_large", "tasks.analysis_stale"]) {
    const h = stageHarness({ status: "failed", code });
    await runStage(h.args, h.deps);
    const r = await runStage(h.args, h.deps);
    assertEquals(r.state, "failed");
    assertEquals(r.evidence!.code, code);
    assertEquals(r.evidence!.requiredBytes, null);
    assertEquals(r.artifacts, {});
  }
  const cancelled = stageHarness({ status: "cancelled" });
  await runStage(cancelled.args, cancelled.deps);
  assertEquals((await runStage(cancelled.args, cancelled.deps)).state, "failed");
});

Deno.test("analysis-bound turn lost-response replay retains original prompt/send identity; full-prompt rejection stays failed", async () => {
  const h = stageHarness({ start: "lost" });
  const a = { ...h.args, stage: "implement" as const, key: "tg-7.implement.c1", analysis: f.analysis() };
  await runStage(a, h.deps);
  const before = structuredClone(h.get()!.request);
  await runStage({ ...a, review: bound({ summary: "New feedback must not replace a possibly accepted send." }) }, h.deps);
  assertEquals(h.get()!.request, before);
  assert(h.get()!.invalidated);
  const sends = h.calls.filter((c) => c.method === "POST");
  assertEquals(sends[0], sends[1]);
  const oversized = stageHarness({ status: "failed", code: "tasks.analysis_prompt_too_large" });
  await runStage(a, oversized.deps);
  const done = await runStage(a, oversized.deps);
  assertEquals(done.state, "failed");
  assertEquals(done.evidence!.code, "tasks.analysis_prompt_too_large");
});

Deno.test("a feature child refuses absent/stale/foreign semantic docs review and verifies the existing approved native receipt", async () => {
  const view = structuredClone(group);
  view.tasks[0].status = "completed";
  view.tasks.push({ id: 12, position: 2, title: "Feature", brief: "Implement after docs review", status: "running", deliverables: [] });
  const fixture = await analysisFixture(view);
  const result = structuredClone(fixture.result);
  result.provenance.subtask = 12;
  const analysis = bound({
    subtask: 12,
    outcome: "ready",
    purpose: "implement",
    operationId: 899,
    plan_sha256: fixture.plan.plan_sha256,
    plan: fixture.plan,
    candidate: { head: HEAD, tree: TREE },
    result,
  });
  const args: StageArgs = { workItem: "tg-7", stage: "implement", key: "tg-7.implement.c1", ...fixture.inputs, analysis };
  const pointer = { subtask: 11, plan_sha256: fixture.plan.plan_sha256, operationId: 898 };
  const approved = {
    id: 898,
    kind: "tasks.turn",
    target_type: "task_group",
    target_id: 7,
    status: "succeeded",
    input: { subtask: 11, role: "reviewer", analysis: { operation: 897, plan_sha256: fixture.plan.plan_sha256 } },
    result: { receipt: { outcome: "approved" } },
  };
  for (
    const review of [
      undefined,
      bound({ docsReview: { ...pointer, plan_sha256: "c".repeat(64) }, candidate: { head: HEAD, tree: TREE } }),
      bound({ docsReview: pointer, candidate: { head: HEAD, tree: TREE } }),
    ]
  ) {
    const h = stageHarness({ group: view, docsReview: { ...approved, target_id: 8 } });
    assertEquals((await runStage({ ...args, review }, h.deps)).state, "failed");
    assert(!h.calls.some((c) => c.method === "POST"));
  }
  const h = stageHarness({ group: view, docsReview: approved });
  assertEquals((await runStage({ ...args, review: bound({ docsReview: pointer, candidate: { head: HEAD, tree: TREE } }) }, h.deps)).state, "running");
  assertEquals(h.calls.filter((c) => c.method === "POST").length, 1);
});

Deno.test("authors require full evidence and current semantic docs review; no-docs never removes review policy", async () => {
  const h = stageHarness();
  const a = { ...h.args, stage: "implement" as const, key: "tg-7.implement.c1", analysis: f.analysis() };
  await runStage(a, h.deps);
  const turn = h.calls.find((c) => c.method === "POST")!;
  assertEquals(turn.body!.analysis, { operation: 899, plan_sha256: f.plan.plan_sha256 });
  assert(String(turn.body!.prompt).includes("waiver policy"));
  assert(!String(turn.body!.prompt).includes(f.result.artifact.content)); // Gateway appends retained full bytes, client sends reference.
  const missing = stageHarness();
  const refused = await runStage({ ...a, analysis: null }, missing.deps);
  assertEquals(refused.state, "failed");
  assert(!missing.calls.some((c) => c.method === "POST"));
});
