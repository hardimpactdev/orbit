import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { placed, workflow } from "./_support/heavy_workflows.ts";

Deno.test("heavy methods and native coordinators select workers", () => {
  for (const name of placed) {
    const value = workflow(name);
    assertEquals(
      value.queueTimeout,
      ["project-maintenance-inspect", "project-observe"].includes(name) ? 30 : undefined,
      name,
    );
    if (name === "horizon-new-dawn-detect") assertEquals(value.labels, { role: "swamp" }, name);
    for (const job of value.jobs) {
      for (const step of job.steps) {
        if (name !== "horizon-new-dawn-detect") assertEquals(step.labels, { role: "swamp" }, name);
        const fields = { ...value, ...job, ...step };
        assert(fields.target || fields.labels || fields.platform, name);
        if (step.task.modelType === "@nckrtl/native-host-method") {
          assert(!("labels" in step));
          assert(!("labels" in job));
        }
      }
    }
  }
  const horizon = workflow("horizon-new-dawn-detect");
  assertEquals(horizon.jobs[1].target, "${{ inputs.worker }}");
  assertEquals(horizon.jobs[1].labels, {});
  assertEquals(workflow("dependency-results-prune").labels, { role: "swamp" });
});

Deno.test("active coordinators select authoritative workers", () => {
  for (const name of ["groups-driver", "group-fanout", "group-supervise", "group-run", "operation-dependency-merge"]) {
    for (const job of workflow(name).jobs) {
      for (const step of job.steps) {
        // The native dependency merge now places each action on the Swamp worker.
        assertEquals(step.labels, { role: "swamp" }, `${name}/${step.name}`);
      }
    }
  }
});
