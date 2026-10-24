// Exact delivered public DTOs. Execution/HTTP/ownership are simulated here;
// repository_analysis_producer_test.ts separately executes the real checker.
import { assert, assertEquals, assertThrows } from "jsr:@std/assert@1.0.19";
import {
  type Http,
  type Job,
  runStage,
  type StageArgs,
} from "../extensions/behaviors/_lib/gateway_stage.ts";
import {
  sha256,
  validateAnalysisFailure,
  validateProvisionResult,
} from "../extensions/behaviors/_lib/repository_analysis.ts";
import {
  analysisFixture,
  bound,
  HEAD,
  TREE,
} from "./_support/analysis_fixture.ts";

const directory = new URL(
  "./fixtures/repository-analysis-public/",
  import.meta.url,
);
const read = async (name: string) =>
  JSON.parse(await Deno.readTextFile(new URL(name, directory)));
const pinned = (await read("tasks-provision-pinned.json")).body.data;
const overflow = (await read("tasks-analyze-failure-overflow.json")).body.data;
const unavailable =
  (await read("tasks-analyze-failure-unavailable.json")).body.data;
const group = {
  id: 7,
  title: "Feature",
  brief: "Docs first",
  status: "running",
  tasks: [{
    id: 11,
    position: 1,
    title: "Docs",
    brief: "Write docs",
    status: "running",
    deliverables: [],
  }],
};
const f = await analysisFixture(group);

function harness(
  stage: "provision" | "analyze",
  source: Record<string, unknown>,
  lost = false,
) {
  let job: Job | null = null, now = 0, accepted = false;
  const op = structuredClone(source);
  const calls: {
    method: string;
    path: string;
    body?: Record<string, unknown>;
    key?: string;
  }[] = [];
  const http: Http = (method, path, body, key) => {
    calls.push({ method, path, body: structuredClone(body), key });
    const ok = (data: unknown, status = 200) =>
      Promise.resolve({ status, body: { data } });
    if (path === "/api/v1/task-groups/7" && method === "GET") return ok(group);
    if (method === "POST") {
      // Rebind the recorded DTO to this simulated admitted request. Nested
      // result bytes/fields are unmodified; this is not native execution proof.
      Object.assign(op, { id: 899, target_id: 7, input: body });
      if (!accepted) {
        accepted = true;
        if (lost) return Promise.resolve({ status: 0, body: null });
      }
      return ok({ id: 899, status: "queued", poll_after_seconds: 1 }, 202);
    }
    return ok(op);
  };
  const deps = {
    http,
    readJob: () => Promise.resolve(job),
    writeJob: (j: Job) => {
      job = structuredClone(j);
      return Promise.resolve();
    },
    now: () => now += 10000,
  };
  const args: StageArgs = {
    workItem: "tg-7",
    key: `tg-7.${stage}.c1`,
    stage,
    ...f.inputs,
  };
  return { args, deps, calls, op, get: () => job };
}

Deno.test("public fixtures are byte-identical to the delivered SDK fixtures at the pinned native object", async () => {
  const manifest = await read("source.json");
  assertEquals(manifest.sha, "0364765d6d41794f3f89d702e0f72cc54e954b07");
  for (
    const [name, entry] of Object.entries(manifest.files) as [
      string,
      { source: string; sha256: string },
    ][]
  ) {
    const text = await Deno.readTextFile(new URL(name, directory));
    const result = await new Deno.Command("git", {
      args: [
        "-C",
        "/fast/apps/orbit/default",
        "show",
        `${manifest.sha}:${entry.source}`,
      ],
      stdout: "piped",
    }).output();
    assert(result.success);
    assertEquals(text, new TextDecoder().decode(result.stdout));
    assertEquals(await sha256(text), entry.sha256);
  }
});

Deno.test("public failure DTO retains known/unknown sizes and rejects malformed, unsafe and extra fields", () => {
  for (const operation of [overflow, unavailable]) {
    assertEquals(
      validateAnalysisFailure(operation.result, operation.error_code),
      operation.result,
    );
  }
  const code = overflow.error_code;
  for (const size of [null, 65537, Number.MAX_SAFE_INTEGER]) {
    const value = { schema: 1, error: { code, required_bytes: size } };
    assertEquals(validateAnalysisFailure(value, code), value);
  }
  for (
    const size of [
      0,
      -1,
      65536,
      65537.5,
      "216325",
      true,
      Number.MAX_SAFE_INTEGER + 1,
      Infinity,
      NaN,
    ]
  ) {
    assertThrows(() =>
      validateAnalysisFailure({
        schema: 1,
        error: { code, required_bytes: size },
      }, code)
    );
  }
  for (
    const value of [
      null,
      {},
      { ...overflow.result, artifact: {} },
      { schema: 2, error: overflow.result.error },
      {
        schema: 1,
        error: { ...overflow.result.error, message: "not a public field" },
      },
      { schema: 1, error: { code } },
      {
        schema: 1,
        error: { code: "tasks.analysis_unavailable", required_bytes: 216325 },
      },
    ]
  ) {
    assertThrows(() => validateAnalysisFailure(value, code));
  }
  assertThrows(() =>
    validateAnalysisFailure(overflow.result, "tasks.analysis_unavailable")
  );
});

Deno.test("public provision pin is immutable and independent of starting commit; unsafe or partial pins are unavailable", () => {
  const r = validateProvisionResult(pinned.result);
  assert(r.starting_commit !== r.analysis_base.base);
  for (
    const repository of [
      "git@github.com:acme/provision.git",
      "https://github.com/acme/provision.git",
      "ssh://git@github.com:2222/acme/provision.git",
      "ssh://github.com:22/acme/provision.git",
    ]
  ) {
    validateProvisionResult({
      ...r,
      analysis_base: { ...r.analysis_base, repository, branch: "task-🧪-é" },
    });
  }
  for (
    const patch of [
      null,
      undefined,
      {},
      { ...r.analysis_base, extra: true },
      { ...r.analysis_base, base: HEAD.toUpperCase() },
      { ...r.analysis_base, instance_id: 2 },
      { ...r.analysis_base, base: "abc" },
      ...[
        "https://user:secret@github.com/acme/repo",
        "ssh://git:secret@github.com/repo",
        "https://github.com/repo?x",
        "git@github.com:repo#x",
        "/host/repo",
        "https://github.com",
        "https://github.com/\n",
      ].map((repository) => ({ ...r.analysis_base, repository })),
      ...[
        "HEAD",
        "-branch",
        "refs/../main",
        "x.lock",
        "a//b",
        ".hidden",
        "a@{b",
        "x ",
        "a\\b",
        "é".repeat(128),
      ].map((branch) => ({ ...r.analysis_base, branch })),
    ]
  ) {
    assertThrows(() => validateProvisionResult({ ...r, analysis_base: patch }));
  }
});

Deno.test("failed public analysis survives lost reply/restart with original result, code, key and validated size; never succeeds", async () => {
  for (
    const source of [overflow, unavailable, {
      ...overflow,
      result: {
        schema: 1,
        error: { code: overflow.error_code, required_bytes: null },
      },
    }, { ...overflow, result: null }]
  ) {
    const h = harness("analyze", source, true);
    await runStage(h.args, h.deps);
    const request = structuredClone(h.get()!.request);
    await runStage(h.args, h.deps);
    const done = await runStage(h.args, h.deps);
    assertEquals(done.state, "failed");
    assertEquals(done.operationStatus, "failed");
    assertEquals(done.evidence!.code, source.error_code);
    assertEquals(
      done.evidence!.requiredBytes,
      source.result?.error.required_bytes ?? null,
    );
    assertEquals(
      (done.evidence!.operation as Record<string, unknown>).result,
      source.result,
    );
    assertEquals(done.artifacts, {});
    assertEquals(done.request, request);
    assertEquals(await runStage(h.args, h.deps), {
      ...done,
      launchedNow: false,
    });
    const posts = h.calls.filter((c) => c.method === "POST");
    assertEquals(posts.length, 2);
    assertEquals(posts[0], posts[1]);
  }
});

Deno.test("foreign, stale, malformed and cancelled failure data never supplies trusted metadata or usable evidence", async () => {
  for (
    const patch of [
      { kind: "tasks.turn" },
      { target_id: 8 },
      { id: 900 },
      { failed_step: "workspace_ready" },
      { input: { ...overflow.input, tree: "c".repeat(40) } },
      { result: { ...overflow.result, artifact: {} } },
      {
        result: {
          schema: 1,
          error: { code: overflow.error_code, required_bytes: 12 },
        },
      },
      {
        result: {
          schema: 1,
          error: { code: "tasks.unknown", required_bytes: 216325 },
        },
      },
      { error_code: "tasks.analysis_unavailable" },
      { status: "cancelled" },
    ]
  ) {
    const h = harness("analyze", overflow);
    await runStage(h.args, h.deps);
    Object.assign(h.op, patch);
    const done = await runStage(h.args, h.deps);
    assertEquals(done.state, "failed");
    assertEquals(done.evidence!.requiredBytes, null);
    assertEquals(done.evidence!.failure, null);
    assertEquals(done.artifacts, {});
    assertEquals(h.calls.filter((c) => c.method === "POST").length, 1);
  }
  for (const status of ["waiting", "running"]) {
    const h = harness("analyze", { ...overflow, status });
    await runStage(h.args, h.deps);
    const pending = await runStage(h.args, h.deps);
    assertEquals(pending.state, "running");
    assertEquals(pending.evidence, null);
  }
  const falseSuccess = harness("analyze", { ...overflow, status: "succeeded" });
  await runStage(falseSuccess.args, falseSuccess.deps);
  const refused = await runStage(falseSuccess.args, falseSuccess.deps);
  assertEquals(refused.artifacts.analysis.outcome, "invalid");
});

Deno.test("provision replay retains the scoped public pin; missing/null/foreign pins park without baseline or analysis", async () => {
  const h = harness("provision", pinned, true);
  await runStage(h.args, h.deps);
  await runStage(h.args, h.deps);
  const done = await runStage(h.args, h.deps);
  assertEquals(done.state, "succeeded");
  assertEquals(
    done.artifacts.workspace.analysisBase,
    pinned.result.analysis_base,
  );
  assertEquals(
    done.artifacts.workspace.startingCommit,
    pinned.result.starting_commit,
  );
  assertEquals(done.artifacts.workspace.group, 7);
  assertEquals(done.artifacts.workspace.operationId, 899);
  const posts = h.calls.filter((c) => c.method === "POST");
  assertEquals(posts[0], posts[1]);
  Object.assign(h.op, { result: { ...pinned.result, analysis_base: null } });
  assertEquals(
    (await runStage(h.args, h.deps)).artifacts.workspace,
    done.artifacts.workspace,
  );
  for (
    const source of [
      (await read("tasks-provision-unavailable.json")).body.data,
      { ...pinned, result: { ...pinned.result, analysis_base: undefined } },
      {
        ...pinned,
        result: {
          ...pinned.result,
          analysis_base: { ...pinned.result.analysis_base, instance_id: 9 },
        },
      },
      { ...pinned, kind: "tasks.analyze" },
    ]
  ) {
    const invalid = harness("provision", source);
    await runStage(invalid.args, invalid.deps);
    const refused = await runStage(invalid.args, invalid.deps);
    assertEquals(refused.state, "failed");
    assertEquals(refused.operationStatus, "succeeded");
    assertEquals(refused.artifacts.workspace.analysisBase, null);
    assertEquals(refused.artifacts.workspace.outcome, "blocked");
    assertEquals(refused.evidence!.groupEnded, false);
    assertEquals(
      invalid.calls.filter((c) => c.method === "POST").map((c) => c.path),
      ["/api/v1/task-groups/7/provision"],
    );
  }
  for (
    const patch of [{ target_id: 8 }, { id: 900 }, { target_type: "instance" }]
  ) {
    const foreign = harness("provision", pinned);
    await runStage(foreign.args, foreign.deps);
    Object.assign(foreign.op, patch);
    const refused = await runStage(foreign.args, foreign.deps);
    assertEquals(refused.state, "failed");
    assertEquals(refused.artifacts.workspace.analysisBase, null);
  }
});

Deno.test("analysis and turns require plan equality with the provision pin; divergent base is used rather than starting commit", async () => {
  const workspace = f.inputs.workspace.payload;
  for (
    const patch of [
      { analysisBase: null },
      { group: 8 },
      { instanceId: 9 },
      { nodeId: null },
      { operationId: null },
      ...["base", "repository", "branch"].map((field) => ({
        analysisBase: {
          ...workspace.analysisBase,
          [field]: field === "base" ? TREE : "other",
        },
      })),
    ]
  ) {
    for (const stage of ["analyze", "implement", "review"] as const) {
      const h = harness("analyze", overflow);
      const args = {
        ...h.args,
        stage,
        key: `tg-7.${stage}.c1`,
        analysis: f.analysis(stage),
        workspace: bound({ ...workspace, ...patch }),
      };
      const refused = await runStage(args, h.deps);
      assertEquals(refused.state, "failed");
      assertEquals(h.calls.filter((c) => c.method === "POST"), []);
    }
  }
  const valid = harness("analyze", overflow);
  await runStage({
    ...valid.args,
    workspace: bound({ ...workspace, startingCommit: TREE }),
  }, valid.deps);
  assertEquals(valid.calls.find((c) => c.method === "POST")!.body!.base, HEAD);
  const prior = structuredClone(valid.get()!.request);
  const drained = await runStage({
    ...valid.args,
    workspace: bound({
      ...workspace,
      analysisBase: { ...workspace.analysisBase, base: TREE },
    }),
  }, valid.deps);
  assert(drained.invalidated);
  assertEquals(drained.request, prior);
  assertEquals(valid.calls.filter((c) => c.method === "POST").length, 1);
  const turn = harness("analyze", {
    ...pinned,
    kind: "tasks.turn",
    status: "waiting",
    result: null,
  }, true);
  const turnArgs = {
    ...turn.args,
    stage: "implement" as const,
    key: "tg-7.implement.c1",
    analysis: f.analysis(),
  };
  await runStage(turnArgs, turn.deps);
  const send = structuredClone(turn.get()!.request);
  await runStage({
    ...turnArgs,
    workspace: bound({ ...workspace, analysisBase: null }),
  }, turn.deps);
  assertEquals(turn.get()!.request, send);
  assert(turn.get()!.invalidated);
  const sends = turn.calls.filter((c) => c.method === "POST");
  assertEquals(sends[0], sends[1]);
});
