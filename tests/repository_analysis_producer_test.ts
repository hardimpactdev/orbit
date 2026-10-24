// Local producer integration, not a Gateway/VM/live ownership proof. Copy exact
// merged source into disposable Git fixtures. Never execute in the source checkout.
import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { type Manifest, sha256, validateAnalysis } from "../extensions/behaviors/_lib/repository_analysis.ts";
import { analysisFixture } from "./_support/analysis_fixture.ts";

const SOURCE = Deno.env.get("ORBIT_ANALYSIS_SOURCE") ?? "/fast/apps/orbit/default";
const SOURCE_SHA = "8b6fb914c6fbab3abd70459afe832f7307e4d5c6";
const FILES = [
  "bin/task-analyze",
  "apps/gateway/app/Support/StrictJson.php",
  "apps/gateway/app/Domain/Tasks/TaskAnalysisPlan.php",
  "apps/gateway/app/Domain/Tasks/TaskAnalysisReport.php",
  "apps/docs/app/Documentation/DocsImpact.php",
  "apps/docs/app/Documentation/DocsImpactReport.php",
];
const decode = (b: Uint8Array) => new TextDecoder("utf-8", { fatal: true }).decode(b);
async function command(cwd: string, name: string, args: string[], input?: string, env?: Record<string, string>) {
  const child = new Deno.Command(name, { cwd, args, env, stdin: input === undefined ? "null" : "piped", stdout: "piped", stderr: "piped" }).spawn();
  if (input !== undefined) {
    const writer = child.stdin.getWriter();
    await writer.write(new TextEncoder().encode(input));
    await writer.close();
  }
  const out = await child.output();
  return { success: out.success, code: out.code, stdout: decode(out.stdout), stderr: decode(out.stderr) };
}
async function git(root: string, ...args: string[]) {
  const out = await command(root, "git", ["-c", "core.hooksPath=/dev/null", "-c", "core.fsmonitor=false", ...args]);
  assert(out.success, out.stderr);
  return out.stdout.trim();
}
async function write(root: string, path: string, value: string) {
  await Deno.mkdir(`${root}/${path.slice(0, path.lastIndexOf("/")) || "."}`, { recursive: true });
  await Deno.writeTextFile(`${root}/${path}`, value);
}
async function tree(root: string) {
  const index = `${root}/.git/fixture-index`;
  await Deno.copyFile(`${root}/.git/index`, index);
  const env = { GIT_INDEX_FILE: index };
  assert((await command(root, "git", ["add", "-A"], undefined, env)).success);
  const out = await command(root, "git", ["write-tree"], undefined, env);
  assert(out.success);
  return out.stdout.trim();
}

Deno.test("real merged checker/adapter: owned pages, new/actual/delete/rename/unresolved paths, generator checks, typed overflow", async () => {
  assertEquals(await git(SOURCE, "rev-parse", "HEAD"), SOURCE_SHA, "revalidate source drift explicitly; do not silently run a different checker");
  const evidence = await Deno.makeTempDir({ prefix: "swamp-analysis-evidence-" });
  const measurements: Record<string, unknown>[] = [];
  const hashes: Record<string, string> = {};
  for (const path of FILES) {
    const bytes = await Deno.readTextFile(`${SOURCE}/${path}`);
    const committed = await command(SOURCE, "git", ["show", `${SOURCE_SHA}:${path}`]);
    assert(committed.success);
    assertEquals(bytes, committed.stdout, `source drift: ${path}`);
    hashes[path] = await sha256(bytes);
  }
  for (
    const mode of [
      "no-docs",
      "public-contract",
      "actual-inventory",
      "unresolved",
      "generator-passed",
      "generator-missing",
      "generator-failed",
      "generator-stale",
      "unavailable",
      "overflow",
    ]
  ) {
    const root = await Deno.makeTempDir({ prefix: "swamp-analysis-producer-" });
    try {
      for (const path of FILES) {
        await Deno.mkdir(`${root}/${path.slice(0, path.lastIndexOf("/"))}`, { recursive: true });
        await Deno.copyFile(`${SOURCE}/${path}`, `${root}/${path}`);
      }
      await Deno.chmod(`${root}/bin/task-analyze`, 0o755);
      await write(
        root,
        "apps/docs/vendor/autoload.php",
        "<?php require __DIR__.'/../app/Documentation/DocsImpact.php'; require __DIR__.'/../app/Documentation/DocsImpactReport.php';",
      );
      await write(root, "docs/reference/tasks.md", '---\ntitle: Tasks\ncovers:\n  - "apps/gateway/app/Domain/Tasks/**"\n---\nTask contract.\n');
      await write(root, "apps/docs/config/docs-covers-ratchet.php", "<?php return ['docs/reference/tasks.md'];");
      await write(root, "apps/gateway/app/Domain/Tasks/Old.php", "<?php // Existing covered task implementation.\n");
      await write(root, "old.txt", "rename source\n");
      await write(root, "docs/openapi.json", '{"paths":{}}');
      await write(root, "docs/docs.json", '{"navigation":{}}');
      if (mode.startsWith("generator-") && mode !== "generator-missing") {
        const state = mode.slice("generator-".length);
        // A generator test double, checked by the real scanner; it refuses write mode.
        await write(
          root,
          "bin/docs-openapi",
          `#!/bin/sh\n[ "$#" = 1 ] && [ "$1" = --check ] || exit 99\nprintf '%s\\n' --check >> .git/generator-checks\n${
            state === "passed" ? "exit 0" : `echo ${state}\nexit 1`
          }\n`,
        );
        await Deno.chmod(`${root}/bin/docs-openapi`, 0o755);
      }
      await git(root, "init", "--quiet");
      await git(root, "config", "gc.auto", "0");
      await git(root, "config", "maintenance.auto", "false");
      await git(root, "add", ".");
      await git(root, "-c", "user.name=Fixture", "-c", "user.email=fixture@example.test", "commit", "--quiet", "-m", "fixture");
      const base = await git(root, "rev-parse", "HEAD");
      let entries: Manifest["paths"] = [{ change: "add", path: "tests/ExampleTest.php" }];
      if (mode === "public-contract") entries = [{ change: "add", path: "apps/gateway/app/Domain/Tasks/Proposed.php" }];
      if (mode === "actual-inventory") {
        entries = [{ change: "delete", path: "apps/gateway/app/Domain/Tasks/Old.php" }, { change: "add", path: "apps/gateway/app/Domain/Tasks/Proposed.php" }, {
          change: "rename",
          from: "old.txt",
          path: "renamed-é.txt",
        }];
        await Deno.remove(`${root}/apps/gateway/app/Domain/Tasks/Old.php`);
        await Deno.rename(`${root}/old.txt`, `${root}/renamed-é.txt`);
        await write(root, "untracked.txt", "actual omitted work\n");
      }
      if (mode === "unresolved") entries = [{ change: "add", path: "apps/gateway/config/runtime.php" }];
      if (mode.startsWith("generator-")) entries = [{ change: "modify", path: "docs/openapi.json" }];
      if (mode === "unavailable") await Deno.remove(`${root}/apps/docs/vendor/autoload.php`);
      if (mode === "overflow") {
        for (let i = 0; i < 140; i++) await write(root, `${"d".repeat(240)}/${String(i).padStart(4, "0")}${"x".repeat(240)}.txt`, "actual untracked path\n");
      }
      const group = { id: 7, title: "Fixture", brief: "Complete plan", tasks: [{ id: 11, position: 1, title: "Docs", brief: "Write docs", deliverables: [] }] };
      const fixture = await analysisFixture(group, entries);
      const plan = { ...fixture.plan, base };
      const candidate = { head: base, tree: await tree(root) };
      const input = { execution_id: "e".repeat(32), plan: plan.manifest, plan_sha256: plan.plan_sha256, base, ...candidate };
      const start = performance.now();
      const out = await command(root, "php", ["bin/task-analyze"], JSON.stringify(input));
      const durationMs = performance.now() - start;
      await Deno.writeTextFile(`${evidence}/${mode}.stdout.json`, out.stdout);
      await Deno.writeTextFile(`${evidence}/${mode}.stderr.txt`, out.stderr);
      assertEquals(await tree(root), candidate.tree, "fixed analysis must leave candidate unchanged");
      if (["overflow", "unavailable"].includes(mode)) {
        assertEquals(out.code, 1);
        const failure = JSON.parse(out.stdout);
        assertEquals(failure.error.code, mode === "overflow" ? "tasks.analysis_report_too_large" : "tasks.analysis_unavailable");
        if (mode === "overflow") {
          // Retain actual complete producer evidence separately as UNUSABLE. It is
          // never reduced, passed as a successful API artifact, or reconstructed from stderr.
          await write(
            root,
            ".git/full-report.php",
            `<?php require getcwd().'/apps/docs/vendor/autoload.php'; $input=json_decode(stream_get_contents(STDIN),true,flags:JSON_THROW_ON_ERROR); $report=(new App\\Documentation\\DocsImpact(getcwd()))->report($input['base'],['tests/ExampleTest.php']); echo json_encode(['schema'=>1,'execution_id'=>$input['execution_id'],'plan_sha256'=>$input['plan_sha256'],'base'=>$input['base'],'head'=>$input['head'],'tree'=>$input['tree'],'status'=>App\\Documentation\\DocsImpactReport::status($report,$input['base'],$report['paths']),'diagnostics'=>[],'report'=>['schema'=>'orbit.docs-impact.v1','data'=>$report]],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\\n";`,
          );
          const full = await command(root, "php", [".git/full-report.php"], JSON.stringify(input));
          assert(full.success, full.stderr);
          const bytes = new TextEncoder().encode(full.stdout).length;
          assert(bytes > 65536);
          assertEquals(failure.error.required_bytes, bytes);
          await Deno.writeTextFile(`${evidence}/overflow.complete.UNUSABLE.json`, full.stdout);
          measurements.push({ mode: "overflow-complete", bytes, sha256: await sha256(full.stdout), usable: false });
        }
      } else {
        assertEquals(out.code, 0, out.stderr);
        assertEquals(out.stderr, "");
        const envelope = JSON.parse(out.stdout);
        const result = {
          ...fixture.result,
          outcome: envelope.status,
          artifact: { content: out.stdout, bytes: new TextEncoder().encode(out.stdout).length, sha256: await sha256(out.stdout) },
          provenance: { ...fixture.result.provenance, base, before: candidate, after: candidate, duration_ms: Math.round(durationMs) },
        };
        const parsed = await validateAnalysis(result, { operation: 899, group: 7, subtask: 11, plan, candidate, instanceId: 1, nodeId: 2 });
        if (mode === "no-docs" || mode === "generator-passed") assertEquals(parsed.outcome, "ready");
        else if (mode === "public-contract") {
          assertEquals(parsed.outcome, "action_required");
          assert(envelope.report.data.impacted_pages.some((p: { page: string }) => p.page === "docs/reference/tasks.md"));
        } else if (mode === "actual-inventory") {
          assertEquals(parsed.outcome, "plan_required");
          assertEquals(parsed.omitted, ["untracked.txt"]);
          assert(envelope.report.data.paths.includes("old.txt"));
          assert(envelope.report.data.paths.includes("renamed-é.txt"));
          await write(
            root,
            ".git/gate.php",
            "<?php require getcwd().'/apps/docs/vendor/autoload.php'; echo json_encode((new App\\Documentation\\DocsImpact(getcwd()))->gate($argv[1]),JSON_THROW_ON_ERROR); ",
          );
          const gate = await command(root, "php", [".git/gate.php", base]);
          assert(gate.success, gate.stderr);
          const actualGate = JSON.parse(gate.stdout);
          assert(actualGate.report.paths.includes("untracked.txt"));
          assertEquals(actualGate.passed, false);
          assert(actualGate.missing_pages.includes("docs/reference/tasks.md"));
          const branch = await git(root, "symbolic-ref", "--short", "HEAD");
          await write(
            root,
            `.git/orbit/docs-unaffected/${branch}.txt`,
            "docs/reference/tasks.md: Existing policy waiver still needs independent semantic review.\n",
          );
          const waived = await command(root, "php", [".git/gate.php", base]);
          assert(waived.success, waived.stderr);
          assertEquals(JSON.parse(waived.stdout).passed, true, "existing repository waiver mechanism is preserved, not machine-approved");
          await Deno.writeTextFile(`${evidence}/actual-handoff-gate.json`, gate.stdout);
          await Deno.writeTextFile(`${evidence}/actual-handoff-gate-existing-waiver.json`, waived.stdout);
        } else assertEquals(parsed.outcome, "blocked");
        if (mode === "unresolved") assert(envelope.report.data.errors.length > 0, "exit0 plus report errors must block");
        if (mode.startsWith("generator-") && mode !== "generator-missing") {
          assertEquals((await Deno.readTextFile(`${root}/.git/generator-checks`)).trim(), "--check");
        }
      }
      measurements.push({
        mode,
        durationMs,
        mechanicalLlmCalls: 0,
        exitCode: out.code,
        bytes: new TextEncoder().encode(out.stdout).length,
        sha256: await sha256(out.stdout),
      });
    } finally {
      await Deno.remove(root, { recursive: true });
    }
  }
  await Deno.writeTextFile(
    `${evidence}/provenance.json`,
    JSON.stringify(
      {
        source: SOURCE_SHA,
        files: hashes,
        measurements,
        comparableBeforeBaseline: null,
        planningWritingReviewLlmCalls: "not measured by mechanical fixture; explicit separate judgment steps",
        liveProof: false,
      },
      null,
      2,
    ),
  );
  console.log(`Complete local producer evidence retained: ${evidence}`);
});
