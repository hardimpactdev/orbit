import { assertEquals } from "jsr:@std/assert@1.0.19";
import { createPhaseSteps } from "./create_factory.ts";

Deno.test("create factory uses one supplied admitted envelope and the caller's original root", () => {
    const graph = createPhaseSteps("inputs.create");
    assertEquals(graph, createPhaseSteps("inputs.create"));
    const json = JSON.stringify(graph);
    assertEquals(json.includes("inputs.intent"), false);
    assertEquals(json.includes("inputs.execution_secrets"), false);
    assertEquals(json.includes("root_run_id"), false);
    assertEquals(json.includes("run.id"), true);
    assertEquals(json.includes("inputs.create.operation_id"), true);
    assertEquals(graph.terminalResult, {
        job: "create",
        step: "finish-result",
    });
    for (const job of graph.jobs) {
        for (const step of job.steps as Record<string, unknown>[]) {
            assertEquals(step.target, "orbit-node-209");
            assertEquals(step.queueTimeout, 15);
        }
    }
});

Deno.test("create source and ordered setup each have one official SSH execution step", () => {
    const graph = createPhaseSteps();
    const steps = graph.jobs.flatMap((job) =>
        job.steps as Record<string, unknown>[]
    );
    for (const name of ["create-prepare", "create-source", "finish-setup"]) {
        const step = steps.filter((step) => step.name === name);
        assertEquals(step.length, 1);
        assertEquals(
            (step[0].task as Record<string, unknown>).behaviorType,
            "@swamp/ssh",
        );
        assertEquals(
            (step[0].task as Record<string, unknown>).methodName,
            "exec",
        );
    }
    assertEquals(
        steps.some((step) => /^finish-setup-[0-9]+$/.test(String(step.name))),
        false,
    );
});
