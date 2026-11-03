import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { copy } from "jsr:@std/fs@1.0.19/copy";
import { parse } from "jsr:@std/yaml@1.0.12";
import { nativeRepo, ROOT } from "../../../tests/_support/native_harness.ts";

type Scope = { name: string; labels?: unknown; target?: unknown; platform?: unknown; queueTimeout?: number };
type Workflow = Scope & { jobs: (Scope & { steps: Scope[] })[] };
const read = async (name: string) => parse(await Deno.readTextFile(`${ROOT}/workflows/workflow-${name}.yaml`)) as Workflow;
const native = ["project-maintenance-inspect", "project-routine", "project-observe", "maintenance-projects", "dependency-upgrade"];
async function placed() {
  // dependency-package/routine were retired with native Project maintenance.
  const names = [...native, "horizon-new-dawn-detect"];
  for await (const file of Deno.readDir(`${ROOT}/workflows`)) {
    if (/^workflow-project-.*-(detect|routine)\.yaml$/.test(file.name)) names.push(file.name.slice(9, -5));
  }
  return [...new Set(names)].sort();
}

Deno.test("heavy methods and native coordinators select workers", async () => {
  for (const name of await placed()) {
    const workflow = await read(name);
    if (!native.includes(name)) assertEquals(workflow.labels, { role: "swamp" }, name);
    // Shape rule 6 permits measured queue budgets; a declared budget must be positive.
    for (const scope of [workflow, ...workflow.jobs, ...workflow.jobs.flatMap((job) => job.steps)]) {
      if (scope.queueTimeout !== undefined) assert(Number.isInteger(scope.queueTimeout) && scope.queueTimeout > 0, name);
    }
    for (const job of workflow.jobs) for (const step of job.steps) {
      if (native.includes(name)) assertEquals(step.labels, { role: "swamp" }, name);
      const fields = { ...workflow, ...job, ...step };
      assert(fields.target || fields.labels || fields.platform, name);
    }
  }
  const horizon = await read("horizon-new-dawn-detect");
  assertEquals(horizon.jobs[1].target, "${{ inputs.worker }}");
  assertEquals(horizon.jobs[1].labels, {});
  assertEquals((await read("dependency-results-prune")).labels, { role: "swamp" });
});

Deno.test("active coordinators select authoritative workers", async () => {
  // maintenance-workers was deleted; the remaining coordinators still own placement.
  for (const name of ["groups-driver", "group-fanout", "group-supervise", "group-run", "operation-dependency-merge"]) {
    for (const job of (await read(name)).jobs) for (const step of job.steps) {
      if (name === "operation-dependency-merge") assertEquals(job.labels, { role: "swamp" });
      assertEquals(step.labels, { role: "swamp" }, `${name}/${step.name}`);
    }
  }
});

Deno.test("pinned native loader validates shipped workflows", async () => {
  await using repo = await nativeRepo();
  repo.timeoutMs = 90_000;
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
  for (const name of await placed()) assert(names.has((await read(name)).name), name);
});
