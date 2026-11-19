import { assert, assertEquals, assertFalse } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import type { Flow } from "../../../../bin/gate-test-support.ts";

Deno.test("relocation behaviors name their target and every step runs on swamp", async () => {
  const flow = parse(
    await Deno.readTextFile(
      new URL(
        "../../../../workflows/workflow-operation-node-role-relocate.yaml",
        import.meta.url,
      ),
    ),
  ) as Flow;
  const names: string[] = [];
  for (const step of flow.jobs.flatMap((j) => j.steps)) {
    assertEquals(step.labels, { role: "swamp" });
    assertFalse("target" in step);
    if (step.task.behaviorType === "@swamp/ssh") {
      names.push(step.task.behaviorName!);
      assert(
        step.writes ||
          [
            "Check Reverb",
            "Probe the Process backends",
            "Check the public certificates",
          ].includes(step.name),
      );
    }
  }
  assert(
    names.every((s) =>
      s.includes("inputs.intent.target_node_id") &&
      !s.includes("run.id") && !s.includes("operation_id")
    ),
  );
  assertEquals(new Set(names).size, names.length);
});
