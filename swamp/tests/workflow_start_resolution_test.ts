/**
 * Swamp e459 resolves every expression of a run that reads only its inputs when the run starts: each step's inputs, guard, forEach and
 * name, also of a step whose guard will skip it. A key that the run's intent lacks fails the run before it starts (Operation 22250:
 * the combined task:sync workflow read inputs.intent.checkout_path, which a copy intent has not, and the sync never started). This
 * resolves every such expression of a workflow against the inputs the Gateway really starts it with, from the fixture that the Pest
 * tests keep equal to the Gateway's own builders (tests/Support/WorkflowStartIntents.php in the Gateway).
 */
import { assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { environment, secretRefusal } from "./_support/swamp_workflow.ts";

type Inputs = Record<string, unknown>;
const intents = JSON.parse(Deno.readTextFileSync(new URL("./fixtures/workflow-start-intents.json", import.meta.url))) as Record<string, Inputs>;

type Step = { name: string; guard?: unknown; forEach?: unknown; task?: { type?: string; workflowIdOrName?: string; inputs?: Record<string, unknown> } };
const RUN = { id: "0f0e0d0c-0b0a-4908-8706-050403020100" };

/** A step output stands in as this string: its value is known only once the step ran, and a child reads it as a value. */
const withoutOutputs = (expression: string): string =>
  expression.replace(/steps\[("[^"]*")\]\.outputs(?:\.[A-Za-z_]\w*\b(?!\())*/g, '"step-output"');

/** The value of a workflow input as its caller writes it: one whole `${{ … }}` is that value, text with expressions is a string. */
function value(raw: unknown, context: Record<string, unknown>): unknown {
  if (typeof raw !== "string") return raw;
  const whole = /^\s*\$\{\{([\s\S]*?)\}\}\s*$/.exec(raw);
  const evaluate = (expression: string) => environment.evaluate(withoutOutputs(expression.trim()), context);
  if (whole) return evaluate(whole[1]);
  return raw.replace(/\$\{\{([\s\S]*?)\}\}/g, (_, expression: string) => String(evaluate(expression)));
}

/**
 * The expressions of a workflow that fail at run start: every `${{ … }}` anywhere in a job or step that reads no step output (`steps.`)
 * and no forEach item (`self.`), which Swamp resolves later, evaluated with the pinned CEL environment against the run's inputs. A child
 * workflow resolves its own inputs when its step starts it, so each child step that can run (its guard, read with the steps before it
 * as skipped or succeeded, is not true) is followed: its inputs are built from the caller's map, a step output standing in as a
 * string, and the child is resolved against them in turn.
 */
function unresolved(workflow: string, inputs: Inputs, caller = "", followed: string[] = []): string[] {
  const parsed = parse(Deno.readTextFileSync(new URL(`../workflows/${workflow}`, import.meta.url))) as { jobs: { name: string; steps: Step[] }[] };
  const failed: string[] = [];
  const walk = (where: string, value: unknown): void => {
    if (typeof value === "string") {
      for (const match of value.matchAll(/\$\{\{([\s\S]*?)\}\}/g)) {
        const expression = match[1].trim();
        // Serve applies the secret rule to every expression when the run starts, also to one that reads a step output later.
        const refused = secretRefusal(expression);
        if (refused !== null) {
          failed.push(`${where}: ${refused}`);
          continue;
        }
        if (/\b(steps|self)\b/.test(expression)) continue;
        try {
          environment.evaluate(expression.replaceAll("vault.get(", "vaultget("), { inputs, run: RUN });
        } catch (error) {
          failed.push(`${where}: ${(error as Error).message.split("\n")[0]}`);
        }
      }
    } else if (Array.isArray(value)) {
      value.forEach((item) => walk(where, item));
    } else if (value !== null && typeof value === "object") {
      for (const [key, item] of Object.entries(value)) if (key !== "steps") walk(where, item);
    }
  };
  const steps: Record<string, { status: string; outputs: Record<string, unknown> }> = {};
  for (const job of parsed.jobs) {
    walk(`${caller}${job.name}`, job);
    for (const step of job.steps) {
      const where = `${caller}${job.name} / ${step.name}`;
      walk(where, step);
      let skipped = false;
      try {
        skipped = value(step.guard ?? false, { inputs, steps, run: RUN }) === true;
      } catch {
        // A guard over a step output is read when the step is reached; the step may run.
      }
      steps[step.name] = { status: skipped ? "skipped" : "succeeded", outputs: {} };
      if (skipped || step.task?.type !== "workflow" || step.forEach !== undefined) continue;
      const child: Inputs = {};
      try {
        for (const [key, raw] of Object.entries(step.task.inputs ?? {})) child[key] = value(raw, { inputs, steps, run: RUN });
      } catch (error) {
        failed.push(`${where}: the inputs of ${step.task.workflowIdOrName}: ${(error as Error).message.split("\n")[0]}`);
        continue;
      }
      followed.push(`${step.name} -> ${step.task.workflowIdOrName}`);
      failed.push(...unresolved(`workflow-${step.task.workflowIdOrName}.yaml`, child, `${where} -> ${step.task.workflowIdOrName} / `, followed));
    }
  }
  return failed;
}

Deno.test("a copy sync starts: every expression of operation-task-sync-copy resolves against its intent", () => {
  assertEquals(unresolved("workflow-operation-task-sync-copy.yaml", intents.sync_copy), []);
});

Deno.test("a proof run in a copy starts: every expression of operation-proof-run-copy resolves, and each batch child too", () => {
  assertEquals(unresolved("workflow-operation-proof-run-copy.yaml", intents.proof_run_copy), []);
  const intent = intents.proof_run_copy.intent as { verifications: Inputs[] };
  for (const verify of intent.verifications) {
    const child = { operation_id: intents.proof_run_copy.operation_id, node_id: verify.node_id, kind: "proof.verify", intent: verify, execution_secrets: {} };
    assertEquals(unresolved("workflow-proof-fleet-verify.yaml", child), []);
  }
});

Deno.test("a publish starts for a copy: every expression of operation-task-publish resolves", () => {
  assertEquals(unresolved("workflow-operation-task-publish.yaml", intents.publish_copy), []);
});

Deno.test("production clone candidate reads resolve for both a checkout and a container default", () => {
  const workflow = "workflow-instance-clone-candidate.yaml";
  const parsed = parse(Deno.readTextFileSync(new URL(`../workflows/${workflow}`, import.meta.url))) as { jobs: { steps: Step[] }[] };
  const steps = parsed.jobs[0].steps;
  const branch = steps.find((step) => step.name === "candidate-branch")!;
  const origin = steps.find((step) => step.name === "candidate-origin")!;
  const ssh = {
    identity_file: "/etc/orbit/fixture",
    target: { host: "192.0.2.7", user: "orbit", port: 22, host_keys: ["fixture"] },
  };
  const checkout = { checkout: "/home/orbit/apps/tool/default", ssh_execution: ssh };
  const container = {
    container: { project: "orbit-instances", name: "instance-7", workspace: "/srv/workspace", seed_commit: "a".repeat(40) },
    ssh_execution: ssh,
  };
  for (const candidate of [checkout, container]) {
    const inputs = { intent: { candidate } };
    assertEquals(unresolved(workflow, inputs), []);
    const context = { inputs };
    const guest = "container" in candidate;
    assertEquals(value(branch.task!.inputs!.sudo, context), guest);
    assertEquals(value(origin.guard ?? false, context), guest);
    assertEquals(value(branch.task!.inputs!.argv, context), [
      ...(guest
        ? ["/usr/bin/incus", "exec", "--project", "orbit-instances", "instance-7", "--", "/usr/sbin/runuser", "-u", "agent", "--",
          "/usr/bin/git", "--no-optional-locks"]
        : ["/usr/bin/git"]),
      "-c", "core.hooksPath=/dev/null", "-c", "core.fsmonitor=false", "-C",
      guest ? "/srv/workspace" : "/home/orbit/apps/tool/default", "branch", "--show-current",
    ]);
  }
});

Deno.test("serve's secret rule: a vault.get expression with any container comparison is refused, has() is not one", () => {
  const refused = (expression: string) => secretRefusal(expression) !== null;
  // The turn-11 guard of "Check the .env content", which serve would have refused for every publish.
  assertEquals(refused('vault.get(inputs.execution_secrets.git_authorization.vault, "workspace" in inputs.intent ? inputs.a.key : inputs.b.key)'), true);
  assertEquals(refused('vault.get(inputs.v, inputs.k) != null && [1] == [1]'), true);
  assertEquals(refused('vault.get(inputs.v, inputs.m != {} ? inputs.k : inputs.j)'), true);
  assertEquals(refused('vault.get(inputs.v, has(inputs.intent.workspace) ? inputs.a.key : inputs.b.key)'), false);
  assertEquals(refused('vault.get(inputs.v, inputs.kind == "tasks.publish" ? inputs.a.key : inputs.b.key)'), false);
  assertEquals(refused('"workspace" in inputs.intent'), false);
  // An equality refused unless one side is a scalar literal (a value, or minus over one); a call such as uint(1) is not one.
  assertEquals(refused('vault.get(inputs.v, inputs.intent.a == inputs.intent.b ? inputs.k : inputs.j)'), true);
  assertEquals(refused('vault.get(inputs.v, uint(1) == inputs.intent.n ? inputs.k : inputs.j)'), true);
  assertEquals(refused('vault.get(inputs.v, "x" in inputs.intent.list ? inputs.k : inputs.j)'), true);
  assertEquals(refused('vault.get(inputs.v, inputs.intent.a == null ? inputs.k : inputs.j)'), false);
  assertEquals(refused('vault.get(inputs.v, inputs.intent.a == "x" ? inputs.k : inputs.j)'), false);
  assertEquals(refused('vault.get(inputs.v, inputs.intent.n != -1 ? inputs.k : inputs.j)'), false);
});

Deno.test("no workflow holds a vault.get expression that serve refuses at run start", () => {
  const offenders: string[] = [];
  for (const entry of Deno.readDirSync(new URL("../workflows/", import.meta.url))) {
    const walk = (value: unknown): void => {
      if (typeof value === "string") {
        for (const match of value.matchAll(/\$\{\{([\s\S]*?)\}\}/g)) {
          if (secretRefusal(match[1].trim()) !== null) offenders.push(`${entry.name}: ${match[1].trim().slice(0, 120)}`);
        }
      } else if (value !== null && typeof value === "object") {
        Object.values(value).forEach(walk);
      }
    };
    walk(parse(Deno.readTextFileSync(new URL(`../workflows/${entry.name}`, import.meta.url))));
  }
  assertEquals(offenders, []);
});

Deno.test("the child workflows that a run starts resolve against the inputs their caller builds", () => {
  const followed = (workflow: string, inputs: Inputs): string[] => {
    const list: string[] = [];
    assertEquals(unresolved(workflow, inputs, "", list), []);
    return list;
  };
  assertEquals(followed("workflow-operation-task-sync-copy.yaml", intents.sync_copy),
    ["Bring in the base -> task-copy-base", "Discard the base scratch -> task-copy-base"]);
  assertEquals(followed("workflow-operation-task-publish.yaml", intents.publish_copy).filter((line) => line.includes("task-instance-workspace")),
    ["Preflight the copy -> task-instance-workspace", "Export the copy -> task-instance-workspace", "Check the copy -> task-instance-workspace",
      "Discard the copy scratch -> task-instance-workspace"]);
});
