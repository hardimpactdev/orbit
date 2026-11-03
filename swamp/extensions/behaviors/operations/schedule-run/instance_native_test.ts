/** A refused native child prevents the Schedule parent's end check. */
import { assertEquals } from "jsr:@std/assert@1.0.19";
import { nativeRepo } from "../../../../tests/_support/native_harness.ts";
import { placedWorkflow } from "./native_fixture.ts";

// Empty and two-member success plans are covered by lifecycle_native_test.ts's
// "the definition parent converges two members without model lock collisions".
Deno.test("failed member skips the end check", async () => {
    await using repo = await nativeRepo();
    const parent = "operation-schedule-install-instance-definitions";
    await placedWorkflow(repo, parent);
    await repo.workflow("operation-schedule-create", (workflow) => {
        workflow.inputs = {
            type: "object",
            properties: { intent: { type: "object" } },
        };
        workflow.jobs[0].steps = [{
            name: "Install the member",
            task: {
                type: "assert",
                expr: "inputs.intent.accepted",
                message: "fixture member refused",
            },
        }];
    });
    await repo.cli(["workflow", "validate", parent]);
    const { run, steps } = await repo.runWorkflow(parent, {
        operation_id: 1,
        node_id: 209,
        kind: "schedule.install_instance_definitions",
        execution_secrets: {},
        intent: {
            instance_id: 42,
            members: [true, false].map((accepted, index) => ({
                id: `0ab86033-94e3-42de-8b46-57c1d8bdb00${index}`,
                intent: { accepted },
            })),
        },
    }, { success: false });
    assertEquals(run.status, "failed");
    assertEquals(steps["Check the members"], "skipped");
});
