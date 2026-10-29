import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { createModelTestContext } from "jsr:@swamp-club/swamp-testing@0.20261016.58";
import { processesSchedules } from "./mod.ts";
import { type Context, Operation } from "../../operation-runner/mod.ts";
import type { Http } from "../../automatic-deploy/gateway.ts";

class Fixture {
  operation = Operation.parse({
    id: 7,
    node_id: 2,
    kind: "schedule.create",
    status: "running",
    cancel_requested: false,
    input: {
      node_id: 2,
      id: "830fd68c-948b-4e46-a215-229c7f510246",
      user: "orbit",
      group: "orbit",
      home: "/home/orbit",
      working_directory: "/home/orbit",
      shell: "/bin/bash",
      login_shell: true,
      command: "true",
      calendar: "daily",
      timeout_seconds: 3600,
      desired: "enabled",
    },
    step_results: {},
  });
  calls: { path: string; body: Record<string, unknown> }[] = [];
  runs = 0;
  childStatus = "succeeded";
  notStartedOnce = false;
  failAfterRun = false;
  cancelAtRun = false;
  failCompletion = false;
  answers: Record<string, Record<string, unknown>> = {};
  leaseLost = false;
  receiptState: "running" | "unknown" | null = null;
  receiptUnavailable = false;
  failAfterStep = false;
  receipts = new Map<string, Record<string, unknown>>();
  resources = new Map<string, Record<string, unknown>>();
  http: Http = async (_method, path, raw) => {
    const body = (raw ?? {}) as Record<string, unknown>;
    this.calls.push({ path, body });
    await Promise.resolve();
    let data: unknown = this.operation;
    if (path.endsWith("/claim")) {
      data = body.kind === this.operation.kind ? this.operation : null;
      if (data) this.operation.status = "running";
    }
    if (this.leaseLost) {
      return { status: 409, body: { error: { code: "operation.lease_lost" } } };
    }
    if (path.includes("/receipts/")) {
      if (this.receiptUnavailable) return { status: 502, body: {} };
      const key = path.split("/").at(-1)!;
      const result = this.receiptState ? undefined : this.receipts.get(key);
      data = {
        effect_key: `op-${this.operation.id}.${key}`,
        state: this.receiptState ??
          (result ? result.error_code ? "failed" : "succeeded" : "unknown"),
        exit_code: result ? result.error_code ? 1 : 0 : null,
        result: result ?? null,
        finished_at: result ? "2026-10-28T22:00:00Z" : null,
      };
    }
    if (path.endsWith("/children")) {
      data = {
        ...this.operation,
        id: 50,
        kind: String(body.key).startsWith("runtime-")
          ? (this.operation.kind.startsWith("schedule.")
            ? "schedule.create"
            : "process.restart")
          : "route.caddy_converge",
        status: this.childStatus,
        input: {},
      };
    }
    if (path.endsWith("/wait")) {
      data = { ...this.operation, status: "waiting" };
    }
    if (path.endsWith("/run")) {
      if (this.notStartedOnce) {
        this.notStartedOnce = false;
        return {
          status: 200,
          body: {
            data: { error_code: "agent.target_busy", not_started: true },
          },
        };
      }
      const key = String(body.key);
      if (!this.receipts.has(key)) {
        if (this.cancelAtRun) {
          this.receipts.set(key, { error_code: "operation.cancelled" });
        } else {
          if (body.transport !== "gateway" && body.name !== "process.status") {
            this.runs++;
          }
          this.receipts.set(
            key,
            this.answers[String(body.name)] ??
              (body.transport === "gateway" ? { ready: true } : {}),
          );
        }
      }
      if (this.failAfterRun) {
        this.failAfterRun = false;
        throw new Error("Lost run response");
      }
      data = this.receipts.get(key);
    }
    if (path.endsWith("/step")) {
      const existing = this.operation.steps.find((step) =>
        step.name === body.name
      );
      if (existing) existing.status = String(body.status);
      else {this.operation.steps.push({
          name: String(body.name),
          status: String(body.status),
        });}
      if (body.status === "succeeded") {
        this.operation.step_results[String(body.name)] = body.result;
      }
      if (this.failAfterStep && body.status !== "running") {
        this.failAfterStep = false;
        throw new Error("Lost step response");
      }
    }
    if (path.endsWith("/complete") && this.failCompletion) {
      this.failCompletion = false;
      throw new Error("Lost completion response");
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
  execute() {
    return processesSchedules(() => this.http).methods.execute.execute({
      kind: this.operation.kind,
      worker: "run-1",
    }, this.context());
  }
}

Deno.test("Schedule create, destroy, enable and run use only admitted fields", async () => {
  for (
    const [verb, command] of [["create", "install"], ["destroy", "remove"], [
      "enable",
      "activate",
    ], ["run", "run"]]
  ) {
    const f = new Fixture();
    f.operation.kind = `schedule.${verb}`;
    f.operation.input.cascade = false;
    f.operation.input.unrelated = "never forward";
    await f.execute();
    const run = f.calls.find((call) => call.path.endsWith("/run"))!.body;
    assertEquals(run.name, `schedule.${command}`);
    assertEquals(run.node_id, undefined);
    assertEquals((run.input as Record<string, unknown>).node_id, undefined);
    assertEquals((run.input as Record<string, unknown>).unrelated, undefined);
    assertEquals(f.runs, 1);
    assertEquals(f.resources.get("operation-7")!.status, "succeeded");
  }
});
Deno.test("lost run reply reuses the same effect key", async () => {
  const f = new Fixture();
  f.operation.kind = "schedule.run";
  f.failAfterRun = true;
  await assertRejects(() => f.execute());
  await f.execute();
  assertEquals(f.runs, 1);
  assertEquals(
    f.calls.filter((call) => call.path.endsWith("/run")).map((call) =>
      call.body.key
    ),
    ["schedule-run"],
  );
});
Deno.test("lost successful step reply resumes from recorded results", async () => {
  const f = new Fixture();
  f.failAfterStep = true;
  await assertRejects(() => f.execute());
  await f.execute();
  assertEquals(f.runs, 1);
  assertEquals(f.calls.filter((call) => call.path.endsWith("/run")).length, 1);
});
Deno.test("saved completion retries without a new heartbeat or Node command", async () => {
  const f = new Fixture();
  f.failCompletion = true;
  await assertRejects(() => f.execute());
  f.leaseLost = false;
  await f.execute();
  assertEquals(f.runs, 1);
  assertEquals(
    f.calls.filter((call) => call.path.endsWith("/claim")).length,
    1,
  );
});
Deno.test("cancellation before effects records a cancelled completion", async () => {
  const f = new Fixture();
  f.operation.cancel_requested = true;
  await f.execute();
  assertEquals(f.runs, 0);
  assertEquals(f.resources.get("operation-7")!.status, "cancelled");
});
Deno.test("cancellation at run does not retry the refused effect", async () => {
  const f = new Fixture();
  f.cancelAtRun = true;
  await f.execute();
  assertEquals(f.runs, 0);
  assertEquals(f.resources.get("operation-7")!.status, "cancelled");
});
Deno.test("expired lease stops without effects or completion", async () => {
  const f = new Fixture();
  f.leaseLost = true;
  await assertRejects(() => f.execute());
  assertEquals(f.runs, 0);
  assertEquals(f.resources.size, 0);
});
Deno.test("Schedule refusals retain fixed error codes and no command output", async () => {
  for (
    const error of [
      "schedule.calendar_invalid",
      "schedule.artifact_conflict",
      "schedule.rollback_failed",
      "schedule.run_active",
      "schedule.target_unavailable",
    ]
  ) {
    const f = new Fixture();
    f.answers["schedule.install"] = { error_code: error };
    await f.execute();
    assertEquals(f.resources.get("operation-7")!.error_code, error);
    assertEquals(f.resources.get("operation-7")!.status, "failed");
  }
});
Deno.test("restart after a failed step recovers its keyed refusal", async () => {
  const f = new Fixture();
  f.answers["schedule.install"] = { error_code: "schedule.artifact_conflict" };
  f.failAfterStep = true;
  await assertRejects(() => f.execute());
  await f.execute();
  assertEquals(f.runs, 1);
  assertEquals(f.resources.get("operation-7")!.status, "failed");
});
function processFixture(verb = "create", runtime = "systemd") {
  const f = new Fixture();
  f.operation.kind = `process.${verb}`;
  f.operation.input = {
    node_id: 2,
    process_id: 12,
    runtime,
    name: runtime === "systemd"
      ? "orbit-process-12-proof.service"
      : "orbit-process-12-proof",
    unit: "orbit-process-12-proof.service",
    content: "owned unit",
    desired: "running",
    on_demand: true,
    activation_pending: false,
    vite: null,
    container: "orbit-process-12-proof",
    spec: "abc",
    image: "example:1",
    command: ["/usr/bin/sleep", "300"],
    restart: "unless-stopped",
    working_directory: null,
    ports: [],
    mounts: [],
    environment: {},
  };
  f.answers["process.stage-unit"] = {
    staged: true,
    previous: false,
    state: null,
  };
  f.answers["process.activate-unit"] = { backup_left: false };
  f.answers["process.status"] = {
    state: verb === "destroy" ? "absent" : "active",
  };
  return f;
}
Deno.test("systemd create stages then activates the owned unit", async () => {
  const f = processFixture();
  await f.execute();
  assertEquals(
    f.calls.filter((call) => call.path.endsWith("/run")).map((call) =>
      call.body.name
    ),
    [
      "process.stage-unit",
      "process.activate-unit",
      "process.status",
      "process.runtime_ready",
    ],
  );
  assertEquals(
    (f.calls.find((call) => call.body.name === "process.activate-unit")!.body
      .input as Record<string, unknown>).change,
    "create",
  );
});
Deno.test("unchanged settled systemd unit remains untouched", async () => {
  const f = processFixture();
  f.answers["process.stage-unit"] = {
    staged: false,
    previous: true,
    state: "active",
  };
  await f.execute();
  assertEquals(f.runs, 1);
});
Deno.test("pending or unsettled systemd activation reloads without replacing bytes", async () => {
  for (const pending of [false, true]) {
    const f = processFixture();
    f.operation.input.activation_pending = pending;
    f.answers["process.stage-unit"] = {
      staged: false,
      previous: true,
      state: pending ? "active" : "inactive",
    };
    await f.execute();
    assertEquals(
      (f.calls.find((call) => call.body.name === "process.activate-unit")!.body
        .input as Record<string, unknown>).change,
      "reload",
    );
  }
});
Deno.test("systemd replacement, backup refusal and release refusal retain evidence", async () => {
  const f = processFixture();
  f.answers["process.stage-unit"] = {
    staged: true,
    previous: true,
    state: null,
  };
  f.answers["process.activate-unit"] = { backup_left: true };
  await f.execute();
  assertEquals(
    f.resources.get("operation-7")!.error_code,
    "process.systemd_converge_failed",
  );
  const g = processFixture("start");
  g.operation.input.directory = "/home/production/current";
  g.answers["process.check-release"] = { available: false };
  await g.execute();
  assertEquals(g.runs, 1);
  assertEquals(
    g.resources.get("operation-7")!.error_code,
    "process.release_unavailable",
  );
});
Deno.test("Docker converge transports the exact definition without logging it", async () => {
  const f = processFixture("create", "docker");
  f.operation.input.environment = { PASSWORD: "private-value" };
  await f.execute();
  assertEquals(f.runs, 1);
  assertEquals(
    f.calls.find((call) => call.path.endsWith("/run"))!.body.name,
    "process.converge-container",
  );
  assertEquals(
    JSON.stringify(f.calls.filter((call) => call.path.endsWith("/step")))
      .includes("private-value"),
    false,
  );
});
Deno.test("runtime switch removes retired footprint before creating replacement", async () => {
  const f = processFixture("update", "docker");
  f.operation.input.retired_runtime = "systemd";
  f.operation.input.retired_name = "orbit-process-12-proof.service";
  await f.execute();
  assertEquals(
    f.calls.filter((call) => call.path.endsWith("/run")).map((call) =>
      call.body.name
    ),
    [
      "process.remove",
      "process.converge-container",
      "process.status",
      "process.runtime_ready",
    ],
  );
});
Deno.test("Process start, stop, restart and removal target only their artifact", async () => {
  for (const runtime of ["systemd", "docker"]) {
    for (const verb of ["start", "stop", "restart", "destroy"]) {
      const f = processFixture(verb, runtime);
      await f.execute();
      assertEquals(f.runs, 1);
      const call = f.calls.find((call) => call.path.endsWith("/run"))!.body;
      assertEquals(
        call.name,
        verb === "destroy" ? "process.remove" : "process.control",
      );
      assertEquals((call.input as Record<string, unknown>).process_id, 12);
    }
  }
});
Deno.test("lost systemd activation reply resumes the same keyed activation", async () => {
  const f = processFixture();
  f.operation.step_results["process-stage-unit"] = {
    staged: true,
    previous: false,
    state: null,
  };
  f.failAfterRun = true;
  await assertRejects(() => f.execute());
  await f.execute();
  assertEquals(f.runs, 1);
});
Deno.test("wrong family or target refuses before any Node command", async () => {
  for (const wrong of ["node", "kind"]) {
    const f = new Fixture();
    if (wrong === "node") f.operation.input.node_id = 99;
    else f.operation.kind = "firewall.allow";
    await assertRejects(() => f.execute());
    assertEquals(f.runs, 0);
  }
});

Deno.test("Vite startup prepares its environment, starts a Caddy child and verifies ownership", async () => {
  const f = processFixture("start");
  f.operation.input.vite_plan = {
    suspend: { operation: "suspend", marker: "/run/orbit/awake/test" },
    environment: {
      operation: "environment",
      application: "/home/orbit/app",
      path: "/etc/orbit/vite/app-instance-1.env",
      instance_id: 1,
      marker: "/run/orbit/awake/test",
      content: "# Orbit Instance 1\nORBIT_DEV_SERVER_PORT=5173\n",
    },
    awake: { operation: "awake", marker: "/run/orbit/awake/test" },
    listener: {
      unit: "orbit-process-12-proof.service",
      port: 5173,
      process_id: 12,
      probe: true,
    },
  };
  f.answers["vite.check-listener"] = { owned: true, ready: true };
  await f.execute();
  assertEquals(
    f.calls.filter((call) => call.path.endsWith("/children")).map((call) =>
      call.body
    ),
    [{
      worker: "run-1",
      key: "vite-caddy",
    }],
  );
  assertEquals(f.resources.get("operation-7")!.status, "succeeded");
});

Deno.test("uncertain Schedule run remains pending until its original receipt arrives", async () => {
  const f = new Fixture();
  f.operation.kind = "schedule.run";
  f.failAfterRun = true;
  await assertRejects(() => f.execute());
  f.receiptState = "unknown";
  assertEquals((await f.execute()).status, "pending");
  f.receiptState = "running";
  assertEquals((await f.execute()).status, "pending");
  f.receiptUnavailable = true;
  assertEquals((await f.execute()).status, "pending");
  assertEquals(f.calls.filter((call) => call.path.endsWith("/run")).length, 1);
  assertEquals(f.resources.has("operation-7"), false);
  f.receiptUnavailable = false;
  f.receiptState = null;
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(f.calls.filter((call) => call.path.endsWith("/run")).length, 1);
});

Deno.test("unavailable receipt cannot authorize the first restart attempt", async () => {
  const f = new Fixture();
  f.operation.kind = "process.restart";
  f.operation.input = {
    node_id: 2,
    process_id: 4,
    runtime: "systemd",
    name: "orbit-process-4-proof.service",
    on_demand: false,
  };
  f.receiptUnavailable = true;
  assertEquals((await f.execute()).status, "pending");
  assertEquals(f.runs, 0);
});

Deno.test("Process restart adopts the receipt without another run request", async () => {
  const f = new Fixture();
  f.operation.kind = "process.restart";
  f.operation.input = {
    node_id: 2,
    process_id: 4,
    runtime: "systemd",
    name: "orbit-process-4-proof.service",
    on_demand: false,
  };
  f.failAfterRun = true;
  await assertRejects(() => f.execute());
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(
    f.calls.filter((call) =>
      call.path.endsWith("/run") && call.body.transport === "agent" &&
      call.body.name !== "process.status"
    ).length,
    1,
  );
});

Deno.test("Instance runtime composition waits for recorded children before proving readiness", async () => {
  for (
    const kind of [
      "process.install_instance_definitions",
      "schedule.install_instance_definitions",
      "process.restart_instance",
    ]
  ) {
    const f = new Fixture();
    f.operation.kind = kind;
    f.operation.input = { instance_id: 42 };
    f.operation.execution_context = {
      descriptors: [{
        key: "runtime-ready",
        step: "runtime-ready",
        transport: "gateway",
        name: kind.startsWith("process.")
          ? "process.instance_runtime_ready"
          : "schedule.instance_runtime_ready",
        input: { instance_id: 42 },
        resumable: true,
        required: true,
      }],
      result: { runtime_child_count: 1 },
    };
    f.childStatus = "queued";
    assertEquals((await f.execute()).status, "pending");
    assertEquals(
      f.calls.filter((call) => call.path.endsWith("/run")).length,
      0,
    );
    assertEquals(
      f.calls.find((call) => call.path.endsWith("/wait"))!.body.keys,
      ["runtime-0"],
    );
    f.childStatus = "succeeded";
    await f.execute();
    assertEquals(f.resources.get("operation-7")!.status, "succeeded");
    assertEquals(f.runs, 0);
    assertEquals(
      f.calls.filter((call) => call.path.endsWith("/run")).map((call) =>
        call.body.name
      ),
      [f.operation.execution_context.descriptors[0].name],
    );
  }
});
Deno.test("A failed runtime child prevents the readiness run", async () => {
  const f = new Fixture();
  f.operation.kind = "schedule.install_instance_definitions";
  f.operation.input = { instance_id: 42 };
  f.operation.execution_context.result = { runtime_child_count: 1 };
  f.childStatus = "failed";
  await f.execute();
  assertEquals(f.resources.get("operation-7")!.status, "failed");
  assertEquals(f.calls.filter((call) => call.path.endsWith("/run")).length, 0);
});
Deno.test("A proven not-started Schedule run can retry the same key once", async () => {
  const f = new Fixture();
  f.operation.kind = "schedule.run";
  f.notStartedOnce = true;
  assertEquals((await f.execute()).status, "pending");
  await f.execute();
  assertEquals(f.runs, 1);
  assertEquals(f.resources.get("operation-7")!.status, "succeeded");
  assertEquals(
    f.calls.filter((call) => call.path.endsWith("/run")).map((call) =>
      call.body.key
    ),
    ["schedule-run", "schedule-run"],
  );
});
