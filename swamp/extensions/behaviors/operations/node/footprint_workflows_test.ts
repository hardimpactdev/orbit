import { roleRemovalWorkflow } from "./role_removal_workflow.ts";
import { assertEquals, assertFalse } from "jsr:@std/assert@1.0.19";
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
    const role = !path.pathname.endsWith("node-converge-direct.yaml");
    const convergeRole = path.pathname.endsWith(
      "node-role-converge-direct.yaml",
    );
    assertEquals(
      parse(text),
      path.pathname.endsWith("node-role-remove-direct.yaml")
        ? roleRemovalWorkflow()
        : footprintWorkflow(
          role,
          convergeRole,
          path.pathname.endsWith("node-role-relocate-direct.yaml"),
        ),
    );
  }
});

Deno.test("shared chain installs the admitted agent before role effects and footprint proof", () => {
  for (const role of [false, true]) {
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
    assertFalse(
      jobs.some((job) =>
        job.steps.some((step) => step.task.type === "workflow")
      ),
    );
  }
});

Deno.test("role has no observe jobs; converge preserves uncertainty cancellation checkpoints", () => {
  const role = footprintWorkflow(true).jobs as Job[];
  assertEquals(
    role.flatMap((job) => job.steps).filter((step) =>
      step.task.methodName === "observe"
    ),
    [],
  );
  const converge = footprintWorkflow(false).jobs as Job[];
  for (
    const job of converge.slice(
      1,
      converge.findIndex((job) => job.name === "projection"),
    )
  ) {
    assertEquals(job.steps[0].task.methodName, "observe");
    assertEquals(
      job.steps[1].task.inputs?.cancellation,
      "${{ steps." + job.steps[0].name + ".outputs }}",
    );
  }
});

Deno.test("projection always observes all previous jobs including failed or cancelled stages", () => {
  for (const role of [false, true]) {
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
