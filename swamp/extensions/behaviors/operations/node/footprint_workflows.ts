import {
  roleRemovalJobs,
  roleRemovalWorkflow,
} from "./role_removal_workflow.ts";
import { roleMetricsJobs } from "./role_metrics_workflow.ts";
import { roleCertificateJobs } from "./role_certificates_workflow.ts";
import { rolePublicationJobs } from "./role_publication_workflow.ts";
/** Generate native jobs from one footprint chain; cached stages stay in their root run. */
import { stringify } from "jsr:@std/yaml@1.0.12";
import { BaselineStage } from "./role_direct_local.ts";
import { Stage } from "./converge_direct_contract.ts";

type Fields = Record<string, unknown>;
const expression = (value: string) => "${{ " + value + " }}";
const key = (stage: string) => stage.replaceAll("-", "_");
const root = Object.fromEntries(
  ["operation_id", "node_id", "kind"].map((
    name,
  ) => [name, expression(`inputs.${name}`)]),
);
const arguments_ = { ...root, run_id: expression("run.id") };
const admission = expression("steps.admit.outputs");
const inactive = expression("!steps.admit.outputs.ready");
const baseline: readonly string[] = BaselineStage.options;
const outputs = (stages: readonly string[]) =>
  expression(
    stages.map((stage) => {
      const name = key(stage);
      return `(has(steps.${name}) && has(steps.${name}.outputs) ? [steps.${name}.outputs] : [])`;
    }).join(" + "),
  );
const dependency = (job: string, type = "succeeded") => ({
  job,
  condition: { type },
});

export function footprintWorkflow(
  role: boolean,
  convergeRole = false,
  relocateRole = false,
) {
  const behavior = role ? "node-role-add-direct" : "node-converge-direct";
  const task = (methodName: string, inputs: Fields = {}) => ({
    type: "behavior",
    behaviorType: `@nckrtl/${behavior}`,
    behaviorName: expression(
      `"${role ? "node-role" : "node-converge"}-" + string(inputs.node_id)`,
    ),
    methodName,
    inputs: { ...arguments_, ...inputs },
  });
  const jobs: Fields[] = [{
    name: "admission",
    steps: [{ name: "admit", writes: true, task: task("admit") }],
  }];
  const order = role
    ? [...Stage.options.slice(0, 2), ...baseline, ...Stage.options.slice(2)]
    : Stage.options;
  let previous = "admission";
  let dependencies = "null";
  let prepared = false;
  for (const stage of order) {
    if (role && stage === "runtime") {
      jobs.push({
        name: "analytics_storage_job",
        dependsOn: [dependency(previous)],
        steps: [{
          name: "analytics_storage",
          writes: true,
          guard: inactive,
          target: expression(
            '"orbit-node-" + string(steps.admit.outputs.snapshot.runtime != null && steps.admit.outputs.snapshot.runtime.role == "analytics" ? steps.admit.outputs.snapshot.runtime.clickhouse.node_id : inputs.node_id)',
          ),
          task: task("analyticsStorage", { admission }),
        }],
      });
      previous = "analytics_storage_job";
    }
    if (role && stage === "caddy") {
      const metrics = roleMetricsJobs(previous, {
        blocked: "!steps.admit.outputs.ready",
      });
      jobs.push(...metrics.jobs);
      previous = metrics.lastJob;
      const certificates = roleCertificateJobs(previous, {
        blocked: "!steps.admit.outputs.ready",
      });
      jobs.push(...certificates.jobs);
      previous = certificates.lastJob;
      const publication = rolePublicationJobs(previous, {
        blocked: "!steps.admit.outputs.ready",
        ...(relocateRole
          ? {
            excludeNodeId:
              "steps.admit.outputs.snapshot.source_removal.node_id",
          }
          : {}),
      });
      jobs.push(...publication.jobs);
      previous = publication.lastJob;
      dependencies =
        `(${metrics.result}) != null && (${metrics.result}).ready && (${certificates.result}) != null && (${certificates.result}).ready && (${publication.result}) != null && (${publication.result}).ready`;
      jobs.push({
        name: "prepare_footprint_job",
        dependsOn: [dependency(previous)],
        steps: [{
          name: "prepare_footprint",
          writes: true,
          guard: inactive,
          task: task("prepareFootprint", { admission }),
        }],
      });
      previous = "prepare_footprint_job";
      prepared = true;
    }
    const name = key(stage);
    const isBaseline = role && baseline.includes(stage);
    const cancellation = `${name}_cancellation`;
    const steps: Fields[] = [];
    // Converge retains its saved pre-write checkpoint so uncertain writes reconcile
    // before cancellation on resume. Role methods read cancellation themselves.
    if (!role) {
      steps.push({
        name: cancellation,
        writes: false,
        guard: inactive,
        task: task("observe", { stage }),
      });
    }
    const inputs: Fields = {
      admission: prepared
        ? expression("steps.prepare_footprint.outputs")
        : admission,
      stage,
    };
    if (!isBaseline) {
      inputs.binary_changed = stage === "agent"
        ? expression(
          "has(steps.agent_install) && has(steps.agent_install.outputs)" +
            (role
              ? " && has(steps.agent_install.outputs.binary_changed)"
              : "") +
            " ? steps.agent_install.outputs.binary_changed : false",
        )
        : false;
    }
    if (!role) {
      inputs.cancellation = expression(`steps.${cancellation}.outputs`);
    }
    steps.push({
      name,
      writes: true,
      guard: inactive,
      ...(!role
        ? {
          dependsOn: [{ step: cancellation, condition: { type: "succeeded" } }],
        }
        : {}),
      task: task(
        isBaseline ? "baseline" : role ? "footprint" : "stage",
        inputs,
      ),
    }, {
      name: `${name}_proven`,
      guard: inactive,
      dependsOn: [{ step: name, condition: { type: "succeeded" } }],
      task: {
        type: "assert",
        expr: `steps.${name}.outputs.ok`,
        severity: "high",
        message: isBaseline
          ? "The role baseline stage refused"
          : `The ${stage} stage refused its admitted footprint`,
      },
    });
    const job = `${name}_job`;
    jobs.push({ name: job, dependsOn: [dependency(previous)], steps });
    previous = job;
  }
  const results = role
    ? { baseline: outputs(baseline), footprints: outputs(Stage.options) }
    : { outcomes: outputs(Stage.options) };
  if (role) {
    jobs.push({
      name: "cli_job",
      dependsOn: jobs.map((job) => dependency(String(job.name), "always")),
      steps: [{
        name: "cli",
        writes: true,
        guard: expression(
          "!steps.admit.outputs.ready" + baseline.map((stage) => {
            const name = key(stage);
            return ` || !has(steps.${name}) || !has(steps.${name}.outputs) || !steps.${name}.outputs.ok`;
          }).join(""),
        ),
        task: task("cli", {
          admission: expression(
            "has(steps.prepare_footprint) && has(steps.prepare_footprint.outputs) ? steps.prepare_footprint.outputs : steps.admit.outputs",
          ),
          ...results,
        }),
      }],
    });
  }
  let cleanup: string | undefined;
  if (relocateRole) {
    jobs.push({
      name: "target_ready_job",
      dependsOn: [dependency("cli_job")],
      steps: [{
        name: "target_ready",
        writes: true,
        task: task("targetReady", {
          admission: expression(
            "has(steps.prepare_footprint) && has(steps.prepare_footprint.outputs) ? steps.prepare_footprint.outputs : steps.admit.outputs",
          ),
          ...results,
          dependencies: expression(`{"ready": ${dependencies}}`),
          cli: expression(
            "has(steps.cli) && has(steps.cli.outputs) ? steps.cli.outputs : null",
          ),
        }),
      }],
    });
    const source = roleRemovalJobs("target_ready_job");
    jobs.push(...source.jobs);
    cleanup = source.result;
  }
  jobs.push({
    name: "projection",
    dependsOn: jobs.map((job) => dependency(String(job.name), "always")),
    steps: [{
      name: "project",
      writes: true,
      guard: expression("!has(steps.admit) || !has(steps.admit.outputs)"),
      task: task("project", {
        admission: role
          ? expression(
            "has(steps.prepare_footprint) && has(steps.prepare_footprint.outputs) ? steps.prepare_footprint.outputs : steps.admit.outputs",
          )
          : admission,
        ...results,
        ...(role
          ? {
            ...(cleanup ? { cleanup: expression(cleanup) } : {}),
            dependencies: expression(`{"ready": ${dependencies}}`),
            cli: expression(
              "has(steps.cli) && has(steps.cli.outputs) ? steps.cli.outputs : null",
            ),
          }
          : {}),
      }),
    }],
  });
  return {
    id: role
      ? relocateRole
        ? "48ce785a-7429-463e-a776-7b5a59bb2961"
        : convergeRole
        ? "2c3425ad-31c5-4ab3-9039-191438338fb6"
        : "36af16a4-008f-41f3-81ed-187cb11b1a6b"
      : "daace7ba-223f-47b6-9452-7c0da7cf384e",
    name: relocateRole
      ? "operation-node-role-relocate-direct"
      : convergeRole
      ? "operation-node-role-converge-direct"
      : `operation-${behavior}`,
    description:
      "One exact-Node native root; generated from the shared footprint chain.",
    inputs: {
      type: "object",
      properties: {
        operation_id: { type: "integer", minimum: 1 },
        node_id: { type: "integer", minimum: 1 },
        kind: {
          type: "string",
          enum: [
            role
              ? relocateRole
                ? "node.role_relocate"
                : convergeRole
                ? "node.role_converge"
                : "node.role_add"
              : "node.converge",
          ],
        },
      },
      required: ["operation_id", "node_id", "kind"],
      additionalProperties: false,
    },
    target: expression('"orbit-node-" + string(inputs.node_id)'),

    jobs,
  };
}

export function generatedFootprintWorkflows() {
  return [
    footprintWorkflow(false),
    footprintWorkflow(true),
    footprintWorkflow(true, true),
    footprintWorkflow(true, false, true),
    roleRemovalWorkflow(),
  ].map((workflow) => {
    return {
      path: new URL(
        `../../../../workflows/workflow-${workflow.name}.yaml`,
        import.meta.url,
      ),
      text:
        "# deno-fmt-ignore-file\n# Generated: deno run --allow-read --allow-write extensions/behaviors/operations/node/footprint_workflows.ts\n" +
        stringify(workflow, { flowLevel: 4, lineWidth: -1 }),
    };
  });
}

if (import.meta.main) {
  for (const { path, text } of generatedFootprintWorkflows()) {
    await Deno.writeTextFile(path, text);
  }
}
