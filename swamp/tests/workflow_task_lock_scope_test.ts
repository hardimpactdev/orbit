/**
 * Two task groups whose copies share a Node never share a Swamp behavior lock for a long step (docs/reference/tasks.md#two-groups-on-one-node):
 * Swamp holds a behavior's lock for the whole step and fails a second step after 60 s. Each workflow's behavior names are rendered with the
 * pinned CEL compiler for two groups on Node 7; only the short steps listed here keep one shared name.
 */
import { assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { Environment } from "npm:@marcbachmann/cel-js@7.6.1";

type Task = { type: string; behaviorName?: string; workflowIdOrName?: string; inputs?: Record<string, unknown> };
type Flow = { jobs: { steps: { name: string; task: Task }[] }[] };

const cel = new Environment({ enableOptionalTypes: true, unlistedVariablesAreDyn: true, homogeneousAggregateLiterals: false });
const expression = (text: string) => text.trim().slice(3, -2).trim();
const flow = (file: string) =>
  parse(Deno.readTextFileSync(new URL(`../workflows/${file}`, import.meta.url))) as Flow;
const steps = (file: string) => flow(file).jobs.flatMap((job) => job.steps);

/** The behavior name of every behavior step, for the run's inputs. */
const names = (file: string, inputs: Record<string, unknown>) =>
  new Set(
    steps(file).filter((step) => step.task.type === "behavior").map((step) =>
      String(cel.evaluate(expression(step.task.behaviorName!), { inputs, run: { id: "run-1" } }))
    ),
  );
const shared = (file: string, first: Record<string, unknown>, second: Record<string, unknown>) => {
  const other = names(file, second);
  return [...names(file, first)].filter((name) => other.has(name)).sort();
};

const node = 7;
const ssh = (host: string, nodeId: number) => ({ target: { node_id: nodeId, host, user: "orbit", port: 22 } });
const workspace = { runtime: { project: "orbit-instances", name: "task-41" }, branch: "task-41", snapshot_id: "0b8a4c1e-5d1f-4c55-9d35-1c0f6a2b7e10" };
const publish = (group: number) => ({ operation_id: 900 + group, intent: { group_id: group, instance_id: 500 + group, node_id: node, workspace, ssh_execution: ssh("10.6.0.7", node) } });

Deno.test("two publishes of copies on one Node share no behavior name", () => {
  assertEquals(shared("workflow-operation-task-publish.yaml", publish(41), publish(42)), []);
});

Deno.test("the publish passes its group to every workspace phase", () => {
  const calls = steps("workflow-operation-task-publish.yaml").filter((step) => step.task.workflowIdOrName === "task-instance-workspace");
  assertEquals(calls.length, 3);
  for (const call of calls) {
    const group = call.task.inputs?.group_id;
    assertEquals(typeof group, "string", `${call.name} passes no group_id`);
    assertEquals(Number(cel.evaluate(expression(String(group)), { inputs: publish(42) })), 42, call.name);
  }
});

Deno.test("two workspace phases on one Node share only their short input, evidence and cleanup steps", () => {
  const phase = (group: number) => ({ node_id: node, group_id: group, phase: "export", intent: { ...workspace, ssh_execution: ssh("10.6.0.7", node) } });
  assertEquals(shared("workflow-task-instance-workspace.yaml", phase(41), phase(42)), ["ansible-input", "task-workspace-cleanup", "task-workspace-evidence"]);
});

Deno.test("two copy checks on one Node share only their short input, evidence and cleanup steps", () => {
  const check = (group: number) => ({ operation_id: 900 + group, node_id: node, kind: "tasks.check", intent: { group_id: group, workspace }, run_id: "run-1" });
  assertEquals(shared("workflow-task-instance-check.yaml", check(41), check(42)), ["ansible-input", "task-instance-check-cleanup", "task-instance-check-evidence"]);
});

Deno.test("two syncs share only their short scratch cleanup on 209; each VM is its own group's", () => {
  const sync = (group: number) => ({ operation_id: 900 + group, intent: { group_id: group, node_id: 20 + group, ssh_execution: ssh(`10.44.0.${group}`, 20 + group) } });
  assertEquals(shared("workflow-operation-task-sync.yaml", sync(41), sync(42)), ["task-sync-scratch"]);
});
