import { assert, assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.11";
import {
  type Http,
  type Job,
  runStage,
  type StageArgs,
} from "../extensions/behaviors/_lib/gateway_stage.ts";
import { maintenanceResult } from "../extensions/behaviors/_lib/swamp_maintenance.ts";
import { behavior as gateway } from "../extensions/behaviors/orbit_gateway.ts";
import { WEBSITE_PROJECT } from "./_support/dependency_projects.ts";
import { startFakeGateway } from "./_support/fake_gateway.ts";

const scan = {
  subtask: 701,
  outcome: "passed",
  exit_code: 0,
  changed: false,
  head: "a".repeat(40),
  tree: "b".repeat(40),
  detections: [{ schema: 1, candidates: [] }, { schema: 1, candidates: [] }],
};
const envelope = (payload: Record<string, unknown>) => ({
  payload,
  recordedAt: "2026-10-27T16:00:00Z",
});
function fixture() {
  // Project check cannot run in this checkout. Detection must never request it.
  const fake = startFakeGateway({ 7: { handoffAlwaysFails: true } });
  const group = fake.groups.get(7)!;
  group.status = "running";
  group.taskable_id = 1;
  group.vm_power = "running";
  group.tasks[0].status = "running";
  group.tasks[0].deliverables = [{
    id: "detect",
    type: "review",
    description: "Scan reports and unchanged snapshots.",
  }];
  const calls: { method: string; path: string }[] = [];
  const jobs = new Map<string, Job>();
  let revokes = 0;
  const http: Http = async (method, path, body, key) => {
    calls.push({ method, path });
    if (method === "POST" && /\/(check|turn|publish)$/.test(path)) {
      throw new Error("Detect attempted a check, repair turn or publication");
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
  const execute = (stage: StageArgs["stage"], payload = scan) => {
    const args: StageArgs = {
      workItem: "tg-7",
      key: `tg-7.${stage}.c1`,
      stage,
      routine: true,
      detection: true,
      script: envelope(payload),
    };
    return runStage(args, {
      http,
      projects: [WEBSITE_PROJECT],
      readJob: () => Promise.resolve(jobs.get(args.key) ?? null),
      writeJob: (job) => {
        jobs.set(args.key, structuredClone(job));
        return Promise.resolve();
      },
      revokeWorker: () => {
        revokes++;
        return Promise.resolve();
      },
    });
  };
  return { fake, group, calls, execute, revokes: () => revokes };
}

Deno.test("read-only detect completes without installing dependencies or running the project check", async () => {
  const f = fixture();
  try {
    const done = await f.execute("finish");
    assertEquals(done.state, "succeeded");
    assertEquals(done.evidence?.completed, true);
    assertEquals(f.group.status, "completed");
    assertEquals(f.group.tasks[0].status, "completed");
    assertEquals(f.group.vm_power, "destroyed");
    assertEquals(f.revokes(), 1);
    assertEquals(f.fake.operations.size, 0);
    assert(f.calls.some((c) => c.path.endsWith("/complete")));
    const mutations = f.calls.filter((c) => c.method !== "GET").length;
    await f.execute("finish");
    assertEquals(f.calls.filter((c) => c.method !== "GET").length, mutations);
  } finally {
    await f.fake.close();
  }
});

Deno.test("detect rejects failed, changed, incomplete or foreign scan evidence without completing work", async () => {
  for (
    const bad of [
      { outcome: "failed" },
      { exit_code: 127 },
      { changed: true },
      { subtask: 702 },
      { tree: "invalid" },
      { detections: [] },
      { detections: [{ schema: 1, candidates: [] }] },
      {
        detections: [{ schema: 2, candidates: [] }, {
          schema: 1,
          candidates: [],
        }],
      },
    ]
  ) {
    const f = fixture();
    try {
      await assertRejects(() => f.execute("finish", { ...scan, ...bad }));
      assertEquals(f.group.tasks[0].status, "running");
      assertEquals(f.group.status, "running");
      assertEquals(f.revokes(), 0);
      assert(!f.calls.some((c) => c.method !== "GET"));
    } finally {
      await f.fake.close();
    }
  }
});

Deno.test("detect completion preserves operator assistance, ended groups and an existing PR", async () => {
  for (
    const change of [
      { assistance_requested: true },
      { status: "failed" },
      { status: "cancelled" },
      { watched_pr_url: "https://example.test/pr/1" },
    ]
  ) {
    const f = fixture();
    try {
      Object.assign(f.group, change);
      await assertRejects(() => f.execute("finish"));
      assertEquals(f.revokes(), 0);
      assert(!f.calls.some((c) => c.method !== "GET"));
    } finally {
      await f.fake.close();
    }
  }
});

Deno.test("no detect entry point can start a check, agent turn or publication", async () => {
  const f = fixture();
  try {
    for (
      const stage of [
        "handoff",
        "repair",
        "implement",
        "review",
        "publish",
        "baseline",
      ] as const
    ) {
      await assertRejects(() => f.execute(stage), Error, "detect permits only");
      assertEquals(
        gateway.methods.detect.arguments.safeParse({
          workItem: "tg-7",
          stage,
          key: `tg-7.${stage}.c1`,
        }).success,
        false,
      );
    }
    assertEquals(f.calls, []);
    const definition = parse(
      await Deno.readTextFile(
        new URL(
          "../models/@swamp/software-factory/dependency-detect.yaml",
          import.meta.url,
        ),
      ),
    ) as {
      globalArguments: {
        stages: { id: string; transitions?: { to: string }[] }[];
      };
    };
    const stages = definition.globalArguments.stages;
    assertEquals(stages.map((s) => s.id), [
      "provision",
      "script",
      "finish",
      "assistance",
      "completed",
      "failed",
    ]);
    assert(
      stages.find((s) => s.id === "script")!.transitions!.some((t) =>
        t.to === "finish"
      ),
    );
    assert(
      !stages.some((s) => ["handoff", "repair", "publish"].includes(s.id)),
    );
  } finally {
    await f.fake.close();
  }
});

Deno.test("a changed HEAD fails the read-only snapshot invariant even when its tree is identical", async () => {
  const run = JSON.parse(
    await Deno.readTextFile(
      new URL("./fixtures/maintenance-worker/run.json", import.meta.url),
    ),
  );
  const before = run.jobs[0].steps[0].dataArtifacts[0].attributes;
  const after = run.jobs[0].steps.at(-1).dataArtifacts[0].attributes;
  after.head = "d".repeat(40);
  after.tree = before.tree;
  assertEquals(maintenanceResult(run).result?.changed, true);
});
