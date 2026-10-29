import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { createModelTestContext } from "jsr:@swamp-club/swamp-testing@0.20261016.58";
import { database } from "./mod.ts";
import { type Context, Operation } from "../../operation-runner/mod.ts";
import type { Http } from "../../automatic-deploy/gateway.ts";
import { type Child, type Descriptor, Kinds } from "./contract.ts";
import type { z } from "npm:zod@4.4.3";

class Fixture {
  operation = Operation.parse({
    id: 7,
    node_id: 2,
    kind: "database.create",
    status: "running",
    cancel_requested: false,
    input: { intent_id: 9, connection_id: 11, driver: "pgsql" },
    step_results: {},
  });
  descriptors: z.infer<typeof Descriptor>[] = [{
    key: "database-apply",
    step: "database-apply",
    transport: "agent",
    name: "database.server-admin",
    input: {
      operation: "execute",
      container: "orbit-process-1-dev",
      password: { $secret: "database.intent", id: 9 },
      sql: { $secret: "database.intent", id: 9 },
      password_variable: "PGPASSWORD",
      client: ["psql"],
    },
    required: true,
    cleanup: false,
    resumable: true,
  }];
  children: z.infer<typeof Child>[] = [];
  childRows: Record<string, unknown>[] = [];
  loseChild = false;
  childStatus = "succeeded";
  omitChildrenContext = false;
  receiptState: "succeeded" | "failed" | "running" | "unknown" | null = null;
  calls: { path: string; body: Record<string, unknown> }[] = [];
  receipts = new Map<string, Record<string, unknown>>();
  resources = new Map<string, Record<string, unknown>>();
  runs = 0;
  completions = 0;
  loseRun = false;
  loseStep = false;
  loseCompletion = false;
  leaseLost = false;
  refusal: string | null = null;
  http: Http = async (_method, path, raw) => {
    await Promise.resolve();
    const body = raw as Record<string, unknown>;
    this.calls.push({ path, body });
    if (this.leaseLost) {
      return { status: 409, body: { error: { code: "operation.lease_lost" } } };
    }
    let data: unknown = {
      ...this.operation,
      execution_context: {
        descriptors: this.descriptors,
        ...(this.omitChildrenContext ? {} : { children: this.children }),
      },
    };
    if (path.includes("/receipts/")) {
      const key = decodeURIComponent(path.split("/").at(-1)!);
      const result = this.receipts.get(key);
      data = {
        effect_key: `op-7.${key}`,
        state: this.receiptState ??
          (result ? (result.error_code ? "failed" : "succeeded") : "unknown"),
        result: result ?? null,
      };
    }
    if (path.endsWith("/children")) {
      if (_method === "POST") {
        let child = this.childRows.find((row) => row.key === body.key);
        if (!child) {
          child = {
            id: 20 + this.childRows.length,
            key: body.key,
            kind: this.children.find((entry) => entry.key === body.key)!.kind,
            node_id: this.children.find((entry) =>
              entry.key === body.key
            )!.node_id,
            status: this.childStatus,
            error_code: null,
            result: {},
          };
          this.childRows.push(child);
        }
        data = child;
        if (this.loseChild) {
          this.loseChild = false;
          throw new Error("Lost child admission reply");
        }
      } else data = this.childRows;
    }
    if (path.endsWith("/run")) {
      const key = String(body.key);
      if (!this.receipts.has(key)) {
        this.runs++;
        this.receipts.set(
          key,
          this.refusal ? { error_code: this.refusal } : {
            backend: this.operation.kind.endsWith("destroy")
              ? "absent"
              : "active",
          },
        );
      }
      data = this.receipts.get(key);
      if (this.loseRun) {
        this.loseRun = false;
        throw new Error("Lost run reply");
      }
    }
    if (path.endsWith("/step")) {
      const saved = this.operation.steps.find((step) =>
        step.name === body.name
      );
      if (saved) saved.status = String(body.status);
      else {this.operation.steps.push({
          name: String(body.name),
          status: String(body.status),
        });}
      if (body.status === "succeeded") {
        this.operation.step_results[String(body.name)] = body.result;
      }
      if (this.loseStep && body.status === "succeeded") {
        this.loseStep = false;
        throw new Error("Lost step reply");
      }
    }
    if (path.endsWith("/complete")) {
      this.completions++;
      if (this.loseCompletion) {
        this.loseCompletion = false;
        throw new Error("Lost completion reply");
      }
    }
    return { status: 200, body: { data } };
  };
  context(): Context {
    const { context } = createModelTestContext({
      globalArgs: {
        gatewayUrl: "https://gateway.example.test",
        caFile: "/unused",
      },
    });
    const write = context.writeResource.bind(context);
    return Object.assign(context, {
      globalArgs: {
        gatewayUrl: "https://gateway.example.test",
        caFile: "/unused",
      },
      readResource: (name: string) =>
        Promise.resolve(this.resources.get(name) ?? null),
      writeResource: async (
        spec: string,
        name: string,
        value: Record<string, unknown>,
      ) => {
        const handle = await write(spec, name, value);
        this.resources.set(name, structuredClone(value));
        return handle;
      },
    });
  }
  execute(worker = "run-1") {
    return database(() => this.http).methods.execute.execute({
      operationId: 7,
      worker,
    }, this.context());
  }
}

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
Deno.test("server initialization finishes readiness and secret removal before completion", async () => {
  const f = new Fixture();
  f.operation.kind = "database_server.create";
  f.descriptors = [
    "container",
    "initialize",
    "remove-initialization-secret",
    "database-apply",
  ].map((key) => ({ ...f.descriptors[0], key, step: key }));
  await f.execute();
  assertEquals(
    f.calls.filter((call) => call.path.endsWith("/run")).map((call) =>
      call.body.key
    ),
    [
      "container",
      "initialize",
      "remove-initialization-secret",
      "database-apply",
    ],
  );
  assertEquals(f.calls.at(-1)!.path, "/api/v1/operation-executor/7/complete");
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
Deno.test("a lost completion reply posts only the saved permanent completion intent", async () => {
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
  assertEquals([...f.resources.values()], [{
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
