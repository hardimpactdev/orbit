import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import {
    ingressWorkflow,
    mutationWorkflow,
    updateWorkflow,
} from "./workflow.ts";
import { workloadWorkflow } from "../../instance-route-workload/workflow.ts";
Deno.test("checked-in Route update workflows match their native composition factories", async () => {
    for (
        const workflow of [
            updateWorkflow(),
            mutationWorkflow("forward"),
            mutationWorkflow("restore"),
            ingressWorkflow(),
            workloadWorkflow(),
        ]
    ) {
        const value = JSON.parse(JSON.stringify(workflow));
        const actual = parse(
            await Deno.readTextFile(
                new URL(
                    `../../../../../workflows/workflow-${value.name}.yaml`,
                    import.meta.url,
                ),
            ),
        );
        assertEquals(actual, value);
    }
});

Deno.test("Route update reuses its existing children with one209selector and phases below600lines", async () => {
    for (
        const workflow of [
            updateWorkflow(),
            mutationWorkflow("forward"),
            mutationWorkflow("restore"),
            ingressWorkflow(),
            workloadWorkflow(),
        ]
    ) {
        const value = JSON.parse(JSON.stringify(workflow));
        for (const job of value.jobs) {
            for (const step of job.steps) {
                assertEquals(
                    step.target ?? job.target ?? value.target,
                    "orbit-node-209",
                );
                assertEquals("labels" in step, false);
            }
        }
        if (value.name.startsWith("route-mutation-")) {
            const source = await Deno.readTextFile(
                new URL(
                    `../../../../../workflows/workflow-${value.name}.yaml`,
                    import.meta.url,
                ),
            );
            assert(source.trimEnd().split("\n").length < 600);
            const effects = value.jobs.flatMap((
                job: { steps: { name: string; task: { type: string } }[] },
            ) => job.steps).filter((step: { name: string }) =>
                step.name !== "result" && step.name !== "forward" &&
                step.name !== "restore"
            );
            assert(
                effects.every((step: { task: { type: string } }) =>
                    step.task.type === "workflow"
                ),
            );
        }
    }
});
