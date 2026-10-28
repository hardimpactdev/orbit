import { assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";

Deno.test("observation schedules stagger bounded work and never poll each minute", async () => {
  const schedules: Record<string, string> = {};
  const behaviorNames = new Set<string>();
  for (const name of ["collection", "horizon", "maintenance", "retention"]) {
    const workflow = parse(
      await Deno.readTextFile(
        new URL(
          `../../../workflows/workflow-observation-${name}.yaml`,
          import.meta.url,
        ),
      ),
    ) as {
      concurrency: number;
      trigger: { schedule: string };
      jobs: { steps: { task: { behaviorName: string } }[] }[];
    };
    schedules[name] = workflow.trigger.schedule;
    assertEquals(workflow.concurrency, 1);
    behaviorNames.add(workflow.jobs[0].steps[0].task.behaviorName);
  }
  assertEquals(schedules, {
    collection: "15 */10 * * * *",
    horizon: "45 */10 * * * *",
    maintenance: "25 */5 * * * *",
    retention: "10 17 2 * * *",
  });
  assertEquals(behaviorNames.size, 4);
});
