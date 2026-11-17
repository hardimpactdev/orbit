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
    execution_secrets,
    intent: { ...intent, container: { clone: {}, project: "orbit-instances" } },
  }), []);
});

Deno.test("a nested-fleet create and clone read the Club key copy only when the intent says it is there", () => {
  const execution_secrets = {
    git_credential: copy("instance-git-credential-1"), environment: copy("instance-create-environment-input-1"),
    process_environments: copy("instance-create-processes-1"),
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
// https://github.com/swamp-club/swamp/blob/537e03d1/src/infrastructure/cel/cel_evaluator.ts
// Native workflow validation type-checks these expressions with runtime data as `dyn`; this test evaluates them with real receipt values.
Deno.test("create finalization evaluates checkout, default and clone receipts with Swamp's CEL types", () => {
  const workflow = parse(Deno.readTextFileSync(new URL("../workflows/workflow-operation-instance-create.yaml", import.meta.url))) as Workflow;
  const finish = workflow.jobs.flatMap((job) => job.steps).find((step) => step.name === "finish")!;
  const expressions = (finish.task as { inputs: Record<string, string> }).inputs;
  // One map, one YAML anchor: clone-register reads exactly what finish reads.
  const register = workflow.jobs.flatMap((job) => job.steps).find((step) => step.name === "clone-register")!;
  assertEquals((register.task as { inputs: Record<string, string> }).inputs, expressions);
  const commit = "a".repeat(40);
  const succeeded = { status: "succeeded" };
  const workspace = { ...succeeded, outputs: { stdout: `orbit-commit=${commit}` } };
  const registration = JSON.parse(Deno.readTextFileSync(new URL("fixtures/instance-create-16210-t3-register.json", import.meta.url))).step;
  const server = JSON.parse(registration.outputs.stdout);
  const steps = {
    checkout: workspace, container: succeeded, workspace, seed: succeeded,
    "container-processes": succeeded, "clone-container": succeeded,
    // The T3 step prints the checkout's commit line, then T3's own line.
    "clone-t3": { ...succeeded, outputs: { stdout: `orbit-commit=${commit}\n{"phase":"t3"}` } },
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
    assertEquals([args.folder, args.t3_file], ["/tmp/orbit-ansible-previous-gateway-run", "/tmp/orbit-ansible-previous-gateway-run-t3.json"]);
    assertEquals(args.container === null, container === null);
    // A ready clone's clone-register takes the same map and adds its registered server (instance-t3/mod.ts).
    const phases = container?.clone && args.container !== null ? { ...args, container: { ...args.container, register: "succeeded", server: registration.outputs.stdout } } : args;
    const result = createResult(intent, phases);
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

Deno.test("a clone's create delivers a nested clone's Club key and has no fleet or database step: the completion owns them", () => {
  const workflow = parse(Deno.readTextFileSync(new URL("../workflows/workflow-operation-instance-create.yaml", import.meta.url))) as {
    jobs: { name: string; steps: { name: string; guard: string; dependsOn: unknown[]; task: { inputs: Record<string, string> } }[] }[];
  };
  const clone = workflow.jobs.find((job) => job.name === "clone")!.steps;
  // The copy stages the release scripts itself; the registration is in the create job, after the publication.
  assertEquals(clone.map((step) => step.name), ["clone-ci-hold", "clone-seed-wait", "clone-container", "clone-club-key", "clone-t3", "clone-session-copy"]);
  // Its Route is the job `publication`, which only a clone whose admission took the Route Nodes runs.
  assertEquals(workflow.jobs.map((job) => job.name), ["input", "legacy", "clone", "publication", "create"]);
  const step = (name: string) => clone.find((s) => s.name === name)!;
  // The Club key comes right after the container's first start, as before; the T3 step, with the checkout, waits for it or its skip.
  assertEquals(step("clone-club-key").dependsOn, [{ step: "clone-container", condition: { type: "succeeded" } }]);
  const afterKey = [{ step: "clone-container", condition: { type: "succeeded" } },
    { step: "clone-club-key", condition: { type: "or", conditions: [{ type: "succeeded" }, { type: "skipped" }] } }];
  assertEquals(step("clone-t3").dependsOn, afterKey);
  // T3 is registered only after the T3 step checked the checkout too, so a task copy's cleaning ends before anyone can reach its T3.
  assertEquals(step("clone-session-copy").dependsOn, [{ step: "clone-t3", condition: { type: "always" } }]);
  const create = workflow.jobs.find((job) => job.name === "create")!.steps;
  const register = create.find((s) => s.name === "clone-register")!;
  assertEquals(register.dependsOn, []);
  assert(register.guard.includes('steps["clone-session-copy"].status == "succeeded"'));
  assertEquals(create.find((s) => s.name === "finish")!.dependsOn, [{ step: "clone-register", condition: { type: "always" } }]);
  // A proof copy holds its CI place before the copy exists; every other clone skips the hold and copies at once.
  assertEquals(step("clone-ci-hold").dependsOn, []);
  // The copy waits for its admitted seed first (docs/reference/development-containers.md#a-retake-keeps-the-seed); a wait that reached
  // its bound fails only its own step, and the copy still runs.
  assertEquals(step("clone-seed-wait").dependsOn,
    [{ step: "clone-ci-hold", condition: { type: "or", conditions: [{ type: "succeeded" }, { type: "skipped" }] } }]);
  assertEquals(step("clone-container").dependsOn,
    [{ step: "clone-ci-hold", condition: { type: "or", conditions: [{ type: "succeeded" }, { type: "skipped" }] } },
      { step: "clone-seed-wait", condition: { type: "always" } }]);
  const held = { already_active: false, container: { name: "instance-8", clone: { source: "instance-7", ci_hold: { places: 5 } } } };
  const holds = (intent: Record<string, unknown>) => environment.evaluate(swampExpression(step("clone-ci-hold").guard), { inputs: { intent } });
  const holdArgv = (intent: Record<string, unknown>) => environment.evaluate(swampExpression(step("clone-ci-hold").task.inputs.argv), { inputs: { intent } });
  assertEquals([holds(held), holds({ ...held, container: { name: "instance-8", clone: { source: "instance-7" } } }), holds({ ...held, container: null })],
    [false, true, true]);
  // The step checks the script before it calls it: a Node without it prints the refusal line and calls nothing.
  assertEquals(holdArgv(held), ["timeout", "240", "sh", "-c", "[ -x \"$1\" ] || { echo orbit-ci-hold=unavailable; exit 0; }; exec \"$@\"", "orbit-ci-hold", "/usr/local/libexec/orbit-ci-runner-hold", "hold", "instance-8", "5"]);
  assertEquals(holdArgv({ ...held, container: null }), ["true"]);
  // The T3 step runs the checkout part only; the services part is the completion's. Its stdin is the workspace's private JSON.
  assert(step("clone-t3").task.inputs.argv.endsWith(', "t3"] }}'));
  assert(step("clone-t3").task.inputs.stdin.includes("process_environments"));
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

const registration = JSON.parse(Deno.readTextFileSync(new URL("fixtures/instance-create-16210-t3-register.json", import.meta.url))).step;

/**
 * The create workflow on the pinned engine's levels (`runWorkflow`), with the real result of `finish` and of a ready clone's
 * `clone-register`, which builds the same result from the same map with its registered server (instance-t3/mod.ts).
 */
function runCreate(intent: Record<string, unknown>, outcomes: Record<string, Outcome>): Record<string, Ran> {
  return runWorkflow("workflow-operation-instance-create.yaml", "instance.create", intent, outcomes, (step, cel) => {
    const given = outcomes[step.name] ?? {};
    if (given.status === "failed") return given;
    const values = Object.fromEntries(Object.entries(step.task.inputs!).map(([key, value]) => [key, cel(value)]));
    const args = behavior.methods.create_result.arguments.parse(values);
    const container = step.name === "clone-register" && args.container !== null
      ? { ...args.container, register: "succeeded", server: registration.outputs.stdout } : args.container;
    const result = createResult(intent, { ...args, container });
    // A ready create's result step removes the run folder itself (assets.ts, instance-t3/mod.ts), so the cleanup step skips.
    return { duration: given.duration, outputs: { ready: result.ready, stdout: result.stdout, removed: result.ready } };
  }, ["finish", "clone-register"]);
}

/** The result step that ran: a ready clone's clone-register, else finish (the Gateway's terminal `fallback`). */
const resultOf = (ran: Record<string, Ran>) => ran.finish.status === "skipped" ? ran["clone-register"] : ran.finish;

const cloneIntent = {
  instance_id: 8, already_active: false, container: { clone: { source: "instance-7" }, project: "orbit-instances", mail: null },
  database: null, server_database: null, server_valkey: null, processes: [], setup_steps: [], route: null,
};
const commit = "b".repeat(40);
// Seconds per step from timeline.md (Projects 69, 68, 76): T3, the longer of its level, 4.2 s beside the checkout.
const cloneOutcomes = (): Record<string, Outcome> => ({
  input: { duration: 1.3 },
  // The seed wait: one dispatch, and a normal copy finds its seed at the first request.
  "clone-seed-wait": { duration: 0.9 },
  // The copy step stages the stage copy first: about 0.1 s more than the copy alone.
  "clone-container": { duration: 5.4 },
  // The T3 step: the checkout beside T3 (4.2 s), then the sessions check (0.2 s), in one step.
  "clone-t3": { duration: 4.4, outputs: { stdout: `orbit-commit=${commit}\n{"phase":"checkout","environment":{"copied":["APP_NAME"],"dropped":[]}}\n{"phase":"t3"}` } },
  "clone-session-copy": { duration: 1.4 },
  "clone-register": { duration: 1.3 },
  "clone-private-cleanup": { duration: 1.3 },
  cleanup: { duration: 0.3 },
});

Deno.test("the scheduler imports the level sort of the Swamp release that the repository pins", () => {
  const pin = /^swampVersion: \S+-sha\.([0-9a-f]{8})$/m.exec(Deno.readTextFileSync(new URL("../.swamp.yaml", import.meta.url)))![1];
  const imported = /swamp-club\/swamp\/([0-9a-f]{40})\//.exec(Deno.readTextFileSync(new URL("./_support/swamp_workflow.ts", import.meta.url)))![1];
  assertEquals(imported.slice(0, 8), pin, "move the TopologicalSortService import to the new Swamp pin");
});

Deno.test("a clone's create ends at the registration: five dispatches, one T3 step with the checkout, by the pinned engine's sort", () => {
  // Swamp's levels come from the static graph, so guards (the Club key, the legacy steps) never move a step to another level.
  const { jobs, steps } = levelsOf("workflow-operation-instance-create.yaml");
  const sorted = (levels: string[][]) => levels.map((level) => [...level].sort());
  assertEquals(sorted(jobs), [["input"], ["clone", "legacy", "publication"], ["create"]]);
  // The address is published beside the copy, in its own job (docs/cli/instance/container/create.mmd). Each step allows its failure,
  // so a failed publication never fails the run and so the create (Swamp fails a job, and the run, on a failed step otherwise).
  assertEquals(sorted(steps.publication), [["clone-route-csr", "clone-route-input"], ["clone-route"]]);
  const publicationJob = (parse(Deno.readTextFileSync(new URL("../workflows/workflow-operation-instance-create.yaml", import.meta.url))) as { jobs: { name: string; steps: { name: string; allowFailure?: boolean }[] }[] })
    .jobs.find((job) => job.name === "publication")!;
  assertEquals(publicationJob.steps.map((step) => [step.name, step.allowFailure]), [["clone-route-input", true], ["clone-route-csr", true], ["clone-route", true]]);
  assertEquals(sorted(steps.clone), [["clone-ci-hold"], ["clone-seed-wait"], ["clone-container"], ["clone-club-key"], ["clone-t3"], ["clone-session-copy"]]);
  // A ready clone runs the first level (the registration beside the private folder's removal) and skips the rest at no cost.
  assertEquals(sorted(steps.create), [["clone-private-cleanup", "clone-register"], ["finish"], ["stop-input", "withdraw-route"], ["stop-container"], ["accepted", "cleanup"]]);
});

Deno.test("a normal clone succeeds at the registration in about 18 s: no input step, the copy stages, the registration builds the result", () => {
  const ran = runCreate(cloneIntent, cloneOutcomes());
  assertEquals([ran.input.status, ran["clone-club-key"].status, ran.finish.status], ["skipped", "skipped", "skipped"]);
  // The seed wait is the first step after the start, then the copy; each later step starts when the one before it ends.
  assertEquals([ran["clone-seed-wait"].status, ran["clone-seed-wait"].start], ["succeeded", 0]);
  assertEquals(ran["clone-container"].start, ran["clone-seed-wait"].end);
  assertEquals(ran["clone-t3"].start, ran["clone-container"].end);
  assertEquals(ran["clone-session-copy"].start, ran["clone-t3"].end);
  assertEquals(ran["clone-register"].start, ran["clone-session-copy"].end);
  for (const name of ["clone-seed-wait", "clone-container", "clone-t3", "clone-session-copy", "clone-register", "clone-private-cleanup"]) {
    assertEquals(ran[name].status, "succeeded", name);
  }
  // No step of the completion runs here: no Route, database or fleet step exists in the create.
  assertEquals(Object.keys(ran).filter((name) => /route|database|server-|fleet|services|check/.test(name) && ran[name].status !== "skipped"), []);
  // The private folder goes beside the registration.
  assertEquals(ran["clone-private-cleanup"].start, ran["clone-register"].start);
  const result = JSON.parse(String(ran["clone-register"].outputs!.stdout));
  assertEquals([ran["clone-register"].outputs!.ready, result.commit, result.environment, result.t3], [true, commit, { copied: ["APP_NAME"], dropped: [] }, JSON.parse(registration.outputs.stdout)]);
  assertEquals([ran["stop-input"].status, ran["stop-container"].status, ran["withdraw-route"].status, ran.accepted.status], ["skipped", "skipped", "skipped", "succeeded"]);
  // The registration removed the ready create's copy of the sessions: the cleanup step skips, and the run ends with accepted.
  assertEquals(ran.cleanup.status, "skipped");
  // Seconds per level, from timeline.md: the seed wait's dispatch 0.9 · copy with the stage 5.4 · T3 with the checkout and the sessions
  // check 4.4 · copy 1.4 · register with the result 1.3. With 3.8 s for the Gateway's admission and the Swamp start, and the model's
  // 1 s for the assert, the create succeeds about 18 s after the click: the input, stage and finish levels (4.6 s) are gone.
  assertEquals(Math.round(ran["clone-register"].end * 10) / 10, 13.4);
  assertEquals(Math.round((3.8 + ran.accepted.end) * 10) / 10, 18.2);
});

Deno.test("a proof copy's create holds its CI place before the copy; a failed hold copies nothing", () => {
  const proofIntent = { ...cloneIntent, container: { ...cloneIntent.container, name: "instance-8", clone: { source: "instance-7", ci_hold: { places: 5, node: "ci-a" } } } };
  const ran = runCreate(proofIntent, { ...cloneOutcomes(), "clone-ci-hold": { duration: 2 } });
  assertEquals([ran["clone-ci-hold"].status, ran["clone-ci-hold"].start], ["succeeded", 0]);
  assertEquals(ran["clone-seed-wait"].start, ran["clone-ci-hold"].end);
  assertEquals(ran["clone-container"].start, ran["clone-seed-wait"].end);
  // Another clone skips the hold at no cost.
  assertEquals(runCreate(cloneIntent, cloneOutcomes())["clone-ci-hold"].status, "skipped");
  const refused = runCreate(proofIntent, { ...cloneOutcomes(), "clone-ci-hold": { status: "failed", duration: 2 } });
  assertEquals([refused["clone-container"].status, refused["clone-t3"].status], ["skipped", "skipped"]);
  // A Node without the hold script: the step prints its refusal, nothing copies, and the create fails with the named code and Node.
  const missing = runCreate(proofIntent, { ...cloneOutcomes(), "clone-ci-hold": { duration: 0.2, outputs: { stdout: "orbit-ci-hold=unavailable\n" } } });
  assertEquals([missing["clone-ci-hold"].status, missing["clone-container"].status, missing["clone-t3"].status], ["succeeded", "skipped", "skipped"]);
  const result = JSON.parse(String(resultOf(missing).outputs?.stdout));
  assertEquals([result.ready, result.failed_step, result.error_code], [false, "ci_hold", "instance.ci_hold_unavailable"]);
  assertEquals(result.detail, "The Node ci-a has no hold script of its ci-runner role. Converge the role on the Node.");
  assertEquals(missing.accepted.status, "failed");
});

Deno.test("every create meets clone-ci-hold: each of its expressions resolves at run start, and only a proof copy runs it", () => {
  // Serve resolves a step's expressions when the run starts, also of a step that its guard skips (workflow_start_resolution_test.ts),
  // so each create kind reaches this step: a default, a normal clone, a non-container Instance and a proof copy.
  const workflow = parse(Deno.readTextFileSync(new URL("../workflows/workflow-operation-instance-create.yaml", import.meta.url))) as {
    jobs: { name: string; steps: { name: string }[] }[];
  };
  const hold = workflow.jobs.find((job) => job.name === "clone")!.steps.find((step) => step.name === "clone-ci-hold")!;
  const ssh_execution = { target: { host: "10.0.0.3", user: "orbit", port: 22n, host_keys: [] }, identity_file: "/none" };
  const creates: Record<string, Record<string, unknown>> = {
    default: { ...cloneIntent, container: { ...cloneIntent.container, clone: null } },
    clone: cloneIntent,
    plain: { ...cloneIntent, container: null },
    proof: { ...cloneIntent, container: { ...cloneIntent.container, name: "instance-8", clone: { source: "instance-7", ci_hold: { places: 5n } } } },
  };
  const skipped: Record<string, unknown> = {};
  for (const [kind, intent] of Object.entries(creates)) {
    const inputs = { intent: { ...intent, instance_id: 8n, ssh_execution }, node_id: 3n, operation_id: 1n, kind: "instance.create" };
    const resolve = (value: unknown): unknown =>
      typeof value === "string" && value.includes("${{") ? environment.evaluate(swampExpression(value), { inputs })
        : Array.isArray(value) ? value.map(resolve)
        : value !== null && typeof value === "object" ? Object.fromEntries(Object.entries(value).map(([key, item]) => [key, resolve(item)]))
        : value;
    const resolved = resolve(hold) as { guard: boolean; task: { inputs: { argv: unknown } } };
    skipped[kind] = [resolved.guard, resolved.task.inputs.argv];
  }
  assertEquals(skipped, {
    default: [true, ["true"]],
    clone: [true, ["true"]],
    plain: [true, ["true"]],
    proof: [false, ["timeout", "240", "sh", "-c", "[ -x \"$1\" ] || { echo orbit-ci-hold=unavailable; exit 0; }; exec \"$@\"", "orbit-ci-hold", "/usr/local/libexec/orbit-ci-runner-hold", "hold", "instance-8", "5"]],
  });
});

// The publication's seconds from the outer fleet's Route clones (completions 21279, 21311, 21292): the CSR and its signature 3 to 4 s,
// the Route create child with its TLS probe 5 to 7 s.
const routeIntent = { ...cloneIntent, route: { leaf_indexes: [0] } };
const publication = (csr: number, route: number, outcomes = cloneOutcomes()): Record<string, Outcome> =>
  ({ ...outcomes, "clone-route-csr-0": { duration: csr, outputs: { sign: { stdout: "signed" } } }, "clone-route": { duration: route } });

Deno.test("a clone's address is published beside its copy: from the first level after input, and the registration never waits for it", () => {
  const ran = runCreate(routeIntent, publication(4, 7));
  // The publication starts with the clone steps, at the first level after input.
  assertEquals(ran["clone-route-csr-0"].start, ran.input.end);
  assertEquals(ran["clone-route-csr-0"].start, ran["clone-seed-wait"].start);
  // Its own input (the site's run folder) runs beside the CSR, not before it.
  assertEquals([ran["clone-route-input"].status, ran["clone-route-input"].start], ["succeeded", ran["clone-route-csr-0"].start]);
  assertEquals(ran["clone-route"].start, ran["clone-route-csr-0"].end);
  for (const name of ["clone-route-csr-0", "clone-route"]) assertEquals(ran[name].status, "succeeded", name);
  // The legacy Route steps stay skipped: the clone path has its own names.
  assertEquals([ran["route-csr"].status, ran.route.status], ["skipped", "skipped"]);
  // The clone chain is as fast as without a Route, and the create ends at the registration.
  const plain = runCreate(cloneIntent, cloneOutcomes());
  for (const name of ["clone-container", "clone-t3", "clone-register"]) assertEquals(ran[name].start, plain[name].start, name);
  assertEquals(ran.finish.status, "skipped");
  const receipt = JSON.parse(String(resultOf(ran).outputs!.stdout));
  assertEquals([resultOf(ran).outputs!.ready, receipt.route_publication, ran["withdraw-route"].status], [true, "published", "skipped"]);
  // A database still waits for the completion.
  const owned = runCreate({ ...routeIntent, database: {}, server_database: { node_id: 5, fill: ["docker"] } }, publication(4, 7));
  assertEquals(Object.keys(owned).filter((name) => /database|server-/.test(name) && owned[name].status !== "skipped"), []);
});

Deno.test("the registration waits for the publication, as finish did: a slow CSR or Route child delays the end, never the copy or T3", () => {
  const plain = runCreate(routeIntent, publication(4, 7));
  // The measured margin is 1 to 10 s; a publication 30 s long is the case where the create waits.
  for (const [csr, route] of [[4, 30], [30, 7]]) {
    const ran = runCreate(routeIntent, publication(csr, route));
    for (const name of ["clone-container", "clone-t3", "clone-session-copy"]) assertEquals(ran[name].start, plain[name].start, `${name} ${csr}/${route}`);
    assertEquals(ran["clone-register"].start, Math.max(ran["clone-session-copy"].end, ran["clone-route"].end), `${csr}/${route}`);
    assertEquals(ran["clone-register"].start, ran["clone-route"].end, `${csr}/${route}`);
    assertEquals(resultOf(ran).outputs!.ready, true);
  }
  // The measured publication (CSR 4 s, Route 7 s) ends before the chain from the copy to the session copy (11.2 s).
  assert(plain["clone-route"].end <= plain["clone-session-copy"].end);
});

Deno.test("a publication that fails in the create never stops a clone step or the create; it is not withdrawn there", () => {
  for (const [name, outcome] of [["clone-route-csr-0", { status: "failed", duration: 3 }], ["clone-route", { status: "failed", duration: 6 }]] as const) {
    const ran = runCreate(routeIntent, { ...publication(4, 7), [name]: outcome });
    if (name === "clone-route-csr-0") assertEquals(ran["clone-route"].status, "skipped");
    for (const step of ["clone-container", "clone-t3", "clone-register"]) assertEquals(ran[step].status, "succeeded", step);
    const receipt = JSON.parse(String(resultOf(ran).outputs!.stdout));
    assertEquals([resultOf(ran).outputs!.ready, receipt.failed_step, receipt.route_publication], [true, undefined, "failed"], name);
    assertEquals(receipt.warnings, [{ code: "instance.create_route_failed", step: "route" }]);
    // The create succeeds, so it withdraws nothing: the completion captures the Route again and publishes it.
    assertEquals([ran["withdraw-route"].status, ran["stop-container"].status, ran.accepted.status], ["skipped", "skipped", "succeeded"], name);
  }
});

Deno.test("a create that fails or is cancelled before clone-route ends withdraws the half Route; one that published keeps it", () => {
  // A cancel ends the running steps failed: the copy and the Route child.
  const cancelled = runCreate(routeIntent, { ...publication(4, 7), "clone-container": { status: "failed", duration: 5.3 }, "clone-route": { status: "failed", duration: 6 } });
  assertEquals([cancelled.finish.outputs!.ready, cancelled["withdraw-route"].status, cancelled["stop-container"].status], [false, "succeeded", "succeeded"]);
  // Only the CSR ran: its key and certificate go too.
  const early = runCreate(routeIntent, { ...publication(4, 7), "clone-container": { status: "failed", duration: 0.5 }, "clone-route-csr-0": { status: "failed", duration: 1 } });
  assertEquals([early.finish.outputs!.ready, early["withdraw-route"].status], [false, "succeeded"]);
  // The realistic order of an early failure: the copy fails at 5 s, while the publication goes on and publishes 6 s later. The address
  // is active and its Nodes free, so the failed create keeps it (502) until a retry or a removal; withdraw-route skips it.
  const copyFirst = runCreate(routeIntent, { ...publication(4, 7), "clone-container": { status: "failed", duration: 5.3 } });
  assert(copyFirst["clone-container"].end < copyFirst["clone-route"].end);
  assertEquals([copyFirst["clone-route"].status, copyFirst.finish.outputs!.ready, copyFirst["withdraw-route"].status, copyFirst["stop-container"].status],
    ["succeeded", false, "skipped", "succeeded"]);
  // The create job starts when the publication ends; after a failure, finish waits for its level: the private folder's removal.
  assertEquals(copyFirst["clone-private-cleanup"].start, copyFirst["clone-route"].end);
  assertEquals(copyFirst.finish.start, copyFirst["clone-private-cleanup"].end);
  // A published address is active and its Nodes are free: a later failure keeps it (502) until a retry or a removal.
  const late = runCreate(routeIntent, { ...publication(4, 7), "clone-register": { status: "failed", duration: 1.3 } });
  assertEquals([late["clone-route"].status, late.finish.outputs!.ready, late["withdraw-route"].status, late["stop-container"].status], ["succeeded", false, "skipped", "succeeded"]);
});

Deno.test("a route-free clone and a route-free task copy, or a clone whose admission found the Route Nodes held, run no publication step", () => {
  // Guard: `inputs.intent.route == null` skips clone-route, and the CSR's forEach list is empty. A task copy (`clean_workspace`) of a
  // web-serving Project carries `route` like any clone and publishes when its admission took the Nodes (the first case above).
  for (const intent of [cloneIntent, { ...cloneIntent, container: { ...cloneIntent.container, clean_workspace: true } }]) {
    const ran = runCreate(intent, cloneOutcomes());
    assertEquals(ran["clone-route"].status, "skipped");
    assertEquals("clone-route-csr-0" in ran, false);
    assertEquals(JSON.parse(String(resultOf(ran).outputs!.stdout)).route_publication, undefined);
    assertEquals([ran.finish.status, ran["clone-route-input"].status], ["skipped", "skipped"]);
  }
});

Deno.test("a nested-fleet clone gets its Club key before the T3 step, and starts no fleet in its create", () => {
  const outcomes: Record<string, Outcome> = { ...cloneOutcomes(), "clone-club-key": { duration: 1.5, outputs: { stdout: '{"phase": "club-key", "club_key": "changed"}' } } };
  const nested = { ...cloneIntent, container: { ...cloneIntent.container, project: "orbit-instances-nested", club_key: { available: true } }, fleet: { seed: "e".repeat(40) } };
  const ran = runCreate(nested, outcomes);
  assertEquals(ran["clone-club-key"].status, "succeeded");
  // This nested copy's Project serves no web pages: no publication step runs, and the levels stay.
  assertEquals([ran["clone-route"].status, "clone-route-csr-0" in ran], ["skipped", false]);
  // A nested copy of a web-serving Project publishes beside its copy; its Club key and the registration keep their places.
  const routed = runCreate({ ...nested, route: { leaf_indexes: [0] } }, publication(4, 7, outcomes));
  assertEquals([routed["clone-club-key"].status, routed["clone-route-csr-0"].status, routed["clone-route"].status], ["succeeded", "succeeded", "succeeded"]);
  assertEquals(routed["clone-route-csr-0"].start, routed["clone-seed-wait"].start);
  for (const name of ["clone-club-key", "clone-t3", "clone-register"]) assertEquals(routed[name].start, ran[name].start, name);
  assertEquals(JSON.parse(String(resultOf(routed).outputs!.stdout)).route_publication, "published");
  assertEquals(ran["clone-t3"].start, ran["clone-club-key"].end);
  assertEquals("clone-fleet" in ran, false);
  assertEquals(resultOf(ran).outputs!.ready, true);
  assertEquals(JSON.parse(String(resultOf(ran).outputs!.stdout)).club_key, "changed");
  // A failed Club key runs no T3 step, registers nothing and stops the container.
  outcomes["clone-club-key"] = { status: "failed", duration: 1.5 };
  const failed = runCreate(nested, outcomes);
  for (const name of ["clone-t3", "clone-session-copy", "clone-register"]) assertEquals(failed[name].status, "skipped", name);
  assertEquals([failed.finish.outputs!.ready, failed["stop-container"].status, failed["clone-private-cleanup"].status], [false, "succeeded", "succeeded"]);
});

Deno.test("each failure before the end names its step, registers nothing and stops the container", () => {
  const cases: [string, Record<string, Outcome>, string, string][] = [
    // The stage is the copy step's first part: its failure (instance.clone_input_failed) is the copy step's.
    ["stage", { "clone-container": { status: "failed", duration: 0.2 } }, "clone", "instance.create_clone_failed"],
    ["copy", { "clone-container": { status: "failed", duration: 5.3 } }, "clone", "instance.create_clone_failed"],
    // A failed T3 step without its t3.json reads as T3; with it, the part names the checkout, T3 or the sessions check
    // (instance_clone_test.py T3PhaseTest).
    ["T3", { "clone-t3": { status: "failed", duration: 4.2 } }, "t3", "instance.t3_unready"],
    ["register", { "clone-register": { status: "failed", duration: 1.3 } }, "register", "instance.create_register_failed"],
  ];
  for (const [label, outcomes, step, code] of cases) {
    const ran = runCreate(cloneIntent, { ...cloneOutcomes(), ...outcomes });
    const result = JSON.parse(String(ran.finish.outputs!.stdout));
    assertEquals([ran.finish.outputs!.ready, result.failed_step, result.error_code, result.t3], [false, step, code, undefined], label);
    // Nothing to withdraw: the create publishes no Route.
    // finish builds the failed result; a clone writes the stop's run folder first, then stops its copy.
    assertEquals([ran.finish.status, ran["stop-input"].status, ran["stop-container"].status, ran["withdraw-route"].status, ran["clone-private-cleanup"].status, ran.cleanup.status, ran.accepted.status],
      ["succeeded", "succeeded", "succeeded", "skipped", "succeeded", "succeeded", "failed"], label);
  }
  // The T3 step brings its part's t3.json to the run, early or late: the create result names the part (instance_clone_test.py).
  const t3 = runCreate(cloneIntent, { ...cloneOutcomes(), "clone-t3": { status: "failed", duration: 1.1 } });
  assertEquals([t3["clone-session-copy"].status, t3["clone-register"].status], ["succeeded", "skipped"]);
});

Deno.test("a nested-fleet clone's start reads only the Club key, and every wake reads no key", () => {
  assertEquals(failures("workflow-operation-instance-start.yaml", {
    execution_secrets: { club_key: copy("nested-fleet-club-api-key") },
    intent: { container: { clone: {}, project: "orbit-instances-nested", club_key: { available: true } }, fleet: {} },
  }), []);
  // The wake's own workflow names no secret: a normal wake keeps its empty map.
  assertEquals(failures("workflow-operation-instance-wake.yaml", { execution_secrets: {}, intent: { container: { clone: {} } } }), []);
});

Deno.test("a checkout create and a container default with a Route keep reading route-csr and route; only a clone reads its clone- steps", () => {
  const workflow = parse(Deno.readTextFileSync(new URL("../workflows/workflow-operation-instance-create.yaml", import.meta.url))) as {
    jobs: { steps: { name: string; guard?: string; task: { inputs?: Record<string, string> } }[] }[];
  };
  const step = (name: string) => workflow.jobs.flatMap((job) => job.steps).find((s) => s.name === name)!;
  const finish = step("finish").task.inputs!;
  const evaluate = (expression: string, context: Record<string, unknown>) => environment.evaluate(swampExpression(expression), context);
  const legacy = { "route-csr-0": { status: "succeeded" }, route: { status: "failed", outputs: {} } };
  const cloned = { "clone-route-csr-0": { status: "failed" }, "clone-route": { status: "skipped", outputs: {} } };
  for (const container of [null, { clone: null }]) {
    const intent = { already_active: false, route: { leaf_indexes: [0n] }, container };
    const steps = { ...legacy, ...cloned, finish: { outputs: { ready: false } } };
    assertEquals([evaluate(finish.route, { inputs: { intent }, steps }), evaluate(finish.certificates, { inputs: { intent }, steps })], ["failed", ["succeeded"]], JSON.stringify(container));
    // A failed create that ran its Route takes it back.
    assertEquals(evaluate(step("withdraw-route").guard!, { inputs: { intent }, steps }), false, JSON.stringify(container));
  }
  const clone = { already_active: false, route: { leaf_indexes: [0n] }, container: { clone: { source: "instance-7" } } };
  const steps = { "route-csr": { status: "skipped" }, route: { status: "skipped" }, ...cloned, finish: { outputs: { ready: false } } };
  assertEquals([evaluate(finish.route, { inputs: { intent: clone }, steps }), evaluate(finish.certificates, { inputs: { intent: clone }, steps })], ["skipped", ["failed"]]);
  assertEquals(evaluate(step("withdraw-route").guard!, { inputs: { intent: clone }, steps }), false);
  // A clone whose clone-route succeeded keeps its active address.
  assertEquals(evaluate(step("withdraw-route").guard!, { inputs: { intent: clone }, steps: { ...steps, "clone-route": { status: "succeeded" } } }), true);
});
