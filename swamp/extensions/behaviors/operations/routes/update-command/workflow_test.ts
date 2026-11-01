import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import {
    createWorkflow,
    ingressWorkflow,
    mutationWorkflow,
    removalWorkflow,
    targetWorkflow,
    updateWorkflow,
} from "./workflow.ts";
import { workloadWorkflow } from "../../instance-route-workload/workflow.ts";
Deno.test("checked-in Route update workflows match their native composition factories", async () => {
    for (
        const workflow of [
            updateWorkflow(),
            targetWorkflow(),
            createWorkflow(),
            removalWorkflow(),
            mutationWorkflow("withdraw"),
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
            targetWorkflow(),
            createWorkflow(),
            removalWorkflow(),
            mutationWorkflow("withdraw"),
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
                step.name !== "restore" && step.name !== "withdraw"
            );
            assert(
                effects.every((step: { task: { type: string } }) =>
                    step.task.type === "workflow"
                ),
            );
        }
    }
});

Deno.test("Route primitive and shared publication children use one209selector", async () => {
    for (
        const name of [
            "operation-route-verification",
            "operation-route-target-unset",
            "operation-route-publication",
            "route-dns-publish",
            "route-dns-grace",
            "route-environment-write",
            "route-certificate-publish",
            "route-certificate-remove",
        ]
    ) {
        const flow = parse(
            await Deno.readTextFile(
                new URL(
                    `../../../../../workflows/workflow-${name}.yaml`,
                    import.meta.url,
                ),
            ),
        ) as {
            target?: string;
            jobs: {
                target?: string;
                steps: { target?: string; labels?: unknown }[];
            }[];
        };
        for (const job of flow.jobs) {
            for (const step of job.steps) {
                assertEquals(
                    step.target ?? job.target ?? flow.target,
                    "orbit-node-209",
                );
                assertEquals("labels" in step, false);
            }
        }
    }
});
