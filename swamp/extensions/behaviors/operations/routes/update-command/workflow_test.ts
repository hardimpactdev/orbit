import { assertEquals } from "jsr:@std/assert@1.0.19";
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
