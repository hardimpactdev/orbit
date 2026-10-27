import { assert, assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import {
  ensureMaintenance,
  ensurePlacedMaintenance,
  ensureWorker,
  MaintenanceAttention,
  revokeEndedWorker,
  START_RECEIPT_GRACE_MS,
  type WorkflowIntent,
  type WorkflowRun,
} from "../extensions/behaviors/_lib/maintenance_worker.ts";
import {
  type MaintenanceContext,
  maintenanceInputs,
  maintenanceResult,
  reconcileWorkers,
  workerControl,
} from "../extensions/behaviors/_lib/swamp_maintenance.ts";
import {
  type Http,
  type Job,
  runStage,
  type StageArgs,
} from "../extensions/behaviors/_lib/gateway_stage.ts";
import { WEBSITE_PROJECT } from "./_support/dependency_projects.ts";
import { startFakeGateway } from "./_support/fake_gateway.ts";

const runFixture = () =>
  JSON.parse(
    Deno.readTextFileSync(
      new URL("./fixtures/maintenance-worker/run.json", import.meta.url),
    ),
  ) as WorkflowRun;
const workerFixture = () =>
  JSON.parse(
    Deno.readTextFileSync(
      new URL("./fixtures/maintenance-worker/worker.json", import.meta.url),
    ),
  );
const project = {
  ...WEBSITE_PROJECT,
  worker: {
    composerPaths: ["."],
    jsPaths: ["."],
    policy: { ".": { minimumReleaseAgeDays: 7 } },
  },
};
const args: StageArgs = {
  workItem: "tg-7",
  key: "tg-7.script.c1",
  stage: "script",
  routine: true,
  dependencyProject: project,
};

function workerHarness() {
  const records = new Map<string, Record<string, unknown>>();
  const calls: Record<string, unknown>[] = [];
  let enrolled = false, groupStatus = "running", lost = true, label = "7";
  const context = {
    globalArgs: {
      enrollmentVault: "default",
      workerUrl: "wss://swamp.orbit",
      serveUrl: "wss://swamp.orbit",
      serveTokenFile: "unused",
      caFile: "unused",
    },
    definition: { name: "orbit-gateway" },
    queryData: (predicate: string) => {
      const model = JSON.parse(/modelName == ("[^"]+")/.exec(predicate)![1]);
      const name = JSON.parse(/name == ("[^"]+")/.exec(predicate)![1]);
      if (model.startsWith("worker-") && enrolled) {
        return Promise.resolve([{
          tokenName: model.slice(7),
          status: "idle",
          labels: { group: label },
        }]);
      }
      return Promise.resolve(
        records.has(`${model}/${name}`)
          ? [records.get(`${model}/${name}`)]
          : [],
      );
    },
    writeResource: (
      _spec: string,
      name: string,
      data: Record<string, unknown>,
    ) => {
      records.set(`orbit-gateway/${name}`, structuredClone(data));
      return Promise.resolve({ name });
    },
    runModel: (
      call: {
        name: string;
        method: string;
        arguments?: Record<string, unknown>;
      },
    ) => {
      calls.push(call);
      records.set(`${call.name}/token-main`, {
        state: call.method === "mint" ? "unused" : "revoked",
        expiresAt: "2099-01-01T00:00:00Z",
        vaultName: "default",
        secretKey: `worker-token-${call.name}`,
      });
      return Promise.resolve({ ok: true });
    },
    vaultService: { get: () => Promise.resolve("fixture-credential") },
  } as MaintenanceContext;
  const http: Http = (method, path, body, key) => {
    if (method === "POST") {
      assertEquals(path, "/api/v1/task-groups/7/worker");
      assertEquals(body, {
        worker: {
          orchestrator_url: "wss://swamp.orbit",
          enrollment_token: "task-group-7-1.fixture-credential",
          server_token: "task-group-7-1-access.fixture-credential",
          labels: { group: "7" },
        },
      });
      assertEquals(key, "tg-7.worker.c1");
      calls.push({ method, path, key });
      if (lost) {
        lost = false;
        return Promise.resolve({ status: 0, body: null });
      }
      return Promise.resolve({ status: 202, body: { data: { id: 88 } } });
    }
    return Promise.resolve({
      status: 200,
      body: {
        data: path.includes("operations")
          ? workerFixture()
          : { status: groupStatus },
      },
    });
  };
  const deps = workerControl(context, http);
  return {
    records,
    calls,
    deps,
    enroll: () => {
      enrolled = true;
    },
    relabel: (value: string) => {
      label = value;
    },
    end: (status: string) => {
      groupStatus = status;
    },
  };
}

Deno.test("Swamp mints one short-lived single-machine token; lost Gateway response replays without durable plaintext", async () => {
  const h = workerHarness();
  await ensureWorker(7, h.deps);
  await ensureWorker(7, h.deps);
  const waiting = await ensureWorker(7, h.deps);
  assertEquals(waiting.ready, false);
  h.enroll();
  const ready = await ensureWorker(7, h.deps);
  assertEquals(ready.operationId, 88);
  assertEquals(ready.ready, true);
  assertEquals(
    h.calls.filter((call) =>
      call.method === "mint" && call.modelType === "swamp/enrollment-token"
    ),
    [{
      modelType: "swamp/enrollment-token",
      name: "task-group-7-1",
      method: "mint",
      arguments: {
        durationMs: 7200000,
        vaultName: "default",
        maxEnrollments: 1,
      },
    }],
  );
  assertEquals(h.calls.filter((call) => call.method === "POST").length, 2);
  assert(!JSON.stringify([...h.records]).includes("fixture-credential"));
});

Deno.test("completed, failed and cancelled groups revoke once through Swamp, including a lost revoke/save response", async () => {
  for (const state of ["completed", "failed", "cancelled"]) {
    const h = workerHarness();
    await ensureWorker(7, h.deps);
    await revokeEndedWorker(7, h.deps);
    assertEquals(h.calls.filter((call) => call.method === "revoke").length, 0);
    h.end(state);
    const save = h.deps.save;
    let lost = true;
    h.deps.save = (session) => {
      if (session.revoked && lost) {
        lost = false;
        throw new Error("lost save");
      }
      return save(session);
    };
    await assertRejects(() => revokeEndedWorker(7, h.deps));
    await revokeEndedWorker(7, h.deps);
    await revokeEndedWorker(7, h.deps);
    assertEquals((await h.deps.read(7))!.revoked, true);
    assert(!JSON.stringify([...h.records]).includes("fixture-credential"));
  }
});

Deno.test("an operation from another group never confirms worker enrollment", async () => {
  const h = workerHarness();
  await ensureWorker(7, h.deps);
  await ensureWorker(7, h.deps);
  h.deps.http = () =>
    Promise.resolve({
      status: 200,
      body: { data: { ...workerFixture(), target_id: 9 } },
    });
  await assertRejects(() => ensureWorker(7, h.deps), Error, "identity differs");
});

Deno.test("lost Swamp workflow start adopts the exact run; a missing or foreign receipt never dispatches twice", async () => {
  let intent: WorkflowIntent | null = null, launches = 0, available = true;
  const inputs = maintenanceInputs(project, args, 7, {
    cwd: "/vm/repo",
    mode: "routine",
    base: "a".repeat(40),
  });
  const run = { ...runFixture(), inputs };
  const deps = {
    read: () => Promise.resolve(structuredClone(intent)),
    save: (value: WorkflowIntent) => {
      intent = structuredClone(value);
      return Promise.resolve();
    },
    launch: () => {
      launches++;
      return Promise.reject(new Error("response lost"));
    },
    search: () => Promise.resolve(available ? [run] : []),
    get: () => Promise.resolve(run),
  };
  await assertRejects(() => ensureMaintenance(inputs, deps));
  available = false;
  assertEquals(await ensureMaintenance(inputs, deps), null);
  assertEquals(await ensureMaintenance(inputs, deps), null);
  available = true;
  assertEquals((await ensureMaintenance(inputs, deps))!.id, run.id);
  assertEquals(launches, 1);
  await assertRejects(
    () => ensureMaintenance({ ...inputs, cwd: "/host/repo" }, deps),
    Error,
    "input conflict",
  );
  run.inputs = { ...inputs, group: "9" };
  await assertRejects(
    () => ensureMaintenance(inputs, deps),
    Error,
    "identity differs",
  );
});

Deno.test("package placement carries VM cwd, configured paths/policy and literal advisory IDs; detection inputs have no package arguments", () => {
  const target = {
    workItem: "tg-7",
    path: ".",
    manager: "js" as const,
    package: "react",
    version: "20.0.0",
    purpose: "security" as const,
    advisories: ["GHSA-test"],
    advisoryUrls: ["https://github.com/advisories/GHSA-test"],
  };
  const input = maintenanceInputs(
    project,
    { ...args, packageTarget: target },
    7,
    { cwd: "/vm/repo", mode: "package", base: "a".repeat(40) },
  );
  assertEquals(input.group, "7");
  assertEquals(input.composerPaths, []);
  assertEquals(input.js, {
    cwd: "/vm/repo",
    paths: ["."],
    policy: project.worker.policy,
    captureFailure: true,
    path: ".",
    package: "react",
    version: "20.0.0",
    purpose: "security",
    advisoryIds: ["GHSA-test"],
  });
  assertEquals(input.jsCommon, {
    cwd: "/vm/repo",
    paths: ["."],
    policy: project.worker.policy,
    captureFailure: true,
  });
});

Deno.test("worker receipts distinguish completed command failures from interruption and malformed snapshots", () => {
  const run = runFixture();
  assertEquals(maintenanceResult(run).result!.changed, true);
  const failure = {
    name: "failure",
    attributes: { exitCode: 1, message: "composer update failed" },
  };
  run.jobs[0].steps[1].dataArtifacts = [failure];
  assertEquals(maintenanceResult(run).result!.outcome, "failed");
  assertEquals(maintenanceResult(run).result!.exit_code, 1);
  run.status = "interrupted";
  assertEquals(maintenanceResult(run).state, "failed");
  run.status = "failed";
  assertEquals(maintenanceResult(run).state, "failed");
  run.jobs[0].steps[1].status = "failed";
  assertEquals(maintenanceResult(run).state, "failed");
  run.status = "succeeded";
  run.jobs[0].steps[1].status = "succeeded";
  run.jobs[0].steps.at(-1)!.dataArtifacts![0].attributes!.tree = "invalid";
  assertEquals(maintenanceResult(run).state, "failed");
});

Deno.test("worker-enabled driver uses the VM Instance path, records existing factory products and never calls tasks.script", async () => {
  const fake = startFakeGateway({ 7: {} });
  let job: Job | null = null;
  const requests: string[] = [];
  const http: Http = async (method, path, body, key) => {
    requests.push(path);
    if (path === "/api/v1/instances/70") {
      return {
        status: 200,
        body: {
          data: {
            id: 70,
            project_id: 20,
            checkout_path: "/vm/repo",
            starting_commit: "a".repeat(40),
          },
        },
      };
    }
    const response = await fetch(fake.url + path, {
      method,
      headers: {
        "Content-Type": "application/json",
        ...(key ? { "Idempotency-Key": key } : {}),
      },
      ...(body ? { body: JSON.stringify(body) } : {}),
    });
    return { status: response.status, body: await response.json() };
  };
  const deps = {
    http,
    projects: [project],
    readJob: () => Promise.resolve(job),
    writeJob: (value: Job) => {
      job = structuredClone(value);
      return Promise.resolve();
    },
    maintenance: (
      input: StageArgs,
      group: number,
      request: Record<string, unknown>,
    ) => {
      assertEquals(input.dependencyProject, project);
      assertEquals(group, 7);
      assertEquals(request.cwd, "/vm/repo");
      return Promise.resolve(maintenanceResult(runFixture()));
    },
  };
  try {
    fake.groups.get(7)!.taskable_id = 70;
    const result = await runStage(args, deps);
    assertEquals(result.state, "succeeded");
    assertEquals(result.artifacts.script.changed, true);
    assertEquals(result.evidence!.workerName, "task-group-7-1");
    assertEquals(result.operationId, null);
    assert(!requests.some((path) => path.endsWith("/script")));
    job = null;
    await assertRejects(
      () => runStage(args, { ...deps, maintenance: undefined }),
      Error,
      "fallback is disabled",
    );
  } finally {
    await fake.close();
  }
});

Deno.test("disconnected ready worker rotates; expired unknown start is reconciled without restarting an unenrolled process", async () => {
  const h = workerHarness();
  await ensureWorker(7, h.deps);
  // Lost response: retain the old key even if its enrollment has since expired.
  h.records.get("task-group-7-1/token-main")!.expiresAt =
    "2000-01-01T00:00:00Z";
  await ensureWorker(7, h.deps);
  assertEquals(
    h.calls.filter((c) =>
      c.method === "mint" && c.modelType === "swamp/enrollment-token"
    ).length,
    1,
  );
  const waiting = await ensureWorker(7, h.deps);
  assertEquals(waiting.generation, 1);
  assertEquals(waiting.operationId, 88);
  assertEquals(h.calls.filter((c) => c.method === "revoke").length, 0);
  const fresh = workerHarness();
  await ensureWorker(7, fresh.deps);
  await ensureWorker(7, fresh.deps);
  fresh.enroll();
  await ensureWorker(7, fresh.deps);
  fresh.deps.connected = () => Promise.resolve(false);
  assertEquals((await ensureWorker(7, fresh.deps)).generation, 2);
});

Deno.test("expired staged worker enrollment rotates only after a scoped terminal operation", async () => {
  const h = workerHarness();
  await ensureWorker(7, h.deps);
  await ensureWorker(7, h.deps);
  h.deps.http = () =>
    Promise.resolve({
      status: 200,
      body: {
        data: {
          ...workerFixture(),
          status: "failed",
          error_code: "tasks.worker_enrollment_expired",
        },
      },
    });
  assertEquals((await ensureWorker(7, h.deps)).generation, 2);
});

Deno.test("worker summary publication reuses saved checked text and rejects malformed or changed summaries", async () => {
  for (const scenario of ["valid", "malformed", "changed"]) {
    const fake = startFakeGateway({ 7: {} });
    const jobs = new Map<string, Job>();
    let calls = 0, now = Date.now();
    const http: Http = async (method, path, body, key) => {
      if (path === "/api/v1/instances/70") {
        return {
          status: 200,
          body: {
            data: {
              id: 70,
              project_id: 20,
              checkout_path: "/vm/repo",
              starting_commit: "a".repeat(40),
            },
          },
        };
      }
      const response = await fetch(fake.url + path, {
        method,
        headers: {
          "Content-Type": "application/json",
          ...(key ? { "Idempotency-Key": key } : {}),
        },
        ...(body ? { body: JSON.stringify(body) } : {}),
      });
      return { status: response.status, body: await response.json() };
    };
    const handoff = {
      payload: {
        subtask: 701,
        outcome: "passed",
        checkId: 3,
        commit: "a".repeat(40),
        candidate: { tree: "c".repeat(40) },
      },
      recordedAt: "2026-10-27T00:00:00Z",
    };
    const input: StageArgs = {
      ...args,
      stage: "publish",
      key: "tg-7.publish.c1",
      handoff,
    };
    let key = input.key;
    const deps = {
      http,
      projects: [project],
      now: () => now,
      readJob: () => Promise.resolve(jobs.get(key) ?? null),
      writeJob: (job: Job) => {
        jobs.set(key, structuredClone(job));
        return Promise.resolve();
      },
      readPriorPublish: () => Promise.resolve([...jobs.values()]),
      maintenance: () => {
        calls++;
        return Promise.resolve({
          state: "succeeded" as const,
          runId: runFixture().id,
          result: {
            outcome: "passed",
            changed: scenario === "changed",
            head: "a".repeat(40),
            tree: "c".repeat(40),
            output: scenario === "malformed"
              ? "bad"
              : "Update dependencies\n\n| Package | From | To |\n| --- | --- | --- |\n| fixture | 1 | 2 |",
          },
        });
      },
    };
    try {
      fake.groups.get(7)!.taskable_id = 70;
      fake.groups.get(7)!.tasks[0].status = "running";
      const first = await runStage(input, deps);
      if (scenario !== "valid") {
        assertEquals(first.state, "failed");
        assertEquals(fake.startsOf(7, "publish"), 0);
        continue;
      }
      assert(first.request!.path.endsWith("/publish"));
      assertEquals(fake.startsOf(7, "publish"), 1);
      now += 60000;
      assertEquals((await runStage(input, deps)).state, "succeeded");
      key = "tg-7.publish.c2";
      await runStage({ ...input, key }, deps);
      assertEquals(
        calls,
        1,
        "same check uses the saved summary after the child ends",
      );
      assertEquals(fake.startsOf(7, "script"), 0);
    } finally {
      await fake.close();
    }
  }
});

Deno.test("a lost mint response adopts the existing vault-backed token, and worker polls honor Gateway delays", async () => {
  const h = workerHarness();
  const mint = h.deps.mint;
  h.deps.mint = async (name) => {
    await mint(name);
    throw new Error("mint response lost");
  };
  await assertRejects(() => ensureWorker(7, h.deps));
  await ensureWorker(7, h.deps);
  await ensureWorker(7, h.deps);
  assertEquals(
    h.calls.filter((call) =>
      call.method === "mint" && call.modelType === "swamp/enrollment-token"
    ).length,
    1,
  );
  let now = Date.now(), reads = 0;
  h.deps.now = () => now;
  h.deps.http = () => {
    reads++;
    return Promise.resolve({
      status: 200,
      body: {
        data: { ...workerFixture(), status: "waiting", poll_after_seconds: 30 },
      },
    });
  };
  await ensureWorker(7, h.deps);
  await ensureWorker(7, h.deps);
  assertEquals(reads, 1);
  now += 30000;
  await ensureWorker(7, h.deps);
  assertEquals(reads, 2);
});

Deno.test("worker repair keeps the exact package and advisory scope and returns to maintenance", async () => {
  const fake = startFakeGateway({ 7: {} });
  let job: Job | null = null, now = Date.now();
  let prompt = "";
  const http: Http = async (method, path, body, key) => {
    if (path.endsWith("/turn")) prompt = String(body!.prompt);
    const response = await fetch(fake.url + path, {
      method,
      headers: {
        "Content-Type": "application/json",
        ...(key ? { "Idempotency-Key": key } : {}),
      },
      ...(body ? { body: JSON.stringify(body) } : {}),
    });
    return { status: response.status, body: await response.json() };
  };
  const input: StageArgs = {
    ...args,
    stage: "repair",
    key: "tg-7.repair.c1",
    script: {
      payload: { subtask: 701, outcome: "failed", output: "update failed" },
      recordedAt: "2026-10-27T00:00:00Z",
    },
    packageTarget: {
      workItem: "tg-7",
      manager: "composer",
      path: ".",
      package: "livewire/livewire",
      version: "4.4.8",
      purpose: "security",
      advisories: ["PKSA-fixture"],
      advisoryUrls: ["https://github.com/advisories/GHSA-fixture"],
    },
  };
  const deps = {
    http,
    projects: [project],
    now: () => now,
    readJob: () => Promise.resolve(job),
    writeJob: (value: Job) => {
      job = structuredClone(value);
      return Promise.resolve();
    },
  };
  try {
    await runStage(input, deps);
    assert(prompt.includes("livewire/livewire → 4.4.8"));
    assert(
      prompt.includes(
        "PKSA-fixture: https://github.com/advisories/GHSA-fixture",
      ),
    );
    assert(prompt.includes("Swamp reruns this exact target"));
    assert(!prompt.includes("Finish scripts/dependencies"));
    now += 60000;
    assertEquals(
      (await runStage(input, deps)).artifacts.change.rerunMaintenance,
      true,
    );
  } finally {
    await fake.close();
  }
});

Deno.test("successful worker startup without enrollment fails within five minutes, including after token expiry", async () => {
  const h = workerHarness();
  let now = Date.now();
  h.deps.now = () => now;
  await ensureWorker(7, h.deps);
  await ensureWorker(7, h.deps);
  const waiting = await ensureWorker(7, h.deps);
  assertEquals(waiting.startedAt, new Date(now).toISOString());
  now += START_RECEIPT_GRACE_MS - 1;
  assertEquals((await ensureWorker(7, h.deps)).ready, false);
  h.records.get("task-group-7-1/token-main")!.expiresAt =
    "2000-01-01T00:00:00Z";
  now++;
  await assertRejects(
    () => ensureWorker(7, h.deps),
    MaintenanceAttention,
    "did not enroll within five minutes",
  );
  assertEquals(
    h.calls.filter((call) =>
      call.method === "mint" && call.modelType === "swamp/enrollment-token"
    ).length,
    1,
  );
  assertEquals(h.calls.filter((call) => call.method === "revoke").length, 0);
});

Deno.test("uncertain workflow launch raises assistance after five minutes without a second dispatch", async () => {
  let intent: WorkflowIntent | null = null, launches = 0, now = Date.now();
  const deps = {
    read: () => Promise.resolve(intent),
    save: (value: WorkflowIntent) => {
      intent = structuredClone(value);
      return Promise.resolve();
    },
    search: () => Promise.resolve([]),
    launch: () => {
      launches++;
      throw new Error("serve restarted before accepting dispatch");
    },
    get: () => Promise.reject(new Error("no receipt")),
    now: () => now,
  };
  await assertRejects(() =>
    ensureMaintenance({ key: "lost", worker: "task-group-7-1" }, deps)
  );
  now += START_RECEIPT_GRACE_MS - 1;
  assertEquals(
    await ensureMaintenance({ key: "lost", worker: "task-group-7-1" }, deps),
    null,
  );
  now++;
  await assertRejects(
    () => ensureMaintenance({ key: "lost", worker: "task-group-7-1" }, deps),
    MaintenanceAttention,
    "no Swamp run receipt five minutes",
  );
  assertEquals(launches, 1);
});

Deno.test("saved worker target survives a disconnected or expired worker during active and uncertain writes", async () => {
  for (const uncertain of [false, true]) {
    const h = workerHarness();
    h.deps.read = () =>
      Promise.reject(
        new Error("must not check or rotate a worker with an intent"),
      );
    const inputs = {
      key: "in-flight",
      cwd: "/vm/repo",
      worker: "task-group-7-1",
    };
    const run = { ...runFixture(), inputs, status: "running" };
    let intent: WorkflowIntent = {
      key: inputs.key,
      inputs,
      runId: uncertain ? null : run.id,
      createdAt: new Date().toISOString(),
    };
    const deps = {
      read: () => Promise.resolve(intent),
      save: (value: WorkflowIntent) => {
        intent = value;
        return Promise.resolve();
      },
      search: () => Promise.resolve([run]),
      launch: () => Promise.reject(new Error("must not launch again")),
      get: () => Promise.resolve(run),
    };
    assertEquals(
      (await ensurePlacedMaintenance(
        7,
        { key: inputs.key, cwd: "/vm/repo" },
        h.deps,
        deps,
      ))!.id,
      run.id,
    );
    assertEquals(intent.inputs.worker, "task-group-7-1");
    assertEquals(h.calls.length, 0);
  }
});

Deno.test("worker placement is saved with the enrollment-bound name even when labels change", async () => {
  const h = workerHarness();
  await ensureWorker(7, h.deps);
  await ensureWorker(7, h.deps);
  h.enroll();
  h.relabel("8");
  let intent: WorkflowIntent | null = null;
  const deps = {
    read: () => Promise.resolve(intent),
    save: (value: WorkflowIntent) => {
      intent = structuredClone(value);
      return Promise.resolve();
    },
    search: () => Promise.resolve([]),
    launch: (inputs: Record<string, unknown>) => {
      assertEquals(inputs.worker, "task-group-7-1");
      return Promise.resolve("placed");
    },
    get: () =>
      Promise.resolve({
        ...runFixture(),
        id: "placed",
        inputs: intent!.inputs,
      }),
  };
  await ensurePlacedMaintenance(7, { key: "placed" }, h.deps, deps);
  assertEquals(intent!.inputs.worker, "task-group-7-1");
});

Deno.test("worker cleanup continues after one session fails and retries it on the next sweep", async () => {
  let broken = true;
  const saved: number[] = [];
  const sessions = [7, 8].map((group) => ({
    group,
    generation: 1,
    tokenName: `task-group-${group}-1`,
    operationId: 88,
    ready: true,
    revoked: false,
  }));
  const context: MaintenanceContext = {
    globalArgs: {
      enrollmentVault: "default",
      workerUrl: "unused",
      serveUrl: "unused",
      serveTokenFile: "unused",
      caFile: "unused",
    },
    definition: { name: "orbit-gateway" },
    queryData: (predicate) =>
      Promise.resolve(
        predicate.includes("startsWith")
          ? sessions.filter((s) => !s.revoked)
          : predicate.includes('modelName == "orbit-gateway"')
          ? [sessions.find((s) => predicate.includes(`worker-${s.group}`))!]
          : [{ state: "used" }],
      ),
    runModel: ({ name }) => {
      if (name === "task-group-7-1" && broken) {
        throw new Error("revoke transport failed");
      }
      return Promise.resolve({ ok: true });
    },
    writeResource: (_spec, name, data) => {
      const group = Number(data.group);
      saved.push(group);
      Object.assign(sessions.find((s) => s.group === group)!, data);
      return Promise.resolve({ name });
    },
    vaultService: {
      get: () => Promise.reject(new Error("must not read secrets")),
    },
  };
  const http: Http = () =>
    Promise.resolve({ status: 200, body: { data: { status: "completed" } } });
  await reconcileWorkers(context, http);
  assertEquals(saved, [8]);
  broken = false;
  await reconcileWorkers(context, http);
  assertEquals(saved, [8, 7]);
});

Deno.test("the enrollment grace starts at the Gateway operation's success even after a driver outage", async () => {
  const h = workerHarness();
  await ensureWorker(7, h.deps);
  await ensureWorker(7, h.deps);
  const now = Date.now();
  h.deps.now = () => now;
  h.deps.http = () =>
    Promise.resolve({
      status: 200,
      body: {
        data: {
          ...workerFixture(),
          finished_at: new Date(now - START_RECEIPT_GRACE_MS).toISOString(),
        },
      },
    });
  await assertRejects(
    () => ensureWorker(7, h.deps),
    MaintenanceAttention,
    "did not enroll within five minutes",
  );
});

Deno.test("worker server authentication is minted for only its worker identity and recovered after a lost mint reply", async () => {
  const h = workerHarness();
  const mintServer = h.deps.mintServer;
  h.deps.mintServer = async (name, expiresAt) => {
    await mintServer(name, expiresAt);
    throw new Error("server token mint reply lost");
  };
  await assertRejects(() => ensureWorker(7, h.deps));
  await ensureWorker(7, h.deps);
  const calls = h.calls.filter((call) =>
    call.modelType === "swamp/server-token"
  );
  assertEquals(calls.length, 1);
  assertEquals(calls[0].name, "task-group-7-1-access");
  const input = calls[0].arguments as Record<string, unknown>;
  assertEquals(input.principalId, "worker:task-group-7-1");
  assertEquals(input.vaultName, "default");
  assert(Number(input.durationMs) > 0);
  assertEquals(input.groups, undefined);
  assertEquals(input.collectives, undefined);
  h.end("completed");
  let failed = true;
  const revokeServer = h.deps.revokeServer;
  h.deps.revokeServer = (name) => {
    if (failed) {
      failed = false;
      throw new Error("revoke reply lost");
    }
    return revokeServer(name);
  };
  await assertRejects(() => revokeEndedWorker(7, h.deps));
  assertEquals((await h.deps.read(7))!.revoked, false);
  await revokeEndedWorker(7, h.deps);
  assertEquals((await h.deps.read(7))!.revoked, true);
  assertEquals(
    h.records.get("task-group-7-1-access/token-main")!.state,
    "revoked",
  );
  assert(!JSON.stringify([...h.records]).includes("fixture-credential"));
});

Deno.test("missing worker names stay absent in maintenance receipts", () => {
  for (
    const state of ["succeeded", "failed", "step-failed", "malformed-receipt"]
  ) {
    const run = runFixture();
    delete run.inputs.worker;
    if (state === "failed") run.status = "failed";
    if (state === "step-failed") run.jobs[0].steps[1].status = "failed";
    if (state === "malformed-receipt") {
      run.jobs[0].steps[1].dataArtifacts = [{
        name: "failure",
        attributes: { exitCode: "invalid" },
      }];
    }
    const result = maintenanceResult(run);
    assertEquals(result.workerName, undefined);
    assert(!JSON.stringify(result).includes('"workerName"'));
  }
});

Deno.test("uncertain starts preserve an absent worker name", async () => {
  const inputs = { key: "missing-worker" };
  const intent: WorkflowIntent = {
    key: inputs.key,
    inputs,
    runId: null,
    createdAt: new Date(0).toISOString(),
  };
  const error = await assertRejects(() =>
    ensureMaintenance(inputs, {
      read: () => Promise.resolve(intent),
      save: () => Promise.reject(new Error("must not save")),
      search: () => Promise.resolve([]),
      launch: () => Promise.reject(new Error("must not dispatch")),
      get: () => Promise.reject(new Error("no receipt")),
    }), MaintenanceAttention);
  assertEquals(error.workerName, undefined);
});
