import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { createModelTestContext } from "jsr:@swamp-club/swamp-testing@0.20261016.58";
import { integrations } from "./mod.ts";
import {
  type Context,
  ExecutorError,
  Operation,
} from "../../operation-runner/mod.ts";
import type { Http } from "../../automatic-deploy/gateway.ts";

class Fixture {
  operation = Operation.parse({
    id: 12,
    node_id: 2,
    kind: "metrics.node_exporter_converge",
    status: "running",
    cancel_requested: false,
    input: { address: "10.44.0.2", metrics_address: "10.44.0.3" },
    step_results: {},
  });
  calls: { path: string; body: Record<string, unknown> }[] = [];
  resources = new Map<string, Record<string, unknown>>();
  receipt: Record<string, unknown> | null = null;
  result: Record<string, unknown> = { changed: true };
  effects = 0;
  loseRun = false;
  loseCompletion = false;
  receiptState: "succeeded" | "failed" | "running" | "unknown" = "succeeded";
  leaseLost = false;
  completionRefusal: string | null = null;
  cancelAtRun = false;
  prestart: string | null = null;
  http: Http = async (_method, path, raw) => {
    const body = (raw ?? {}) as Record<string, unknown>;
    this.calls.push({ path, body: structuredClone(body) });
    await Promise.resolve();
    if (this.leaseLost) {
      return { status: 409, body: { error: { code: "operation.lease_lost" } } };
    }
    let data: unknown = this.operation;
    if (path.includes("/receipts/")) {
      data = {
        effect_key: `op-12.${path.split("/").at(-1)}`,
        state: this.receipt === null ? "unknown" : this.receiptState,
        exit_code: this.receiptState === "failed" ? 1 : 0,
        result: this.receipt,
        finished_at: null,
      };
    }
    if (path.endsWith("/run")) {
      if (this.prestart !== null) {
        return {
          status: 200,
          body: { data: { error_code: this.prestart, not_started: true } },
        };
      }
      if (!this.receipt) {
        if (this.cancelAtRun) {
          this.receipt = { error_code: "operation.cancelled" };
        } else {
          this.effects++;
          this.receipt = structuredClone(this.result);
        }
      }
      if (this.loseRun) {
        this.loseRun = false;
        throw new Error("Lost run reply");
      }
      data = this.receipt;
    }
    if (path.endsWith("/step")) {
      const step = this.operation.steps.find((step) => step.name === body.name);
      if (step) step.status = String(body.status);
      else {this.operation.steps.push({
          name: String(body.name),
          status: String(body.status),
        });}
      if (body.status === "succeeded") {
        this.operation.step_results[String(body.name)] = body.result;
      }
    }
    if (
      path.endsWith("/complete") && body.status === "succeeded" &&
      this.completionRefusal !== null
    ) {
      return { status: 409, body: { error: { code: this.completionRefusal } } };
    }
    if (path.endsWith("/complete")) {
      this.operation.status = String(body.status);
      data = this.operation;
    }
    if (path.startsWith("/api/v1/operations/")) {
      const completion = this.resources.get(`operation-${this.operation.id}`);
      data = {
        ...this.operation,
        result: completion?.result ?? null,
        error_code: completion?.error_code ?? null,
      };
    }
    if (path.endsWith("/complete") && this.loseCompletion) {
      this.loseCompletion = false;
      throw new Error("Lost completion reply");
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
  execute(worker = "workflow-12") {
    return integrations(() => this.http).methods.execute.execute({
      kind: this.operation.kind,
      worker,
    }, this.context());
  }
}

Deno.test("each exporter component converges and removes only its recorded addresses", async () => {
  for (const component of ["node_exporter", "cadvisor"]) {
    for (const action of ["converge", "remove"]) {
      const f = new Fixture();
      f.operation.kind = `metrics.${component}_${action}`;
      assertEquals((await f.execute()).status, "succeeded");
      const run = f.calls.find((call) => call.path.endsWith("/run"))!.body;
      assertEquals(
        run.name,
        component === "node_exporter"
          ? "metrics.node-exporter"
          : "metrics.cadvisor",
      );
      assertEquals(run.input, {
        ...f.operation.input,
        action: { operation: action },
      });
      assertEquals("node_id" in run, false);
      assertEquals(f.calls.at(-1)!.body.result, { changed: true });
    }
  }
});
Deno.test("command refusal fails its step with the stable family code", async () => {
  const f = new Fixture();
  f.result = { error_code: "metrics.exporter_firewall_inactive" };
  assertEquals((await f.execute()).status, "failed");
  assertEquals(f.calls.at(-2)!.body.status, "failed");
  assertEquals(
    f.calls.at(-1)!.body.error_code,
    "metrics.exporter_firewall_inactive",
  );
});
Deno.test("unproven and malformed outcomes cannot complete", async () => {
  for (
    const result of [{}, { changed: false }, { error_code: "unsafe error\n" }]
  ) {
    const f = new Fixture();
    f.result = result;
    await assertRejects(() => f.execute());
    assertEquals(
      f.calls.some((call) => call.path.endsWith("/complete")),
      false,
    );
  }
});
Deno.test("unexpected result fields never reach a step or persistent receipt", async () => {
  const f = new Fixture();
  f.result = {
    changed: true,
    token: "secret-sentinel",
    environment: { password: "secret-sentinel" },
  };
  await f.execute();
  assertEquals(
    JSON.stringify([...f.resources]).includes("secret-sentinel"),
    false,
  );
  assertEquals(JSON.stringify(f.calls).includes("secret-sentinel"), false);
});
Deno.test("restart after a lost Node reply reads the target receipt and keeps one mutation", async () => {
  const f = new Fixture();
  f.loseRun = true;
  await assertRejects(() => f.execute(), Error, "Lost run reply");
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(f.effects, 1);
  const runs = f.calls.filter((call) => call.path.endsWith("/run"));
  assertEquals(runs.length, 1);
  assertEquals(
    f.calls.filter((call) => call.path.includes("/receipts/")).length,
    1,
  );
});
Deno.test("restart after a saved step skips Node work", async () => {
  const f = new Fixture();
  f.operation.step_results["node-exporter-converge"] = { changed: true };
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(f.effects, 0);
});
Deno.test("a lost completion reply confirms the assigned terminal result without new work", async () => {
  const f = new Fixture();
  f.loseCompletion = true;
  await assertRejects(() => f.execute(), Error, "Lost completion reply");
  const count = f.calls.length;
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(f.calls.length, count + 1);
  assertEquals(f.effects, 1);
});
Deno.test("expired lease stops the worker; a replacement reuses the uncertain effect", async () => {
  const f = new Fixture();
  f.loseRun = true;
  await assertRejects(() => f.execute());
  f.leaseLost = true;
  await assertRejects(() => f.execute(), ExecutorError, "operation.lease_lost");
  f.leaseLost = false;
  assertEquals((await f.execute("replacement")).status, "succeeded");
  assertEquals(f.effects, 1);
});
Deno.test("cancellation before a step skips mutation and Gateway refusal closes a started step", async () => {
  const before = new Fixture();
  before.operation.cancel_requested = true;
  assertEquals((await before.execute()).status, "cancelled");
  assertEquals(before.effects, 0);
  const atRun = new Fixture();
  atRun.cancelAtRun = true;
  assertEquals((await atRun.execute()).status, "cancelled");
  assertEquals(atRun.effects, 0);
  assertEquals(atRun.calls.at(-2)!.body.status, "failed");
});
Deno.test("cancellation after an uncertain effect reconciles the same receipt", async () => {
  const f = new Fixture();
  f.loseRun = true;
  await assertRejects(() => f.execute());
  f.operation.cancel_requested = true;
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(f.effects, 1);
});

Deno.test("Vault carries only a secret reference and persists only safe collection metadata", async () => {
  for (
    const [action, backend] of [["teardown", "absent"]]
  ) {
    const f = new Fixture();
    f.operation.kind = `vault.${action}`;
    f.operation.input = {
      configuration: { $secret: "vault.credentials", id: 2 },
    };
    f.result = {
      outcome: "succeeded",
      data: {
        backend,
        sources: ["database-connection:1"],
        cipher_ids: ["cipher-1"],
        revisions: ["revision-1"],
        fingerprints: ["hash-1"],
        client_secret: "secret-sentinel",
      },
      log: "secret-sentinel",
    };
    assertEquals((await f.execute()).status, "succeeded");
    assertEquals(
      f.calls.find((call) => call.path.endsWith("/run"))!.body.input,
      { action, ...f.operation.input },
    );
    assertEquals(
      JSON.stringify([...f.resources]).includes("secret-sentinel"),
      false,
    );
    assertEquals(JSON.stringify(f.calls).includes("secret-sentinel"), false);
  }
});
Deno.test("Vault refuses wrong backend and retains machine-readable failure", async () => {
  const f = new Fixture();
  f.operation.kind = "vault.teardown";
  f.operation.input = {
    configuration: { $secret: "vault.credentials", id: 2 },
  };
  f.result = { outcome: "succeeded", data: { backend: "synced" } };
  await assertRejects(() => f.execute());
  f.receipt = null;
  f.result = {
    outcome: "failed",
    data: { error_code: "vault.organization_missing" },
  };
  assertEquals((await f.execute()).status, "failed");
  assertEquals(f.calls.at(-1)!.body.error_code, "vault.organization_missing");
});
Deno.test("Reverb waits for fresh process reports and resumes a completed pending probe with a new read key", async () => {
  const f = new Fixture();
  f.operation.kind = "websocket.app_rotate";
  f.operation.input = {
    registry_operation_id: "56818f31-84e7-415a-ae29-b283e9903a99",
  };
  f.result = { outcome: "pending", data: { backend: "pending" } };
  await assertRejects(() => f.execute(), Error, "websocket.registry_pending");
  assertEquals(f.calls.some((call) => call.path.endsWith("/complete")), false);
  f.receipt = null;
  f.result = { outcome: "succeeded", data: { backend: "applied" } };
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(
    f.calls.filter((call) => call.path.endsWith("/run")).map((call) =>
      call.body.key
    ),
    ["reverb-registry-applied:0", "reverb-registry-applied:1"],
  );
  assertEquals(
    f.calls.some((call) => call.body.name === "websocket.converge-runtime"),
    false,
  );
});

Deno.test("a still-running target receipt ends without another effect or completion", async () => {
  const f = new Fixture();
  f.loseRun = true;
  await assertRejects(() => f.execute(), Error, "Lost run reply");
  f.receiptState = "running";
  await assertRejects(() => f.execute(), Error, "operation.effect_running");
  assertEquals(f.calls.filter((call) => call.path.endsWith("/run")).length, 1);
  assertEquals(f.calls.some((call) => call.path.endsWith("/complete")), false);
});
Deno.test("only an unknown target receipt allows the same keyed run again", async () => {
  const f = new Fixture();
  f.loseRun = true;
  await assertRejects(() => f.execute(), Error, "Lost run reply");
  f.receiptState = "unknown";
  await f.execute();
  const runs = f.calls.filter((call) => call.path.endsWith("/run"));
  assertEquals(runs.length, 2);
  assertEquals(runs[0].body, runs[1].body);
  assertEquals(f.effects, 1);
});
Deno.test("a failed target receipt and a saved failed step replay terminal failure", async () => {
  const f = new Fixture();
  f.result = { error_code: "metrics.install_refused" };
  f.loseRun = true;
  await assertRejects(() => f.execute(), Error, "Lost run reply");
  f.receiptState = "failed";
  assertEquals((await f.execute()).status, "failed");
  assertEquals(f.calls.at(-1)!.body.error_code, "metrics.install_refused");
  f.resources.clear();
  f.operation.step_results["node-exporter-converge"] = f.result;
  assertEquals((await f.execute()).status, "failed");
  assertEquals(f.effects, 1);
});

Deno.test("a placed integration dispatch claims before effects and saves its assignment", async () => {
  const f = new Fixture();
  const original = f.http;
  f.http = async (method, path, body) => {
    if (path.endsWith("/run")) {
      assertEquals(f.resources.get("assignment-workflow-12"), {
        operation_id: 12,
      });
    }
    return await original(method, path, body);
  };
  await f.execute();
  assertEquals(f.calls[0], {
    path: "/api/v1/operation-executor/claim",
    body: { kind: f.operation.kind, worker: "workflow-12" },
  });
  assertEquals(
    f.calls.filter((call) => call.path.endsWith("/claim")).length,
    1,
  );
});

Deno.test("a placed integration dispatch with no work does not persist an assignment or run effects", async () => {
  const f = new Fixture();
  let calls = 0;
  f.http = (method, path) => {
    calls++;
    assertEquals([method, path], ["POST", "/api/v1/operation-executor/claim"]);
    return Promise.resolve({ status: 200, body: { data: null } });
  };
  assertEquals(await f.execute(), { claimed: false });
  assertEquals(calls, 1);
  assertEquals(f.resources.size, 0);
});

Deno.test("a stale assigned dispatch refuses its lost lease without selecting the next Operation", async () => {
  const f = new Fixture();
  f.resources.set("assignment-workflow-12", { operation_id: 12 });
  f.leaseLost = true;
  await assertRejects(() => f.execute(), ExecutorError, "operation.lease_lost");
  assertEquals(f.calls.map((call) => call.path), [
    "/api/v1/operation-executor/12/heartbeat",
  ]);
});

Deno.test("a terminal result that conflicts with the assigned completion cannot claim another Operation", async () => {
  const f = new Fixture();
  f.loseCompletion = true;
  await assertRejects(() => f.execute(), Error, "Lost completion reply");
  const original = f.http;
  f.http = async (method, path, body) =>
    path.startsWith("/api/v1/operations/")
      ? {
        status: 200,
        body: {
          data: {
            id: 12,
            kind: f.operation.kind,
            status: "succeeded",
            result: { changed: false },
            error_code: null,
          },
        },
      }
      : await original(method, path, body);
  await assertRejects(
    () => f.execute(),
    Error,
    "integration.completion_unproven",
  );
  assertEquals(
    f.calls.filter((call) => call.path.endsWith("/claim")).length,
    1,
  );
  assertEquals(f.effects, 1);
});

Deno.test("proven pre-start refusals keep an exporter unfinished and retry the same key", async () => {
  for (
    const code of [
      "agent.target_busy",
      "agent.lock_unavailable",
      "agent.supervisor_not_ready",
    ]
  ) {
    const f = new Fixture();
    f.prestart = code;
    await assertRejects(
      () => f.execute(),
      Error,
      "operation.effect_not_started",
    );
    assertEquals(f.effects, 0);
    assertEquals(f.receipt, null);
    assertEquals(
      f.calls.some((call) =>
        call.path.endsWith("/complete") ||
        call.path.endsWith("/step") && call.body.status !== "running"
      ),
      false,
    );
    f.prestart = null;
    assertEquals((await f.execute()).status, "succeeded");
    assertEquals(f.effects, 1);
    const runs = f.calls.filter((call) => call.path.endsWith("/run"));
    assertEquals(runs[0], runs[1]);
  }
});

Deno.test("an unrecognized pre-start marker cannot become a terminal command refusal", async () => {
  const f = new Fixture();
  f.prestart = "agent.effect_unknown";
  await assertRejects(() => f.execute(), Error, "integration.outcome_unproven");
  assertEquals(f.calls.some((call) => call.path.endsWith("/complete")), false);
  assertEquals(
    f.calls.some((call) =>
      call.path.endsWith("/step") && call.body.status !== "running"
    ),
    false,
  );
});

Deno.test("Vault failed collection deletion cannot complete as a successful teardown", async () => {
  for (const action of ["teardown"]) {
    const f = new Fixture();
    f.operation.kind = `vault.${action}`;
    f.operation.input = {
      configuration: { $secret: "vault.credentials", id: 2 },
    };
    f.result = { outcome: "failed", data: { error_code: "vault.unreachable" } };
    assertEquals((await f.execute()).status, "failed");
    assertEquals(f.calls.at(-1)?.body.error_code, "vault.unreachable");
    assertEquals(f.effects, 1);
  }
});

Deno.test("all registry mutations use only the admitted read probe and preserve it after a lost reply", async () => {
  for (const verb of ["app_rotate"]) {
    const f = new Fixture();
    f.operation.kind = `websocket.${verb}`;
    f.operation.input = {
      registry_operation_id: "56818f31-84e7-415a-ae29-b283e9903a99",
      revision: 9,
      app: { name: "disposable" },
    };
    f.result = { outcome: "succeeded", data: { backend: "applied" } };
    f.loseRun = true;
    await assertRejects(() => f.execute(), Error, "Lost run reply");
    assertEquals((await f.execute()).status, "succeeded");
    assertEquals(f.effects, 1);
    const runs = f.calls.filter((call) => call.path.endsWith("/run"));
    assertEquals(runs.length, 1);
    assertEquals(runs[0].body, {
      worker: "workflow-12",
      key: "reverb-registry-applied:0",
      transport: "remote_program",
      name: "websocket.registry-applied",
      input: { registry_operation_id: f.operation.input.registry_operation_id },
    });
    const count = f.calls.length;
    await f.execute();
    assertEquals(f.effects, 1);
    assertEquals(
      f.calls.slice(count).some((call) => call.path.endsWith("/run")),
      false,
    );
  }
});

Deno.test("a superseded registry revision fails without claiming success or restarting Reverb", async () => {
  const f = new Fixture();
  f.operation.kind = "websocket.app_rotate";
  f.operation.input = {
    registry_operation_id: "56818f31-84e7-415a-ae29-b283e9903a99",
  };
  f.result = {
    outcome: "superseded",
    data: { error_code: "websocket.registry_superseded" },
  };
  assertEquals((await f.execute()).status, "failed");
  assertEquals(
    f.calls.at(-1)!.body.error_code,
    "websocket.registry_superseded",
  );
  assertEquals(
    f.calls.filter((call) => call.path.endsWith("/run")).map((call) =>
      call.body.name
    ),
    ["websocket.registry-applied"],
  );
});

Deno.test("cancellation before a registry probe stops observation and preserves the saved registry", async () => {
  const f = new Fixture();
  f.operation.kind = "websocket.app_rotate";
  f.operation.input = {
    registry_operation_id: "56818f31-84e7-415a-ae29-b283e9903a99",
  };
  f.operation.cancel_requested = true;
  assertEquals((await f.execute()).status, "cancelled");
  assertEquals(f.effects, 0);
  assertEquals(f.calls.some((call) => call.path.endsWith("/run")), false);
});

Deno.test("a superseded result between probe and completion ends failed across saved-intent re-entry", async () => {
  for (const reentry of [false, true]) {
    const f = new Fixture();
    f.operation.kind = "websocket.app_rotate";
    f.operation.input = {
      registry_operation_id: "56818f31-84e7-415a-ae29-b283e9903a99",
    };
    f.result = { outcome: "succeeded", data: { backend: "applied" } };
    if (reentry) {
      f.completionRefusal = "websocket.registry_pending";
      await assertRejects(
        () => f.execute(),
        ExecutorError,
        "websocket.registry_pending",
      );
      assertEquals(f.resources.get("operation-12")!.status, "succeeded");
    }
    f.completionRefusal = "websocket.registry_superseded";
    assertEquals((await f.execute()).status, "failed");
    assertEquals(f.effects, 1);
    assertEquals(f.resources.get("operation-12")!.status, "failed");
    assertEquals(
      f.calls.at(-1)!.body.error_code,
      "websocket.registry_superseded",
    );
    await f.execute();
    assertEquals(f.effects, 1);
  }
});

Deno.test("disabled Metrics acknowledges exporter preference without a fleet or Node effect", async () => {
  const f = new Fixture();
  f.operation.kind = "metrics.reconcile";
  f.operation.input = {
    preference_only: true,
    exporter_intent: { node_id: 2, desired: true },
    fleet: [],
  };
  f.operation.execution_context = {
    descriptors: [{
      key: "integration-receipt",
      step: "integration-receipt",
      transport: "gateway",
      name: "metrics.receipt",
      input: { backend: "recorded" },
      resumable: true,
      required: true,
    }],
  };
  f.result = { backend: "recorded" };
  assertEquals((await f.execute()).status, "succeeded");
  const runs = f.calls.filter((c) => c.path.endsWith("/run"));
  assertEquals(runs.length, 1);
  assertEquals(runs[0].body.transport, "gateway");
  assertEquals(runs[0].body.name, "metrics.receipt");
  assertEquals(runs[0].body.input, { backend: "recorded" });
  assertEquals(f.calls.some((c) => c.path.endsWith("/children")), false);
  assertEquals(f.calls.at(-1)!.body.result, { backend: "recorded" });
});
