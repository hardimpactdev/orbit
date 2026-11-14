import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { behavior } from "../extensions/behaviors/operations/instance-dev-command/assets.ts";
import { createResult } from "../extensions/behaviors/operations/instance-dev-command/result.ts";
import { behavior as vpRequirements } from "../extensions/behaviors/operations/instance-vp-command/requirements.ts";
import { vpWorkspaceSteps } from "../extensions/behaviors/operations/instance-vp-command/steps.ts";
import { swampExpression } from "./_support/swamp_expression.ts";
import { environment, failures, type GraphJob, levelsOf, type Outcome, type Ran, runWorkflow } from "./_support/swamp_workflow.ts";

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

Deno.test("a clone's create delivers a nested clone's Club key and has no fleet, database or Route step: the completion owns them", () => {
  const workflow = parse(Deno.readTextFileSync(new URL("../workflows/workflow-operation-instance-create.yaml", import.meta.url))) as {
    jobs: { name: string; steps: { name: string; guard: string; dependsOn: unknown[]; task: { inputs: Record<string, string> } }[] }[];
  };
  const clone = workflow.jobs.find((job) => job.name === "clone")!.steps;
  assertEquals(clone.map((step) => step.name), ["clone-input", "clone-container", "clone-club-key", "clone-workspace", "clone-t3", "clone-sessions",
    "clone-session-copy", "clone-register"]);
  assertEquals(workflow.jobs.map((job) => job.name), ["input", "legacy", "clone", "create"]);
  const step = (name: string) => clone.find((s) => s.name === name)!;
  // The Club key comes right after the container's first start, as before; the workspace and T3 wait for it or its skip.
  assertEquals(step("clone-club-key").dependsOn, [{ step: "clone-container", condition: { type: "succeeded" } }]);
  const afterKey = [{ step: "clone-container", condition: { type: "succeeded" } },
    { step: "clone-club-key", condition: { type: "or", conditions: [{ type: "succeeded" }, { type: "skipped" }] } }];
  assertEquals([step("clone-workspace").dependsOn, step("clone-t3").dependsOn], [afterKey, afterKey]);
  // T3 is registered only after the join, so a task copy's cleaning in the checkout ends before anyone can reach its T3.
  assertEquals(step("clone-sessions").dependsOn, [{ step: "clone-t3", condition: { type: "succeeded" } }, { step: "clone-workspace", condition: { type: "succeeded" } }]);
  assertEquals(step("clone-register").dependsOn, [{ step: "clone-session-copy", condition: { type: "succeeded" } }]);
  // The workspace step runs the checkout part only; the services part is the completion's.
  assert(step("clone-workspace").task.inputs.argv.endsWith('+ ["checkout"] }}'));
  const skips = (intent: Record<string, unknown>) => environment.evaluate(swampExpression(step("clone-club-key").guard), { inputs: { intent } });
  const nested = { already_active: false, container: { clone: { source: "instance-7" }, project: "orbit-instances-nested", club_key: { available: true } } };
  assertEquals(skips(nested), false);
  assertEquals(skips({ ...nested, container: { ...nested.container, project: "orbit-instances" } }), true);
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
// Seconds per step from timeline.md (Projects 69, 68, 76): T3, the longer of its level, 4.2 s beside the checkout.
const cloneOutcomes = (): Record<string, Outcome> => ({
  input: { duration: 1.3 },
  "clone-input": { duration: 2 },
  "clone-container": { duration: 5.3 },
  "clone-workspace": { duration: 3, outputs: { stdout: `orbit-commit=${commit}\n{"phase":"checkout","environment":{"copied":["APP_NAME"],"dropped":[]}}` } },
  "clone-t3": { duration: 4.2, outputs: { stdout: "{}" } },
  "clone-sessions": { duration: 2.6, outputs: { stdout: "{}" } },
  "clone-session-copy": { duration: 1.4 },
  "clone-register": { ...JSON.parse(Deno.readTextFileSync(new URL("fixtures/instance-create-16210-t3-register.json", import.meta.url))).step, duration: 1.3 },
  "clone-private-cleanup": { duration: 1.3 },
  cleanup: { duration: 0.3 },
});

Deno.test("the scheduler imports the level sort of the Swamp release that the repository pins", () => {
  const pin = /^swampVersion: \S+-sha\.([0-9a-f]{8})$/m.exec(Deno.readTextFileSync(new URL("../.swamp.yaml", import.meta.url)))![1];
  const imported = /swamp-club\/swamp\/([0-9a-f]{40})\//.exec(Deno.readTextFileSync(new URL("./_support/swamp_workflow.ts", import.meta.url)))![1];
  assertEquals(imported.slice(0, 8), pin, "move the TopologicalSortService import to the new Swamp pin");
});

Deno.test("a clone's create ends at the registration: the workspace and T3 share one level, by the pinned engine's sort", () => {
  // Swamp's levels come from the static graph, so guards (the Club key, the legacy steps) never move a step to another level.
  const { jobs, steps } = levelsOf("workflow-operation-instance-create.yaml");
  const sorted = (levels: string[][]) => levels.map((level) => [...level].sort());
  assertEquals(sorted(jobs), [["input"], ["clone", "legacy"], ["create"]]);
  assertEquals(sorted(steps.clone), [["clone-input"], ["clone-container"], ["clone-club-key"], ["clone-t3", "clone-workspace"], ["clone-sessions"],
    ["clone-session-copy"], ["clone-register"]]);
  assertEquals(sorted(steps.create), [["clone-private-cleanup", "finish"], ["stop-container", "withdraw-route"], ["accepted", "cleanup"]]);
  // T3 reads nothing of the workspace.
  const flow = parse(Deno.readTextFileSync(new URL("../workflows/workflow-operation-instance-create.yaml", import.meta.url))) as { jobs: GraphJob[] };
  const t3 = flow.jobs.find((job) => job.name === "clone")!.steps.find((step) => step.name === "clone-t3")!;
  assertEquals(JSON.stringify(t3.task).includes("clone-workspace"), false);
});

Deno.test("a normal clone succeeds at the registration in about 24 s, with the workspace and T3 together after the copy", () => {
  const ran = runCreate(cloneIntent, cloneOutcomes());
  assertEquals(ran["clone-club-key"].status, "skipped");
  for (const name of ["clone-workspace", "clone-t3"]) assertEquals(ran[name].start, ran["clone-container"].end, name);
  assertEquals(ran["clone-sessions"].start, Math.max(ran["clone-workspace"].end, ran["clone-t3"].end));
  for (const name of ["clone-t3", "clone-sessions", "clone-session-copy", "clone-register", "clone-private-cleanup"]) {
    assertEquals(ran[name].status, "succeeded", name);
  }
  // No step of the completion runs here: no Route, database or fleet step exists in the create.
  assertEquals(Object.keys(ran).filter((name) => /route|database|server-|fleet|services|check/.test(name) && ran[name].status !== "skipped"), []);
  assertEquals(ran.finish.start, ran["clone-register"].end);
  // The private folder goes beside finish, not after the container stop.
  assertEquals(ran["clone-private-cleanup"].start, ran.finish.start);
  assertEquals(ran.finish.outputs!.ready, true);
  assertEquals(JSON.parse(String(ran.finish.outputs!.stdout)).commit, commit);
  assertEquals(JSON.parse(String(ran.finish.outputs!.stdout)).environment, { copied: ["APP_NAME"], dropped: [] });
  assertEquals([ran["stop-container"].status, ran["withdraw-route"].status, ran.accepted.status], ["skipped", "skipped", "succeeded"]);
  // Seconds per level, from timeline.md: input 1.3 · stage 2.0 · copy 5.3 · T3 4.2 beside the checkout · sessions 2.6 · copy 1.4 · register 1.3 ·
  // finish 1.3. With 3.8 s for the Gateway's admission and the Swamp start, the create succeeds about 24 s after the click.
  assertEquals(Math.round(ran.finish.end * 10) / 10, 19.1);
  assertEquals(Math.round((3.8 + ran.accepted.end) * 10) / 10, 24.2);

  // Sessions is the join: a slow workspace and a slow T3 each hold it back.
  for (const slow of ["clone-workspace", "clone-t3"]) {
    const outcomes = cloneOutcomes();
    outcomes[slow] = { ...outcomes[slow], duration: 30 };
    const late = runCreate(cloneIntent, outcomes);
    assertEquals(late["clone-sessions"].start, late[slow].end, slow);
    assertEquals(late.finish.outputs!.ready, true, slow);
  }
});

Deno.test("a clone with a database or a Route ends at the registration as fast: its completion makes them", () => {
  const plain = runCreate(cloneIntent, cloneOutcomes());
  const owned = runCreate({ ...cloneIntent, database: {}, server_database: { node_id: 5, fill: ["docker"] }, route: { leaf_indexes: [0] } }, cloneOutcomes());
  assertEquals(owned.finish.start, plain.finish.start);
  assertEquals([owned.finish.outputs!.ready, owned["withdraw-route"].status], [true, "skipped"]);
});

Deno.test("a nested-fleet clone gets its Club key before the workspace and T3, and starts no fleet in its create", () => {
  const outcomes: Record<string, Outcome> = { ...cloneOutcomes(), "clone-club-key": { duration: 1.5, outputs: { stdout: '{"phase": "club-key", "club_key": "changed"}' } } };
  const nested = { ...cloneIntent, container: { ...cloneIntent.container, project: "orbit-instances-nested", club_key: { available: true } }, fleet: { seed: "e".repeat(40) } };
  const ran = runCreate(nested, outcomes);
  assertEquals(ran["clone-club-key"].status, "succeeded");
  assertEquals([ran["clone-workspace"].start, ran["clone-t3"].start], [ran["clone-club-key"].end, ran["clone-club-key"].end]);
  assertEquals("clone-fleet" in ran, false);
  assertEquals(ran.finish.outputs!.ready, true);
  assertEquals(JSON.parse(String(ran.finish.outputs!.stdout)).club_key, "changed");
  // A failed Club key runs neither line, registers nothing and stops the container.
  outcomes["clone-club-key"] = { status: "failed", duration: 1.5 };
  const failed = runCreate(nested, outcomes);
  for (const name of ["clone-workspace", "clone-t3", "clone-sessions", "clone-register"]) assertEquals(failed[name].status, "skipped", name);
  assertEquals([failed.finish.outputs!.ready, failed["stop-container"].status, failed["clone-private-cleanup"].status], [false, "succeeded", "succeeded"]);
});

Deno.test("each failure before the end names its step, registers nothing and stops the container", () => {
  const cases: [string, Record<string, Outcome>, string, string][] = [
    ["stage", { "clone-input": { status: "failed" } }, "clone", "instance.create_clone_failed"],
    ["copy", { "clone-container": { status: "failed", duration: 5.3 } }, "clone", "instance.create_clone_failed"],
    ["workspace", { "clone-workspace": { status: "failed", duration: 3 } }, "workspace", "instance.create_workspace_failed"],
    ["T3", { "clone-t3": { status: "failed", duration: 4.2 } }, "t3", "instance.t3_unready"],
    ["sessions", { "clone-sessions": { status: "failed", duration: 2.6 } }, "register", "instance.create_register_failed"],
    ["register", { "clone-register": { status: "failed", duration: 1.3 } }, "register", "instance.create_register_failed"],
  ];
  for (const [label, outcomes, step, code] of cases) {
    const ran = runCreate(cloneIntent, { ...cloneOutcomes(), ...outcomes });
    const result = JSON.parse(String(ran.finish.outputs!.stdout));
    assertEquals([ran.finish.outputs!.ready, result.failed_step, result.error_code, result.t3], [false, step, code, undefined], label);
    // Nothing to withdraw: the create publishes no Route.
    assertEquals([ran["stop-container"].status, ran["withdraw-route"].status, ran["clone-private-cleanup"].status, ran.cleanup.status, ran.accepted.status],
      [label === "stage" ? "skipped" : "succeeded", "skipped", "succeeded", "succeeded", "failed"], label);
  }
  // The T3 step brings its part's t3.json to the run, early or late: the create result names the part (instance_clone_test.py).
  const t3 = runCreate(cloneIntent, { ...cloneOutcomes(), "clone-t3": { status: "failed", duration: 1.1 } });
  assertEquals([t3["clone-workspace"].status, t3["clone-session-copy"].status, t3["clone-register"].status], ["succeeded", "succeeded", "skipped"]);
});

Deno.test("a nested-fleet clone's start reads only the Club key, and every wake reads no key", () => {
  assertEquals(failures("workflow-operation-instance-start.yaml", {
    execution_secrets: { club_key: copy("nested-fleet-club-api-key") },
    intent: { container: { clone: {}, project: "orbit-instances-nested", club_key: { available: true } }, fleet: {} },
  }), []);
  // The wake's own workflow names no secret: a normal wake keeps its empty map.
  assertEquals(failures("workflow-operation-instance-wake.yaml", { execution_secrets: {}, intent: { container: { clone: {} } } }), []);
});
