import { assertEquals } from "jsr:@std/assert@1";
import { parse } from "jsr:@std/yaml@1";
import {
    clusterRouterWorkflow,
    prepareWorkflow,
    probeWorkflow,
    publicationWorkflow,
} from "./workflow.ts";
Deno.test("checked-in Cluster Router DAGs match the single native composition", async () => {
    for (
        const [file, expected] of Object.entries({
            "workflow-operation-cluster-router.yaml": clusterRouterWorkflow(),
            "workflow-cluster-router-prepare.yaml": prepareWorkflow(),
            "workflow-cluster-router-forward.yaml": publicationWorkflow(
                "forward",
            ),
            "workflow-cluster-router-restore.yaml": publicationWorkflow(
                "restore",
            ),
            "workflow-cluster-router-probe.yaml": probeWorkflow(),
        })
    ) {
        assertEquals(
            parse(
                await Deno.readTextFile(
                    new URL("../../../../workflows/" + file, import.meta.url),
                ),
            ),
            expected,
        );
    }
});

Deno.test("Router workflow steps declare the pinned queue budget", () => {
    for (
        const workflow of [
            clusterRouterWorkflow(),
            prepareWorkflow(),
            publicationWorkflow("forward"),
            publicationWorkflow("restore"),
            probeWorkflow(),
        ]
    ) {
        assertEquals(workflow.queueTimeout, 600);
        for (
            const job of workflow.jobs as { steps: Record<string, unknown>[] }[]
        ) {
            for (const step of job.steps) assertEquals(step.queueTimeout, 600);
        }
    }
});

Deno.test("Router remote preparation and probes have process bounds", () => {
    const workflow = prepareWorkflow();
    const steps =
        (workflow.jobs as { steps: Record<string, unknown>[] }[])[0].steps;
    for (
        const [name, seconds] of [["account_command", "30"], [
            "packages",
            "600",
        ], ["caddy_command", "300"]]
    ) {
        const task = steps.find((step) => step.name === name)!.task as Record<
            string,
            unknown
        >;
        assertEquals(task.methodName, "exec");
        const inputs = task.inputs as { argv: string[]; timeoutSec: number };
        assertEquals(inputs.timeoutSec, Number(seconds) + 30);
        assertEquals(inputs.argv.slice(0, 5), [
            "/usr/bin/timeout",
            seconds,
            "/usr/bin/python3",
            "-I",
            "-c",
        ]);
    }
    const probe = (probeWorkflow().jobs as {
        steps: { task: { inputs: { argv: string } } }[];
    }[])[0].steps[0];
    assertEquals(
        probe.task.inputs.argv.includes(
            '["/usr/bin/timeout","10","/usr/bin/curl"',
        ),
        true,
    );
    assertEquals(
        probe.task.inputs.argv.includes(
            '["/usr/bin/timeout","10","/usr/bin/openssl"',
        ),
        true,
    );
});
