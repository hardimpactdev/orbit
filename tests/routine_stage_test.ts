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
  const deps = {
    http,
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

Deno.test("routine script replays the exact saved request after a lost reply, honors polls and never launches twice", async () => {
  const f = await fixture();
  try {
    let lose = true;
    const http: Http = async (...input) => {
      const response = await f.deps.http(...input);
      if (input[0] === "POST" && lose) {
        lose = false;
        return { status: 0, body: null };
      }
      return response;
    };
    const args = f.args("script");
    const deps = { ...f.deps, http };
    await runStage(args, deps);
    const saved = structuredClone(f.get());
    f.set(saved); // Retained state in a fresh execution, with no in-memory operation id.
    await runStage(args, deps);
    await runStage(args, deps); // Poll not due.
    assertEquals(
      f.calls.filter((c) => c.path.startsWith("/api/v1/operations/")).length,
      0,
    );
    f.tick();
    const done = await runStage(args, deps);
    await runStage(args, deps);
    assertEquals(done.state, "succeeded");
    assertEquals(done.artifacts.script.outcome, "passed");
    assertEquals(f.fake.operations.size, 1);
    const posts = f.calls.filter((c) => c.method === "POST");
    assertEquals(posts.length, 2);
    assertEquals(posts[0], posts[1]);
    assertEquals(posts[0].body, {
      subtask: 701,
      script: "scripts/dependencies",
      args: ["routine"],
    });
    assertEquals(posts[0].key, "tg-7.script.s701.c1");
  } finally {
    await f.fake.close();
  }
});

Deno.test("routine refuses a foreign or contradictory successful script result without advancing board state", async () => {
  for (const bad of ["group", "input", "result"]) {
    const f = await fixture();
    try {
      const args = f.args("script");
      await runStage(args, f.deps);
      const op = [...f.fake.operations.values()][0];
      if (bad === "group") op.target_id = 8;
      if (bad === "input") {
        op.input = {
          subtask: 999,
          script: "scripts/dependencies",
          args: ["routine"],
        };
      }
      if (bad === "result") {
        (op.result as Record<string, unknown>).exit_code = 23;
      }
      f.tick();
      await assertRejects(() => runStage(args, f.deps));
      assertEquals(f.fake.groups.get(7)!.tasks[0].status, "running");
      assertEquals(f.fake.operations.size, 1);
      assertEquals(
        f.calls.filter((c) => c.path.endsWith("/publish")).length,
        0,
      );
    } finally {
      await f.fake.close();
    }
  }
});

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
    assertEquals(f.fake.groups.get(7)!.tasks[0].status, "completed");
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
      assertEquals(f.fake.operations.size, 1);
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
      output: "npm update failed",
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
