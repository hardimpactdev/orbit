import { assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import type { Flow } from "../../../../tests/_support/node_host.ts";

Deno.test("role checks serialize runtime files and exporter endpoints", async () => {
  const workflow = parse(await Deno.readTextFile(
    new URL("../../../../workflows/workflow-operation-node-role-add.yaml", import.meta.url),
  )) as Flow;
  const steps = workflow.jobs.flatMap((job) => job.steps);
  for (const name of ["Check runtime files", "Check exporter endpoints"]) {
    assertEquals(steps.find((step) => step.name === name)?.concurrency, 1, name);
  }
});
