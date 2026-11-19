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
            "Probe the Router hop",
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

Deno.test("the Ingress move probes the Router hop and refuses a 5xx certificate answer before private DNS switches", async () => {
  const flow = parse(
    await Deno.readTextFile(
      new URL(
        "../../../../workflows/workflow-operation-node-role-relocate.yaml",
        import.meta.url,
      ),
    ),
  ) as Flow;
  const edge = flow.jobs.find((j) => j.name === "edge")!;
  const names = edge.steps.map((s) => s.name);
  assertEquals(names, [
    "Probe the Router hop",
    "Check the public certificates",
    "Check the certificate answers",
  ]);
  const hop = JSON.stringify(edge.steps[0]);
  assert(hop.includes("inputs.intent.router_probes"));
  assert(hop.includes('self.probe.domain + \\":443:\\" + self.probe.host'));
  assert(JSON.stringify(edge.steps[1]).includes('\\"%{http_code}\\"'));
  const check = edge.steps[2].task as unknown as {
    type: string;
    expr: string;
  };
  assertEquals(check.type, "assert");
  assert(
    check.expr.includes('k.startsWith("Check the public certificates")') &&
      check.expr.includes('!steps[k].outputs.stdout.startsWith("5")'),
  );
  // The dns job, and so the switch, runs only after the edge job succeeded.
  const dns = flow.jobs.find((j) => j.name === "dns")! as unknown as {
    dependsOn: unknown;
  };
  assertEquals(dns.dependsOn, [{
    job: "edge",
    condition: { type: "succeeded" },
  }]);
});
