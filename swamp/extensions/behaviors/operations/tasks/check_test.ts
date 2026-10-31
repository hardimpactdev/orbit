import { assertEquals, assertRejects } from "jsr:@std/assert@1";
import { classifyCi } from "./check.ts";
import { withTaskCancellation } from "./cancellation.ts";
import { DirectOperationCancelled } from "../_lib/direct.ts";

const head = "a".repeat(40);
const run = {
  id: 10,
  name: "Required checks",
  head_sha: head,
  status: "completed",
  conclusion: "success",
  app: { slug: "github-actions" },
};
Deno.test("only the exact SHA and GitHub Actions identity can satisfy the check", () => {
  assertEquals(classifyCi([run], [], head, run.name).state, "passed");
  assertEquals(
    classifyCi([{ ...run, head_sha: "b".repeat(40) }], [], head, run.name)
      .fault,
    "configuration",
  );
  assertEquals(
    classifyCi(
      [run, { ...run, id: 11, app: { slug: "other-app" } }],
      [],
      head,
      run.name,
    ).fault,
    "configuration",
  );
});
Deno.test("every current run of the required name must pass", () => {
  assertEquals(
    classifyCi(
      [run, { ...run, id: 11, status: "in_progress", conclusion: null }],
      [],
      head,
      run.name,
    ).state,
    "pending",
  );
  assertEquals(
    classifyCi(
      [run, { ...run, id: 11, conclusion: "failure" }],
      [],
      head,
      run.name,
    ).fault,
    "change",
  );
  assertEquals(
    classifyCi([{ ...run, conclusion: "cancelled" }], [], head, run.name).fault,
    "infrastructure",
  );
  assertEquals(
    classifyCi([{ ...run, conclusion: "skipped" }], [], head, run.name).fault,
    "configuration",
  );
});
Deno.test("missing required jobs distinguish pending, workflow failure and configuration", () => {
  assertEquals(classifyCi([], [], head, run.name).state, "missing");
  assertEquals(
    classifyCi(
      [],
      [{
        id: 20,
        status: "in_progress",
        conclusion: null,
        app: { slug: "github-actions" },
      }],
      head,
      run.name,
    ).state,
    "missing",
  );
  assertEquals(
    classifyCi(
      [],
      [{
        id: 20,
        status: "completed",
        conclusion: "failure",
        app: { slug: "github-actions" },
      }],
      head,
      run.name,
    ).fault,
    "change",
  );
  assertEquals(
    classifyCi(
      [],
      [{
        id: 20,
        status: "completed",
        conclusion: "success",
        app: { slug: "github-actions" },
      }],
      head,
      run.name,
    ).fault,
    "configuration",
  );
});
Deno.test("CI projection retains only bounded public metadata", () => {
  const result = classifyCi(
    [{
      ...run,
      output: { summary: "do not persist raw output" },
      private: "discard",
    }],
    [],
    head,
    run.name,
  );
  assertEquals(result.runs, [{
    id: 10,
    name: run.name,
    head_sha: head,
    status: "completed",
    conclusion: "success",
    app_slug: "github-actions",
  }]);
});
Deno.test("native Tasks observes Gateway cancellation while its own effect runs", async () => {
  let reads = 0;
  await assertRejects(() =>
    withTaskCancellation(
      {
        read: () => {
          reads++;
          return Promise.resolve({ cancel_requested: true });
        },
      },
      (signal) =>
        new Promise((_, reject) =>
          signal.addEventListener(
            "abort",
            () =>
              reject(new DirectOperationCancelled({ effectRunning: false })),
            { once: true },
          )
        ),
      undefined,
      () => Promise.resolve(),
    ), DirectOperationCancelled);
  assertEquals(reads, 1);
});
Deno.test("an unreadable cancellation record never becomes a confirmed cancellation", async () => {
  await assertRejects(
    () =>
      withTaskCancellation(
        { read: () => Promise.reject(new Error("offline")) },
        (signal) =>
          new Promise((_, reject) =>
            signal.addEventListener("abort", () =>
              reject(new DirectOperationCancelled({ effectRunning: false })), {
              once: true,
            })
          ),
        undefined,
        () =>
          Promise.resolve(),
      ),
    Error,
    "observation unavailable",
  );
});

import { CheckInput, hosted } from "./check.ts";
const tree = "b".repeat(40);
function hostedFixture() {
  const input = CheckInput.parse({
    group_id: 1,
    instance_id: 2,
    node_id: 3,
    subtask: 4,
    check_id: 5,
    checkout_path: "/srv/task",
    ssr_port: null,
    phase: "handoff",
    command: "true",
    setup: [],
    deliverables: [],
    base: head,
    message: "Handoff",
    repository: "acme/widgets",
    check_name: run.name,
    branch: "task-1-handoff",
  });
  const result = {
    outcome: "passed",
    check_id: 5,
    head,
    tree,
    commit: head,
    deliverables: [],
  };
  const calls: string[] = [];
  let cancelled = false;
  let currentHead = head;
  let named = { ...run };
  let time = Date.parse("2026-10-31T09:00:00Z");
  const request: typeof fetch = (url, init) => {
    const path = String(url);
    calls.push((init?.method ?? "GET") + " " + path);
    if (init?.method === "DELETE") {
      return Promise.resolve(new Response(null, { status: 204 }));
    }
    return Promise.resolve(
      Response.json(
        path.includes("check-runs")
          ? { check_runs: [named] }
          : { check_suites: [] },
      ),
    );
  };
  const gateway = {
    read: () => Promise.resolve({ cancel_requested: cancelled }),
    withPrivateMaterial: async (
      action: (value: Record<string, unknown>) => Promise<void>,
    ) => {
      calls.push("private");
      try {
        await action({
          credentials: {
            repository: "acme/widgets",
            write_token: "fixture_write",
            checks_token: "fixture_checks",
            read_token: "fixture_read",
          },
        });
      } catch {
        throw new Error("Private callback failed");
      }
    },
  };
  const runtime = {
    invoke: (value: Record<string, unknown>, signal?: AbortSignal) => {
      calls.push(String(value.mode));
      if (value.mode === "push") {
        assertEquals(signal, undefined);
        assertEquals(value.commit, head);
        return Promise.resolve({ pushed: true });
      }
      return Promise.resolve({ head: currentHead, tree });
    },
    request,
    now: () => time,
    wait: () => {
      time += 56 * 60 * 1000;
      return Promise.resolve();
    },
  };
  return {
    input,
    result,
    gateway,
    runtime,
    calls,
    cancel: () => {
      cancelled = true;
    },
    move: () => {
      currentHead = "c".repeat(40);
    },
    fail: () => {
      named = { ...run, conclusion: "failure" };
    },
    pending: () => {
      named = { ...run, status: "in_progress" };
    },
  };
}
Deno.test("hosted handoff pushes only its candidate and revokes tokens after exact-head CI", async () => {
  const f = hostedFixture();
  const result = await hosted(
    f.input,
    10,
    f.result,
    f.gateway,
    new AbortController().signal,
    f.runtime,
  );
  assertEquals(result.outcome, "passed");
  assertEquals(result.ci, {
    state: "passed",
    fault: null,
    runs: [{
      id: 10,
      name: run.name,
      head_sha: head,
      status: "completed",
      conclusion: "success",
      app_slug: "github-actions",
    }],
    suites: [],
    branch: f.input.branch,
    commit: head,
    check_name: run.name,
    pushed_at: "2026-10-31T09:00:00.000Z",
    observed_at: "2026-10-31T09:00:00.000Z",
  });
  assertEquals(f.calls.filter((call) => call === "private").length, 1);
  assertEquals(f.calls.filter((call) => call.startsWith("DELETE ")).length, 3);
  assertEquals(JSON.stringify(result).includes("fixture_"), false);
});
Deno.test("cancellation after a confirmed push stays cancelled across private transport", async () => {
  const f = hostedFixture();
  f.cancel();
  await assertRejects(
    () =>
      hosted(
        f.input,
        10,
        f.result,
        f.gateway,
        new AbortController().signal,
        f.runtime,
      ),
    DirectOperationCancelled,
  );
  assertEquals(f.calls.filter((call) => call.startsWith("DELETE ")).length, 3);
});
Deno.test("a moved checked workspace cannot use green hosted CI", async () => {
  const f = hostedFixture();
  f.move();
  assertEquals(
    (await hosted(
      f.input,
      10,
      f.result,
      f.gateway,
      new AbortController().signal,
      f.runtime,
    )).outcome,
    "changed",
  );
});
Deno.test("a failed hosted check returns a business failure and bounded observation times out", async () => {
  const f = hostedFixture();
  f.fail();
  assertEquals(
    (await hosted(
      f.input,
      10,
      f.result,
      f.gateway,
      new AbortController().signal,
      f.runtime,
    )).outcome,
    "failed",
  );
  f.pending();
  assertEquals(
    (await hosted(
      f.input,
      10,
      f.result,
      f.gateway,
      new AbortController().signal,
      f.runtime,
    )).error_code,
    "tasks.handoff_ci_timeout",
  );
});

Deno.test("startup failures and skipped aggregate jobs keep the existing fault classification", () => {
  assertEquals(
    classifyCi([{ ...run, conclusion: "startup_failure" }], [], head, run.name)
      .fault,
    "infrastructure",
  );
  assertEquals(
    classifyCi(
      [],
      [{
        id: 20,
        status: "completed",
        conclusion: "startup_failure",
        app: run.app,
      }],
      head,
      run.name,
    ).fault,
    "change",
  );
  assertEquals(
    classifyCi(
      [{ ...run, conclusion: "skipped" }, {
        ...run,
        id: 11,
        name: "Build",
        conclusion: "failure",
      }],
      [],
      head,
      run.name,
    ).fault,
    "change",
  );
});

Deno.test("registry failure remains an infrastructure refusal with token cleanup", async () => {
  const f = hostedFixture();
  f.runtime.request = (_url, init) =>
    Promise.resolve(
      init?.method === "DELETE"
        ? new Response(null, { status: 204 })
        : new Response(null, { status: 503 }),
    );
  assertEquals(
    (await hosted(
      f.input,
      10,
      f.result,
      f.gateway,
      new AbortController().signal,
      f.runtime,
    )).error_code,
    "tasks.handoff_ci_unavailable",
  );
});
