import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.11";
import { createModelTestContext } from "jsr:@swamp-club/swamp-testing@0.20261016.58";
import { type Context, Operation } from "../../operation-runner/mod.ts";
import type { Http } from "../../automatic-deploy/gateway.ts";
import { tools } from "./mod.ts";
import { fixturePlan } from "./plan_fixture.ts";
import { Completion, ReceiptResult } from "./contract.ts";

class Fixture {
  operation = Operation.parse({
    id: 11,
    kind: "tool.install",
    node_id: 2,
    status: "running",
    cancel_requested: false,
    input: {
      node_id: 2,
      manager: "vp",
      package: "is-number",
      platform: "linux",
      architecture: "x86_64",
      version_constraint: null,
      tool_id: null,
      intent_only: false,
    },
    step_results: {},
  });
  calls: {
    method: string;
    peer: number;
    path: string;
    body: Record<string, unknown>;
  }[] = [];
  resources = new Map<string, Record<string, unknown>>();
  receipts = new Map<string, Record<string, unknown>>();
  peer = 12;
  leasePeer: number | null = null;
  leaseWorker: string | null = null;
  leaseExpired = false;
  noClaim = false;
  loseClaim = false;
  loseAssignment = false;
  commitThenLoseCompletion = false;
  queuedNext = false;
  terminalResult: Record<string, unknown> | null = null;
  terminalError: string | null = null;
  installed: string | null = null;
  candidate = "7.0.0";
  candidateReply: unknown = undefined;
  block: string | null = null;
  plan = ["is-number"];
  composerRoots: string[] = [];
  scopeRefusal: string | null = null;
  mutations = 0;
  loseRun = false;
  loseCompletion = false;
  leaseLost = false;
  cancelAtRun = false;
  refuse: string | null = null;
  refuseStep: string | null = null;
  emptyResultsAsArrays = true;
  mutationResultOverride: unknown = undefined;
  receiptStateOverride: "running" | "unknown" | undefined;
  admittedPlan = fixturePlan;
  http: Http = async (method, path, raw) => {
    const body = (raw ?? {}) as Record<string, unknown>;
    this.calls.push({ method, peer: this.peer, path, body });
    await Promise.resolve();
    if (this.leaseLost) {
      return { status: 409, body: { error: { code: "operation.lease_lost" } } };
    }
    this.operation.execution_context = this.admittedPlan(this.operation);
    let data: unknown = this.operation;
    if (path === `/api/v1/operations/${this.operation.id}`) {
      return {
        status: 200,
        body: {
          data: {
            ...this.operation,
            result: this.terminalResult &&
                Object.keys(this.terminalResult).length === 0
              ? []
              : this.terminalResult,
            error_code: this.terminalError,
          },
        },
      };
    }
    if (path.includes("/receipts/")) {
      const key = decodeURIComponent(path.split("/receipts/")[1]);
      const result = this.receipts.get(key);
      const state = this.receiptStateOverride ??
        (result ? (result.error_code ? "failed" : "succeeded") : "unknown");
      const terminal = state === "succeeded" || state === "failed";
      return {
        status: 200,
        body: {
          data: {
            effect_key: `op-${this.operation.id}.${key}`,
            state,
            exit_code: terminal ? (state === "failed" ? 1 : 0) : null,
            result: terminal
              ? (result && Object.keys(result).length === 0 ? [] : result)
              : null,
            finished_at: terminal ? "2026-10-29T06:46:20Z" : null,
          },
        },
      };
    }
    if (path.endsWith("/claim")) {
      if (this.queuedNext && this.operation.status === "succeeded") {
        this.operation = {
          ...this.operation,
          id: 12,
          status: "running",
          step_results: {},
        };
      }
      if (
        this.noClaim || body.kind !== this.operation.kind ||
        this.leasePeer !== null && !this.leaseExpired &&
          (this.peer !== this.leasePeer || body.worker !== this.leaseWorker)
      ) data = null;
      else {
        this.leasePeer = this.peer;
        this.leaseWorker = String(body.worker);
        this.leaseExpired = false;
        if (this.loseClaim) {
          this.loseClaim = false;
          throw new Error("Lost claim reply");
        }
      }
    } else if (
      this.peer !== this.leasePeer || body.worker !== this.leaseWorker
    ) {
      return { status: 409, body: { error: { code: "operation.lease_lost" } } };
    }
    if (path.endsWith("/run")) {
      const key = String(body.key);
      if (!this.receipts.has(key)) {
        const name = String(body.name);
        let receipt: Record<string, unknown> = {};
        if (this.cancelAtRun) receipt = { error_code: "operation.cancelled" };
        else if (this.refuse && (!this.refuseStep || this.refuseStep === key)) {
          receipt = { error_code: this.refuse };
        } else if (name === "tool.inspect-composer-scope") {
          receipt = this.scopeRefusal
            ? { error_code: this.scopeRefusal }
            : { roots: this.composerRoots };
        } else if (
          (name.endsWith("-inventory") || name.endsWith("-adoption"))
        ) {
          receipt = { installed_version: this.installed, block: this.block };
        } else if (name.endsWith("-version")) receipt = { version: "1.0.0" };
        else if (name.endsWith("-candidate")) {
          receipt = {
            version: this.candidateReply === undefined
              ? this.candidate
              : this.candidateReply,
          };
        } else if (name.endsWith("-removal-plan")) {
          receipt = { packages: this.plan };
        } else if (key === "package-mutate") {
          this.mutations++;
          this.installed = this.operation.kind === "tool.remove"
            ? null
            : this.candidate;
        }
        this.receipts.set(key, receipt);
      }
      data = this.receipts.get(key);
      if (
        this.emptyResultsAsArrays && data &&
        Object.keys(data as Record<string, unknown>).length === 0
      ) data = [];
      if (
        key === "package-mutate" && this.mutationResultOverride !== undefined
      ) {
        data = this.mutationResultOverride;
      }
      if (this.loseRun && key === "package-mutate") {
        this.loseRun = false;
        throw new Error("Lost Node reply");
      }
    }
    if (path.endsWith("/step") && body.status === "succeeded") {
      this.operation.step_results[String(body.name)] = body.result;
    }
    if (path.endsWith("/complete") && this.loseCompletion) {
      this.loseCompletion = false;
      throw new Error("Lost completion reply");
    }
    if (path.endsWith("/complete") && this.commitThenLoseCompletion) {
      this.operation.status = String(body.status);
      this.terminalResult = body.result as Record<string, unknown>;
      this.terminalError = body.error_code as string | null;
      this.commitThenLoseCompletion = false;
      throw new Error("Committed completion reply lost");
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
        if (spec === "assignment" && this.loseAssignment) {
          this.loseAssignment = false;
          throw new Error("Lost assignment write reply");
        }
        return handle;
      },
    });
  }
  execute(worker = "run-11") {
    return tools(() => this.http).methods.execute.execute({
      kind: this.operation.kind,
      worker,
    }, this.context());
  }
  complete() {
    return this.calls.filter((c) => c.path.endsWith("/complete")).at(-1)!.body;
  }
}

Deno.test("the retained polling workflow discovers only the registered Tool kinds", async () => {
  const workflow = parse(
    await Deno.readTextFile("workflows/workflow-operation-tools.yaml"),
  ) as { jobs: { steps: { task: { inputs: Record<string, unknown> } }[] }[] };
  const registration = JSON.parse(
    await Deno.readTextFile(
      "extensions/behaviors/operations/tools/registration.json",
    ),
  );
  assertEquals(workflow.jobs[0].steps[0].task.inputs.kinds, registration.kinds);
  assertEquals(registration.kinds, ["tool.manager_converge"]);
  for (
    const kind of ["tool.install", "tool.update", "tool.remove", "tool.adopt"]
  ) {
    assertEquals(
      tools().methods.execute.arguments.safeParse({ kind, worker: "dispatch" })
        .success,
      false,
    );
  }
  for (const kind of registration.kinds) {
    assertEquals(
      tools().methods.execute.arguments.safeParse({ kind, worker: "dispatch" })
        .success,
      true,
    );
  }
});

Deno.test("manager convergence claims its kind and returns only manager readiness", async () => {
  for (const manager of ["apt", "vp", "composer"]) {
    const f = new Fixture();
    f.operation.kind = "tool.manager_converge";
    f.operation.input.manager = manager;
    delete f.operation.input.package;
    assertEquals((await f.execute()).status, "succeeded");
    assertEquals(f.calls[0].body.kind, "tool.manager_converge");
    assertEquals(f.complete().result, { version: "1.0.0" });
    assertEquals(f.mutations, 0);
  }
});

Deno.test("installs through each exact manager and omits a transport target", async () => {
  for (
    const [manager, prefix, platform] of [
      ["apt", "apt", "linux"],
      ["composer", "composer", "linux"],
      ["vp", "vp", "linux"],
      ["brew", "homebrew-formula", "macos"],
      ["brew-cask", "homebrew-cask", "macos"],
    ]
  ) {
    const f = new Fixture();
    Object.assign(f.operation.input, {
      manager,
      platform,
      architecture: "arm64",
    });
    assertEquals((await f.execute()).status, "succeeded");
    const mutation = f.calls.find((c) =>
      c.body.key === "package-mutate" && c.path.endsWith("/run")
    )!.body;
    assertEquals(mutation.name, `tool.${prefix}-install`);
    assertEquals("node_id" in mutation, false);
    assertEquals(mutation.input, {
      package: "is-number",
      ...(platform === "macos" ? { architecture: "arm64" } : {}),
    });
    assertEquals(f.mutations, 1);
    assertEquals(f.complete().result, {
      installed_version: "7.0.0",
      block: null,
    });
  }
});
Deno.test("install refuses silent adoption, including on a restarted process", async () => {
  const f = new Fixture();
  f.installed = "7.0.0";
  assertEquals((await f.execute()).status, "failed");
  assertEquals(f.complete().error_code, "tool.package_already_installed");
  assertEquals(f.mutations, 0);
});
Deno.test("same managed install verifies the live version without changing it", async () => {
  const f = new Fixture();
  f.installed = "7.0.0";
  f.operation.input.tool_id = 8;
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(f.mutations, 0);
});
Deno.test("APT and Composer reject unavailable or malformed candidates before installation", async () => {
  for (const manager of ["apt", "composer"]) {
    for (const version of [null, "", "not-semver", 42]) {
      const f = new Fixture();
      Object.assign(f.operation.input, {
        manager,
        version_constraint: "^7.0",
      });
      f.candidateReply = version;

      assertEquals((await f.execute()).status, "failed");
      assertEquals(
        f.complete().error_code,
        version === "not-semver"
          ? "tool.candidate_version_unparseable"
          : "tool.candidate_version_unavailable",
      );
      assertEquals(f.mutations, 0);
      assertEquals(
        f.calls.find((c) =>
          c.body.key === "package-candidate" && c.path.endsWith("/run")
        )?.body.input,
        manager === "composer"
          ? { package: "is-number", operation: "install" }
          : { package: "is-number" },
      );
    }
  }
});
Deno.test("APT and Composer removal uses only an exact single-package plan and never selects a candidate", async () => {
  for (const manager of ["apt", "composer"]) {
    for (const plan of [[], ["is-number", "dependency"], ["is-number"]]) {
      const f = new Fixture();
      f.operation.kind = "tool.remove";
      f.operation.input.manager = manager;
      f.installed = "7.0.0";
      f.plan = plan;

      assertEquals(
        (await f.execute()).status,
        plan.length === 1 ? "succeeded" : "failed",
      );
      assertEquals(f.mutations, plan.length === 1 ? 1 : 0);
      assertEquals(
        f.calls.some((c) =>
          c.path.endsWith("/run") && String(c.body.name).endsWith("-candidate")
        ),
        false,
      );
    }
  }
});
Deno.test("update refuses an absent managed package", async () => {
  const f = new Fixture();
  f.operation.kind = "tool.update";
  f.operation.input.tool_id = 8;
  assertEquals((await f.execute()).status, "failed");
  assertEquals(f.mutations, 0);
});
Deno.test("candidate gates stop install and leave a blocked update unchanged", async () => {
  for (const kind of ["tool.install", "tool.update"]) {
    const f = new Fixture();
    f.operation.kind = kind;
    f.candidate = "8.0.0";
    f.operation.input.version_constraint = "^7.0";
    if (kind === "tool.update") {
      f.installed = "7.0.0";
      f.operation.input.tool_id = 8;
    }
    assertEquals(
      (await f.execute()).status,
      kind === "tool.install" ? "failed" : "succeeded",
    );
    assertEquals(f.mutations, 0);
  }
});
Deno.test("removal refuses a plan that includes another root or dependencies", async () => {
  for (const plan of [[], ["is-number", "other-package"]]) {
    const f = new Fixture();
    f.operation.kind = "tool.remove";
    f.installed = "7.0.0";
    f.plan = plan;
    assertEquals((await f.execute()).status, "failed");
    assertEquals(f.complete().error_code, "tool.removal_plan_unsafe");
    assertEquals(f.mutations, 0);
  }
});
Deno.test("exact removal verifies absence and already absent removal is harmless", async () => {
  for (const present of [true, false]) {
    const f = new Fixture();
    f.operation.kind = "tool.remove";
    if (present) f.installed = "7.0.0";
    assertEquals((await f.execute()).status, "succeeded");
    assertEquals(f.mutations, present ? 1 : 0);
  }
});

Deno.test("removal refuses a missing same-scope plan descriptor before mutation", async () => {
  for (const manager of ["apt", "vp", "composer"]) {
    const f = new Fixture();
    f.operation.kind = "tool.remove";
    f.operation.input.manager = manager;
    f.installed = "7.0.0";
    f.admittedPlan = (operation) => {
      const plan = fixturePlan(operation);
      plan.descriptors = plan.descriptors.filter((entry) =>
        entry.key !== "package-removal-plan"
      );
      return plan;
    };

    await assertRejects(
      () => f.execute(),
      Error,
      "operation.execution_forbidden",
    );
    assertEquals(f.mutations, 0);
  }
});
Deno.test("lost Node reply resumes the same effect key and recorded receipts", async () => {
  const f = new Fixture();
  f.loseRun = true;
  await assertRejects(() => f.execute(), Error, "Lost Node reply");
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(f.mutations, 1);
  assertEquals(f.calls.filter((c) => c.path.endsWith("/claim")).length, 1);
  assertEquals(
    f.calls.filter((c) =>
      c.path.endsWith("/run") && c.body.key === "package-mutate"
    ).map((c) => c.body.key),
    ["package-mutate"],
  );
  assertEquals(
    f.calls.some((c) => c.path.endsWith("/11/receipts/package-mutate")),
    true,
  );
});
Deno.test("empty PHP effect results finish the mutation and verify installed inventory", async () => {
  const f = new Fixture();
  Object.assign(f.operation.input, { manager: "apt", package: "gh" });
  f.candidate = "2.46.0-4";
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(f.mutations, 1);
  assertEquals(f.complete().result, {
    installed_version: "2.46.0-4",
    block: null,
  });
  const finished = f.calls.find((c) =>
    c.path.endsWith("/step") && c.body.key === "package-mutate:finish"
  )!;
  assertEquals(finished.body.result, {});
  assertEquals(finished.body.status, "succeeded");
});
Deno.test("reconciliation resumes an empty saved mutation receipt without running the installer", async () => {
  const f = new Fixture();
  Object.assign(f.operation.input, { manager: "apt", package: "gh" });
  f.installed = "2.46.0-4";
  f.operation.step_results = {
    "manager-ready": { version: "apt 3.2.0" },
    "package-before": { installed_version: null, block: null },
    "package-mutate": [],
  };
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(f.mutations, 0);
  assertEquals(
    f.calls.filter((c) => c.path.endsWith("/run")).map((c) => c.body.key),
    ["tool-verified"],
  );
  assertEquals(f.complete().result, {
    installed_version: "2.46.0-4",
    block: null,
  });
});
Deno.test("a fresh dispatch settles open Operation 1190 from its successful empty mutation receipt", async () => {
  const f = new Fixture();
  Object.assign(f.operation, {
    id: 1190,
    node_id: 17,
    status: "running",
    steps: [
      { name: "manager-ready", status: "succeeded" },
      { name: "package-before", status: "succeeded" },
      { name: "package-mutate", status: "running" },
    ],
    step_results: {
      "manager-ready": { version: "apt 3.2.0" },
      "package-before": { installed_version: null, block: null },
    },
  });
  Object.assign(f.operation.input, {
    node_id: 17,
    manager: "apt",
    package: "gh",
  });
  f.installed = "2.46.0-4";
  f.mutations = 1;
  f.receipts.set("package-mutate", {});
  f.leasePeer = 13;
  f.leaseWorker = "old-worker";
  f.leaseExpired = true;

  assertEquals((await f.execute("recovery-dispatch")).status, "succeeded");
  assertEquals(f.mutations, 1);
  assertEquals(
    f.calls.filter((c) => c.path.endsWith("/run")).map((c) => c.body.key),
    ["tool-verified"],
  );
  assertEquals(
    f.calls.find((c) => c.path.endsWith("/receipts/package-mutate"))?.method,
    "GET",
  );
  assertEquals(
    f.calls.some((c) =>
      c.path === "/api/v1/operation-executor/1190/step" &&
      c.body.key === "package-mutate:finish"
    ),
    true,
  );
  assertEquals(
    f.calls.filter((c) => c.path.endsWith("/complete")).map((c) => c.path),
    ["/api/v1/operation-executor/1190/complete"],
  );
  assertEquals(f.complete().result, {
    installed_version: "2.46.0-4",
    block: null,
  });
});
Deno.test("a running receipt leaves the original Operation open without replaying the effect", async () => {
  const f = new Fixture();
  f.loseRun = true;
  await assertRejects(() => f.execute(), Error, "Lost Node reply");
  f.receiptStateOverride = "running";
  await assertRejects(() => f.execute(), Error, "operation.effect_running");
  assertEquals(
    f.calls.filter((c) =>
      c.path.endsWith("/run") && c.body.key === "package-mutate"
    ).length,
    1,
  );
  assertEquals(f.calls.some((c) => c.path.endsWith("/complete")), false);
});
Deno.test("an unknown receipt retries only the original keyed effect", async () => {
  const f = new Fixture();
  f.loseAssignment = true;
  await assertRejects(() => f.execute(), Error, "Lost assignment write reply");
  f.operation.steps = [{ name: "manager-ready", status: "running" }];
  f.receiptStateOverride = "unknown";
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(
    f.calls.find((c) => c.path.endsWith("/receipts/manager-ready"))?.method,
    "GET",
  );
  assertEquals(f.calls.filter((c) => c.path.endsWith("/claim")).length, 1);
  assertEquals(f.mutations, 1);
});
Deno.test("non-empty arrays are rejected as command results without falsely completing", async () => {
  for (const invalid of [["gh"], [{ installed_version: "2.46.0-4" }]]) {
    const f = new Fixture();
    f.mutationResultOverride = invalid;
    await assertRejects(() => f.execute(), Error, "expected record");
    assertEquals(f.calls.some((c) => c.path.endsWith("/complete")), false);
    assertEquals(f.mutations, 1);
    assertEquals(
      f.calls.some((c) => c.body.key === "package-mutate:finish"),
      false,
    );
  }
});
Deno.test("empty record normalization preserves nested lists and rejects non-record results", () => {
  assertEquals(ReceiptResult.parse([]), {});
  assertEquals(ReceiptResult.parse({ packages: ["gh"] }), { packages: ["gh"] });
  assertEquals(
    Completion.parse({ status: "cancelled", result: [], error_code: null })
      .result,
    {},
  );
  for (const invalid of [["gh"], [{ version: "2.46.0" }], null, "stdout"]) {
    assertEquals(ReceiptResult.safeParse(invalid).success, false);
  }
});
Deno.test("the placed worker claims and records its assignment before effects", async () => {
  const f = new Fixture();
  assertEquals((await f.execute()).operationId, 11);
  assertEquals(f.calls[0].path, "/api/v1/operation-executor/claim");
  assertEquals(f.calls[0].body, { kind: "tool.install", worker: "run-11" });
  assertEquals(
    f.calls.every((c) => c.method === "POST" && c.peer === 12),
    true,
  );
  assertEquals(f.resources.get("assignment-run-11"), { operation_id: 11 });
});
Deno.test("a lost claim race performs no effects or assignment", async () => {
  const f = new Fixture();
  f.noClaim = true;
  assertEquals(await f.execute(), { claimed: false });
  assertEquals(f.calls.length, 1);
  assertEquals(f.resources.size, 0);
  assertEquals(f.mutations, 0);
});
Deno.test("a lost claim reply replays the same worker before any effect", async () => {
  const f = new Fixture();
  f.loseClaim = true;
  await assertRejects(() => f.execute(), Error, "Lost claim reply");
  assertEquals(f.resources.size, 0);
  assertEquals(f.mutations, 0);
  assertEquals((await f.execute()).operationId, 11);
  assertEquals(
    f.calls.filter((c) => c.path.endsWith("/claim")).map((c) => c.body),
    [
      { kind: "tool.install", worker: "run-11" },
      { kind: "tool.install", worker: "run-11" },
    ],
  );
  assertEquals(f.mutations, 1);
});
Deno.test("a lost assignment write reply retries the stored ID before effects without claiming again", async () => {
  const f = new Fixture();
  f.loseAssignment = true;
  await assertRejects(() => f.execute(), Error, "Lost assignment write reply");
  assertEquals(f.calls.length, 1);
  assertEquals(f.mutations, 0);
  assertEquals((await f.execute()).operationId, 11);
  assertEquals(f.calls[1].path, "/api/v1/operation-executor/11/heartbeat");
  assertEquals(f.calls.filter((c) => c.path.endsWith("/claim")).length, 1);
});
Deno.test("a committed completion with a lost reply reads its original ID without claiming queued work", async () => {
  const f = new Fixture();
  f.commitThenLoseCompletion = true;
  f.queuedNext = true;
  await assertRejects(
    () => f.execute(),
    Error,
    "Committed completion reply lost",
  );
  const count = f.calls.length;
  assertEquals(await f.execute(), {
    claimed: true,
    operationId: 11,
    status: "succeeded",
  });
  assertEquals(f.calls.slice(count).map((c) => [c.method, c.path]), [[
    "GET",
    "/api/v1/operations/11",
  ]]);
  assertEquals(f.operation.id, 11);
  assertEquals(f.mutations, 1);
});
Deno.test("a committed cancellation tolerates the Gateway empty-array result without new claims", async () => {
  const f = new Fixture();
  f.operation.cancel_requested = true;
  f.commitThenLoseCompletion = true;
  await assertRejects(
    () => f.execute(),
    Error,
    "Committed completion reply lost",
  );
  assertEquals(await f.execute(), {
    claimed: true,
    operationId: 11,
    status: "cancelled",
  });
  assertEquals(f.mutations, 0);
  assertEquals(f.calls.filter((c) => c.path.endsWith("/claim")).length, 1);
});
Deno.test("a saved completion must match the original Gateway terminal evidence", async () => {
  for (const field of ["kind", "status", "result", "error_code"]) {
    const f = new Fixture();
    f.commitThenLoseCompletion = true;
    await assertRejects(
      () => f.execute(),
      Error,
      "Committed completion reply lost",
    );
    if (field === "kind") f.operation.kind = "tool.remove";
    if (field === "status") f.operation.status = "failed";
    if (field === "result") {
      f.terminalResult = { installed_version: "8.0.0", block: null };
    }
    if (field === "error_code") f.terminalError = "tool.install_failed";
    await assertRejects(
      () =>
        tools(() => f.http).methods.execute.execute({
          kind: "tool.install",
          worker: "run-11",
        }, f.context()),
      Error,
      "Unproven saved Tool completion",
    );
    assertEquals(f.calls.filter((c) => c.path.endsWith("/claim")).length, 1);
  }
});
Deno.test("a retry on another peer cannot claim new work or write the old lease", async () => {
  const f = new Fixture();
  f.loseRun = true;
  await assertRejects(() => f.execute(), Error, "Lost Node reply");
  f.peer = 13;
  await assertRejects(() => f.execute(), Error, "operation.lease_lost");
  assertEquals(f.calls.filter((c) => c.path.endsWith("/claim")).length, 1);
  assertEquals(f.mutations, 1);
});
Deno.test("a fresh dispatch recovers an expired lease without repeating the proven effect", async () => {
  const f = new Fixture();
  f.loseRun = true;
  await assertRejects(() => f.execute(), Error, "Lost Node reply");
  f.peer = 13;
  assertEquals(await f.execute("replacement"), { claimed: false });
  f.leaseExpired = true;
  assertEquals((await f.execute("replacement")).status, "succeeded");
  assertEquals(f.leasePeer, 13);
  assertEquals(f.mutations, 1);
});
Deno.test("lost completion reply replays saved completion after reading and heartbeating its original ID", async () => {
  const f = new Fixture();
  f.loseCompletion = true;
  await assertRejects(() => f.execute(), Error, "Lost completion reply");
  const count = f.calls.length;
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(f.calls.length, count + 3);
  assertEquals(f.mutations, 1);
});
Deno.test("an expired lease stops before any new command", async () => {
  const f = new Fixture();
  f.leaseLost = true;
  await assertRejects(() => f.execute(), Error, "operation.lease_lost");
  assertEquals(f.calls.length, 1);
});
Deno.test("cancellation before execution and Gateway cancellation both stop mutation", async () => {
  for (const atRun of [true, false]) {
    const f = new Fixture();
    f.operation.cancel_requested = !atRun;
    f.cancelAtRun = atRun;
    assertEquals((await f.execute()).status, "cancelled");
    assertEquals(f.mutations, 0);
  }
});
Deno.test("a command refusal retains its stable error code", async () => {
  const f = new Fixture();
  f.refuse = "tool.vp_scope_conflict";
  assertEquals((await f.execute()).status, "failed");
  assertEquals(f.complete().error_code, "tool.manager_unavailable");
});
Deno.test("package failures complete as failed with the original managed package observation", async () => {
  for (const verb of ["install", "update", "remove"]) {
    const f = new Fixture();
    f.operation.kind = `tool.${verb}`;
    if (verb !== "install") {
      f.operation.input.tool_id = 8;
      f.installed = "7.0.0";
    }
    f.refuseStep = "package-mutate";
    f.refuse = "tool.vp_operation_failed";

    assertEquals((await f.execute()).status, "failed");
    assertEquals(f.complete().error_code, `tool.${verb}_failed`);
    assertEquals(f.operation.step_results["package-before"], {
      installed_version: verb === "install" ? null : "7.0.0",
      block: null,
    });
    assertEquals(f.mutations, 0);
    assertEquals(
      f.calls.some((c) =>
        c.body.key === "tool-verified" && c.path.endsWith("/run")
      ),
      false,
    );
  }
});
Deno.test("missing admitted mutation descriptor refuses before a package effect", async () => {
  const f = new Fixture();
  f.admittedPlan = (operation) => ({
    descriptors: fixturePlan(operation).descriptors.filter((entry) =>
      entry.key !== "package-mutate"
    ),
  });
  await assertRejects(
    () => f.execute(),
    Error,
    "operation.execution_forbidden",
  );
  assertEquals(f.mutations, 0);
  assertEquals(f.calls.some((call) => call.path.endsWith("/complete")), false);
});

Deno.test("fake admission matches 100 actual Gateway descriptor contexts", async () => {
  const contexts: unknown[] = [];
  for (const manager of ["apt", "composer", "vp", "brew", "brew-cask"]) {
    for (
      const verb of ["install", "update", "remove", "adopt", "manager_converge"]
    ) {
      for (const platform of ["linux", "macos"]) {
        for (const intent_only of [false, true]) {
          const operation = Operation.parse({
            id: 11,
            kind: `tool.${verb}`,
            node_id: 2,
            status: "running",
            cancel_requested: false,
            step_results: {},
            input: {
              manager,
              package: "is-number",
              platform,
              architecture: "arm64",
              tool_id: null,
              intent_only,
              version_constraint: null,
              materialize_command: "tool.materialize-vp",
              materialize_fields: ["username", "release"],
              username: "orbit",
              release: "pinned",
            },
          });
          contexts.push(fixturePlan(operation));
        }
      }
    }
  }
  const canonical = (value: unknown): unknown =>
    Array.isArray(value)
      ? value.map(canonical)
      : value !== null && typeof value === "object"
      ? Object.fromEntries(
        Object.entries(value).sort(([a], [b]) => a < b ? -1 : a > b ? 1 : 0)
          .map(([key, entry]) => [key, canonical(entry)]),
      )
      : value;
  const digest = new Uint8Array(
    await crypto.subtle.digest(
      "SHA-256",
      new TextEncoder().encode(JSON.stringify(canonical(contexts))),
    ),
  );
  // Generated from ToolOperationExecution::context at Orbit a321a32b; empty input maps normalized as on the wire.
  assertEquals(
    Array.from(digest, (byte) => byte.toString(16).padStart(2, "0")).join(""),
    "b7628f410f75c7b741cde57f95a4930c56d665fb4351b5429cc3bd37baa3a01a",
  );
});
