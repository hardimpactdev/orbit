import { assert, assertEquals } from "jsr:@std/assert@1.0.19";

// Canonical producer/reader handoff: check the wire bytes and cross-document
// invariants before either implementation consumes these fixtures.
const directory = new URL("./fixtures/dependency-results-v1/", import.meta.url);
const names = ["success", "running-partial", "failed", "blocked", "pr-unknown"];
const bytes = (s: string) => new TextEncoder().encode(s);
async function sha256(s: string) {
  return [...new Uint8Array(await crypto.subtle.digest("SHA-256", bytes(s)))]
    .map((n) => n.toString(16).padStart(2, "0")).join("");
}
// Raw wire records are checked below before either side has trusted types.
// deno-lint-ignore no-explicit-any
type Json = Record<string, any>;
const identity = (v: Json) => [v.path, v.ecosystem, v.package_manager];

async function document(exchange: Json, index: boolean) {
  const { request, response } = exchange;
  assertEquals(request.type, "data.get");
  assertEquals(response.type, "data.get");
  assertEquals(response.id, request.id);
  assertEquals(request.payload.modelIdOrName, "orbit-gateway");
  assertEquals(request.payload.includeContent, true);
  const data = response.payload.data;
  assertEquals(data.modelName, "orbit-gateway");
  assertEquals(data.modelType, "@nckrtl/orbit-gateway");
  assertEquals(data.name, request.payload.dataName);
  assertEquals(data.contentType, "application/json");
  assertEquals(data.contentEncoding, "utf-8");
  assertEquals(data.lifetime, "infinite");
  assertEquals(data.garbageCollection, "infinite");
  assertEquals(typeof data.content, "string");
  assertEquals(data.size, bytes(data.content).length);
  assertEquals(data.checksum, await sha256(data.content));
  assert(data.size <= (index ? 65_536 : 1_048_576));
  assert(Number.isSafeInteger(data.version) && data.version > 0);
  if (index) assertEquals(request.payload.version, undefined);
  else assertEquals(request.payload.version, data.version);
  const value: Json = JSON.parse(data.content);
  assertEquals(value.schema_version, 1);
  assertEquals(value.project_id, 20);
  assertEquals(value.repository, "github.com/fixture/dependency-app");
  assertEquals(data.tags.contract, "orbit.dependencies.v1");
  assertEquals(data.tags.kind, index ? "index" : "report");
  assertEquals(data.tags.project_id, String(value.project_id));
  assertEquals(data.tags.repository, value.repository);
  assertEquals(data.tags.source_ref, "refs/heads/main");
  assertEquals(
    data.name,
    index
      ? `dependencies-v1-project-20-${await sha256(value.source_ref)}`
      : `dependencies-v1-report-${value.publication_id}-${await sha256(
        JSON.stringify(identity(value)),
      )}`,
  );
  return value;
}

function checkAttempt(a: Json, project: boolean) {
  assert(
    (project
      ? ["running", "succeeded", "partial", "failed"]
      : ["running", "succeeded", "failed"]).includes(a.status),
  );
  assert(a.run_id.length > 0 && a.run_id.length <= 256);
  assert(Number.isFinite(Date.parse(a.started_at)));
  if (a.status === "running") assertEquals(a.finished_at, null);
  else assert(Date.parse(a.finished_at) >= Date.parse(a.started_at));
  if (["running", "succeeded"].includes(a.status)) {
    assertEquals(a.error_code, null);
  } else assert(/^[a-z][a-z0-9_]*$/.test(a.error_code));
}

for (const name of names) {
  Deno.test(`canonical dependency fixture: ${name}`, async () => {
    const fixture = JSON.parse(
      await Deno.readTextFile(new URL(`${name}.json`, directory)),
    );
    assertEquals(fixture.fixture_version, 1);
    assertEquals(fixture.scenario, name);
    for (const snapshot of fixture.snapshots) {
      const index = await document(snapshot.index, true);
      assert(index.sources.length > 0 && index.sources.length <= 64);
      checkAttempt(index.last_attempt, true);
      const sources = index.sources as Json[];
      assertEquals(
        new Set(sources.map((s) => JSON.stringify(identity(s)))).size,
        sources.length,
      );
      const reports = await Promise.all(
        snapshot.reports.map((r: Json) => document(r, false)),
      );
      for (const source of sources) {
        checkAttempt(source.last_attempt, false);
        if (!source.report) {
          assertEquals(source.last_attempt.status, "failed");
          continue;
        }
        const pointer = source.report;
        assertEquals(identity(pointer), identity(source));
        const n = snapshot.reports.findIndex((r: Json) =>
          r.request.payload.dataName === pointer.dataName
        );
        assert(n >= 0, "pointer must have an exact fixture report");
        const report = reports[n];
        assertEquals(
          snapshot.reports[n].request.payload.version,
          pointer.version,
        );
        assertEquals(pointer.modelIdOrName, "orbit-gateway");
        for (
          const field of [
            "path",
            "ecosystem",
            "package_manager",
            "source_commit",
            "observed_at",
            "publication_id",
          ]
        ) {
          assertEquals(report[field], pointer[field], field);
        }
        assertEquals(report.project_id, index.project_id);
        assertEquals(report.repository, index.repository);
        if (source.last_attempt.status === "succeeded") {
          assertEquals(report.source_commit, index.source_commit);
        }
        assertEquals(report.basis, "locked");
        assertEquals(report.observed_on, "repository");
        assertEquals(report.instance_id, null);
        assert(
          Date.parse(report.published_at) >= Date.parse(report.observed_at),
        );
        assert(report.packages.length <= 5000 && report.findings.length <= 500);
        assertEquals(
          new Set(report.packages.map((p: Json) => p.id)).size,
          report.packages.length,
        );
        for (const p of report.packages) {
          assertEquals(typeof p.direct, "boolean");
          assert(p.scopes.length > 0);
          assertEquals(new Set(p.scopes).size, p.scopes.length);
          assert(
            p.scopes.every((s: string) =>
              ["regular", "development"].includes(s)
            ),
          );
          assert(["registry", "git", "path"].includes(p.origin));
        }
        assertEquals(
          new Set(report.findings.map((f: Json) => f.id)).size,
          report.findings.length,
        );
        for (const f of report.findings) {
          assert(report.packages.some((p: Json) => p.name === f.package));
          assert(["major", "advisory"].includes(f.kind));
          assert(["ready", "blocked", "in_batch"].includes(f.state));
          assert(f.current_versions.length > 0);
          assertEquals(
            new Set(f.current_versions).size,
            f.current_versions.length,
          );
          assertEquals(f.advisory_ids.length > 0, f.kind === "advisory");
          assert(/^[a-z][a-z0-9_]*$/.test(f.reason.code));
          assert(f.reason.text.length > 0 && f.reason.text.length <= 1024);
          if (f.target_version === null) assertEquals(f.state, "blocked");
          if (f.state === "in_batch") {
            assert(f.target_version !== null);
            assert(f.links !== null);
          }
          if (f.links) {
            assert(
              Number.isSafeInteger(f.links.group_id) && f.links.group_id > 0,
            );
            assert(f.links.run_id.length > 0);
          }
        }
      }
      const pr = index.batch_pr;
      assertEquals(pr.status, snapshot.expected.pr);
      if (pr.status === "unknown") {
        assert(pr.error_code !== null);
        if (pr.last_success_at) {
          assert(Date.parse(pr.last_success_at) < Date.parse(pr.checked_at));
        }
      } else {
        assertEquals(pr.error_code, null);
        assertEquals(pr.last_success_at, pr.checked_at);
        assertEquals(pr.value === null, pr.status === "absent");
      }
      if (pr.value) {
        assertEquals(pr.value.repository, index.repository);
        assertEquals(
          pr.value.url,
          `https://${index.repository}/pull/${pr.value.number}`,
        );
        assert(/^[a-f0-9]{40}$/.test(pr.value.head_commit));
      }
      if (
        ["running", "partial", "failed"].includes(index.last_attempt.status)
      ) {
        assertEquals(snapshot.expected.admission, "blocked");
      }
    }
  });
}

Deno.test("running and partial refresh preserve original good commit, observation and immutable coordinates", async () => {
  const load = async (name: string) =>
    JSON.parse(await Deno.readTextFile(new URL(`${name}.json`, directory)));
  const original = await load("success");
  const retained = await load("running-partial");
  const good = JSON.parse(
    original.snapshots[0].index.response.payload.data.content,
  );
  for (const snapshot of retained.snapshots) {
    const index = JSON.parse(snapshot.index.response.payload.data.content);
    assertEquals(index.sources[0].report, good.sources[0].report);
    assert(index.source_commit !== index.sources[0].report.source_commit);
    assertEquals(
      snapshot.reports[0].response,
      original.snapshots[0].reports[0].response,
    );
  }
});

Deno.test("PR unknown keeps last success; lifecycle publication preserves inventory time and run", async () => {
  const fixture = JSON.parse(
    await Deno.readTextFile(new URL("pr-unknown.json", directory)),
  );
  const [open, unknown] = fixture.snapshots.map((s: Json) =>
    JSON.parse(s.index.response.payload.data.content)
  );
  assertEquals(unknown.batch_pr.value, open.batch_pr.value);
  assertEquals(unknown.batch_pr.last_success_at, open.batch_pr.last_success_at);
  assertEquals(unknown.last_attempt, open.last_attempt);
  const report = JSON.parse(
    fixture.snapshots[0].reports[0].response.payload.data.content,
  );
  assert(Date.parse(report.published_at) > Date.parse(report.observed_at));
  assertEquals(report.observed_at, open.last_attempt.finished_at);
  assertEquals(report.run_id, open.last_attempt.run_id);
  assert(report.publication_id !== open.publication_id);
});

Deno.test("canonical fixture bytes match the frozen SHA256SUMS manifest", async () => {
  const manifest = await Deno.readTextFile(new URL("SHA256SUMS", directory));
  const entries = manifest.trim().split("\n").map((line) => line.split("  "));
  assertEquals(
    entries.map(([, file]) => file).sort(),
    names.map((n) => `${n}.json`).sort(),
  );
  for (const [expected, file] of entries) {
    assertEquals(
      await sha256(await Deno.readTextFile(new URL(file, directory))),
      expected,
      file,
    );
  }
});
