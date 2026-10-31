import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { Kinds } from "./contract.ts";

import { Fixture } from "./tests/fixture.ts";

Deno.test("each database kind uses admitted descriptors and never supplies a transport Node", async () => {
  for (const kind of Kinds) {
    const f = new Fixture();
    f.operation.kind = kind;
    assertEquals((await f.execute()).status, "succeeded");
    const run = f.calls.find((call) => call.path.endsWith("/run"))!.body;
    assertEquals("node_id" in run, false);
    assertEquals(run.input, f.descriptors[0].input);
    assertEquals(f.resources.get("operation-7")!.status, "succeeded");
  }
});
Deno.test("PostgreSQL test pool repair resumes proven members before creating the next", async () => {
  const f = new Fixture();
  f.descriptors = ["test-pool-0", "test-pool-1", "database-apply"].map((
    key,
  ) => ({ ...f.descriptors[0], key, step: key }));
  f.operation.step_results["test-pool-0"] = { backend: "active" };
  await f.execute();
  assertEquals(f.runs, 2);
});
Deno.test("Valkey and SQL refusals finish failed and keep the stable error code", async () => {
  for (
    const code of [
      "database.in_use",
      "database.name_conflict",
      "database.acl_rules_invalid",
      "database.server_in_use",
      "operation.execution_uncertain",
    ]
  ) {
    const f = new Fixture();
    f.refusal = code;
    assertEquals((await f.execute()).status, "failed");
    assertEquals(f.calls.at(-1)!.body.error_code, code);
    assertEquals(
      f.calls.filter((call) => call.path.endsWith("/step")).at(-1)!.body.status,
      "failed",
    );
  }
});
Deno.test("a lost Node reply reuses one effect key and Gateway receipt", async () => {
  const f = new Fixture();
  f.loseRun = true;
  await assertRejects(() => f.execute(), Error, "Lost run reply");
  assertEquals(f.completions, 0);
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(f.runs, 1);
  assertEquals(
    f.calls.filter((call) => call.path.endsWith("/run")).map((call) =>
      call.body.key
    ),
    ["database-apply"],
  );
});
Deno.test("a lost step reply resumes its proven receipt without another Node call", async () => {
  const f = new Fixture();
  f.loseStep = true;
  await assertRejects(() => f.execute(), Error, "Lost step reply");
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(f.runs, 1);
});
Deno.test("a lost completion reply confirms its saved terminal result without claiming again", async () => {
  const f = new Fixture();
  f.loseCompletion = true;
  await assertRejects(() => f.execute(), Error, "Lost completion reply");
  const calls = f.calls.length;
  await f.execute();
  assertEquals(f.calls.length, calls + 1);
  assertEquals(f.runs, 1);
});
Deno.test("an expired lease stops the old worker and a new claim resumes the same effect", async () => {
  const f = new Fixture();
  f.leaseLost = true;
  await assertRejects(() => f.execute(), Error, "operation.lease_lost");
  assertEquals(f.runs, 0);
  f.leaseLost = false;
  await f.execute("run-2");
  assertEquals(f.runs, 1);
});
Deno.test("cancellation before an effect performs no Node work", async () => {
  const f = new Fixture();
  f.operation.cancel_requested = true;
  assertEquals((await f.execute()).status, "cancelled");
  assertEquals(f.runs, 0);
});
Deno.test("cancellation after a proven effect records that effect", async () => {
  const f = new Fixture();
  f.operation.cancel_requested = true;
  f.operation.step_results["database-apply"] = { backend: "active" };
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(f.runs, 0);
});
Deno.test("Gateway cancellation at invocation finishes the step as failed and Operation as cancelled", async () => {
  const f = new Fixture();
  f.refusal = "operation.cancelled";
  assertEquals((await f.execute()).status, "cancelled");
  assertEquals(
    f.calls.filter((call) => call.path.endsWith("/step")).at(-1)!.body.status,
    "failed",
  );
});
Deno.test("unstructured diagnostic error codes cannot enter receipts", async () => {
  const f = new Fixture();
  f.refusal = "SQL ERROR password=do-not-record";
  await assertRejects(() => f.execute());
  assertEquals([...f.resources.values()], [{ operation_id: 7 }, {
    effect_key: "op-7.database-apply",
  }]);
  assertEquals(f.completions, 0);
});

Deno.test("PostgreSQL removal restores admission after a failed drop", async () => {
  const f = new Fixture();
  f.operation.kind = "database.destroy";
  f.refusal = "database.in_use";
  f.descriptors = [f.descriptors[0], {
    ...f.descriptors[0],
    key: "restore-admission",
    step: "restore-admission",
    cleanup: true,
  }];
  assertEquals((await f.execute()).status, "failed");
  assertEquals(
    f.calls.filter((call) => call.path.endsWith("/run")).map((call) =>
      call.body.key
    ),
    ["database-apply", "restore-admission"],
  );
});

Deno.test("an uncertain running receipt defers without another invocation", async () => {
  const f = new Fixture();
  f.loseRun = true;
  await assertRejects(() => f.execute());
  f.receiptState = "running";
  await assertRejects(
    () => f.execute(),
    Error,
    "Database effect still running",
  );
  assertEquals(f.runs, 1);
  assertEquals(f.completions, 0);
  f.receiptState = "succeeded";
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(f.calls.filter((call) => call.path.endsWith("/run")).length, 1);
});
Deno.test("an unknown receipt permits the same keyed invocation and preserves failure receipts", async () => {
  const f = new Fixture();
  f.refusal = "database.query_failed";
  f.loseRun = true;
  await assertRejects(() => f.execute());
  f.receipts.clear();
  f.receiptState = "unknown";
  assertEquals((await f.execute()).status, "failed");
  assertEquals(
    f.calls.filter((call) => call.path.endsWith("/run")).map((call) =>
      call.body.key
    ),
    ["database-apply", "database-apply"],
  );
  assertEquals(f.calls.at(-1)!.body.error_code, "database.query_failed");
});
Deno.test("children use parent-scoped reads and stable keys after a lost admission reply", async () => {
  const f = new Fixture();
  f.children = [
    {
      key: "export",
      kind: "database.sqlite_export",
      node_id: 3,
      input: { intent_id: 10 },
      phase: "before",
    },
    {
      key: "cleanup",
      kind: "database.sqlite_cleanup",
      node_id: 3,
      input: { intent_id: 11 },
      phase: "after",
    },
  ];
  f.loseChild = true;
  await assertRejects(() => f.execute(), Error, "Lost child admission reply");
  assertEquals(f.runs, 0);
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(f.childRows.length, 2);
  const events = f.calls.filter((call) =>
    call.path.endsWith("/children") || call.path.endsWith("/run")
  );
  assertEquals(events.map((call) => call.body?.key ?? "read"), [
    "export",
    "export",
    "read",
    "database-apply",
    "cleanup",
    "read",
  ]);
  assertEquals(
    events.some((call) => call.body && "parent_operation_id" in call.body),
    false,
  );
});
Deno.test("durable child wait releases the worker and re-entry reuses admission and effects", async () => {
  const f = new Fixture();
  f.children = [{
    key: "export",
    kind: "database.sqlite_export",
    node_id: 3,
    input: { intent_id: 10 },
    phase: "before",
  }];
  f.childStatus = "queued";
  assertEquals((await f.execute()).status, "waiting");
  assertEquals(f.runs, 0);
  assertEquals(f.completions, 0);
  assertEquals(f.calls.at(-1)!.body, {
    worker: "run-1",
    keys: ["export"],
  });
  assertEquals(
    f.calls.filter((call) => call.path.endsWith("/children") && call.body).map((
      call,
    ) => call.body),
    [{ worker: "run-1", key: "export" }],
  );
  f.childRows[0].status = "succeeded";
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(f.childRows.length, 1);
  assertEquals(f.runs, 1);
});

Deno.test("a failed source child prevents target effects but still cleans up the export", async () => {
  const f = new Fixture();
  f.children = [
    {
      key: "export",
      kind: "database.sqlite_export",
      node_id: 3,
      input: { intent_id: 10 },
      phase: "before",
    },
    {
      key: "cleanup",
      kind: "database.sqlite_cleanup",
      node_id: 3,
      input: { intent_id: 11 },
      phase: "after",
    },
  ];
  f.childRows = [{
    id: 20,
    key: "export",
    kind: "database.sqlite_export",
    node_id: 3,
    status: "failed",
    error_code: "sqlite.seed_preflight_failed",
    result: {},
  }];
  assertEquals((await f.execute()).status, "failed");
  assertEquals(f.runs, 0);
  assertEquals(f.childRows.length, 2);
  assertEquals(f.calls.at(-1)!.body.error_code, "sqlite.seed_preflight_failed");
});

Deno.test("the ordinary descriptor context carries eligible child keys and proves completion after cleanup", async () => {
  const f = new Fixture();
  f.omitChildrenContext = true;
  const cleanup = {
    key: "cleanup",
    kind: "database.sqlite_cleanup",
    node_id: 3,
    input: { intent_id: 12 },
    phase: "after" as const,
  };
  f.children = [cleanup];
  f.descriptors.push({
    key: "database-complete",
    step: "database-complete",
    transport: "gateway",
    name: "database.proven",
    input: { intent_id: 9, children: [cleanup] },
    required: true,
    resumable: true,
    cleanup: false,
  });
  assertEquals((await f.execute()).status, "succeeded");
  const events = f.calls.filter((call) =>
    call.body && (call.path.endsWith("/children") || call.path.endsWith("/run"))
  ).map((call) => call.body.key);
  assertEquals(events, [
    "database-apply",
    "cleanup",
    "database-complete",
    "cleanup",
  ]);
});

Deno.test("a failed target effect refreshes the plan and admits newly eligible source cleanup", async () => {
  const f = new Fixture();
  f.revealCleanupAfterFailure = true;
  f.refusal = "database.query_failed";
  f.children = [{
    key: "source-cleanup",
    kind: "database.sqlite_cleanup",
    node_id: 3,
    input: { intent_id: 12 },
    phase: "after",
  }];
  assertEquals((await f.execute()).status, "failed");
  assertEquals(f.childRows.map((row) => row.key), ["source-cleanup"]);
  assertEquals(f.completions, 1);
});
Deno.test("uncertain locked removal reads its target receipt before dispatching again", async () => {
  const f = new Fixture();
  f.descriptors[0].transport = "database_removal";
  f.loseRun = true;
  await assertRejects(() => f.execute(), Error, "Lost run reply");
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(f.runs, 1);
  assertEquals(f.calls.some((call) => call.path.includes("/receipts/")), true);
});

Deno.test("seed abandonment drives source cleanup after a target cleanup refusal", async () => {
  const f = new Fixture();
  f.operation.kind = "database.sqlite_seed_abandon";
  f.descriptors = [{
    ...f.descriptors[0],
    name: "instance.install-sqlite-seed",
    input: { mode: "cleanup", operation: "recorded-attempt" },
  }];
  f.children = [{
    key: "database.sqlite_cleanup.12",
    kind: "database.sqlite_cleanup",
    node_id: 3,
    input: { intent_id: 12 },
    phase: "after",
  }];
  f.revealCleanupAfterFailure = true;
  f.refusal = "sqlite.seed_preflight_failed";
  assertEquals((await f.execute()).status, "failed");
  const target = f.calls.findIndex((call) => call.path.endsWith("/run"));
  const source = f.calls.findIndex((call) =>
    call.path.endsWith("/children") &&
    call.body?.key === "database.sqlite_cleanup.12"
  );
  assertEquals(target >= 0 && source > target, true);
  assertEquals(
    f.resources.get("operation-7")!.error_code,
    "sqlite.seed_preflight_failed",
  );
  assertEquals(f.calls[source].body, {
    worker: "run-1",
    key: "database.sqlite_cleanup.12",
  });
});

Deno.test("busy seed refusal defers abandonment without a cleanup or terminal receipt", async () => {
  const f = new Fixture();
  f.operation.kind = "database.sqlite_seed_abandon";
  f.descriptors = [{
    ...f.descriptors[0],
    name: "instance.install-sqlite-seed",
    input: { mode: "cleanup", operation: "recorded-attempt" },
  }];
  f.children = [{
    key: "database.sqlite_cleanup.12",
    kind: "database.sqlite_cleanup",
    node_id: 3,
    input: { intent_id: 12 },
    phase: "after",
  }];
  f.seedBusy = true;
  await assertRejects(() => f.execute());
  assertEquals(f.runs, 0);
  assertEquals(f.completions, 0);
  assertEquals(f.childRows.length, 0);
  f.seedBusy = false;
  assertEquals(
    (await f.execute("retry-after-seed-finished")).status,
    "succeeded",
  );
  assertEquals(f.runs, 1);
  assertEquals(f.childRows.length, 1);
});

Deno.test("a null placed claim performs no database work", async () => {
  const f = new Fixture();
  f.nullClaim = true;
  assertEquals((await f.execute()).status, "empty");
  assertEquals(f.calls.map((call) => call.path), [
    "/api/v1/operation-executor/claim",
  ]);
  assertEquals(f.resources.size, 0);
  assertEquals(f.runs, 0);
});
Deno.test("a lost lease retry keeps its assignment and cannot select the next queued database", async () => {
  const f = new Fixture();
  f.loseRun = true;
  await assertRejects(() => f.execute(), Error, "Lost run reply");
  assertEquals(f.resources.get("assignment-run-1"), { operation_id: 7 });
  f.leaseLost = true;
  await assertRejects(() => f.execute(), Error, "operation.lease_lost");
  assertEquals(
    f.calls.filter((call) => call.path.endsWith("/claim")).length,
    1,
  );
  assertEquals(f.runs, 1);
});
Deno.test("a saved database completion requires the exact original terminal result", async () => {
  const f = new Fixture();
  await f.execute();
  f.finished = { ...f.finished, result: { backend: "absent" } };
  await assertRejects(
    () => f.execute(),
    Error,
    "Unproven saved database completion",
  );
  assertEquals(
    f.calls.filter((call) => call.path.endsWith("/claim")).length,
    1,
  );
  assertEquals(f.completions, 1);
});
