import { assertEquals } from "jsr:@std/assert@1.0.19";
import { ROOT } from "../../../../bin/gate-test-support.ts";
import { parse } from "jsr:@std/yaml@1.0.12";

Deno.test("tasks.provision's default queue budget covers a loaded dispatch", () => {
    const workflow = parse(
        Deno.readTextFileSync(
            `${ROOT}/workflows/workflow-operation-task-provision.yaml`,
        ),
    ) as {
        queueTimeout: number;
    };
    assertEquals(workflow.queueTimeout, 30);
});
