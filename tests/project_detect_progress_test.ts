import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { behavior as groupRun } from "../extensions/behaviors/orbit_group_run.ts";
import { behavior as driver } from "../extensions/behaviors/orbit_driver.ts";
import {
  type Http,
  type Job,
  runStage,
} from "../extensions/behaviors/_lib/gateway_stage.ts";
import {
  ensurePlacedMaintenance,
  type WorkerSession,
  type WorkflowIntent,
  type WorkflowRun,
} from "../extensions/behaviors/_lib/maintenance_worker.ts";
import {
  maintenanceInputs,
  maintenanceResult,
} from "../extensions/behaviors/_lib/swamp_maintenance.ts";
import { startFakeGateway } from "./_support/fake_gateway.ts";
import { WEBSITE_PROJECT } from "./_support/dependency_projects.ts";
import { standaloneMethodFixture } from "./_support/standalone_method_fixture.ts";

// The factory status/evidence boundary is a fixture. The deployed tick settings,
// driver, Gateway stage planner, worker startup, placement and result reader are real.
// maintenance_factory_test also runs detection with the actual official factory.
Deno.test("scheduled detect takes a ready provision through worker startup and records detection results without extra rotation waits", async () => {
  const maxSteps = groupRun.globalArguments.parse({
    factory: "dependency-detect",
    preapprovalModel: "orbit-driver-detect",
  }).maxSteps;
  const fake = startFakeGateway({ 7: { scriptNoChange: true } });
  const project = {
    ...WEBSITE_PROJECT,
    worker: { composerPaths: ["."], jsPaths: ["."], policy: {} },
  };
  const records = new Map<string, unknown>();
  let stage = "provision", clock = Date.parse("2026-10-27T15:00:00Z");
  let session: WorkerSession | null = null,
    intent: WorkflowIntent | null = null;
  let connected = false, workerStarts = 0, workflowStarts = 0;
  const http: Http = async (method, path, body, key) => {
    if (path === "/api/v1/instances/1") {
      return {
        status: 200,
        body: {
          data: {
            id: 1,
            project_id: 20,
            checkout_path: "/vm/repo",
            starting_commit: "a".repeat(40),
          },
        },
      };
    }
    if (path === "/api/v1/task-groups/7/worker") {
      workerStarts++;
      assertEquals(key, "tg-7.worker.c1");
      return {
        status: 202,
        body: { data: { id: 9000, poll_after_seconds: 1 } },
      };
    }
    if (path === "/api/v1/operations/9000") {
      return {
        status: 200,
        body: {
          data: {
            id: 9000,
            kind: "tasks.worker",
            target_type: "task_group",
            target_id: 7,
            status: "succeeded",
            result: { started: true },
          },
        },
      };
    }
    const r = await fetch(fake.url + path, {
      method,
      headers: {
        "Content-Type": "application/json",
        ...(key ? { "Idempotency-Key": key } : {}),
      },
      ...(body ? { body: JSON.stringify(body) } : {}),
    });
    return { status: r.status, body: await r.json() };
  };
  const report = {
    schema: 1,
    candidates: [{
      path: ".",
      manager: "composer",
      package: "fixture/pkg",
      current: "1.0.0",
      latest: "2.0.0",
      reasons: ["major"],
      advisories: [],
    }],
  };
  const worker = {
    http,
    url: "wss://fixture.invalid",
    now: () => clock,
    read: () => Promise.resolve(session),
    save: (next: WorkerSession) => {
      session = structuredClone(next);
      return Promise.resolve();
    },
    token: () =>
      Promise.resolve({
        state: "unused",
        expiresAt: "2099-01-01T00:00:00Z",
        vaultName: "fixture",
        secretKey: "enrollment",
      }),
    serverToken: () =>
      Promise.resolve({
        state: "active",
        expiresAt: "2099-01-01T00:00:00Z",
        vaultName: "fixture",
        secretKey: "server",
      }),
    mint: () => Promise.resolve(),
    mintServer: () => Promise.resolve(),
    revoke: () => Promise.resolve(),
    revokeServer: () => Promise.resolve(),
    secret: () => Promise.resolve("fixture-credential"),
    connected: () => Promise.resolve(connected),
  };
  const workflow = {
    read: () => Promise.resolve(intent),
    now: () => clock,
    save: (next: WorkflowIntent) => {
      intent = structuredClone(next);
      return Promise.resolve();
    },
    search: () => Promise.resolve([]),
    launch: (inputs: Record<string, unknown>) => {
      workflowStarts++;
      assertEquals(inputs.worker, "task-group-7-1");
      assertEquals(inputs.mode, "detect");
      return Promise.resolve("fixture-detect-run");
    },
    get: () =>
      Promise.resolve(
        {
          id: "fixture-detect-run",
          status: "succeeded",
          inputs: intent!.inputs,
          jobs: [{
            name: "maintenance",
            steps: [
              {
                name: "before",
                status: "succeeded",
                dataArtifacts: [{
                  name: "snapshot",
                  attributes: {
                    head: fake.groups.get(7)!.head,
                    tree: fake.groups.get(7)!.tree,
                  },
                }],
              },
              {
                name: "composer_detect",
                status: "succeeded",
                dataArtifacts: [{ name: "detection", attributes: report }],
              },
              {
                name: "js_detect",
                status: "succeeded",
                dataArtifacts: [{
                  name: "detection",
                  attributes: { schema: 1, candidates: [] },
                }],
              },
              {
                name: "after",
                status: "succeeded",
                dataArtifacts: [{
                  name: "snapshot",
                  attributes: {
                    head: fake.groups.get(7)!.head,
                    tree: fake.groups.get(7)!.tree,
                  },
                }],
              },
            ],
          }],
        } as WorkflowRun,
      ),
  };
  const stageCall = async (args: Parameters<typeof runStage>[0]) => {
    const key = `orbit-gateway/job-${args.key}`;
    const job = await runStage({ ...args, routine: true, detection: true }, {
      http,
      projects: [project],
      now: () => clock,
      readJob: () => Promise.resolve(records.get(key) as Job ?? null),
      writeJob: (job) => {
        records.set(key, structuredClone(job));
        return Promise.resolve();
      },
      maintenance: async (input, group, request) => {
        const run = await ensurePlacedMaintenance(
          group,
          maintenanceInputs(input.dependencyProject!, input, group, {
            ...request,
            mode: "detect",
          }),
          worker,
          workflow,
        );
        return run ? maintenanceResult(run) : { state: "running" };
      },
    });
    return job;
  };
  const context = {
    repoDir: "/fixture",
    definition: { name: "orbit-driver-detect" },
    globalArgs: driver.globalArguments.parse({
      factory: "dependency-detect",
      maxCallsPerTick: 60,
    }),
    logger: { info() {}, warning() {} },
    queryData: (predicate: string) => {
      const model = JSON.parse(predicate.match(/modelName == ("[^"]*")/)![1]);
      const name = JSON.parse(predicate.match(/name == ("[^"]*")/)![1]);
      return Promise.resolve(
        records.has(`${model}/${name}`)
          ? [records.get(`${model}/${name}`)]
          : [],
      );
    },
    writeResource: (
      _spec: string,
      name: string,
      value: Record<string, unknown>,
    ) => {
      records.set(`orbit-driver-detect/${name}`, value);
      return Promise.resolve({ name });
    },
    runModel: (
      call: {
        definition: string;
        method: string;
        arguments?: Record<string, unknown>;
      },
    ) => {
      const a = call.arguments!;
      if (call.definition === "orbit-gateway") {
        throw new Error(
          "maintenance work must use the standalone method boundary",
        );
      } else if (call.method === "status") {
        const evidence = records.get(
          `dependency-detect/evidence-tg-7-${stage}-run`,
        );
        records.set("dependency-detect/status-tg-7", {
          started: true,
          stage: { id: stage, cycle: 1, terminal: stage === "handoff" },
          dispatch: {
            required: true,
            executed: records.has(`dispatched-${stage}`),
          },
          unresolvedBindings: [],
          work: {
            mode: "method",
            resultEvidence: `${stage}-run`,
            method: {
              modelIdOrName: "orbit-gateway",
              methodName: "detect",
              inputs: { workItem: "tg-7", stage },
            },
          },
          transitions: stage === "provision"
            ? [{
              name: "ready",
              to: "script",
              satisfied: Boolean(evidence),
              manual: false,
              gates: [],
            }]
            : [],
        });
      } else if (call.method === "record_dispatch") {
        records.set(`dispatched-${stage}`, true);
      } else if (call.method === "record_artifact") {
        records.set(`artifact-${a.name}`, a.payload);
      } else if (call.method === "record_evidence") {
        records.set(`dependency-detect/evidence-tg-7-${a.name}`, {
          stageId: stage,
          cycle: 1,
          payload: a.payload,
        });
      } else if (call.method === "advance") stage = "script";
      else throw new Error(`Unexpected factory method ${call.method}`);
      return Promise.resolve({ ok: true });
    },
  };
  const method = standaloneMethodFixture((args) =>
    stageCall(args as unknown as Parameters<typeof runStage>[0])
  );
  try {
    // Reproduce a completed provision whose result the minute driver has not read yet.
    await stageCall({
      workItem: "tg-7",
      key: "tg-7.provision.c1",
      stage: "provision",
    });
    clock += 60000;
    await driver.methods.tick.execute(
      { workItems: ["tg-7"], maxSteps },
      context,
    );
    assertEquals(stage, "script");
    assertEquals(
      workerStarts,
      1,
      "a ready provision must reach worker startup in this visit",
    );
    assertEquals(
      workflowStarts,
      0,
      "wait for enrollment before dispatching detection",
    );
    connected = true;
    clock += 60000;
    await driver.methods.tick.execute(
      { workItems: ["tg-7"], maxSteps },
      context,
    );
    assertEquals(workflowStarts, 1);
    assertEquals(workerStarts, 1);
    const result = records.get("artifact-script") as Record<string, unknown>;
    assert(result);
    assertEquals(result.changed, false);
    assertEquals(result.detections, [report, { schema: 1, candidates: [] }]);
    assertEquals(fake.groups.get(7)!.tasks[0].status, "running");
    assertEquals(fake.startsOf(7, "provision"), 1);
    assertEquals(fake.startsOf(7, "publish"), 0);
    assertEquals(fake.startsOf(7, "repair"), 0);
  } finally {
    method.restore();
    await fake.close();
  }
});
