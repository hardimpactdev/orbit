import { assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { Environment } from "npm:@marcbachmann/cel-js@7.6.1";
import { behavior } from "../extensions/behaviors/operations/instance-dev-command/assets.ts";
import { createResult } from "../extensions/behaviors/operations/instance-dev-command/result.ts";
import { behavior as vpRequirements } from "../extensions/behaviors/operations/instance-vp-command/requirements.ts";
import { vpWorkspaceSteps } from "../extensions/behaviors/operations/instance-vp-command/steps.ts";

/**
 * Swamp evaluates a skipped step's inputs too (docs/reference/swamp-command-steps.md), so an expression that reads a vault key or an
 * intent section that a run lacks fails that whole run with `No such key`. This evaluates every step input expression that reads
 * `inputs.execution_secrets` or `inputs.intent.read`, as each kind of run hands it over, and requires that none of them fails.
 * Expressions over `steps.` or a `forEach` item (`self.`) are left out: Swamp evaluates those per item or after the steps they name.
 */
const environment = new Environment({ enableOptionalTypes: true, unlistedVariablesAreDyn: true, homogeneousAggregateLiterals: false })
  .registerFunction("vaultget(dyn, dyn): string", (vault: unknown, key: unknown) => `${vault}/${key}`);

type Workflow = { jobs: { steps: { name: string; task?: unknown }[] }[] };

function failures(workflow: string, inputs: Record<string, unknown>): string[] {
  const parsed = parse(Deno.readTextFileSync(new URL(`../workflows/${workflow}`, import.meta.url))) as Workflow;
  const failed: string[] = [];
  const evaluate = (step: string, value: unknown): void => {
    if (typeof value === "string") {
      for (const match of value.matchAll(/\$\{\{([\s\S]*?)\}\}/g)) {
        const expression = match[1].trim();
        if (!/execution_secrets|intent\.read/.test(expression) || /\b(self|steps)\./.test(expression)) continue;
        try {
          environment.evaluate(expression.replaceAll("vault.get(", "vaultget("), { inputs, run: { id: "run-1" } });
        } catch (error) {
          failed.push(`${step}: ${(error as Error).message.split("\n")[0]}`);
        }
      }
    } else if (value !== null && typeof value === "object") {
      for (const item of Object.values(value)) evaluate(step, item);
    }
  };
  for (const job of parsed.jobs) for (const step of job.steps) evaluate(step.name, step.task);
  return failed;
}

const copy = (key: string) => ({ vault: "orbit-control-inputs", key });

Deno.test("a checkout create reads no key that only a container create has", () => {
  assertEquals(failures("workflow-operation-instance-create.yaml", {
    execution_secrets: { git_credential: copy("instance-git-credential-1"), environment: copy("instance-create-environment-input-1") },
    intent: { container: null, environment_testing: false, server_database: null },
  }), []);
});

Deno.test("a container default's create and a clone's create read their own keys", () => {
  const execution_secrets = {
    git_credential: copy("instance-git-credential-1"), environment: copy("instance-create-environment-input-1"),
    process_environments: copy("instance-create-processes-1"),
  };
  const intent = { container: { clone: null }, environment_testing: false, server_database: null };
  assertEquals(failures("workflow-operation-instance-create.yaml", { execution_secrets, intent }), []);
  assertEquals(failures("workflow-operation-instance-create.yaml", {
    execution_secrets: { ...execution_secrets, model_environment: copy("instance-create-model-1") },
    intent: { ...intent, container: { clone: {} } },
  }), []);
});

Deno.test("a production and a checkout deployment read no key that only a container deployment has", () => {
  for (const variant of ["production", "development"]) {
    assertEquals(failures("workflow-operation-instance-deploy-direct.yaml", {
      execution_secrets: { credential: copy("instance-deploy-operation-1-credential"), environment: copy("instance-environment-1") },
      // Every deployment carries a `production` section; a development one carries it blank.
      intent: { deployment: { variant }, production: { instance_id: 1, home: "/srv/app" } },
    }), [], variant);
  }
});

Deno.test("a container process:start, stop and restart read no `read` section, which only a status or log read has", () => {
  for (const kind of ["process.start", "process.stop", "process.restart"]) {
    assertEquals(failures("workflow-operation-process-container.yaml", {
      kind, execution_secrets: {}, intent: { control: { process_id: 41, name: "orbit-process-41-worker.service" }, container: {} },
    }), [], kind);
  }
});

// The pinned Swamp runtime uses this CEL compiler and environment in createExtensionCelEnvironment:
// https://github.com/swamp-club/swamp/blob/db3f6373/src/infrastructure/cel/cel_evaluator.ts
// Native workflow validation checks argument names, but does not type-check deferred step expressions.
Deno.test("create finalization evaluates checkout, default and clone receipts with Swamp's CEL types", () => {
  const workflow = parse(Deno.readTextFileSync(new URL("../workflows/workflow-operation-instance-create.yaml", import.meta.url))) as Workflow;
  const finish = workflow.jobs.flatMap((job) => job.steps).find((step) => step.name === "finish")!;
  const expressions = (finish.task as { inputs: Record<string, string> }).inputs;
  const commit = "a".repeat(40);
  const succeeded = { status: "succeeded" };
  const workspace = { ...succeeded, outputs: { stdout: `orbit-commit=${commit}` } };
  const registration = JSON.parse(Deno.readTextFileSync(new URL("fixtures/instance-create-16210-t3-register.json", import.meta.url))).step;
  const server = JSON.parse(registration.outputs.stdout);
  const steps = {
    checkout: workspace, container: succeeded, workspace, seed: succeeded, agent: succeeded, t3: succeeded,
    "container-processes": succeeded, "t3-sessions": succeeded,
    "t3-register": registration,
  };
  for (const container of [null, { clone: null }, { clone: { source: "instance-7" } }]) {
    const intent = { instance_id: 8, already_active: false, container, server_database: null, processes: [], setup_steps: [], route: null };
    const phaseSteps = { ...steps, "t3-register": container?.clone ? steps["t3-register"] : { status: "skipped" } };
    const values = Object.fromEntries(Object.entries(expressions).map(([key, value]) => [
      key, environment.evaluate(value.trim().slice(3, -2).trim(), { inputs: { intent }, steps: phaseSteps }),
    ]));
    const args = behavior.methods.create_result.arguments.parse(values);
    assertEquals(args.container === null, container === null);
    const result = createResult(intent, args);
    assertEquals(result.ready, true);
    assertEquals(JSON.parse(result.stdout).commit, commit);
    assertEquals(JSON.parse(result.stdout).t3, container?.clone ? server : undefined);
  }
});

Deno.test("deploy VP workspace reads retain step names for the capture schema", () => {
  const workflow = parse(Deno.readTextFileSync(new URL("../workflows/workflow-operation-instance-deploy-direct.yaml", import.meta.url))) as Workflow;
  const capture = workflow.jobs.flatMap((job) => job.steps).find((step) => step.name === "dev-vp-requirements")!;
  const generated = vpWorkspaceSteps("dev-vp", "inputs.intent").find((step) => step.name === "dev-vp-requirements")!;
  const expressions = [capture, generated].map((step) => (step.task as { inputs: Record<string, string> }).inputs.workspaces);
  // No deploy workspace read was recorded in the selector audit. Use the receiver's declared schema
  // (instance-vp-command/requirements.ts:26), including an empty read and a non-workspace step.
  const steps = {
    "dev-vp-read-workspace-1": { outputs: { stdout: '{"name":"vite-plus","version":"0.2.1"}' } },
    "dev-vp-read-package": { outputs: { stdout: "{}" } },
    "dev-vp-read-workspace-0": { outputs: { stdout: "" } },
  };
  const schema = vpRequirements.methods.capture.arguments.shape.workspaces;
  for (const expression of expressions) for (const reads of [steps, {}] as Record<string, { outputs: { stdout: string } }>[]) {
    const value = environment.evaluate(expression.trim().slice(3, -2).trim(), { steps: reads });
    assertEquals(schema.parse(value), Object.entries(reads)
      .filter(([name]) => name.startsWith("dev-vp-read-workspace-"))
      .map(([step, read]) => ({ step, stdout: read.outputs.stdout })));
  }
});

Deno.test("guarded withdrawal resolves the same child when cleanup is needed and stays deferred during parent preflight", () => {
  const child = parse(Deno.readTextFileSync(new URL("../workflows/workflow-route-withdraw.yaml", import.meta.url))) as { name: string };
  const workflow = parse(Deno.readTextFileSync(new URL("../workflows/workflow-operation-instance-create.yaml", import.meta.url))) as {
    jobs: { steps: { name: string; guard: string; task: { workflowIdOrName: string } }[] }[];
  };
  const cleanup = workflow.jobs.flatMap((job) => job.steps).find((step) => step.name === "withdraw-route")!;
  const evaluate = (expression: string, context: Record<string, unknown> = {}) =>
    environment.evaluate(expression.slice(3, -2).trim(), context);
  const context = { inputs: { intent: { already_active: false, route: {} } },
    steps: { finish: { outputs: { ready: false } }, route: { status: "succeeded" } } };

  // Swamp's guard skips on true: a failed create that published its Route must run the child.
  assertEquals(evaluate(cleanup.guard, context), false);
  assertEquals(evaluate(cleanup.guard, { ...context, steps: { ...context.steps, finish: { outputs: { ready: true } } } }), true);
  for (const name of ["operation-instance-create", "operation-instance-destroy"]) {
    const parent = parse(Deno.readTextFileSync(new URL(`../workflows/workflow-${name}.yaml`, import.meta.url))) as {
      jobs: { steps: { task?: { type: string; workflowIdOrName?: string } }[] }[];
    };
    const target = parent.jobs.flatMap((job) => job.steps).map((step) => step.task)
      .find((task) => task?.type === "workflow" && task.workflowIdOrName?.includes("route-withdraw"))!.workflowIdOrName!;
    assertEquals(target.startsWith("${{"), true, name);
    assertEquals(evaluate(target), child.name);
  }
});
