import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { ROOT } from "../../../../bin/gate-test-support.ts";
import { parse } from "jsr:@std/yaml@1.0.12";

type Step = {
  name: string;
  guard?: string;
  task: { inputs?: { env?: Record<string, string> } };
};

const DESTROY = "Destroy the task VM";
const READ = `steps["${DESTROY}"]`;

/** The expression text between `${{` and `}}`. */
function body(expression: string): string {
  const match = expression.match(/^\$\{\{\s*([\s\S]*?)\s*\}\}$/);
  assert(match, `not an expression: ${expression}`);
  return match[1];
}

Deno.test("tasks.cancel reads the destroy step only after the test that skips it", () => {
  const workflow = parse(Deno.readTextFileSync(`${ROOT}/workflows/workflow-operation-task-cancel.yaml`)) as {
    jobs: { steps: Step[] }[];
  };
  const steps = workflow.jobs[0].steps;
  const destroy = steps.find((step) => step.name === DESTROY)!;
  const check = steps.find((step) => step.name === "Check the VM is gone")!;
  const vm = body(check.task.inputs!.env!.VM);

  // A step its guard skips has no entry in `steps`, so reading it fails the run with "No such key".
  assert(destroy.guard);
  const skip = body(destroy.guard);
  assert(vm.includes(READ));
  // The read must be reachable only when the guard did not skip the step: the guard's own test comes first.
  assertEquals(vm.indexOf(skip), 0, "VM must start with the destroy guard's own test");
  assert(vm.slice(skip.length).trimStart().startsWith("||"));
  assert(skip.length <= vm.indexOf(READ));
});
