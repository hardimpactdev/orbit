import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { behavior } from "../extensions/behaviors/operations/instance-dev-command/assets.ts";
import { createResult } from "../extensions/behaviors/operations/instance-dev-command/result.ts";
import { behavior as vpRequirements } from "../extensions/behaviors/operations/instance-vp-command/requirements.ts";
import { vpWorkspaceSteps } from "../extensions/behaviors/operations/instance-vp-command/steps.ts";
import { swampExpression } from "./_support/swamp_expression.ts";
import { environment, failures, type GraphJob, type Outcome, type Ran, runWorkflow, TopologicalSortService } from "./_support/swamp_workflow.ts";

type Workflow = { jobs: { steps: { name: string; task?: unknown }[] }[] };

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
    "clone-t3": { ...succeeded, outputs: { stdout: "{}" } },
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

Deno.test("only a clone whose intent has `fleet` starts its fleet; a normal clone skips the step and finishes as before", () => {
  const workflow = parse(Deno.readTextFileSync(new URL("../workflows/workflow-operation-instance-create.yaml", import.meta.url))) as {
    jobs: { name: string; steps: { name: string; guard: string; dependsOn: unknown[]; task: { inputs: Record<string, string> } }[] }[];
  };
  const steps = workflow.jobs.flatMap((job) => job.steps);
  const step = (name: string) => steps.find((s) => s.name === name)!;
  /** A guard that is true skips its step. */
  const skips = (intent: Record<string, unknown>) => environment.evaluate(swampExpression(step("clone-fleet").guard), { inputs: { intent } });
  const base = { already_active: false, container: { clone: { source: "instance-7" }, project: "orbit-instances" } };
  const nested = { already_active: false, container: { clone: { source: "instance-7" }, project: "orbit-instances-nested", club_key: { available: true } } };
  const fleet = { guests: { gateway: "nested-gateway" } };
  // A normal clone's intent has no `fleet` key at all (NativeDevelopmentInstanceInput), and a default's create has no clone.
  assertEquals(skips(base), true);
  assertEquals(skips({ ...nested }), true);
  assertEquals(skips({ ...base, container: { clone: null, project: "orbit-instances" } }), true);
  assertEquals(skips({ ...nested, already_active: true, fleet }), true);
  assertEquals(skips({ ...nested, fleet }), false);
  // It runs the existing fleet phases after the copy and the Club key, and the workspace waits for it or its skip.
  assert(step("clone-fleet").task.inputs.run.includes("--tags fleet_check ") && step("clone-fleet").task.inputs.run.includes("--tags fleet_resume "));
  assertEquals(step("clone-fleet").dependsOn, [{ step: "clone-container", condition: { type: "succeeded" } },
    { step: "clone-club-key", condition: { type: "or", conditions: [{ type: "succeeded" }, { type: "skipped" }] } }]);
  assert(JSON.stringify(step("clone-workspace").dependsOn).includes('{"step":"clone-fleet","condition":{"type":"or","conditions":[{"type":"succeeded"},{"type":"skipped"}]}}'));
  // The finish step hands the fleet step's status to the receipt: a normal clone's skipped step changes nothing, a nested clone needs it.
  const finish = step("finish").task.inputs;
  const commit = "a".repeat(40);
  const succeeded = { status: "succeeded" };
  const registration = JSON.parse(Deno.readTextFileSync(new URL("fixtures/instance-create-16210-t3-register.json", import.meta.url))).step;
  const ran = { "clone-container": succeeded, "clone-workspace": { ...succeeded, outputs: { stdout: `orbit-commit=${commit}` } },
    "clone-t3": { ...succeeded, outputs: { stdout: "{}" } }, "clone-sessions": { ...succeeded, outputs: { stdout: "{}" } }, "clone-register": registration };
  const receipt = (intent: Record<string, unknown>, fleetStep: Record<string, unknown> | undefined) => {
    const full = { instance_id: 8, database: null, server_database: null, server_valkey: null, processes: [], setup_steps: [], route: null, ...intent };
    const values = Object.fromEntries(Object.entries(finish).map(([key, value]) => [key, environment.evaluate(swampExpression(value),
      { inputs: { intent: full }, steps: { ...ran, ...(fleetStep ? { "clone-fleet": fleetStep } : {}) }, run: { id: "r" } })]));
    return JSON.parse(createResult(full, behavior.methods.create_result.arguments.parse(values)).stdout);
  };
  assertEquals(receipt(base, undefined).ready, true);
  assertEquals(receipt(base, { status: "skipped" }).ready, true);
  assertEquals(receipt({ ...nested, fleet }, succeeded).ready, true);
  assertEquals([receipt({ ...nested, fleet }, { status: "failed" }).ready, receipt({ ...nested, fleet }, { status: "failed" }).failed_step], [false, "fleet"]);
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

/** The create workflow on the pinned engine's levels (`runWorkflow`), with the real `finish` receipt. */
function runCreate(intent: Record<string, unknown>, outcomes: Record<string, Outcome>): Record<string, Ran> {
  return runWorkflow("workflow-operation-instance-create.yaml", "instance.create", intent, outcomes, (step, cel) => {
    const values = Object.fromEntries(Object.entries(step.task.inputs!).map(([key, value]) => [key, cel(value)]));
    const result = createResult(intent, behavior.methods.create_result.arguments.parse(values));
    return { outputs: { ready: result.ready, stdout: result.stdout } };
  });
}

const cloneIntent = {
  instance_id: 8, already_active: false, container: { clone: { source: "instance-7" }, project: "orbit-instances", mail: null },
  database: null, server_database: null, server_valkey: null, processes: [], setup_steps: [], route: null,
};
const commit = "b".repeat(40);
// Durations in seconds from Operation 20198 (Project 69): the copy 7.9 s, the workspace 5.7 s, agent 2.3 s and T3 6.1 s, now one step.
const cloneOutcomes = (): Record<string, Outcome> => ({
  "clone-container": { duration: 7.9 },
  "clone-workspace": { duration: 5.7, outputs: { stdout: `orbit-commit=${commit}\n{"phase":"workspace"}` } },
  "clone-t3": { duration: 7.2, outputs: { stdout: "{}" } },
  "clone-sessions": { duration: 3.5, outputs: { stdout: "{}" } },
  "clone-register": JSON.parse(Deno.readTextFileSync(new URL("fixtures/instance-create-16210-t3-register.json", import.meta.url))).step,
});

Deno.test("the scheduler imports the level sort of the Swamp release that the repository pins", () => {
  const pin = /^swampVersion: \S+-sha\.([0-9a-f]{8})$/m.exec(Deno.readTextFileSync(new URL("../.swamp.yaml", import.meta.url)))![1];
  const imported = /swamp-club\/swamp\/([0-9a-f]{40})\//.exec(Deno.readTextFileSync(new URL("./_support/swamp_workflow.ts", import.meta.url)))![1];
  assertEquals(imported.slice(0, 8), pin, "move the TopologicalSortService import to the new Swamp pin");
});

Deno.test("the workspace and T3 share one step level of the clone job in every clone shape", () => {
  // Swamp's levels come from the static graph, so guards (database, Valkey, Club key, fleet) never move a step to another level.
  const flow = parse(Deno.readTextFileSync(new URL("../workflows/workflow-operation-instance-create.yaml", import.meta.url))) as { jobs: GraphJob[] };
  const clone = flow.jobs.find((job) => job.name === "clone")!;
  const { levels } = new TopologicalSortService().sort(clone.steps.map((step) => ({
    name: step.name, weight: 0, dependencies: (step.dependsOn ?? []).map((d) => d.step),
  })));
  const level = (name: string) => levels.findIndex((names) => names.includes(name));
  assertEquals(level("clone-t3"), level("clone-workspace"));
  assertEquals(level("clone-sessions"), level("clone-workspace") + 1);
  assertEquals(clone.steps.some((step) => step.name === "clone-agent"), false);
  // T3 names the fleet step for its level only: it reads nothing of the fleet, the database steps or the workspace.
  const t3 = clone.steps.find((step) => step.name === "clone-t3")!;
  assertEquals(JSON.stringify(t3.task).includes("fleet") || JSON.stringify(t3.task).includes("database"), false);
});

Deno.test("a normal clone starts the workspace and T3 together when the copy ends, and sessions waits for both", () => {
  const ran = runCreate(cloneIntent, cloneOutcomes());
  assertEquals(ran["clone-club-key"].status, "skipped");
  assertEquals(ran["clone-fleet"].status, "skipped");
  // The production failure before this change: the workspace started only when the agent step of the level before it ended.
  const endedAt = (time: number) => Object.entries(ran).filter(([, r]) => r.end === time && r.end > r.start).map(([name]) => name).join(", ");
  for (const name of ["clone-workspace", "clone-t3"]) {
    assertEquals(ran[name].start, ran["clone-container"].end, `${name} starts when ${endedAt(ran[name].start)} ends, not when the copy ends`);
  }
  assertEquals(ran["clone-sessions"].start, Math.max(ran["clone-workspace"].end, ran["clone-t3"].end));
  for (const name of ["clone-t3", "clone-sessions", "clone-session-copy", "clone-register", "clone-private-cleanup"]) {
    assertEquals(ran[name].status, "succeeded", name);
  }
  assertEquals("clone-agent" in ran, false);
  assertEquals(ran.finish.outputs!.ready, true);
  assertEquals(JSON.parse(String(ran.finish.outputs!.stdout)).commit, commit);
  assertEquals(ran["stop-container"].status, "skipped");
  assertEquals(ran.accepted.status, "succeeded");

  // Sessions is the join: a slow workspace and a slow T3 each hold it back.
  for (const slow of ["clone-workspace", "clone-t3"]) {
    const outcomes = cloneOutcomes();
    outcomes[slow] = { ...outcomes[slow], duration: 30 };
    const late = runCreate(cloneIntent, outcomes);
    assertEquals(late["clone-sessions"].start, late[slow].end, slow);
    assertEquals(late.finish.outputs!.ready, true, slow);
  }
});

Deno.test("a clone with a database starts the workspace and T3 together when the database steps end", () => {
  const outcomes = { ...cloneOutcomes(), "clone-database-snapshot": { duration: 4, outputs: { digest: { stdout: "abc -" } } }, "clone-database-install": { duration: 9 } };
  const ran = runCreate({ ...cloneIntent, database: {} }, outcomes);
  // The database steps start with the clone job, beside the copy.
  assertEquals(ran["clone-database-snapshot"].start, ran["clone-input"].start);
  const databaseEnd = Math.max(ran["clone-database-install"].end, ran["clone-database-cleanup"].end);
  assertEquals(databaseEnd > ran["clone-container"].end, true);
  assertEquals(ran["clone-workspace"].start, databaseEnd);
  assertEquals(ran["clone-t3"].start, ran["clone-workspace"].start);
  assertEquals(ran["clone-sessions"].start, Math.max(ran["clone-workspace"].end, ran["clone-t3"].end));
  assertEquals(ran.finish.outputs!.ready, true);
});

Deno.test("a nested-fleet clone starts T3 after the fleet step, beside the workspace", () => {
  const outcomes: Record<string, Outcome> = { ...cloneOutcomes(), "clone-fleet": { duration: 40 } };
  const ran = runCreate({ ...cloneIntent, fleet: { seed: "e".repeat(40) } }, outcomes);
  assertEquals(ran["clone-fleet"].status, "succeeded");
  assertEquals(ran["clone-workspace"].start, ran["clone-fleet"].end);
  assertEquals(ran["clone-t3"].start, ran["clone-fleet"].end);
  assertEquals(ran["clone-sessions"].start, Math.max(ran["clone-workspace"].end, ran["clone-t3"].end));
  assertEquals(ran.finish.outputs!.ready, true);
  // A failed fleet start runs neither line, registers nothing and names the fleet.
  outcomes["clone-fleet"] = { status: "failed", duration: 40 };
  const failed = runCreate({ ...cloneIntent, fleet: { seed: "e".repeat(40) } }, outcomes);
  for (const name of ["clone-workspace", "clone-t3", "clone-sessions", "clone-register"]) assertEquals(failed[name].status, "skipped", name);
  assertEquals(JSON.parse(String(failed.finish.outputs!.stdout)).failed_step, "fleet");
  assertEquals(failed["stop-container"].status, "succeeded");
});

Deno.test("a clone whose workspace fails while T3 runs registers nothing and stops the container", () => {
  const outcomes = cloneOutcomes();
  outcomes["clone-workspace"] = { status: "failed", duration: 5.7 };
  const ran = runCreate(cloneIntent, outcomes);
  assertEquals(ran["clone-t3"].status, "succeeded");
  for (const name of ["clone-sessions", "clone-session-copy", "clone-register"]) assertEquals(ran[name].status, "skipped", name);
  const result = JSON.parse(String(ran.finish.outputs!.stdout));
  assertEquals([ran.finish.outputs!.ready, result.failed_step, result.error_code, result.t3], [false, "workspace", "instance.create_workspace_failed", undefined]);
  assertEquals(ran["stop-container"].status, "succeeded");
  assertEquals(ran["clone-private-cleanup"].status, "succeeded");
  assertEquals(ran.accepted.status, "failed");
});

Deno.test("a clone whose T3 step fails, early or late, registers nothing, stops the container and names T3", () => {
  // The agent part (early) and the T3 part (late) fail the same step; instance_clone_test.py proves the code of each part.
  for (const duration of [1.1, 7.2]) {
    const outcomes = cloneOutcomes();
    outcomes["clone-t3"] = { status: "failed", duration };
    const ran = runCreate(cloneIntent, outcomes);
    assertEquals(ran["clone-workspace"].status, "succeeded");
    for (const name of ["clone-sessions", "clone-register"]) assertEquals(ran[name].status, "skipped", name);
    // The copy brings the step's t3.json, which names the part, to the run folder; create_result reads it (instance_clone_test.py).
    assertEquals(ran["clone-session-copy"].status, "succeeded");
    const result = JSON.parse(String(ran.finish.outputs!.stdout));
    assertEquals([ran.finish.outputs!.ready, result.failed_step], [false, "t3"]);
    assertEquals(ran["stop-container"].status, "succeeded");
    assertEquals(ran["clone-private-cleanup"].status, "succeeded");
  }
});

Deno.test("a clone whose database step fails skips the workspace, registers nothing and names the database", () => {
  const outcomes = { ...cloneOutcomes(), "clone-database-snapshot": { outputs: { digest: { stdout: "abc -" } } }, "clone-database-install": { status: "failed", duration: 9 } };
  const ran = runCreate({ ...cloneIntent, database: {} }, outcomes);
  assertEquals([ran["clone-workspace"].status, ran["clone-t3"].status], ["skipped", "succeeded"]);
  assertEquals(ran["clone-database-cleanup"].status, "succeeded");
  assertEquals(ran["clone-register"].status, "skipped");
  assertEquals(JSON.parse(String(ran.finish.outputs!.stdout)).failed_step, "database");
  assertEquals(ran["stop-container"].status, "succeeded");
});

Deno.test("a nested-fleet clone's start reads only the Club key, and every wake reads no key", () => {
  assertEquals(failures("workflow-operation-instance-start.yaml", {
    execution_secrets: { club_key: copy("nested-fleet-club-api-key") },
    intent: { container: { clone: {}, project: "orbit-instances-nested", club_key: { available: true } }, fleet: {} },
  }), []);
  // The wake's own workflow names no secret: a normal wake keeps its empty map.
  assertEquals(failures("workflow-operation-instance-wake.yaml", { execution_secrets: {}, intent: { container: { clone: {} } } }), []);
});
