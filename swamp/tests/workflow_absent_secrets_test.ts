import { assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { Environment } from "npm:@marcbachmann/cel-js@7.6.1";
import { behavior } from "../extensions/behaviors/operations/instance-dev-command/assets.ts";
import { createResult } from "../extensions/behaviors/operations/instance-dev-command/result.ts";
import { behavior as vpRequirements } from "../extensions/behaviors/operations/instance-vp-command/requirements.ts";
import { vpWorkspaceSteps } from "../extensions/behaviors/operations/instance-vp-command/steps.ts";
import { swampExpression } from "./_support/swamp_expression.ts";

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
    intent: { container: null, environment_testing: false, server_database: null, server_valkey: null },
  }), []);
});

Deno.test("a container default's create and a clone's create read their own keys", () => {
  const execution_secrets = {
    git_credential: copy("instance-git-credential-1"), environment: copy("instance-create-environment-input-1"),
    process_environments: copy("instance-create-processes-1"),
  };
  // Every container intent names its Incus project (InstanceContainer::intent).
  const intent = { container: { clone: null, project: "orbit-instances" }, environment_testing: false, server_database: null, server_valkey: null };
  assertEquals(failures("workflow-operation-instance-create.yaml", { execution_secrets, intent }), []);
  assertEquals(failures("workflow-operation-instance-create.yaml", {
    execution_secrets: { ...execution_secrets, model_environment: copy("instance-create-model-1") },
    intent: { ...intent, container: { clone: {}, project: "orbit-instances" } },
  }), []);
});

Deno.test("a nested-fleet create and clone read the Club key copy only when the intent says it is there", () => {
  const execution_secrets = {
    git_credential: copy("instance-git-credential-1"), environment: copy("instance-create-environment-input-1"),
    process_environments: copy("instance-create-processes-1"), model_environment: copy("instance-create-model-1"),
  };
  for (const clone of [null, {}]) {
    for (const available of [true, false]) {
      const club = available ? { club_key: copy("nested-fleet-club-api-key") } : {};
      assertEquals(failures("workflow-operation-instance-create.yaml", {
        execution_secrets: { ...execution_secrets, ...club },
        intent: { container: { clone, project: "orbit-instances-nested", club_key: { available } }, environment_testing: false, server_database: null, server_valkey: null },
      }), [], `clone ${clone !== null}, available ${available}`);
    }
  }
});

/** Each value of a Club key step: Swamp refuses a secret expression that tests `execution_secrets` with `in`. */
Deno.test("the Club key steps read their copy with one whole vault.get and never test execution_secrets", () => {
  for (const workflow of ["workflow-operation-instance-create.yaml", "workflow-operation-instance-deploy-direct.yaml"]) {
    const text = Deno.readTextFileSync(new URL(`../workflows/${workflow}`, import.meta.url));
    const lines = text.split("\n").filter((line) => line.includes("execution_secrets.club_key"));
    assertEquals(lines.length > 0, true, workflow);
    for (const line of lines) {
      assertEquals(/'\$\{\{ vault\.get\(/.test(line.trim().replace(/^[A-Z_a-z]+: /, "")), true, line);
      assertEquals(/\bin inputs\.execution_secrets/.test(line), false, line);
    }
  }
});

Deno.test("migration reads no new source key from an older Gateway input", () => {
  for (const source of [undefined, { repository: "https://github.com/owner/repo.git", branch: "main" }]) {
    assertEquals(failures("workflow-operation-instance-migrate.yaml", {
      node_id: 9,
      execution_secrets: {
        process_environments: copy("instance-migrate-processes-1"),
        model_environment: copy("instance-migrate-model-1"),
        ...(source === undefined ? {} : { source_credential: copy("instance-migrate-source-1") }),
      },
      intent: { migrate: source === undefined ? {} : { source } },
    }), []);
  }
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

Deno.test("a container deployment reads the Club key copy only for a nested-fleet container that has it", () => {
  const execution_secrets = { credential: copy("development-deploy-operation-1-credential"), environment: copy("instance-environment-1"),
    process_environments: copy("development-deploy-operation-1-processes") };
  for (const container of [{ project: "orbit-instances" }, { project: "orbit-instances-nested", club_key: { available: false } },
    { project: "orbit-instances-nested", club_key: { available: true } }]) {
    const club = container.club_key?.available ? { club_key: copy("nested-fleet-club-api-key") } : {};
    assertEquals(failures("workflow-operation-instance-deploy-direct.yaml", {
      execution_secrets: { ...execution_secrets, ...club },
      intent: { deployment: { variant: "container" }, container, production: { instance_id: 1, home: "/srv/app" } },
    }), [], JSON.stringify(container));
  }
});

Deno.test("a container process:start, stop and restart read no `read` section, which only a migrated Schedule log read has", () => {
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
    checkout: workspace, container: succeeded, workspace, seed: succeeded,
    "container-processes": succeeded, "clone-container": succeeded, "clone-workspace": workspace,
    "clone-agent": succeeded, "clone-t3": { ...succeeded, outputs: { stdout: "{}" } },
    "clone-sessions": { ...succeeded, outputs: { stdout: "{}" } },
    "clone-register": registration,
  };
  for (const container of [null, { clone: null }, { clone: { source: "instance-7" } }]) {
    // StartDevelopmentInstanceCreateAction always admits database, including null when no SQLite copy is needed.
    // Keep that key: Swamp's CEL treats an omitted map key as an error, not null.
    const intent = { instance_id: 8, already_active: false, container, database: null, server_database: null, server_valkey: null, processes: [], setup_steps: [], route: null };
    const phaseSteps = { ...steps, "clone-register": container?.clone ? registration : { status: "skipped" } };
    // Swamp supplies run.id independently of the admitting Gateway release.
    const run = { id: "previous-gateway-run" };
    const values = Object.fromEntries(Object.entries(expressions).map(([key, value]) => [
      key, environment.evaluate(swampExpression(value), { inputs: { intent }, steps: phaseSteps, run }),
    ]));
    // A container create, normal or clone, hands the finish method an object as `container`, never a cut string.
    assertEquals(container === null ? values.container : typeof values.container, container === null ? null : "object");
    const args = behavior.methods.create_result.arguments.parse(values);
    assertEquals(args.folder, "/tmp/orbit-ansible-previous-gateway-run");
    assertEquals(args.container === null, container === null);
    const result = createResult(intent, args);
    assertEquals(result.ready, true);
    assertEquals(JSON.parse(result.stdout).commit, commit);
    assertEquals(JSON.parse(result.stdout).t3, container?.clone ? server : undefined);
  }
  // A nested-fleet clone's club-key step reaches the receipt as the script's own word; a skipped step gives none.
  const nested = { clone: { source: "instance-7" }, club_key: { directory: "/run/orbit-club" } };
  const intent = { instance_id: 8, already_active: false, container: nested, database: null, server_database: null, server_valkey: null, processes: [], setup_steps: [], route: null };
  for (const [club, word] of [[{ ...succeeded, outputs: { stdout: '{"phase": "club-key", "changed": false, "club_key": "unchanged", "completed_at": "x"}' } }, "unchanged"], [{ status: "skipped" }, undefined]] as const) {
    const values = Object.fromEntries(Object.entries(expressions).map(([key, value]) => [
      key, environment.evaluate(swampExpression(value), { inputs: { intent }, steps: { ...steps, "clone-club-key": club }, run: { id: "r" } }),
    ]));
    assertEquals(JSON.parse(createResult(intent, behavior.methods.create_result.arguments.parse(values)).stdout).club_key, word);
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
    const value = environment.evaluate(swampExpression(expression), { steps: reads });
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
    environment.evaluate(swampExpression(expression), context);
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
