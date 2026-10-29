import {
  assert,
  assertEquals,
  assertRejects,
  assertThrows,
} from "jsr:@std/assert@1.0.19";
import {
  coalescePr,
  refreshLifecycle,
  type ResultStore,
  type ScanDependencies,
  scanProject,
} from "../extensions/behaviors/_lib/dependency_results.ts";
import {
  type Index,
  indexName,
  IndexSchema,
  type Pr,
  type Report,
  reportName,
  ReportSchema,
} from "../extensions/behaviors/_lib/dependency_results_schema.ts";
import { DependencyProjectSchema } from "../extensions/behaviors/_lib/dependency_projects.ts";
import {
  batchEvidence,
  BatchEvidenceSchema,
  reconcileDependencyIndex,
  resultStore,
} from "../extensions/behaviors/_lib/service_detection.ts";
import type { Findings } from "../extensions/behaviors/_lib/project_schedule.ts";
import type { Candidate } from "../extensions/behaviors/_lib/maintenance/batch.ts";
const A = "a".repeat(40), B = "b".repeat(40), time = "2026-10-28T01:00:00.000Z";
const project = DependencyProjectSchema.parse({
  projectId: 20,
  repository: "https://github.com/fixture/dependency-app.git",
  defaultBranch: "main",
  check: "composer check",
  worker: {
    composerPaths: ["."],
    jsPaths: ["mobile"],
    jsPackageManagers: { mobile: "bun" },
    policy: {},
  },
});
const resolution = {
  id: "example@1",
  name: "example/pkg",
  version: "1.0.0",
  direct: true,
  scopes: ["regular" as const],
  origin: "registry" as const,
};
function fixture() {
  const documents = new Map<string, Map<number, Report>>(),
    indexes: Index[] = [],
    writes: string[] = [],
    reads: [string, number][] = [];
  let current: Index | null = null, findings: Findings | null = null;
  const store: ResultStore = {
    readIndex: () => Promise.resolve(current),
    readReport: (name, version) => {
      reads.push([name, version]);
      const r = documents.get(name)?.get(version);
      if (!r) throw new Error("missing exact version");
      return Promise.resolve(structuredClone(r));
    },
    writeReport: (name, value) => {
      assert(!documents.has(name));
      documents.set(name, new Map([[7, structuredClone(value)]]));
      writes.push(name);
      return Promise.resolve(7);
    },
    writeIndex: (_name, value) => {
      current = structuredClone(IndexSchema.parse(value));
      indexes.push(current);
      writes.push("index");
      return Promise.resolve();
    },
  };
  const deps: ScanDependencies = {
    store,
    now: () => time,
    acquire: () =>
      Promise.resolve({
        root: "/fixture",
        commit: A,
        close: () => Promise.resolve(),
      }),
    detect: (_root, source) => {
      const p = source.ecosystem === "composer"
        ? resolution
        : { ...resolution, name: "example-pkg" };
      return Promise.resolve({
        packages: [p],
        candidates: [
          {
            path: source.path,
            manager: source.ecosystem === "composer" ? "composer" : "js",
            package: p.name,
            current: "1.0.0",
            latest: "2.0.0",
            reasons: ["major"],
            advisories: [],
          } as Candidate,
        ],
      });
    },
    plan: (_root, _project, candidates) =>
      Promise.resolve([{
        schema: 1,
        targets: candidates.map((c) => ({
          path: c.path,
          manager: c.manager,
          package: c.package,
          version: "2.0.0",
          purpose: "major",
          advisories: [],
          advisoryUrls: [],
        })),
        blocked: [],
      }]),
    pr: () =>
      Promise.resolve({
        status: "absent",
        checked_at: time,
        last_success_at: time,
        error_code: null,
        value: null,
      }),
    saveFindings: (v) => {
      findings = structuredClone(v);
      return Promise.resolve();
    },
  };
  return {
    deps,
    store,
    indexes,
    documents,
    reads,
    writes,
    findings: () => findings!,
  };
}

for (
  const name of [
    "success",
    "running-partial",
    "failed",
    "blocked",
    "pr-unknown",
  ]
) {
  Deno.test(`producer schemas accept frozen canonical fixture ${name}`, async () => {
    const fixture = JSON.parse(
      await Deno.readTextFile(
        new URL(
          `./fixtures/dependency-results-v1/${name}.json`,
          import.meta.url,
        ),
      ),
    );
    for (const snapshot of fixture.snapshots) {
      IndexSchema.parse(
        JSON.parse(snapshot.index.response.payload.data.content),
      );
      for (const report of snapshot.reports) {
        ReportSchema.parse(JSON.parse(report.response.payload.data.content));
      }
    }
  });
}
Deno.test("successful VM-free scan publishes immutable exact-version reports before one coherent index and complete plan", async () => {
  const f = fixture(),
    index = await scanProject(project, "run-success", f.deps);
  assertEquals(index.last_attempt.status, "succeeded");
  assertEquals(index.source_commit, A);
  assertEquals(index.sources.length, 2);
  assert(index.sources.every((s) => s.report?.version === 7));
  assertEquals(f.indexes.map((i) => i.last_attempt.status), [
    "running",
    "running",
    "succeeded",
  ]);
  assertEquals(f.writes.at(-1), "index");
  assertEquals(f.findings().targets.length, 2);
  assertEquals(f.findings().sourceCommit, A);
  assertEquals(
    await indexName(20, "refs/heads/main"),
    "dependencies-v1-project-20-f921bd05e68b03740c450e565e0e6173e546193170b2dd404ddb6f153e9b5bf3",
  );
});
Deno.test("partial/running refresh retains original report commit/time/version and revokes all batch eligibility", async () => {
  const f = fixture(), good = await scanProject(project, "old", f.deps);
  f.deps.acquire = () =>
    Promise.resolve({
      root: "/fixture",
      commit: B,
      close: () => Promise.resolve(),
    });
  const detect = f.deps.detect;
  f.deps.detect = (root, source, project) =>
    source.ecosystem === "composer"
      ? Promise.reject(new Error("SECRET /private/token"))
      : detect(root, source, project);
  const partial = await scanProject(project, "new", f.deps);
  assertEquals(partial.last_attempt.status, "partial");
  assertEquals(partial.sources[0].report, good.sources[0].report);
  assertEquals(partial.sources[1].report!.source_commit, B);
  const report = await f.store.readReport(
    partial.sources[1].report!.dataName,
    7,
  );
  assertEquals(report.findings[0].reason.code, "sibling_source_failed");
  assertEquals(f.findings().targets, []);
  assert(!JSON.stringify(partial).includes("SECRET"));
  assertEquals(f.indexes[4].sources[0].report, good.sources[0].report);
});
Deno.test("first acquisition failure is unavailable inventory, never empty successful reports", async () => {
  const f = fixture();
  f.deps.acquire = () => Promise.reject(new Error("private failure"));
  const i = await scanProject(project, "failed", f.deps);
  assertEquals(i.source_commit, null);
  assertEquals(i.last_attempt.status, "failed");
  assert(
    i.sources.every((s) =>
      s.report === null && s.last_attempt.status === "failed"
    ),
  );
  assertEquals(f.documents.size, 0);
  assertEquals(f.findings().targets, []);
});
Deno.test("same-head advisory refresh is never suppressed by yesterday's receipt", async () => {
  const f = fixture(), a = await scanProject(project, "one", f.deps);
  const i = await scanProject(project, "two", f.deps);
  assertEquals(i.source_commit, a.source_commit);
  assert(i.sources[0].report!.dataName !== a.sources[0].report!.dataName);
  assertEquals(i.last_attempt.run_id, "two");
});
Deno.test("finding without verified target is blocked data, not an admission target", async () => {
  const f = fixture();
  f.deps.plan = () =>
    Promise.resolve([{
      schema: 1,
      targets: [],
      blocked: [{
        path: ".",
        manager: "composer",
        package: resolution.name,
        reason: "raw /private/solver",
      }],
    }]);
  const i = await scanProject(project, "blocked", f.deps);
  assertEquals(i.last_attempt.status, "succeeded");
  const r = await f.store.readReport(i.sources[0].report!.dataName, 7);
  assertEquals(r.findings[0].target_version, null);
  assertEquals(r.findings[0].state, "blocked");
  assert(!JSON.stringify(r).includes("/private/solver"));
  assertEquals(f.findings().targets, []);
});

Deno.test("an unsupported declared alias publishes actual inventory identity and a blocked finding", async () => {
  const f = fixture();
  f.deps.detect = (_root, s) =>
    Promise.resolve({
      packages: [{ ...resolution, name: "@voidzero-dev/vite-plus-core" }],
      candidates: [{
        path: s.path,
        manager: s.ecosystem === "composer" ? "composer" : "js",
        package: "vite",
        current: "1.0.0",
        latest: "2.0.0",
        reasons: ["major"],
        advisories: [],
      }],
      identities: { vite: "@voidzero-dev/vite-plus-core" },
    });
  f.deps.plan = () =>
    Promise.resolve([{ schema: 1, targets: [], blocked: [] }]);
  const index = await scanProject(project, "alias", f.deps);
  assertEquals(index.last_attempt.status, "succeeded");
  const r = await f.store.readReport(index.sources[1].report!.dataName, 7);
  assertEquals(r.findings[0].package, "@voidzero-dev/vite-plus-core");
  assertEquals(r.findings[0].state, "blocked");
  assertEquals(r.findings[0].target_version, null);
});
Deno.test("overflow fails the source and preserves its last-good pointer without truncating rows", async () => {
  const f = fixture(), good = await scanProject(project, "good", f.deps);
  f.deps.detect = () =>
    Promise.resolve({
      packages: Array.from(
        { length: 5001 },
        (_, n) => ({ ...resolution, id: String(n) }),
      ),
      candidates: [],
    });
  const i = await scanProject(project, "overflow", f.deps);
  assertEquals(i.last_attempt.status, "failed");
  assertEquals(
    i.sources.map((s) => s.report),
    good.sources.map((s) => s.report),
  );
  assertEquals(f.findings().targets, []);
});
Deno.test("configuration over 64 sources refuses work before acquisition", async () => {
  const f = fixture();
  f.deps.acquire = () => {
    throw new Error("should not acquire");
  };
  await assertRejects(
    () =>
      scanProject(
        {
          ...project,
          worker: {
            ...project.worker,
            composerPaths: Array.from({ length: 65 }, (_, i) => `dir-${i}`),
            jsPaths: [],
          },
        },
        "config",
        f.deps,
      ),
    Error,
    "source_configuration_limit",
  );
});

Deno.test("oversized refresh index retains the complete old snapshot as explicit failure and clears targets", async () => {
  const f = fixture(), good = await scanProject(project, "old", f.deps);
  const next = {
    ...project,
    worker: {
      ...project.worker,
      composerPaths: Array.from(
        { length: 64 },
        (_, i) => `dir-${i}-` + "x".repeat(1000),
      ),
      jsPaths: [],
    },
  };
  const failed = await scanProject(next, "overflow-index", f.deps);
  assertEquals(failed.last_attempt.status, "failed");
  assertEquals(failed.last_attempt.error_code, "index_publication_limit");
  assertEquals(
    failed.sources.map((s) => s.report),
    good.sources.map((s) => s.report),
  );
  assertEquals(f.findings().targets, []);
});
Deno.test("lifecycle publication reads exact versions and retains scan commit/time/run and old reports", async () => {
  const f = fixture(),
    index = await scanProject(project, "scan", f.deps),
    pointer = index.sources[0].report!;
  const before = await f.store.readReport(pointer.dataName, pointer.version);
  const updated = await refreshLifecycle(
    index,
    index.batch_pr,
    [{
      group_id: 2195,
      run_id: "batch-native-run",
      targets: f.findings().targets,
    }],
    f.store,
    time,
  );
  const after = await f.store.readReport(
    updated.sources[0].report!.dataName,
    7,
  );
  assertEquals(after.observed_at, before.observed_at);
  assertEquals(after.run_id, before.run_id);
  assertEquals(after.packages, before.packages);
  assertEquals(after.source_commit, before.source_commit);
  assert(after.publication_id !== before.publication_id);
  assertEquals(after.findings[0].state, "in_batch");
  assertEquals(
    await f.store.readReport(pointer.dataName, pointer.version),
    before,
  );
  assert(
    f.reads.some(([name, version]) =>
      name === pointer.dataName && version === pointer.version
    ),
  );
  assertEquals(
    await reportName(after.publication_id, after),
    updated.sources[0].report!.dataName,
  );
});
Deno.test("native adapter rejects duplicate current indexes and never falls back to latest report", async () => {
  const f = fixture(),
    index = await scanProject(project, "scan", f.deps),
    requested: unknown[] = [];
  const context = {
    definition: { name: "orbit-gateway" },
    queryData: () => Promise.resolve([index, index]),
    readResource: (name: string, version?: number) => {
      requested.push([name, version]);
      return Promise.resolve(null);
    },
    writeResource: () => Promise.resolve({ name: "fixture", version: 1 }),
    runModel: () => Promise.resolve({ ok: true }),
  };
  const store = resultStore(context);
  await assertRejects(
    () => store.readIndex("index"),
    Error,
    "duplicate_dependency_index",
  );
  await assertRejects(() => store.readReport("exact-report", 7));
  assertEquals(requested, [["exact-report", 7]]);
});

Deno.test("pointer expansion overflow publishes bounded failed state instead of leaving a running scan", async () => {
  const f = fixture();
  const p = {
    ...project,
    worker: {
      ...project.worker,
      composerPaths: Array.from(
        { length: 64 },
        (_, i) => `p-${i}-` + "x".repeat(500),
      ),
      jsPaths: [],
    },
  };
  const index = await scanProject(p, "pointer-growth", f.deps);
  assertEquals(index.last_attempt.status, "failed");
  assertEquals(index.last_attempt.error_code, "index_publication_limit");
  assertEquals(index.sources.length, 64);
  assert(
    index.sources.every((s) =>
      s.report === null && s.last_attempt.status === "failed"
    ),
  );
  assertEquals(f.findings().targets, []);
  assert(JSON.stringify(index).length <= 65536);
});
Deno.test("PR lookup failure retains last success/value, missing group fields are unknown", async () => {
  const value = {
    repository: "github.com/fixture/dependency-app",
    number: 7,
    url: "https://github.com/fixture/dependency-app/pull/7",
    head_commit: A,
    group_id: 10,
    run_id: "batch",
  };
  const previous: Pr = {
    status: "open",
    checked_at: time,
    last_success_at: time,
    error_code: null,
    value,
  };
  const ctx = {
    definition: { name: "orbit-gateway" },
    queryData: (q: string) =>
      Promise.resolve(
        q.includes('startsWith("package-')
          ? [{ workItem: "tg-10", targets: [] }]
          : [],
      ),
    writeResource: () => Promise.resolve({ name: "fixture", version: 1 }),
    runModel: () => Promise.resolve({ ok: true }),
  };
  const e = await batchEvidence(
    project,
    previous,
    ctx,
    () =>
      Promise.resolve({
        status: 200,
        body: { data: [{ id: 10, project_id: 20, status: "running" }] },
      }),
  );
  assertEquals(e.pr.status, "unknown");
  assertEquals(e.pr.value, value);
  assertEquals(e.pr.last_success_at, time);
  const absence = await batchEvidence(
    project,
    previous,
    ctx,
    () => Promise.resolve({ status: 200, body: { data: [] } }),
  );
  assertEquals(absence.pr.status, "absent");
  assertEquals(absence.pr.value, null);
});
Deno.test("producer never accepts unsafe local locked versions or duplicate report IDs", async () => {
  const f = fixture(),
    index = await scanProject(project, "scan", f.deps),
    r = await f.store.readReport(index.sources[0].report!.dataName, 7);
  assertThrows(() =>
    ReportSchema.parse({ ...r, packages: [r.packages[0], r.packages[0]] })
  );
});

Deno.test("PR observation verifies exact URL/head/native run, including a still-open PR on a failed group", async () => {
  const url = "https://github.com/fixture/dependency-app/pull/17",
    calls: string[] = [];
  const ctx = {
    definition: { name: "orbit-gateway" },
    queryData: (q: string) =>
      Promise.resolve(
        q.includes('startsWith("package-')
          ? [{ workItem: "tg-2195", targets: [] }]
          : q.includes('name == "owner"')
          ? [{ runId: "native-batch-run" }]
          : [{ state: "succeeded", artifacts: { "pull-request": { url } } }],
      ),
    writeResource: () => Promise.resolve({ name: "fixture", version: 1 }),
    runModel: () => Promise.resolve({ ok: true }),
  };
  const http = () =>
    Promise.resolve({
      status: 200,
      body: {
        data: [{
          id: 2195,
          project_id: 20,
          status: "failed",
          pr_url: url,
          watched_pr_url: url,
        }],
      },
    });
  const e = await batchEvidence(project, null, ctx, http, (value) => {
    calls.push(value);
    return Promise.resolve({
      number: 17,
      url: value,
      state: "OPEN",
      headRefOid: A,
    });
  });
  assertEquals(e.pr.status, "open");
  assertEquals(e.pr.value!.head_commit, A);
  assertEquals(e.pr.value!.run_id, "native-batch-run");
  assertEquals(calls, [url]);
  const unknown = await batchEvidence(
    project,
    e.pr,
    ctx,
    http,
    () => Promise.reject(new Error("not found")),
  );
  assertEquals(unknown.pr.status, "unknown");
  assertEquals(unknown.pr.value, e.pr.value);
  assertEquals(unknown.pr.last_success_at, e.pr.last_success_at);
});

Deno.test("planner failure retains fresh inventory with a separate reason and no admission", async () => {
  const f = fixture();
  f.deps.plan = () => Promise.reject(new Error("solver /private/error"));
  const index = await scanProject(project, "plan-failed", f.deps);
  assertEquals(index.last_attempt.status, "failed");
  assert(index.sources.every((s) => s.report?.source_commit === A));
  const report = await f.store.readReport(index.sources[0].report!.dataName, 7);
  assertEquals(report.findings[0].reason.code, "project_planning_failed");
  assertEquals(f.findings().targets, []);
});
Deno.test("unchanged PR certainty does not create indexes until four minutes, failures publish immediately", async () => {
  const f = fixture(),
    index = await scanProject(project, "scan", f.deps),
    n = f.indexes.length;
  const next = {
    ...index.batch_pr,
    checked_at: "2026-10-28T01:03:59.000Z",
    last_success_at: "2026-10-28T01:03:59.000Z",
  };
  const unchanged = await refreshLifecycle(
    index,
    next,
    [],
    f.store,
    next.checked_at,
  );
  assertEquals(unchanged, index);
  assertEquals(f.indexes.length, n);
  const due = {
    ...next,
    checked_at: "2026-10-28T01:04:00.000Z",
    last_success_at: "2026-10-28T01:04:00.000Z",
  };
  await refreshLifecycle(index, due, [], f.store, due.checked_at);
  assertEquals(f.indexes.length, n + 1);
  assertEquals(
    coalescePr(index.batch_pr, {
      ...next,
      status: "unknown",
      error_code: "batch_pr_lookup_failed",
      last_success_at: time,
    }).status,
    "unknown",
  );
});
Deno.test("first scan immediately links existing batch targets in reports", async () => {
  const f = fixture();
  f.deps.batchLinks = () => [{
    group_id: 2195,
    run_id: "batch",
    targets: [{
      path: ".",
      manager: "composer",
      package: resolution.name,
      version: "2.0.0",
    }],
  }];
  const index = await scanProject(project, "scan", f.deps),
    report = await f.store.readReport(index.sources[0].report!.dataName, 7);
  assertEquals(report.findings[0].state, "in_batch");
  assertEquals(report.findings[0].links, { group_id: 2195, run_id: "batch" });
});
Deno.test("500 findings are accepted and 501 fail closed", async () => {
  const f = fixture(),
    index = await scanProject(project, "bounds", f.deps),
    report = await f.store.readReport(index.sources[0].report!.dataName, 7);
  const findings = Array.from(
    { length: 500 },
    (_, i) => ({ ...report.findings[0], id: `major:${i}` }),
  );
  ReportSchema.parse({ ...report, findings });
  assertThrows(() =>
    ReportSchema.parse({
      ...report,
      findings: [...findings, { ...findings[0], id: "overflow" }],
    })
  );
});
Deno.test("report UTF-8 byte limit rejects over one MiB below the package count limit", async () => {
  const f = fixture(),
    index = await scanProject(project, "bytes", f.deps),
    report = await f.store.readReport(index.sources[0].report!.dataName, 7);
  const packages = Array.from(
    { length: 2000 },
    (_, i) => ({
      ...resolution,
      id: `${i}-` + "x".repeat(500),
      name: "x".repeat(500),
      version: "1".repeat(250),
    }),
  );
  assert(
    new TextEncoder().encode(
      JSON.stringify({ ...report, packages, findings: [] }),
    ).length > 1048576,
  );
  assertThrows(() => ReportSchema.parse({ ...report, packages, findings: [] }));
  ReportSchema.parse({
    ...report,
    packages: packages.slice(0, 500),
    findings: [],
  });
});

Deno.test("minute reconciliation ends an interrupted running attempt and preserves exact last-good reports", async () => {
  const f = fixture(),
    good = await scanProject(project, "previous", f.deps),
    start = new Date(Date.now() - 240000).toISOString();
  const running = {
    run_id: "interrupted",
    started_at: start,
    finished_at: null,
    status: "running" as const,
    error_code: null,
  };
  const stale = IndexSchema.parse({
    ...good,
    last_attempt: running,
    sources: good.sources.map((s) => ({ ...s, last_attempt: running })),
  });
  let saved = stale;
  const ctx = {
    definition: { name: "orbit-gateway" },
    queryData: () => Promise.resolve([]),
    readResource: (name: string, version?: number) =>
      f.store.readReport(name, version!),
    writeResource: (
      _spec: string,
      _name: string,
      value: Record<string, unknown>,
    ) => {
      saved = IndexSchema.parse(value);
      return Promise.resolve({ name: "index", version: 1 });
    },
    runModel: () => Promise.resolve({ ok: true }),
  };
  await reconcileDependencyIndex(
    stale,
    ctx,
    {
      pr: {
        status: "absent",
        checked_at: time,
        last_success_at: time,
        error_code: null,
        value: null,
      },
      links: [],
    },
  );
  assertEquals(saved.last_attempt.status, "failed");
  assertEquals(saved.last_attempt.error_code, "scan_interrupted");
  assertEquals(
    saved.sources.map((s) => s.report),
    good.sources.map((s) => s.report),
  );
});

Deno.test("cancelled PR observation starts no Gateway or GitHub lookup", async () => {
  let calls = 0;
  const ctx = {
    definition: { name: "orbit-gateway" },
    signal: AbortSignal.abort(),
    queryData: () => Promise.resolve([]),
    writeResource: () => Promise.resolve({ name: "unused" }),
    runModel: () => Promise.resolve({ ok: true }),
  };
  const evidence = await batchEvidence(project, null, ctx, () => {
    calls++;
    return Promise.resolve({ status: 200, body: { data: [] } });
  }, () => {
    calls++;
    return Promise.resolve({});
  });
  assertEquals(calls, 0);
  assertEquals(evidence.pr.status, "unknown");
});

Deno.test("PR refresh projects real immutable batch targets into its publication contract", async () => {
  const target = {
    path: ".",
    manager: "js",
    package: "puppeteer",
    version: "25.15.0",
    purpose: "security",
    advisories: ["1243066"],
    advisoryUrls: ["https://github.com/advisories/GHSA-mrhx-c7xc-m3w5"],
  };
  const ctx = {
    definition: { name: "orbit-gateway" },
    queryData: (q: string) =>
      Promise.resolve(
        q.includes('startsWith("package-')
          ? [{ workItem: "tg-2195", targets: [target] }]
          : q.includes('name == "owner"')
          ? [{ runId: "batch-run" }]
          : [],
      ),
    writeResource: () => Promise.resolve({ name: "fixture", version: 1 }),
    runModel: () => Promise.resolve({ ok: true }),
  };
  const evidence = await batchEvidence(
    project,
    null,
    ctx,
    () =>
      Promise.resolve({
        status: 200,
        body: {
          data: [{
            id: 2195,
            project_id: 20,
            status: "running",
            pr_url: null,
            watched_pr_url: null,
          }],
        },
      }),
  );
  assertEquals(evidence.pr.status, "absent");
  assertEquals(BatchEvidenceSchema.parse(evidence).links[0].targets, [{
    path: ".",
    manager: "js",
    package: "puppeteer",
    version: "25.15.0",
  }]);
  assertEquals(target.purpose, "security");
  assertEquals(target.advisories, ["1243066"]);
});
