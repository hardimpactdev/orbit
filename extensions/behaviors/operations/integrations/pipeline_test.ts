import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import {
  type Context,
  Executor,
  Operation,
} from "../../operation-runner/mod.ts";
import { pipeline } from "./pipeline.ts";
import type { Http } from "../../automatic-deploy/gateway.ts";

class PipelineFixture {
  operation = Operation.parse({
    id: 12,
    kind: "analytics.clickhouse_converge",
    node_id: 2,
    status: "running",
    cancel_requested: false,
    step_results: {},
    steps: [],
    input: { effects: {}, flow: [] },
  });
  calls: { path: string; body: Record<string, unknown> }[] = [];
  results = new Map<string, unknown>();
  saved = new Map<string, unknown>();
  resources = new Map<string, Record<string, unknown>>();
  children: string[] = [];
  lose: string | null = null;
  pending = false;
  childFailure = false;
  mutations = 0;
  prestart: string | null = null;
  effect(key: string, name: string, input: Record<string, unknown> = {}) {
    (this.operation.input.effects as Record<string, unknown>)[key] = {
      key,
      step: key,
      transport: name === "integration.receipt" ? "gateway" : "agent",
      name,
      input,
      resumable: true,
      required: true,
    };
    (this.operation.input.flow as unknown[]).push({ type: "run", key });
    this.results.set(
      key,
      name === "integration.receipt"
        ? { backend: "active" }
        : { changed: true },
    );
  }
  child(key: string, when?: Record<string, unknown>) {
    (this.operation.input.flow as unknown[]).push({
      type: "child",
      key,
      kind: "process.update",
      node_id: 2,
      input: { process_id: 7 },
      ...(when ? { when } : {}),
    });
  }
  http: Http = (_method, path, raw) => {
    const body = (raw ?? {}) as Record<string, unknown>;
    this.calls.push({ path, body: structuredClone(body) });
    let data: unknown = this.operation;
    if (path.includes("/receipts/")) {
      const key = path.split("/").at(-1)!;
      data = {
        effect_key: `op-12.${key}`,
        state: this.saved.has(key) ? "succeeded" : "unknown",
        exit_code: 0,
        result: this.saved.get(key) ?? null,
        finished_at: null,
      };
    }
    if (path.endsWith("/step")) {
      const step = this.operation.steps.find((step) => step.name === body.name);
      if (step) step.status = String(body.status);
      else {this.operation.steps.push({
          name: String(body.name),
          status: String(body.status),
        });}
    }
    if (path.endsWith("/run")) {
      if (this.prestart !== null) {
        return Promise.resolve({
          status: 200,
          body: { data: { error_code: this.prestart, not_started: true } },
        });
      }
      const key = String(body.key);
      if (!this.saved.has(key)) {
        this.mutations++;
        this.saved.set(key, this.results.get(key));
      }
      data = this.saved.get(key);
      if (this.lose === key) {
        this.lose = null;
        throw new Error("Lost result");
      }
    }
    if (path.endsWith("/step") && body.status === "succeeded") {
      this.operation.step_results[String(body.name)] = body.result;
    }
    if (path.endsWith("/children")) {
      const flow = this.operation.input.flow as Record<string, unknown>[];
      const recovery = this.operation.input.failure_recovery as {
        child: Record<string, unknown>;
      } | undefined;
      const failure = (this.operation.input.failure_flow ?? []) as Record<
        string,
        unknown
      >[];
      const intent = [...flow, ...failure].find((entry) =>
        entry.type === "child" && entry.key === body.key
      ) ?? recovery?.child;
      if (!intent) {
        throw new Error("Unknown child key");
      }
      assertEquals(Object.keys(body).sort(), ["key", "worker"]);
      this.children.push(String(body.key));
      assertEquals("type" in body, false);
      data = {
        ...this.operation,
        id: 100,
        kind: intent.kind,
        node_id: intent.node_id,
        input: intent.input,
        status: this.pending
          ? "running"
          : this.childFailure
          ? "failed"
          : "succeeded",
      };
    }
    return Promise.resolve({ status: 200, body: { data } });
  };
  context: Context = {
    globalArgs: {
      gatewayUrl: "https://gateway.example.test",
      caFile: "/unused",
    },
    readResource: () => Promise.resolve(null),
    writeResource: (_spec, name, value) => {
      this.resources.set(name, structuredClone(value));
      return Promise.resolve({ name });
    },
  };
  run(worker = "worker-12") {
    return pipeline(
      this.operation,
      new Executor(this.http),
      worker,
      this.context,
    );
  }
}
Deno.test("analytics applies ClickHouse Process changes only when the file receipt proves a change", async () => {
  for (const changed of [true, false]) {
    const f = new PipelineFixture();
    f.effect("files", "analytics.configure-clickhouse");
    f.results.set("files", { changed });
    f.child("clickhouse", { step: "files", field: "changed", equals: true });
    f.effect("integration-receipt", "integration.receipt");
    assertEquals((await f.run()).status, "succeeded");
    assertEquals(f.children, changed ? ["clickhouse"] : []);
  }
});
Deno.test("a failed storage child stops before health, publication and completion success", async () => {
  const f = new PipelineFixture();
  f.child("clickhouse");
  f.childFailure = true;
  f.effect("health", "http.await-healthy");
  f.results.set("health", { healthy: true });
  f.effect("integration-receipt", "integration.receipt");
  assertEquals((await f.run()).status, "failed");
  assertEquals(f.mutations, 0);
});
Deno.test("a pending child suspends execution and a later claim resumes from the same child key", async () => {
  const f = new PipelineFixture();
  f.effect("files", "analytics.configure-clickhouse");
  f.child("clickhouse");
  f.effect("integration-receipt", "integration.receipt");
  f.pending = true;
  assertEquals((await f.run()).status, "waiting");
  assertEquals(f.resources.size, 0);
  f.pending = false;
  assertEquals((await f.run()).status, "succeeded");
  assertEquals(f.children, ["clickhouse", "clickhouse"]);
  assertEquals(
    f.calls.filter((call) =>
      call.path.endsWith("/run") && call.body.key === "files"
    ).length,
    1,
  );
});
Deno.test("health must be proven before publishing and unknown response fields are discarded", async () => {
  const f = new PipelineFixture();
  f.effect("health", "http.await-healthy");
  f.child("publication");
  f.effect("integration-receipt", "integration.receipt");
  f.results.set("health", { healthy: false });
  await assertRejects(() => f.run());
  assertEquals(f.children, []);
  f.saved.clear();
  f.results.set("health", { healthy: true, token: "secret-sentinel" });
  assertEquals((await f.run()).status, "succeeded");
  assertEquals(
    JSON.stringify([...f.resources]).includes("secret-sentinel"),
    false,
  );
  assertEquals(JSON.stringify(f.calls).includes("secret-sentinel"), false);
});
Deno.test("lost effect reply reads its receipt and skips saved successful steps", async () => {
  const f = new PipelineFixture();
  f.effect("files", "analytics.configure-clickhouse");
  f.effect("integration-receipt", "integration.receipt");
  f.lose = "files";
  await assertRejects(() => f.run(), Error, "Lost result");
  assertEquals((await f.run()).status, "succeeded");
  const runs = f.calls.filter((call) =>
    call.path.endsWith("/run") && call.body.key === "files"
  );
  assertEquals(runs.length, 1);
  assertEquals(
    f.calls.filter((call) => call.path.includes("/receipts/")).length,
    1,
  );
  assertEquals(f.mutations, 2);
});

Deno.test("retirement tries every admitted cleanup after a failed child and reports partial cleanup", async () => {
  const f = new PipelineFixture();
  for (const key of ["exporter", "cadvisor", "services"]) {
    f.child(key);
    const flow = f.operation.input.flow as Record<string, unknown>[];
    flow[flow.length - 1].continue_on_failure = true;
  }
  f.childFailure = true;
  f.effect("integration-receipt", "integration.receipt");
  assertEquals((await f.run()).status, "failed");
  assertEquals(f.children, ["exporter", "cadvisor", "services"]);
});

Deno.test("dynamic service inputs refresh after proven inspection before applying exporter state", async () => {
  const f = new PipelineFixture();
  f.effect("inspect", "php.inspect-production-pool");
  f.results.set("inspect", { enabled: true, fingerprint: "pool-new" });
  f.effect("exporter", "metrics.service-runtime", {
    operation: "apply",
    state: { config: "old" },
  });
  const http: Http = (method, path, body) => {
    if (path.endsWith("/heartbeat")) {
      const effects = structuredClone(f.operation.input.effects) as Record<
        string,
        Record<string, unknown>
      >;
      if (f.operation.step_results.inspect) {
        effects.exporter.input = {
          operation: "apply",
          state: { config: "# runtime 1: pool-new\n" },
        };
      }
      return Promise.resolve({
        status: 200,
        body: {
          data: { execution_context: { descriptors: Object.values(effects) } },
        },
      });
    }
    return f.http(method, path, body);
  };
  assertEquals(
    (await pipeline(
      f.operation,
      new Executor(http),
      "worker",
      f.context,
      () => {},
      true,
    )).status,
    "succeeded",
  );
  const apply = f.calls.find((call) =>
    call.path.endsWith("/run") && call.body.key === "exporter"
  );
  assertEquals(apply?.body.input, {
    operation: "apply",
    state: { config: "# runtime 1: pool-new\n" },
  });
});

Deno.test("a proven pre-publication refusal restores takeover intent and republishes before failing", async () => {
  const f = new PipelineFixture();
  f.effect("package", "caddy.install-package");
  f.results.set("package", { error_code: "caddy.install_failed" });
  f.effect("restore", "ai_router.restore-takeover");
  (f.operation.input.flow as unknown[]).pop();
  const effects = f.operation.input.effects as Record<
    string,
    Record<string, unknown>
  >;
  effects.restore.transport = "gateway";
  effects.restore.required = false;
  f.results.set("restore", { restored: true });
  f.operation.input.failure_recovery = {
    before_child: "publication",
    effect: "restore",
    child: {
      key: "republish",
      kind: "route.caddy_converge",
      node_id: 2,
      input: { reason: "restore" },
    },
  };
  assertEquals((await f.run()).status, "failed");
  assertEquals(f.children, ["republish"]);
  assertEquals(
    f.calls.filter((call) => call.path.endsWith("/run")).map((call) =>
      call.body.key
    ),
    ["package", "restore"],
  );
});

Deno.test("an uncertain effect does not restore or republish the collector Route", async () => {
  const f = new PipelineFixture();
  f.effect("package", "caddy.install-package");
  f.lose = "package";
  f.operation.input.failure_recovery = {
    before_child: "publication",
    effect: "restore",
    child: {
      key: "republish",
      kind: "route.caddy_converge",
      node_id: 2,
      input: { reason: "restore" },
    },
  };
  await assertRejects(() => f.run(), Error, "Lost result");
  assertEquals(f.children, []);
  assertEquals(f.resources.size, 0);
});

Deno.test("a failure after collector publication preserves the live collector takeover", async () => {
  const f = new PipelineFixture();
  f.child("publication");
  f.effect("health", "http.await-healthy");
  f.results.set("health", { error_code: "http.unhealthy" });
  f.operation.input.failure_recovery = {
    before_child: "publication",
    effect: "unused",
    child: {
      key: "republish",
      kind: "route.caddy_converge",
      node_id: 2,
      input: { reason: "restore" },
    },
  };
  assertEquals((await f.run()).status, "failed");
  assertEquals(f.children, ["publication"]);
});

Deno.test("only a newly created Reverb with a proven runtime rolls back a publication refusal", async () => {
  for (const created of [false, true]) {
    const f = new PipelineFixture();
    f.operation.kind = "websocket.role_converge";
    f.effect("runtime", "websocket.converge-runtime");
    f.results.set("runtime", { applied: true });
    f.effect("dns", "websocket.publication-state");
    f.results.set("dns", { error_code: "websocket.dns_failed" });
    f.effect("rollback-stop", "websocket.remove-runtime");
    (f.operation.input.flow as unknown[]).pop();
    f.results.set("rollback-stop", { applied: true });
    if (created) {
      f.operation.input.failure_when = {
        step: "runtime",
        field: "applied",
        equals: true,
      };
      f.operation.input.failure_flow = [{ type: "run", key: "rollback-stop" }];
    }
    assertEquals((await f.run()).status, "failed");
    const keys = f.calls.filter((call) => call.path.endsWith("/run")).map((
      call,
    ) => call.body.key);
    assertEquals(
      keys,
      created ? ["runtime", "dns", "rollback-stop"] : ["runtime", "dns"],
    );
  }
});

Deno.test("Reverb rollback requires a proven runtime and preserves lock-loss failures", async () => {
  for (const code of ["websocket.runtime_failed", "node.lock_lost"]) {
    const f = new PipelineFixture();
    f.effect("runtime", "websocket.converge-runtime");
    f.results.set("runtime", { error_code: code });
    f.operation.input.failure_when = {
      step: "runtime",
      field: "applied",
      equals: true,
    };
    f.operation.input.failure_flow = [{ type: "run", key: "unused" }];
    assertEquals((await f.run()).status, "failed");
    assertEquals(f.mutations, 1);
    assertEquals(f.resources.get("operation-12")!.error_code, code);
  }
});

Deno.test("a proven Reverb rollback failure is reported without claiming cleanup succeeded", async () => {
  const f = new PipelineFixture();
  f.effect("runtime", "websocket.converge-runtime");
  f.results.set("runtime", { applied: true });
  f.effect("dns", "websocket.publication-state");
  f.results.set("dns", { error_code: "websocket.dns_failed" });
  f.effect("stop", "websocket.remove-runtime");
  (f.operation.input.flow as unknown[]).pop();
  f.results.set("stop", { error_code: "websocket.stop_failed" });
  f.operation.input.failure_when = {
    step: "runtime",
    field: "applied",
    equals: true,
  };
  f.operation.input.failure_flow = [{ type: "run", key: "stop" }];
  assertEquals((await f.run()).status, "failed");
  assertEquals(
    f.resources.get("operation-12")!.error_code,
    "websocket.rollback_failed",
  );
});

Deno.test("new Reverb rollback releases the parent while publication cleanup is pending and resumes with the same keys", async () => {
  const f = new PipelineFixture();
  f.effect("runtime", "websocket.converge-runtime");
  f.results.set("runtime", { applied: true });
  f.effect("publication", "caddy.install-certificate");
  f.results.set("publication", { error_code: "websocket.publication_refused" });
  f.effect("stop", "websocket.remove-runtime");
  f.results.set("stop", { applied: true });
  f.child("rollback-publication");
  const flow = f.operation.input.flow as unknown[];
  f.operation.input.failure_flow = flow.splice(2);
  f.operation.input.failure_when = {
    step: "runtime",
    field: "applied",
    equals: true,
  };
  f.pending = true;
  assertEquals((await f.run()).status, "waiting");
  assertEquals(f.calls.some((call) => call.path.endsWith("/complete")), false);
  assertEquals(f.resources.size, 0);
  assertEquals(f.calls.at(-1)?.body, {
    worker: "worker-12",
    keys: ["rollback-publication"],
  });
  f.pending = false;
  assertEquals((await f.run("replacement-worker")).status, "failed");
  assertEquals(
    f.calls.at(-1)?.body.error_code,
    "websocket.publication_refused",
  );
  assertEquals(f.mutations, 3);
  assertEquals(f.children, ["rollback-publication", "rollback-publication"]);
});

Deno.test("a lost rollback reply is resolved through its target receipt before failed completion", async () => {
  const f = new PipelineFixture();
  f.effect("runtime", "websocket.converge-runtime");
  f.results.set("runtime", { applied: true });
  f.effect("publication", "caddy.install-certificate");
  f.results.set("publication", { error_code: "websocket.publication_refused" });
  f.effect("stop", "websocket.remove-runtime");
  f.results.set("stop", { applied: true });
  const flow = f.operation.input.flow as unknown[];
  f.operation.input.failure_flow = flow.splice(2);
  f.operation.input.failure_when = {
    step: "runtime",
    field: "applied",
    equals: true,
  };
  f.lose = "stop";
  await assertRejects(() => f.run(), Error, "Lost result");
  assertEquals(f.calls.some((call) => call.path.endsWith("/complete")), false);
  assertEquals((await f.run("replacement-worker")).status, "failed");
  assertEquals(
    f.calls.filter((call) =>
      call.path.endsWith("/run") && call.body.key === "stop"
    ).length,
    1,
  );
  assertEquals(
    f.calls.filter((call) => call.path.endsWith("/receipts/stop")).length,
    1,
  );
  assertEquals(f.mutations, 3);
});

Deno.test("pre-start refusal preserves the pipeline and retries its same effect before dependent children", async () => {
  const f = new PipelineFixture();
  f.effect("files", "analytics.configure-clickhouse");
  f.child("clickhouse");
  f.effect("integration-receipt", "integration.receipt");
  f.prestart = "agent.target_busy";
  await assertRejects(() => f.run(), Error, "operation.effect_not_started");
  assertEquals(f.mutations, 0);
  assertEquals(f.resources.size, 0);
  assertEquals(f.children, []);
  assertEquals(
    f.calls.some((call) =>
      call.path.endsWith("/complete") ||
      call.path.endsWith("/step") && call.body.status !== "running"
    ),
    false,
  );
  f.prestart = null;
  assertEquals((await f.run("replacement-worker")).status, "succeeded");
  const runs = f.calls.filter((call) => call.path.endsWith("/run"));
  assertEquals(runs[0].body.key, runs[1].body.key);
  assertEquals(f.children, ["clickhouse"]);
});

function metricsCaddyRestoreFixture() {
  const f = new PipelineFixture();
  f.operation.kind = "metrics.service_caddy_restore";
  f.operation.input = {
    restore_key: "service-restore-2",
    effects: {
      "integration-receipt": {
        key: "integration-receipt",
        step: "integration-receipt",
        transport: "gateway",
        name: "metrics.receipt",
        input: { backend: "active" },
        resumable: true,
        required: true,
      },
    },
    flow: [
      {
        type: "child",
        key: "service-caddy-restore",
        kind: "route.caddy_converge",
        node_id: 2,
        input: { reason: "metrics service rollback" },
      },
      { type: "run", key: "integration-receipt" },
    ],
  };
  f.results.set("integration-receipt", { backend: "active" });
  return f;
}

Deno.test("Metrics service Caddy restore waits for its Route child then completes with the Metrics receipt", async () => {
  const f = metricsCaddyRestoreFixture();
  f.pending = true;
  assertEquals((await f.run()).status, "waiting");
  assertEquals(f.mutations, 0);
  assertEquals(f.resources.size, 0);
  f.pending = false;

  assertEquals((await f.run()).status, "succeeded");
  assertEquals(f.children, ["service-caddy-restore", "service-caddy-restore"]);
  const runs = f.calls.filter((call) => call.path.endsWith("/run"));
  assertEquals(runs.map((call) => call.body), [{
    worker: "worker-12",
    key: "integration-receipt",
    transport: "gateway",
    name: "metrics.receipt",
    input: { backend: "active" },
  }]);
  assertEquals(f.calls.find((call) => call.path.endsWith("/complete"))?.body, {
    worker: "worker-12",
    status: "succeeded",
    result: { backend: "active" },
    error_code: null,
  });
});

Deno.test("Metrics service Caddy restore refuses a receipt without backend proof", async () => {
  const f = metricsCaddyRestoreFixture();
  f.results.set("integration-receipt", {});

  await assertRejects(() => f.run(), Error, "integration.outcome_unproven");
  assertEquals(f.calls.some((call) => call.path.endsWith("/complete")), false);
  assertEquals(f.resources.size, 0);
});
