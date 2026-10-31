import { assertEquals } from "jsr:@std/assert@1.0.14";
import { parse } from "jsr:@std/yaml@1.0.12";

for (const kind of ["create", "control", "run", "destroy"]) {
  Deno.test(`Schedule ${kind} has exactly one 209 selector on every job and step`, () => {
    const workflow = parse(Deno.readTextFileSync(
      new URL(
        `../workflows/workflow-operation-schedule-${kind}.yaml`,
        import.meta.url,
      ),
    )) as {
      jobs: Array<
        Record<string, unknown> & { steps: Array<Record<string, unknown>> }
      >;
    };
    for (const job of workflow.jobs) {
      for (const scope of [job, ...job.steps]) {
        assertEquals(scope.target, "orbit-node-209");
        assertEquals(["target", "labels"].filter((key) => key in scope), [
          "target",
        ]);
      }
    }
  });
}
