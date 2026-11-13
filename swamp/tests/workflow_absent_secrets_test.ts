import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
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
  const ran = { "clone-container": succeeded, "clone-workspace": { ...succeeded, outputs: { stdout: `orbit-commit=${commit}` } }, "clone-agent": succeeded,
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

// A run of the whole create workflow as Swamp schedules it: a step waits until every step it names has ended, then a dependency
// condition that does not hold or a guard that is true skips it, and a behavior step waits for the lock of its behavior name, so two
// steps with one name never run at the same time. Guards, behavior names, `finish` and `accepted` are evaluated with the pinned CEL.
type Condition = { type: string; conditions?: Condition[] };
type GraphStep = {
  name: string;
  guard?: string;
  dependsOn?: { step: string; condition: Condition }[];
  task: { type: string; behaviorName?: string; inputs?: Record<string, string>; expr?: string };
};
type GraphJob = { name: string; dependsOn?: { job: string; condition: Condition }[]; steps: GraphStep[] };
type Ran = { status: string; outputs?: Record<string, unknown>; start: number; end: number };
type Outcome = { status?: string; duration?: number; outputs?: Record<string, unknown> };

function runCreate(intent: Record<string, unknown>, outcomes: Record<string, Outcome>): Record<string, Ran> {
  const flow = parse(Deno.readTextFileSync(new URL("../workflows/workflow-operation-instance-create.yaml", import.meta.url))) as { jobs: GraphJob[] };
  const ran: Record<string, Ran> = {};
  const running = new Map<string, { lock: string | null }>();
  const locks = new Set<string>();
  const jobStatus: Record<string, string> = {};
  const holds = (condition: Condition, status: string): boolean =>
    condition.type === "always" || condition.type === status ||
    (condition.type === "completed" && (status === "succeeded" || status === "failed")) ||
    (condition.type === "or" && condition.conditions!.some((c) => holds(c, status)));
  const context = () => ({
    // Swamp hands JSON integers to CEL as int, never double; `instance_id % 4` needs that.
    inputs: { intent: { ...intent, instance_id: BigInt(intent.instance_id as number) }, operation_id: 1n, node_id: 3n, kind: "instance.create" },
    steps: Object.fromEntries(Object.entries(ran).filter(([, r]) => r.end <= now).map(([n, r]) => [n, { status: r.status, outputs: r.outputs ?? {} }])),
    run: { id: "run-1" },
  });
  const cel = (expression: string) => environment.evaluate(swampExpression(expression), context());
  const finish = (step: GraphStep): Outcome => {
    const values = Object.fromEntries(Object.entries(step.task.inputs!).map(([key, value]) => [key, cel(value)]));
    const result = createResult(intent, behavior.methods.create_result.arguments.parse(values));
    return { outputs: { ready: result.ready, stdout: result.stdout } };
  };
  let now = 0;
  for (let rounds = 0; rounds < 1000; rounds++) {
    let started = false;
    for (const job of flow.jobs) {
      if (!(job.name in jobStatus)) {
        if (!(job.dependsOn ?? []).every((d) => d.job in jobStatus && jobStatus[d.job] !== "running")) continue;
        jobStatus[job.name] = (job.dependsOn ?? []).every((d) => holds(d.condition, jobStatus[d.job])) ? "running" : "skipped";
      }
      if (jobStatus[job.name] !== "running") continue;
      for (const step of job.steps) {
        if (step.name in ran) continue;
        const deps = step.dependsOn ?? [];
        if (!deps.every((d) => d.step in ran && ran[d.step].end <= now)) continue;
        const skip = !deps.every((d) => holds(d.condition, ran[d.step].status)) || (step.guard !== undefined && cel(step.guard) === true);
        if (skip) {
          ran[step.name] = { status: "skipped", start: now, end: now };
          started = true;
          continue;
        }
        const lock = step.task.type === "behavior" ? String(cel(step.task.behaviorName!)) : null;
        if (lock !== null && locks.has(lock)) continue;
        if (lock !== null) locks.add(lock);
        const outcome: Outcome = step.name === "finish" ? finish(step)
          : step.task.type === "assert" ? { status: cel("${{ " + step.task.expr + " }}") === true ? "succeeded" : "failed" }
          : outcomes[step.name] ?? {};
        ran[step.name] = { status: outcome.status ?? "succeeded", outputs: outcome.outputs ?? { stdout: "" }, start: now, end: now + (outcome.duration ?? 1) };
        running.set(step.name, { lock });
        started = true;
      }
      if (job.steps.every((s) => s.name in ran && ran[s.name].end <= now)) {
        jobStatus[job.name] = job.steps.some((s) => ran[s.name].status === "failed") ? "failed" : "succeeded";
        started = true;
      }
    }
    if (started) continue;
    if (running.size === 0) break;
    now = Math.min(...[...running.keys()].map((n) => ran[n].end));
    for (const [name, { lock }] of [...running]) {
      if (ran[name].end > now) continue;
      running.delete(name);
      if (lock !== null) locks.delete(lock);
    }
  }
  assertEquals(Object.values(jobStatus).filter((s) => s === "running"), [], "every job ends");
  return ran;
}

const cloneIntent = {
  instance_id: 8, already_active: false, container: { clone: { source: "instance-7" }, project: "orbit-instances", mail: null },
  database: null, server_database: null, server_valkey: null, processes: [], setup_steps: [], route: null,
};
const commit = "b".repeat(40);
// Durations in seconds as measured on real clone creates: workspace 4 to 7 s, agent 1.4 s, T3 4.0 s.
const cloneOutcomes = (): Record<string, Outcome> => ({
  "clone-container": { duration: 3 },
  "clone-workspace": { duration: 6, outputs: { stdout: `orbit-commit=${commit}\n{"phase":"workspace"}` } },
  "clone-agent": { duration: 1.4 },
  "clone-t3": { duration: 4, outputs: { stdout: "{}" } },
  "clone-sessions": { outputs: { stdout: "{}" } },
  "clone-register": JSON.parse(Deno.readTextFileSync(new URL("fixtures/instance-create-16210-t3-register.json", import.meta.url))).step,
});

Deno.test("a normal clone create runs agent and T3 beside the workspace, and sessions waits for both", () => {
  const ran = runCreate(cloneIntent, cloneOutcomes());
  // Agent and T3 start once the copy (and the Club key or its skip) ends, before the workspace ends.
  assertEquals(ran["clone-club-key"].status, "skipped");
  assertEquals(ran["clone-agent"].start, ran["clone-container"].end);
  assertEquals(ran["clone-agent"].start < ran["clone-workspace"].end, true, "agent waits for the workspace");
  assertEquals(ran["clone-t3"].start < ran["clone-workspace"].end, true, "T3 waits for the workspace");
  assertEquals(ran["clone-t3"].start, ran["clone-agent"].end);
  // The workspace phase keeps its own lock name, so agent and T3 do not take it.
  assertEquals(ran["clone-workspace"].start, ran["clone-container"].end);
  assertEquals(ran["clone-sessions"].start, Math.max(ran["clone-workspace"].end, ran["clone-t3"].end));
  for (const name of ["clone-agent", "clone-t3", "clone-sessions", "clone-session-copy", "clone-register", "clone-private-cleanup"]) {
    assertEquals(ran[name].status, "succeeded", name);
  }
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

Deno.test("a clone whose workspace fails while T3 runs registers nothing and stops the container", () => {
  const outcomes = cloneOutcomes();
  outcomes["clone-workspace"] = { status: "failed", duration: 6 };
  const ran = runCreate(cloneIntent, outcomes);
  assertEquals(ran["clone-agent"].status, "succeeded");
  assertEquals(ran["clone-t3"].status, "succeeded");
  for (const name of ["clone-sessions", "clone-session-copy", "clone-register"]) assertEquals(ran[name].status, "skipped", name);
  const result = JSON.parse(String(ran.finish.outputs!.stdout));
  assertEquals([ran.finish.outputs!.ready, result.failed_step, result.error_code, result.t3], [false, "workspace", "instance.create_workspace_failed", undefined]);
  assertEquals(ran["stop-container"].status, "succeeded");
  assertEquals(ran["clone-private-cleanup"].status, "succeeded");
  assertEquals(ran.accepted.status, "failed");
});

Deno.test("a clone whose T3 or database phase fails registers nothing, stops the container and names that phase", () => {
  const t3 = cloneOutcomes();
  t3["clone-t3"] = { status: "failed", duration: 4 };
  const failedT3 = runCreate(cloneIntent, t3);
  assertEquals(failedT3["clone-workspace"].status, "succeeded");
  for (const name of ["clone-sessions", "clone-register"]) assertEquals(failedT3[name].status, "skipped", name);
  assertEquals(JSON.parse(String(failedT3.finish.outputs!.stdout)).failed_step, "t3");
  assertEquals(failedT3["stop-container"].status, "succeeded");
  // A database install that fails skips the workspace; agent and T3 still run beside it, and the result names the database.
  const database = cloneOutcomes();
  database["clone-database-install"] = { status: "failed" };
  const failedDatabase = runCreate({ ...cloneIntent, database: {} }, database);
  assertEquals([failedDatabase["clone-workspace"].status, failedDatabase["clone-t3"].status], ["skipped", "succeeded"]);
  assertEquals(failedDatabase["clone-register"].status, "skipped");
  assertEquals(JSON.parse(String(failedDatabase.finish.outputs!.stdout)).failed_step, "database");
  assertEquals(failedDatabase["stop-container"].status, "succeeded");
});
