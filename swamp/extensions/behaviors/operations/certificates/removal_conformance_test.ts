import { assert, assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { routeMutationSteps } from "../routes/mutation-command/steps.ts";

Deno.test("withdrawal certificate cleanup stays visible per scope after the obsolete local loop retires", async () => {
    await assertRejects(
        () => Deno.stat(new URL("../routes/removal-local.ts", import.meta.url)),
        Deno.errors.NotFound,
    );
    const workflow = parse(
        await Deno.readTextFile(
            new URL(
                "../../../../workflows/workflow-operation-route-removal.yaml",
                import.meta.url,
            ),
        ),
    ) as { jobs: { steps: { task: Record<string, unknown> }[] }[] };
    const forward = workflow.jobs.flatMap((job) => job.steps).find((step) =>
        step.task.workflowIdOrName === "route-mutation-withdraw"
    );
    assert(forward);
    const steps = routeMutationSteps(
        "withdraw",
        "inputs.intent.route_mutations",
        "forward",
        undefined,
        "inputs.parent_run_id",
        true,
        true,
    );
    const cleanup = steps.find((step) => step.name === "withdraw_cleanup")!;
    assertEquals(cleanup.labels, { role: "swamp" });
    assertEquals(cleanup.forEach, {
        item: "part",
        in: "${{ inputs.intent.route_mutations.forward.cleanup }}",
    });
    const task = cleanup.task as {
        type: string;
        workflowIdOrName: string;
        inputs: Record<string, unknown>;
    };
    assertEquals(task.type, "workflow");
    assertEquals(task.workflowIdOrName, "route-certificate-remove");
    assertEquals(task.inputs.parent_run_id, "${{ inputs.parent_run_id }}");
    assertEquals(task.inputs.execution_secrets, {});
    const required = (cleanup.dependsOn as { step: string }[]).map((x) =>
        x.step
    );
    assert(required.includes("withdraw_withdraw_caddy"));
    assert(required.includes("withdraw_urls"));
    const lan = steps.find((step) => step.name === "withdraw_retire_lan")!;
    assert(
        (lan.dependsOn as { step: string }[]).some((x) =>
            x.step === "withdraw_cleanup"
        ),
    );
});
