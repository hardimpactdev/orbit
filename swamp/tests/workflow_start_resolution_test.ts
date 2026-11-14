/**
 * Swamp e459 resolves every expression of a run that reads only its inputs when the run starts: each step's inputs, guard, forEach and
 * name, also of a step whose guard will skip it. A key that the run's intent lacks fails the run before it starts (Operation 22250:
 * the combined task:sync workflow read inputs.intent.checkout_path, which a copy intent has not, and the sync never started). This
 * resolves every such expression of a workflow against the inputs the Gateway really starts it with, from the fixture that the Pest
 * tests keep equal to the Gateway's own builders (tests/Support/WorkflowStartIntents.php in the Gateway).
 */
import { assertEquals, assertNotEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { environment } from "./_support/swamp_workflow.ts";

type Inputs = Record<string, unknown>;
const intents = JSON.parse(Deno.readTextFileSync(new URL("./fixtures/workflow-start-intents.json", import.meta.url))) as Record<string, Inputs>;

/**
 * The expressions of a workflow that fail at run start: every `${{ … }}` anywhere in a job or step that reads no step output (`steps.`)
 * and no forEach item (`self.`), which Swamp resolves later, evaluated with the pinned CEL environment against the run's inputs.
 */
function unresolved(workflow: string, inputs: Inputs): string[] {
  const parsed = parse(Deno.readTextFileSync(new URL(`../workflows/${workflow}`, import.meta.url))) as { jobs: { name: string; steps: { name: string }[] }[] };
  const failed: string[] = [];
  const walk = (where: string, value: unknown): void => {
    if (typeof value === "string") {
      for (const match of value.matchAll(/\$\{\{([\s\S]*?)\}\}/g)) {
        const expression = match[1].trim();
        if (/\b(steps|self)\b/.test(expression)) continue;
        try {
          environment.evaluate(expression.replaceAll("vault.get(", "vaultget("), { inputs, run: { id: "0f0e0d0c-0b0a-4908-8706-050403020100" } });
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
  for (const job of parsed.jobs) {
    walk(job.name, job);
    for (const step of job.steps) walk(`${job.name} / ${step.name}`, step);
  }
  return failed;
}

Deno.test("a task VM sync starts: every expression of operation-task-sync resolves against its intent", () => {
  assertEquals(unresolved("workflow-operation-task-sync.yaml", intents.sync_vm), []);
});

Deno.test("a copy sync starts: every expression of operation-task-sync-copy resolves against its intent", () => {
  assertEquals(unresolved("workflow-operation-task-sync-copy.yaml", intents.sync_copy), []);
});

Deno.test("the task VM sync workflow could not start for a copy, so the Gateway never starts it for one", () => {
  const failed = unresolved("workflow-operation-task-sync.yaml", intents.sync_copy);
  assertNotEquals(failed, []);
  assertEquals(failed.every((line) => line.includes("checkout_path")), true, failed.join("\n"));
});

Deno.test("a publish starts for a task VM and for a copy: every expression of operation-task-publish resolves", () => {
  assertEquals(unresolved("workflow-operation-task-publish.yaml", intents.publish_vm), []);
  assertEquals(unresolved("workflow-operation-task-publish.yaml", intents.publish_copy), []);
});
