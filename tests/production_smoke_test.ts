import {
  assertEquals,
  assertRejects,
  assertThrows,
} from "jsr:@std/assert@1.0.19";
import { productionSmoke } from "../extensions/behaviors/production-smoke/mod.ts";
import { Fixture, selected } from "./fixtures/production-smoke/fixture.ts";
import { inputIdentity } from "../extensions/behaviors/_lib/gateway_stage.ts";
import {
  DependencyProjectSchema,
  ProductionSmokeSchema,
} from "../extensions/behaviors/_lib/dependency_project_schema.ts";

Deno.test("smoke opt-in is strict and existing project config stays disabled", async () => {
  const f = new Fixture();
  const valid = f.project.productionSmoke!;
  for (
    const patch of [
      { baseUrl: "http://nckrtl.com" },
      { baseUrl: "https://u:p@nckrtl.com" },
      { baseUrl: "https://nckrtl.com/path" },
      { workflow: "../run.sh" },
      { timeoutSeconds: 601 },
      { unexpected: true },
    ]
  ) {
    assertThrows(() => ProductionSmokeSchema.parse({ ...valid, ...patch }));
  }
  delete f.project.productionSmoke;
  assertEquals((await f.tick("baseline")).status, "disabled");
  assertEquals(f.requests, []);
});
Deno.test("default observation reads deployment evidence without dispatch", async () => {
  const f = new Fixture();
  assertEquals((await f.tick()).status, "observed");
  assertEquals(f.posts, []);
  assertEquals(f.requests[0].path, "/api/v1/instances/7/smoke/context");
});
Deno.test("baseline saves its bound intent and follows the receipt across restart", async () => {
  const f = new Fixture();
  assertEquals((await f.tick("baseline")).status, "pending");
  assertEquals(f.posts[0].key, "smoke.7.9");
  assertEquals(f.stored.get("job-9")?.request, f.posts[0].body);
  f.pass();
  const state = await f.tick();
  assertEquals(state.status, "passed");
  assertEquals(state.known_good, selected);
  await f.tick("baseline");
  assertEquals(f.posts.length, 1);
});
Deno.test("lost admission response with a found receipt never resends", async () => {
  const f = new Fixture();
  f.lost = true;
  await f.tick("baseline");
  await f.tick("baseline");
  assertEquals(f.posts.length, 1);
  assertEquals(f.stored.get("job-9")?.operation_id, 42);
});
Deno.test("a request lost before admission recovers once with the exact key and body", async () => {
  const f = new Fixture();
  f.lostBefore = true;
  await f.tick("baseline");
  const first = structuredClone(f.stored.get("job-9"));
  await f.tick(); // Observation must remain GET-only even when recovery is possible.
  assertEquals(f.posts.length, 1);
  f.lostBefore = false;
  await f.tick("baseline");
  assertEquals(f.posts[1], f.posts[0]);
  assertEquals(f.stored.get("job-9")?.started_at, first?.started_at);
  assertEquals(f.stored.get("job-9")?.resend_used, true);
  assertEquals(f.admitted.size, 1);
  f.pass();
  assertEquals((await f.tick()).status, "passed");
});
Deno.test("recovery allowance survives restart and never loops on missing receipts", async () => {
  const f = new Fixture();
  f.lostBefore = true;
  await f.tick("baseline");
  const first = structuredClone(f.stored.get("job-9"));
  await f.tick("baseline");
  f.stored.delete("state"); // Rebuild from the durable job with its used allowance.
  await f.tick("baseline");
  await f.tick("baseline");
  assertEquals(f.posts.length, 2);
  assertEquals(f.stored.get("job-9")?.started_at, first?.started_at);
  f.now += 600_000;
  assertEquals((await f.tick("baseline")).status, "timed_out");
  assertEquals(f.posts.length, 2);
});
Deno.test("same-key recovery after a missing lookup cannot create another admission", async () => {
  const f = new Fixture();
  f.lost = true;
  await f.tick("baseline");
  Object.assign(f.receipt, { found: false, operation: null, smoke: null });
  await f.tick("baseline");
  assertEquals(f.posts.length, 2);
  assertEquals(f.posts[0], f.posts[1]);
  assertEquals(f.admitted.size, 1);
});
for (
  const [status, code] of [
    [409, "operation.target_busy"],
    [
      409,
      "instance.smoke_selection_changed",
    ],
    [422, "instance.smoke_invalid"],
    [503, "instance.smoke_github_unavailable"],
  ] as const
) {
  Deno.test(`definite ${code} refusal stays unknown and allows an explicit retry`, async () => {
    const f = new Fixture();
    f.refusal = { status, code };
    assertEquals((await f.tick("baseline")).status, "unknown");
    assertEquals(f.stored.get("job-9")?.refusal, { status, code });
    assertEquals(String(f.stored.get("state")?.reason).includes(code), true);
    f.stored.delete("state");
    assertEquals((await f.tick()).status, "unknown");
    assertEquals(String(f.stored.get("state")?.reason).includes(code), true);
    const automatic = code === "operation.target_busy" ||
      code === "instance.smoke_github_unavailable";
    assertEquals(f.posts.length, automatic ? 2 : 1);
    const attempts = f.posts.length;
    f.stored.delete("state"); // Recover the spent refusal allowance from its durable job.
    await f.tick(); // A persistent refusal cannot loop automatic retries.
    assertEquals(f.posts.length, attempts);
    assertEquals(f.stored.get("job-9")?.resend_used, false);
    f.refusal = null;
    await f.tick("baseline");
    assertEquals(f.posts.length, attempts + 1);
    assertEquals(f.posts[0], f.posts[1]);
    assertEquals(f.admitted.size, 1);
    f.pass();
    assertEquals((await f.tick()).status, "passed");
  });
}
Deno.test("a server error is uncertain rather than a definite refusal", async () => {
  const f = new Fixture();
  f.refusal = { status: 500, code: "gateway.internal" };
  await f.tick("baseline");
  assertEquals(f.stored.get("job-9")?.refusal, null);
  await f.tick("baseline");
  await f.tick("baseline");
  assertEquals(f.posts.length, 2);
});
Deno.test("read outages retain intent and a later receipt is adopted", async () => {
  const f = new Fixture();
  await f.tick("baseline");
  f.outage = true;
  await assertRejects(() => f.tick());
  f.outage = false;
  f.pass();
  assertEquals((await f.tick()).status, "passed");
  assertEquals(f.posts.length, 1);
});
Deno.test("a changed selection during the result read cannot establish a baseline", async () => {
  const f = new Fixture();
  await f.tick("baseline");
  f.pass();
  f.onReceipt = () => {
    f.observed.selected.deployment_id = 10;
  };
  const state = await f.tick();
  assertEquals(state.status, "superseded");
  assertEquals(state.known_good, null);
});
Deno.test("configuration changes and opt-out preserve the original intent", async () => {
  const f = new Fixture();
  await f.tick("baseline");
  const key = f.stored.get("job-9")?.key;
  f.project.productionSmoke!.ref = "other";
  assertEquals((await f.tick("baseline")).status, "unknown");
  delete f.project.productionSmoke;
  assertEquals((await f.tick()).status, "disabled");
  assertEquals(f.stored.get("job-9")?.key, key);
  assertEquals(f.posts.length, 1);
});
for (
  const field of [
    "tests",
    "failures",
    "errors",
    "skipped",
    "run_id",
    "run_attempt",
    "workflow_sha",
    "conclusion",
    "commit",
    "deployment_id",
  ]
) {
  Deno.test(`a forged successful smoke cannot pass with wrong ${field}`, async () => {
    const f = new Fixture();
    await f.tick("baseline");
    f.pass();
    Object.assign(f.receipt.smoke!, {
      [field]: ({
        tests: 0,
        failures: 1,
        errors: 1,
        skipped: 1,
        run_id: null,
        run_attempt: null,
        workflow_sha: null,
        conclusion: "failure",
        commit: "c".repeat(40),
        deployment_id: 99,
      } as Record<string, unknown>)[field],
    });
    await assertRejects(() => f.tick());
    assertEquals(f.stored.get("state")?.known_good, null);
  });
}
Deno.test("wrong Project, Route, production role and retention refuse even observation", async () => {
  for (
    const patch of [
      { project_id: 99 },
      { instance_id: 99 },
      { repository_url: "git@github.com:other/repo.git" },
      { production: false },
      {
        base_urls: [],
      },
      { retained_releases: [] },
    ]
  ) {
    const f = new Fixture();
    Object.assign(f.observed, patch);
    await assertRejects(() => f.tick("baseline"));
    assertEquals(f.posts, []);
  }
});
Deno.test("operation and lookup identities cannot be substituted", async () => {
  for (
    const mutate of [(f: Fixture) => {
      f.receipt.request_key = "other";
    }, (f: Fixture) => {
      f.receipt.operation!.id = 99;
    }, (f: Fixture) => {
      f.receipt.operation!.target_id = 99;
    }]
  ) {
    const f = new Fixture();
    await f.tick("baseline");
    mutate(f);
    await assertRejects(() => f.tick());
  }
});
Deno.test("a retained intent with no current-state receipt cannot redispatch", async () => {
  const f = new Fixture();
  await f.tick("baseline");
  f.stored.delete("state");
  await f.tick("baseline");
  assertEquals(f.posts.length, 1);
});
Deno.test("unproven failed dispatch retains the last passing baseline", async () => {
  const f = new Fixture();
  await f.tick("baseline");
  f.pass();
  await f.tick();
  f.observed.selected.deployment_id = 10;
  assertEquals((await f.tick("baseline")).status, "superseded");
  await f.tick("baseline");
  f.receipt.operation!.status = "failed";
  f.receipt.operation!.error_code = "operation.worker_lost";
  assertEquals((await f.tick()).status, "unknown");
  assertEquals(f.stored.get("state")?.known_good, selected);
});
Deno.test("a missing successful deployment is observed without dispatch", async () => {
  const f = new Fixture();
  Object.assign(f.observed, { selected: null });
  assertEquals((await f.tick("baseline")).status, "observed");
  assertEquals(f.posts, []);
});

Deno.test("smoke opt-in does not change an existing dependency work identity", () => {
  const f = new Fixture();
  const configured = DependencyProjectSchema.parse(f.project);
  const identity = (dependencyProject: typeof configured) =>
    inputIdentity({
      workItem: "tg-7",
      stage: "script",
      key: "tg-7.script.c1",
      routine: true,
      dependencyProject,
    });
  delete configured.productionSmoke;
  assertEquals(
    identity(configured),
    identity(DependencyProjectSchema.parse(f.project)),
  );
});

for (const status of ["failed", "cancelled"]) {
  for (const hasResult of [false, true]) {
    Deno.test(`${status} operation with ${hasResult ? "unknown" : "no"} result is unknown`, async () => {
      const f = new Fixture();
      await f.tick("baseline");
      if (hasResult) {
        f.pass();
        f.receipt.smoke!.state = "unknown";
        f.receipt.smoke!.output = "Worker lost without verified test results.";
      }
      f.receipt.operation!.status = status;
      f.receipt.operation!.error_code = "operation.worker_lost";
      const state = await f.tick();
      assertEquals(state.status, "unknown");
      assertEquals(
        state.reason,
        hasResult ? f.receipt.smoke!.output : "operation.worker_lost",
      );
      f.now += 660_000;
      assertEquals((await f.tick()).status, "unknown");
      assertEquals(state.known_good, null);
    });
  }
}
for (const final of ["passed", "failed", "unknown"]) {
  for (const timedOutFirst of [false, true]) {
    Deno.test(`${final} result read after deadline${timedOutFirst ? " and timeout" : ""} is retained`, async () => {
      const f = new Fixture();
      await f.tick("baseline");
      f.now += 660_000;
      if (timedOutFirst) assertEquals((await f.tick()).status, "timed_out");
      f.pass();
      f.receipt.smoke!.state = final;
      f.receipt.smoke!.output = "Final smoke output.";
      if (final === "failed") {
        f.receipt.smoke!.failures = 1;
        f.receipt.smoke!.conclusion = "failure";
      }
      const state = await f.tick();
      assertEquals(state.status, final);
      assertEquals(state.known_good, final === "passed" ? selected : null);
      assertEquals(
        state.reason,
        final === "passed" ? null : "Final smoke output.",
      );
      f.now += 660_000;
      assertEquals((await f.tick()).status, final);
      assertEquals(f.posts.length, 1);
    });
  }
}
Deno.test("a verified test failure takes precedence over a failed operation", async () => {
  const f = new Fixture();
  await f.tick("baseline");
  f.pass();
  Object.assign(f.receipt.smoke!, {
    state: "failed",
    failures: 1,
    conclusion: "failure",
  });
  f.receipt.operation!.status = "failed";
  assertEquals((await f.tick()).status, "failed");
});
Deno.test("workflow failure without failing JUnit is unknown", async () => {
  const f = new Fixture();
  await f.tick("baseline");
  f.pass();
  Object.assign(f.receipt.smoke!, { state: "failed", conclusion: "failure" });
  assertEquals((await f.tick()).status, "unknown");
  f.now += 660_000;
  assertEquals((await f.tick()).status, "unknown");
});
Deno.test("superseded stays visible for the tick and preserves its historical job", async () => {
  const f = new Fixture();
  await f.tick("baseline");
  f.observed.selected.deployment_id = 10;
  const state = await f.tick();
  assertEquals(state.status, "superseded");
  assertEquals(state.job, null);
  assertEquals(f.stored.get("job-9")?.key, "smoke.7.9");
  assertEquals((await f.tick()).status, "observed");
  assertEquals(f.posts.length, 1);
});
Deno.test("the tick budget aborts a stalled request without losing its saved intent", async () => {
  const f = new Fixture();
  let bounded: AbortSignal | undefined;
  const tick = productionSmoke(
    (ctx) => {
      bounded = ctx.signal;
      return (method, path, body, key) =>
        method === "POST"
          ? new Promise(() => {})
          : f.http(method, path, body, key);
    },
    () => f.now,
    100,
  ).methods.tick.execute;
  await assertRejects(
    () => tick({ mode: "baseline" }, f.context()),
    Error,
    "budget exceeded",
  );
  assertEquals(bounded?.aborted, true);
  assertEquals(f.stored.get("job-9")?.key, "smoke.7.9");
});
Deno.test("an already cancelled tick cannot read configuration or dispatch", async () => {
  const f = new Fixture();
  const controller = new AbortController();
  controller.abort(new Error("Stopped"));
  await assertRejects(
    () =>
      productionSmoke(() => f.http).methods.tick.execute({ mode: "baseline" }, {
        ...f.context(),
        signal: controller.signal,
      }),
    Error,
    "Stopped",
  );
  assertEquals(f.requests, []);
});

Deno.test("a crash between the job and state writes cannot spend recovery twice", async () => {
  const f = new Fixture();
  f.lostBefore = true;
  await f.tick("baseline");
  const context = f.context();
  await assertRejects(
    () =>
      productionSmoke(() => f.http, () => f.now).methods.tick.execute({
        mode: "baseline",
      }, {
        ...context,
        writeResource: async (spec, name, value) => {
          const handle = await context.writeResource(spec, name, value);
          if (spec === "job" && value.resend_used) {
            throw new Error("Crash after durable allowance");
          }
          return handle;
        },
      }),
    Error,
    "Crash after durable allowance",
  );
  assertEquals(f.posts.length, 1);
  assertEquals(f.stored.get("job-9")?.resend_used, true);
  await f.tick("baseline");
  assertEquals(f.posts.length, 1);
});
Deno.test("a stalled configuration refresh cannot send after its tick has aborted", async () => {
  const f = new Fixture();
  let release!: () => void;
  const held = new Promise<{ ok: boolean }>((resolve) => {
    release = () => resolve({ ok: true });
  });
  await assertRejects(
    () =>
      productionSmoke(() => f.http, () => f.now, 100).methods.tick.execute({
        mode: "baseline",
      }, {
        ...f.context(),
        runModel: () => held,
      }),
    Error,
    "budget exceeded",
  );
  release();
  await new Promise((resolve) => setTimeout(resolve, 0));
  assertEquals(f.requests, []);
  assertEquals(f.stored.size, 0);
});

for (const status of ["failed", "cancelled"]) {
  Deno.test(`${status} operation cannot establish a baseline from an inconsistent pass`, async () => {
    const f = new Fixture();
    await f.tick("baseline");
    f.pass();
    f.receipt.operation!.status = status;
    f.receipt.operation!.error_code = "operation.worker_lost";
    const state = await f.tick();
    assertEquals(state.status, "unknown");
    assertEquals(state.reason, "operation.worker_lost");
    assertEquals(state.known_good, null);
  });
}

for (
  const [status, code] of [[409, "operation.target_busy"], [
    503,
    "instance.smoke_github_unavailable",
  ]] as const
) {
  Deno.test(`${code} recovers on the workflow's follow-up observation with the original intent`, async () => {
    const f = new Fixture();
    f.refusal = { status, code };
    await f.tick("baseline");
    const first = structuredClone(f.stored.get("job-9"));
    f.now += 10_000; // Native workflow waits ten seconds before its observe step.
    f.refusal = null;
    assertEquals((await f.tick()).status, "pending");
    assertEquals(f.posts.length, 2);
    assertEquals(f.posts[0], f.posts[1]);
    assertEquals(f.stored.get("job-9")?.started_at, first?.started_at);
    assertEquals(f.stored.get("job-9")?.refusal_retry_used, true);
    assertEquals(f.stored.get("job-9")?.resend_used, false);
    f.pass();
    assertEquals((await f.tick()).status, "passed");
  });
  Deno.test(`${code} is not automatically retried after the smoke deadline`, async () => {
    const f = new Fixture();
    f.refusal = { status, code };
    await f.tick("baseline");
    f.now += 600_000;
    assertEquals((await f.tick()).status, "unknown");
    assertEquals(f.posts.length, 1);
    assertEquals(f.stored.get("job-9")?.refusal_retry_used, false);
  });
}
Deno.test("a transient refusal retry lost in transport keeps its separate resend allowance", async () => {
  const f = new Fixture();
  f.refusal = { status: 409, code: "operation.target_busy" };
  await f.tick("baseline");
  f.refusal = null;
  f.lostBefore = true;
  await f.tick();
  assertEquals(f.stored.get("job-9")?.resend_used, false);
  assertEquals(f.stored.get("job-9")?.refusal_retry_used, true);
  f.lostBefore = false;
  await f.tick("baseline");
  assertEquals(f.posts.length, 3);
  assertEquals(f.stored.get("job-9")?.resend_used, true);
  assertEquals(f.admitted.size, 1);
});
Deno.test("empty final unknown output retains the Gateway error code", async () => {
  const f = new Fixture();
  await f.tick("baseline");
  f.pass();
  f.receipt.smoke!.state = "unknown";
  f.receipt.smoke!.output = "";
  f.receipt.operation!.error_code = "instance.smoke_run_unsuccessful";
  const state = await f.tick();
  assertEquals(state.status, "unknown");
  assertEquals(state.reason, "instance.smoke_run_unsuccessful");
  f.now += 720_000;
  assertEquals((await f.tick()).reason, "instance.smoke_run_unsuccessful");
});
