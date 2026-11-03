import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { copy } from "jsr:@std/fs@1.0.19/copy";
import { parse } from "jsr:@std/yaml@1.0.12";
import { nativeRepo, ROOT } from "../../../tests/_support/native_harness.ts";

Deno.test("pinned native loader validates shipped workflows", async () => {
  await using repo = await nativeRepo();
  repo.timeoutMs = 180_000;
  await repo.copyBehaviors("");
  for (const directory of ["models", "workflows"]) await copy(`${ROOT}/${directory}`, `${repo.dir}/${directory}`, { overwrite: true });
  await copy(`${ROOT}/extensions/models`, `${repo.dir}/extensions/models`, { overwrite: true });
  for (const name of ["@swamp/git", "@swamp/software-factory", "@swamp/agent-runner", "@swamp/ssh", "@swamp/cfgmgmt"]) await repo.pull(name);
  repo.realCertificateSigning = true;
  const { stdout } = await repo.cli(["workflow", "validate"]);
  // Bulk validation emits consecutive pretty-printed JSON objects; the summary is last.
  const summary = JSON.parse(stdout.slice(stdout.lastIndexOf('\n{') + 1)) as { passed: boolean; totalFailed: number; workflows: { workflowName: string }[] };
  assert(summary.passed);
  assertEquals(summary.totalFailed, 0);
  const names = new Set(summary.workflows.map((item) => item.workflowName));
  for await (const file of Deno.readDir(`${ROOT}/workflows`)) {
    if (!file.name.endsWith(".yaml")) continue;
    const workflow = parse(await Deno.readTextFile(`${ROOT}/workflows/${file.name}`)) as { name: string };
    assert(names.has(workflow.name), file.name);
  }
});
