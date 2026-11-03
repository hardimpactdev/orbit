import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { copy } from "jsr:@std/fs@1.0.20/copy";
import { nativeRepo, ROOT } from "../../tests/_support/native_harness.ts";
import { placed, workflow } from "../../tests/_support/heavy_workflows.ts";

Deno.test("pinned native loader validates shipped workflows", async () => {
  await using repo = await nativeRepo();
  repo.timeoutMs = 90_000;
  await repo.copyBehaviors("");
  for (const directory of ["models", "workflows"]) {
    await copy(`${ROOT}/${directory}`, `${repo.dir}/${directory}`, { overwrite: true });
  }
  const upstream = JSON.parse(await Deno.readTextFile(`${ROOT}/extensions/models/upstream_extensions.json`));
  for (const name of Object.keys(upstream)) await repo.pull(name);
  const result = await repo.cli(["workflow", "validate"]);
  // Auto-resolution can emit JSON events before the final validation report.
  const start = result.stdout.indexOf('{\n  "workflows"');
  assert(start >= 0, result.stdout);
  const report = JSON.parse(result.stdout.slice(start));
  assertEquals(report.passed, true);
  assertEquals(report.totalFailed, 0);
  const names = new Set(report.workflows.map((item: { workflowName: string }) => item.workflowName));
  for (const name of placed) assert(names.has(workflow(name).name), name);
});
