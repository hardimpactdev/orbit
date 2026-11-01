import { roleRemovalWorkflow } from "./role_removal_workflow.ts";
import { assertEquals } from "jsr:@std/assert@1.0.19";
import { BaselineStage } from "./role_direct_local.ts";
import { parse } from "jsr:@std/yaml@1.0.12";
import {
    footprintWorkflow,
    generatedFootprintWorkflows,
} from "./footprint_workflows.ts";

type Job = {
    name: string;
    dependsOn?: { job: string; condition: { type: string } }[];
    steps: {
        name: string;
        task: {
            type: string;
            methodName?: string;
            inputs?: Record<string, unknown>;
        };
    }[];
};

Deno.test("checked-in footprint DAGs match their single template", async () => {
    for (const { path, text } of generatedFootprintWorkflows()) {
        assertEquals(await Deno.readTextFile(path), text);
        const role = true;
        assertEquals(
            parse(text),
            path.pathname.endsWith("node-role-remove-direct.yaml")
                ? roleRemovalWorkflow()
                : footprintWorkflow(
                    role,
                    path.pathname.endsWith("node-role-relocate-direct.yaml"),
                ),
        );
    }
});

Deno.test("shared chain installs the admitted agent before role effects and footprint proof", () => {
    for (const role of [true] as const) {
        const workflow = footprintWorkflow(role);
        const jobs = workflow.jobs as Job[];
        const stages = jobs.flatMap((job) => job.steps)
            .filter((step) =>
                ["stage", "footprint", "baseline"].includes(
                    step.task.methodName ?? "",
                ) && step.task.inputs?.stage !== undefined
            );
        assertEquals(
            stages.map((step) => step.task.inputs?.stage),
            role
                ? [
                    "agent-install",
                    "caddy-package",
                    ...BaselineStage.options,
                    "caddy",
                    "peer-dns",
                    "private-dns",
                    "route-residue",
                    "php-runtime",
                    "tmpfiles",
                    "agent",
                ]
                : [
                    "agent-install",
                    "caddy-package",
                    "caddy",
                    "peer-dns",
                    "private-dns",
                    "route-residue",
                    "php-runtime",
                    "tmpfiles",
                    "agent",
                ],
        );
        for (let index = 1; index <= stages.length; index++) {
            assertEquals(jobs[index].dependsOn, [{
                job: jobs[index - 1].name,
                condition: { type: "succeeded" },
            }]);
        }
        assertEquals(
            workflow.target,
            '${{ "orbit-node-" + string(inputs.node_id) }}',
        );
        assertEquals("labels" in workflow, false);
        const children = jobs.flatMap((job) => job.steps).filter((step) =>
            step.task.type === "workflow"
        );
        assertEquals(children.length, 1);
        for (const child of children) {
            assertEquals(
                (child.task as Record<string, unknown>).workflowIdOrName,
                "node-role-certificate-publish",
            );
            assertEquals(
                child.task.inputs?.parent_run_id,
                "${{ (has(inputs.parent_run_id) ? inputs.parent_run_id : run.id) }}",
            );
        }
    }
});

Deno.test("retained role generation cannot overwrite the new standalone native converge", () => {
    const role = footprintWorkflow(true).jobs as Job[];
    assertEquals(
        role.flatMap((job) => job.steps).filter((step) =>
            step.task.methodName === "observe"
        ),
        [],
    );
    assertEquals(
        generatedFootprintWorkflows().some(({ path }) =>
            path.pathname.endsWith("node-converge-direct.yaml")
        ),
        false,
    );
});

Deno.test("projection always observes all previous jobs including failed or cancelled stages", () => {
    for (const role of [true] as const) {
        const jobs = footprintWorkflow(role).jobs as Job[];
        const projection = jobs.findIndex((job) => job.name === "projection");
        assertEquals(
            jobs[projection].dependsOn,
            jobs.slice(0, projection).map((job) => ({
                job: job.name,
                condition: { type: "always" },
            })),
        );
        const project = jobs[projection].steps[0].task.inputs;
        const result = String(project?.[role ? "footprints" : "outcomes"]);
        assertEquals(result.includes("has(steps.agent_install.outputs)"), true);
        assertEquals(result.includes("has(steps.agent.outputs)"), true);
    }
});
