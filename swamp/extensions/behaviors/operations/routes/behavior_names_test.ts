import { assertNotMatch } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { ROOT } from "../../../../bin/gate-test-support.ts";
Deno.test("Route child behavior names never contain an unbounded path, domain or service", async () => {
    for (
        const name of [
            "operation-route-create",
            "route-create-csr",
            "route-create-sign",
            "route-create-site",
            "route-create-ingress",
            "route-environment-write",
            "route-target-retire",
            "route-certificate-publish",
            "route-certificate-remove",
        ]
    ) {
        const flow = parse(
            await Deno.readTextFile(`${ROOT}/workflows/workflow-${name}.yaml`),
        ) as {
            jobs: {
                steps: { name: string; task: { behaviorName?: string } }[];
            }[];
        };
        for (const job of flow.jobs) {
            for (const step of job.steps) {
                assertNotMatch(
                    step.task.behaviorName ?? "",
                    /\.(path|domain|unit)\b/,
                    `${name}: ${step.name}`,
                );
            }
        }
    }
});
