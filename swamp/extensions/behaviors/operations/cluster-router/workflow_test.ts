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
