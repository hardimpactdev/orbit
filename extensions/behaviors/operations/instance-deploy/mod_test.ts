import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { createModelTestContext } from "jsr:@swamp-club/swamp-testing@0.20261016.58";
import { instanceDeploy } from "./mod.ts";
import type { Context } from "../../operation-runner/mod.ts";
import type { Http } from "../../automatic-deploy/gateway.ts";

class Gateway {
  steps: Record<string, unknown> = {};
  statuses: Record<string, string> = {};
  loseStepReply = false;
  runs = new Map<string, Record<string, unknown>>();
  effects: string[] = [];
  calls: { path: string; body: Record<string, unknown> }[] = [];
  completion: Record<string, unknown> | null = null;
  resources = new Map<string, Record<string, unknown>>();
  receiptState: "running" | "unknown" | null = null;
  loseRun: string | null = null;
  loseCompletion = false;
  claimAvailable = true;
  failAssignment = false;
  stale = false;
  cancel = false;
  refused: string | null = null;
  kind = "instance.deploy";
  childStatus = "queued";
  childKey = "vp_environment";
  legacy = false;
  config = {
    variant: "production",
    php: false,
    needs_vp: false,
    steps: [{ name: "install", phase: "before_activation", required: true }],
  };
  descriptors() {
    const input = {
      home: "/fast/apps/proof/default",
      repository: "https://example.test/proof.git",
      instance: 17,
    };
    const descriptor = (
      key: string,
      step: string,
      name: string,
      parameters: Record<string, unknown>,
      transport = "agent",
    ) => ({
      key,
      step,
      name,
      input: parameters,
      transport,
      resumable: key !== "before_activation.install",
      required: true,
    });
    return [
      descriptor(
        "source.previous",
        "source_preparation.resolve",
        "instance.inspect-release",
        {
          ...input,
          branch: "main",
          commit: null,
          git_read: { $secret: "instance.deployment", id: 17 },
        },
      ),
      ...(this.steps["source_preparation.resolve"]
        ? [
          descriptor(
            "source.prepare",
            "source_preparation",
            "instance.prepare-release",
            { ...input, branch: "main", commit: "a".repeat(40) },
          ),
        ]
        : []),
      ...(this.config.needs_vp || this.childKey !== "vp_environment"
        ? [
          descriptor(
            `child.${this.childKey}`,
            this.childKey,
            "instance.child-receipt",
            { child_key: this.childKey },
            "gateway",
          ),
        ]
        : []),
      descriptor(
        "before_activation.install",
        "before_activation.install",
        "instance.run-deploy-step",
        {
          ...input,
          step: "install",
          timeout_seconds: 30,
          script: { $secret: "instance.deployment", id: 17 },
          secrets: { $secret: "instance.deployment", id: 17 },
        },
      ),
      descriptor(
        "verification",
        "verification",
        "instance.deployment-receipt",
        { instance_id: 17 },
        "gateway",
      ),
    ];
  }
  operation() {
    return {
      id: 7,
      kind: this.kind,
      node_id: 9,
      status: String(this.completion?.status ?? "running"),
      result: this.completion?.result ?? null,
      error_code: this.completion?.error_code ?? null,
      cancel_requested: this.cancel,
      input: this.legacy ? {} : { deployment: this.config },
      step_results: this.steps,
      execution_context: {
        descriptors: this.legacy ? [] : this.descriptors(),
        result: this.effects.length
          ? {
            deployment_id: 51,
            release: null,
            selected_release: null,
            commit: "a".repeat(40),
          }
          : {},
      },
      steps: Object.entries(this.statuses).map(([name, status]) => ({
        name,
        status,
      })),
    };
  }
  http: Http = async (_method, path, raw) => {
    const body = (raw ?? {}) as Record<string, unknown>;
    this.calls.push({ path, body });
    await Promise.resolve();
    if (this.stale) {
      return { status: 409, body: { error: { code: "operation.lease_lost" } } };
    }
    if (_method === "GET" && path.includes("/receipts/")) {
      const key = decodeURIComponent(path.split("/receipts/")[1]);
      const result = this.runs.get(key) ?? null;
      return {
        status: 200,
        body: {
          data: {
            effect_key: `op-7.${key}`,
            exit_code: null,
            finished_at: null,
            state: this.receiptState ??
              (result === null ? "unknown" : "succeeded"),
            result: this.receiptState ? null : result,
          },
        },
      };
    }
    let data: unknown = this.operation();
    if (path.endsWith("/claim") && !this.claimAvailable) data = null;
    if (path.endsWith("/wait")) {
      if (Object.values(this.statuses).includes("running")) {
        return {
          status: 409,
          body: { error: { code: "operation.step_running" } },
        };
      }
      data = { ...this.operation(), status: "waiting" };
    }
    if (path.endsWith("/children")) {
      assertEquals(body, { worker: "workflow-7", key: this.childKey });
      data = { ...this.operation(), id: 71, status: this.childStatus };
    }

    if (path.endsWith("/run")) {
      const key = String(body.key);
      if (!this.runs.has(key)) {
        this.effects.push(key);
        this.runs.set(
          key,
          key === `child.${this.childKey}`
            ? this.childStatus === "succeeded"
              ? { child_id: 71, child_status: "succeeded" }
              : { child_id: 71, error_code: "deployment.vp_store_failed" }
            : key === "verification"
            ? {
              deployment_id: 51,
              release: null,
              selected_release: null,
              commit: "a".repeat(40),
            }
            : key === "before_activation.install"
            ? this.refused
              ? { error_code: this.refused }
              : { exit_code: 0, timed_out: false }
            : { commit: "a".repeat(40) },
        );
      }
      if (this.loseRun === key) {
        this.loseRun = null;
        throw new Error("Lost Node response");
      }
      data = this.runs.get(key);
    }
    if (path.endsWith("/step")) {
      const name = String(body.name);
      if (
        body.status === "running" &&
        ["failed", "succeeded"].includes(this.statuses[name])
      ) {
        return {
          status: 409,
          body: { error: { code: "operation.step_finished" } },
        };
      }
      this.statuses[name] = String(body.status);
      if (this.loseStepReply && body.status === "failed") {
        this.loseStepReply = false;
        throw new Error("Lost failed-step reply");
      }
    }
    if (path.endsWith("/step") && body.status === "succeeded") {
      this.steps[String(body.name)] = body.result;
    }
    if (path.endsWith("/complete")) {
      this.completion = body;
      if (this.loseCompletion) {
        this.loseCompletion = false;
        throw new Error("Lost completion response");
      }
    }
    return { status: 200, body: { data } };
  };
  context(): Context {
    const { context } = createModelTestContext({
      globalArgs: { gatewayUrl: "https://gateway.test", caFile: "/unused" },
    });
    const write = context.writeResource.bind(context);
    return Object.assign(context, {
      globalArgs: { gatewayUrl: "https://gateway.test", caFile: "/unused" },
      readResource: (name: string) =>
        Promise.resolve(this.resources.get(name) ?? null),
      writeResource: async (
        spec: string,
        name: string,
        value: Record<string, unknown>,
      ) => {
        if (spec === "assignment" && this.failAssignment) {
          throw new Error("Assignment storage unavailable");
        }
        const handle = await write(spec, name, value);
        this.resources.set(name, structuredClone(value));
        return handle;
      },
    });
  }
  execute() {
    return instanceDeploy(() => this.http).methods.execute.execute({
      kind: this.kind,
      worker: "workflow-7",
    }, this.context());
  }
}
Deno.test("legacy PHP deployment intent ends interrupted without Node effects", async () => {
  const gateway = new Gateway();
  gateway.legacy = true;
  gateway.kind = "instance.rollback";

  assertEquals((await gateway.execute()).status, "failed");
  assertEquals(gateway.completion?.error_code, "deployment.interrupted");
  assertEquals(gateway.effects, []);
  assertEquals(
    gateway.calls.some((call) => call.path.endsWith("/step")),
    false,
  );
});
Deno.test("deployment uses exact Gateway descriptors and protected references on the Operation Node", async () => {
  const gateway = new Gateway();
  assertEquals((await gateway.execute()).status, "succeeded");
  assertEquals(gateway.effects, [
    "source.previous",
    "source.prepare",
    "before_activation.install",
    "verification",
  ]);
  for (
    const call of gateway.calls.filter((item) => item.path.endsWith("/run"))
  ) assertEquals("node_id" in call.body, false);
  const script = gateway.calls.find((item) =>
    item.body.key === "before_activation.install"
  )!.body.input as Record<string, unknown>;
  assertEquals(script.script, { $secret: "instance.deployment", id: 17 });
  assertEquals(gateway.completion?.result, {
    deployment_id: 51,
    release: null,
    selected_release: null,
    commit: "a".repeat(40),
  });
});
Deno.test("lost Node response resumes with the same key and recorded receipt", async () => {
  const gateway = new Gateway();
  gateway.loseRun = "before_activation.install";
  await assertRejects(() => gateway.execute(), Error, "Lost Node response");
  assertEquals(gateway.completion, null);
  assertEquals((await gateway.execute()).status, "succeeded");
  assertEquals(
    gateway.effects.filter((key) => key === "before_activation.install").length,
    1,
  );
});
Deno.test("lost completion response verifies terminal intent without another claim or Node command", async () => {
  const gateway = new Gateway();
  gateway.loseCompletion = true;
  await assertRejects(
    () => gateway.execute(),
    Error,
    "Lost completion response",
  );
  const effects = [...gateway.effects];
  assertEquals((await gateway.execute()).status, "succeeded");
  assertEquals(gateway.effects, effects);
});
Deno.test("an expired lease stops without completing or invoking a Node", async () => {
  const gateway = new Gateway();
  gateway.stale = true;
  await assertRejects(() => gateway.execute(), Error, "operation.lease_lost");
  assertEquals(gateway.effects, []);
  assertEquals(gateway.completion, null);
});
Deno.test("cancellation before a Node effect completes cancelled", async () => {
  const gateway = new Gateway();
  gateway.cancel = true;
  assertEquals((await gateway.execute()).status, "cancelled");
  assertEquals(gateway.effects, []);
});
Deno.test("a proven command refusal ends failed and records its code", async () => {
  const gateway = new Gateway();
  gateway.refused = "deployment.step_failed";
  assertEquals((await gateway.execute()).status, "failed");
  assertEquals(gateway.completion?.error_code, "deployment.step_failed");
  assertEquals(gateway.effects.includes("verification"), false);
});

Deno.test("failure completion preserves the Gateway deployment identity", async () => {
  const gateway = new Gateway();
  gateway.refused = "deployment.step_failed";
  assertEquals((await gateway.execute()).status, "failed");
  assertEquals(
    (gateway.completion?.result as Record<string, unknown>).deployment_id,
    51,
  );
});

Deno.test("restart after a recorded failed step preserves failure without reopening the step", async () => {
  const gateway = new Gateway();
  gateway.refused = "deployment.step_failed";
  gateway.loseStepReply = true;
  await assertRejects(() => gateway.execute(), Error, "Lost failed-step reply");
  assertEquals((await gateway.execute()).status, "failed");
  assertEquals(
    gateway.effects.filter((key) => key === "before_activation.install").length,
    1,
  );
});

Deno.test("a running target receipt leaves the Operation pending without reissuing the effect", async () => {
  const gateway = new Gateway();
  gateway.receiptState = "running";
  await assertRejects(
    () => gateway.execute(),
    Error,
    "operation.execution_busy",
  );
  assertEquals(gateway.effects, []);
  assertEquals(gateway.completion, null);
});

Deno.test("restart adopts the target script receipt without posting run again", async () => {
  const gateway = new Gateway();
  gateway.loseRun = "before_activation.install";
  await assertRejects(() => gateway.execute(), Error, "Lost Node response");
  assertEquals((await gateway.execute()).status, "succeeded");
  assertEquals(
    gateway.calls.filter((call) =>
      call.path.endsWith("/run") &&
      call.body.key === "before_activation.install"
    ).length,
    1,
  );
  assertEquals(
    gateway.calls.some((call) =>
      call.path.endsWith("/receipts/before_activation.install")
    ),
    true,
  );
});

Deno.test("deployment yields its worker for a child and resumes before scripts without another checkout", async () => {
  const gateway = new Gateway();
  gateway.config.needs_vp = true;
  assertEquals((await gateway.execute()).status, "waiting");
  assertEquals(gateway.completion, null);
  assertEquals(gateway.statuses.vp_environment, undefined);
  assertEquals(gateway.effects, ["source.previous", "source.prepare"]);
  const wait = gateway.calls.find((call) => call.path.endsWith("/wait"))!;
  assertEquals(wait.body, { worker: "workflow-7", keys: ["vp_environment"] });
  gateway.childStatus = "succeeded";
  assertEquals((await gateway.execute()).status, "succeeded");
  assertEquals(
    gateway.effects.filter((key) => key === "source.prepare").length,
    1,
  );
  assertEquals(
    gateway.effects.indexOf("child.vp_environment") <
      gateway.effects.indexOf("before_activation.install"),
    true,
  );
});

for (
  const [variant, childKey] of [
    ["production", "environment_sync"],
    ["rollback", "process_restart"],
  ]
) {
  Deno.test(`${variant} waits for ${childKey} before starting its receipt step`, async () => {
    const gateway = new Gateway();
    gateway.kind = variant === "rollback"
      ? "instance.rollback"
      : "instance.deploy";
    gateway.config.variant = variant;
    gateway.childKey = childKey;
    assertEquals((await gateway.execute()).status, "waiting");
    assertEquals(gateway.statuses[childKey], undefined);
    assertEquals(gateway.completion, null);
    gateway.childStatus = "succeeded";
    assertEquals((await gateway.execute()).status, "succeeded");
    assertEquals(gateway.statuses[childKey], "succeeded");
    assertEquals(
      gateway.effects.filter((key) => key === `child.${childKey}`).length,
      1,
    );
  });
}

Deno.test("a failed required child stops deployment before scripts", async () => {
  const gateway = new Gateway();
  gateway.config.needs_vp = true;
  gateway.childStatus = "failed";
  assertEquals((await gateway.execute()).status, "failed");
  assertEquals(gateway.completion?.error_code, "deployment.vp_store_failed");
  assertEquals(gateway.effects.includes("before_activation.install"), false);
});

Deno.test("Gateway empty PHP maps in initial context and descriptors are accepted through the shared parser", async () => {
  const gateway = new Gateway();
  const native = gateway.http;
  gateway.http = async (method, path, body) => {
    const response = await native(method, path, body);
    const envelope = response.body as {
      data?: {
        execution_context?: {
          descriptors: { input: unknown }[];
          result?: unknown;
        };
      };
    };
    const context = envelope.data?.execution_context;
    if (context) {
      context.result = [];
      for (const descriptor of context.descriptors) {
        if (Object.keys(descriptor.input as object).length === 0) {
          descriptor.input = [];
        }
      }
    }
    return response;
  };
  assertEquals((await gateway.execute()).status, "succeeded");
});

Deno.test("an uncertain effect reply leaves completion open and resumes from its proven receipt", async () => {
  const gateway = new Gateway();
  const native = gateway.http;
  let loseReply = true;
  gateway.http = async (method, path, body) => {
    const response = await native(method, path, body);
    if (
      loseReply && path.endsWith("/run") &&
      (body as { key?: string }).key === "before_activation.install"
    ) {
      loseReply = false;
      return {
        status: 409,
        body: { error: { code: "operation.execution_uncertain" } },
      };
    }
    return response;
  };
  await assertRejects(
    () => gateway.execute(),
    Error,
    "operation.execution_uncertain",
  );
  assertEquals(gateway.completion, null);
  assertEquals((await gateway.execute()).status, "succeeded");
  assertEquals(
    gateway.calls.filter((call) =>
      call.path.endsWith("/run") &&
      call.body.key === "before_activation.install"
    ).length,
    1,
  );
});

Deno.test("the placed invocation claims its own kind and saves assignment before effects", async () => {
  const gateway = new Gateway();
  const native = gateway.http;
  gateway.http = async (method, path, body) => {
    if (path.endsWith("/run")) {
      assertEquals(gateway.resources.get("assignment-workflow-7"), {
        operation_id: 7,
      });
    }
    return await native(method, path, body);
  };
  const result = await gateway.execute();
  assertEquals(result.claimed, true);
  assertEquals(result.operationId, 7);
  assertEquals(gateway.calls[0], {
    path: "/api/v1/operation-executor/claim",
    body: { kind: "instance.deploy", worker: "workflow-7" },
  });
});

Deno.test("a lost claim race and an unavailable kind perform no later writes", async () => {
  const idle = new Gateway();
  idle.claimAvailable = false;
  assertEquals(await idle.execute(), { claimed: false });
  assertEquals(idle.calls.length, 1);
  assertEquals(idle.resources.size, 0);
  for (
    const code of [
      "operation.executor_invalid",
      "operation.kind_unavailable",
      "operation.child_unproven",
    ]
  ) {
    const gateway = new Gateway();
    const calls: string[] = [];
    gateway.http = (_method, path) => {
      calls.push(path);
      return Promise.resolve({ status: 409, body: { error: { code } } });
    };
    assertEquals(await gateway.execute(), { claimed: false });
    assertEquals(calls, ["/api/v1/operation-executor/claim"]);
    assertEquals(gateway.resources.size, 0);
  }
});

Deno.test("assignment storage failure prevents every effect", async () => {
  const gateway = new Gateway();
  gateway.failAssignment = true;
  await assertRejects(
    () => gateway.execute(),
    Error,
    "Assignment storage unavailable",
  );
  assertEquals(gateway.calls.length, 1);
  assertEquals(gateway.effects, []);
  assertEquals(gateway.completion, null);
});

Deno.test("a resumed dispatch cannot claim different work after losing its lease", async () => {
  const gateway = new Gateway();
  gateway.loseRun = "before_activation.install";
  await assertRejects(() => gateway.execute(), Error, "Lost Node response");
  const calls = gateway.calls.length;
  gateway.stale = true;
  await assertRejects(() => gateway.execute(), Error, "operation.lease_lost");
  assertEquals(gateway.calls.slice(calls).map((call) => call.path), [
    "/api/v1/operation-executor/7/heartbeat",
  ]);
  assertEquals(
    gateway.calls.filter((call) => call.path.endsWith("/claim")).length,
    1,
  );
});

Deno.test("terminal completion recovery reads matching intent and never claims the next Operation", async () => {
  const gateway = new Gateway();
  gateway.loseCompletion = true;
  await assertRejects(
    () => gateway.execute(),
    Error,
    "Lost completion response",
  );
  const calls = gateway.calls.length;
  assertEquals((await gateway.execute()).status, "succeeded");
  assertEquals(gateway.calls.slice(calls).map((call) => call.path), [
    "/api/v1/operations/7",
  ]);
  gateway.completion = {
    ...gateway.completion,
    result: { deployment_id: 999 },
  };
  await assertRejects(
    () => gateway.execute(),
    Error,
    "Unproven saved Instance completion",
  );
  assertEquals(
    gateway.calls.filter((call) => call.path.endsWith("/claim")).length,
    1,
  );
});
