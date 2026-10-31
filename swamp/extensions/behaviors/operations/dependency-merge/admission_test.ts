import { recordQuery } from "../../../../tests/fixtures/worker_query.ts";
import { batchReviewWait } from "./review.ts";
import type { Job } from "../../_lib/gateway_stage.ts";
import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import {
  MergeConfiguration,
  mergeConfigurationMethod,
} from "./configuration.ts";
import { admitBatchMerge } from "./admission.ts";
import { DependencyProjectSchema } from "../../_lib/dependency_project_schema.ts";
const head = "a".repeat(40);
function admissionFixture() {
  const project = DependencyProjectSchema.parse({
    projectId: 20,
    repository: "https://github.com/acme/widgets.git",
    defaultBranch: "main",
    check: "composer check",
    dependencyAutoMerge: true,
    productionSmoke: {
      instance: 757,
      baseUrl: "https://example.test",
      workflow: ".github/workflows/production-smoke.yml",
      ref: "main",
    },
    worker: { composerPaths: ["."], jsPaths: [], policy: {} },
  });
  const now = Date.parse("2026-10-28T20:00:00Z"),
    checked = new Date(now).toISOString();
  const observation = {
    status: "open",
    checked_at: checked,
    last_success_at: checked,
    error_code: null,
    value: {
      repository: "github.com/acme/widgets",
      number: 3,
      url: "https://github.com/acme/widgets/pull/3",
      head_commit: head,
      group_id: 91,
      run_id: "run-91",
    },
  };
  const target = {
    workItem: "tg-91",
    targets: [{
      path: ".",
      manager: "composer",
      package: "acme/library",
      version: "1.1.0",
      purpose: "security" as "major" | "security",
      advisories: ["GHSA-fixture"],
      advisoryUrls: ["https://github.com/advisories/GHSA-fixture"],
    }],
  };
  const owner = { runId: "run-91" },
    job = {
      stage: "publish",
      group: 91,
      state: "succeeded",
      operationId: 8,
      artifacts: {
        "pull-request": { number: 3, url: observation.value.url, commit: head },
      },
    };
  const stored = new Map<string, Record<string, unknown>>(),
    posts: { key: string | undefined; body: unknown }[] = [];
  let lost = false, projectType = "laravel-app", refused = false;
  const ctx = {
    queryData: recordQuery((predicate: string) =>
      Promise.resolve(
        predicate.includes('name == "package-')
          ? [target]
          : predicate.includes('name == "owner"')
          ? [owner]
          : predicate.includes('name.startsWith("job-')
          ? [job]
          : [...stored.values()],
      )
    ),
    writeResource: (
      _spec: string,
      name: string,
      value: Record<string, unknown>,
    ) => {
      stored.set(name, structuredClone(value));
      return Promise.resolve({ name });
    },
  };
  const http: Parameters<typeof admitBatchMerge>[3] = async (
    method,
    _path,
    body,
    key,
  ) => {
    await Promise.resolve();
    if (method === "GET") {
      return {
        status: 200,
        body: {
          data: {
            id: 20,
            type: projectType,
            repository_url: project.repository,
            default_branch: "main",
          },
        },
      };
    }
    posts.push({ key, body });
    if (lost) {
      lost = false;
      throw new Error("lost admission reply");
    }
    return refused
      ? {
        status: 409,
        body: { error: { code: "dependency_merge.checks_not_passed" } },
      }
      : {
        status: 202,
        body: {
          data: {
            id: 9,
            kind: "tasks.dependency_merge",
            target_type: "task_group",
            target_id: 91,
            input: { head_sha: head, publish_operation_id: 8 },
          },
        },
      };
  };
  return {
    project,
    observation,
    target,
    owner,
    job,
    stored,
    posts,
    ctx,
    http,
    now,
    lose: () => {
      lost = true;
    },
    package: () => {
      projectType = "laravel-package";
    },
    refuse: () => {
      refused = true;
    },
    run: () => admitBatchMerge(project, observation, ctx, http, () => now),
  };
}
Deno.test("switches default false and disabled Projects never POST", async () => {
  const f = admissionFixture();
  f.project.dependencyAutoMerge = false;
  await f.run();
  assertEquals(f.posts, []);
  const { dependencyAutoMerge: _removed, ...base } = f.project;
  assertEquals(DependencyProjectSchema.parse(base).dependencyAutoMerge, false);
});
Deno.test("merge admission persists exact intent before POST and retains one operation", async () => {
  const f = admissionFixture();
  await f.run();
  await f.run();
  assertEquals(f.posts.length, 1);
  assertEquals(f.posts[0], {
    key: `dependency-merge.91.${head}`,
    body: { publish_operation_id: 8, head_sha: head },
  });
  assertEquals([...f.stored.values()][0].operation_id, 9);
});
Deno.test("lost admission reply retries exact key and body", async () => {
  const f = admissionFixture();
  f.lose();
  await assertRejects(() => f.run());
  await f.run();
  assertEquals(f.posts.length, 2);
  assertEquals(f.posts[0], f.posts[1]);
});
Deno.test("pending CI admission refusal remains visible", async () => {
  const f = admissionFixture();
  f.refuse();
  await f.run();
  assertEquals(
    [...f.stored.values()][0].reason,
    "dependency_merge.checks_not_passed",
  );
});
for (
  const invalid of [
    "package",
    "owner",
    "publication",
    "target",
    "stale",
  ] as const
) {
  Deno.test(`reject ${invalid} merge candidate before POST`, async () => {
    const f = admissionFixture();
    if (invalid === "package") f.package();
    if (invalid === "owner") f.owner.runId = "foreign";
    if (invalid === "publication") {
      f.job.artifacts["pull-request"].commit = "c".repeat(40);
    }
    if (invalid === "target") f.target.workItem = "tg-92";
    if (invalid === "stale") {
      f.observation.checked_at = new Date(
        f.now - 61_000,
      ).toISOString();
    }
    if (invalid === "stale") {
      f.observation.last_success_at = f.observation.checked_at;
      await f.run();
    } else await assertRejects(() => f.run());
    assertEquals(f.posts, []);
  });
}

Deno.test("execution configuration refresh runs the private extension again", async () => {
  const f = admissionFixture();
  let enabled = true, reads = 0;
  const ctx = {
    globalArgs: {
      dependencyProjects: [],
      projectExtensions: [{ projectId: 20, type: "@nckrtl/project-widgets" }],
    },
    invokeModel: () => {
      reads++;
      return Promise.resolve({ ok: true });
    },
    queryData: recordQuery(() =>
      Promise.resolve([{
        schema: 3,
        ...f.project,
        dependencyAutoMerge: enabled,
      }])
    ),
    writeResource: (
      _spec: string,
      _name: string,
      value: Record<string, unknown>,
    ) => {
      f.stored.set("config", value as never);
      return Promise.resolve({ name: "config" });
    },
  };
  await mergeConfigurationMethod.execute({ projectId: 20 }, ctx);
  assertEquals(MergeConfiguration.parse(f.stored.get("config")).enabled, true);
  enabled = false;
  await mergeConfigurationMethod.execute({ projectId: 20 }, ctx);
  assertEquals(MergeConfiguration.parse(f.stored.get("config")).enabled, false);
  assertEquals(reads, 2);
});

Deno.test("major batches stay open without posting merge admission", async () => {
  const f = admissionFixture();
  f.target.targets[0].purpose = "major";
  f.target.targets[0].advisories = [];
  f.target.targets[0].advisoryUrls = [];
  await f.run();
  assertEquals(f.posts, []);
  assertEquals([...f.stored.values()][0].reason, "dependency_merge.major_bump");
});
Deno.test("agent-repaired batches use the same new-head publication gate", async () => {
  const f = admissionFixture();
  const repaired = "c".repeat(40);
  f.observation.value.head_commit = repaired;
  f.job.artifacts["pull-request"].commit = repaired;
  f.job.operationId = 12;
  await f.run();
  assertEquals(f.posts[0], {
    key: `dependency-merge.91.${repaired}`,
    body: { publish_operation_id: 12, head_sha: repaired },
  });
});
Deno.test("unmerged batch development Instances never enter the parking path", async () => {
  const f = admissionFixture();
  const job = { workItem: "tg-91", state: "running", stage: "merged" } as Job;
  let parks = 0;
  const deps = {
    targets: () => Promise.resolve([f.target]),
    park: () => {
      parks++;
      return Promise.resolve(job);
    },
  };
  assertEquals(await batchReviewWait(job, deps), job);
  f.target.targets[0].purpose = "major";
  f.target.targets[0].advisories = [];
  f.target.targets[0].advisoryUrls = [];
  assertEquals(await batchReviewWait(job, deps), job);
  assertEquals(parks, 0);
  await batchReviewWait(job, { ...deps, targets: () => Promise.resolve([]) });
  assertEquals(parks, 1);
});
