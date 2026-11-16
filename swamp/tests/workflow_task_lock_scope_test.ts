/**
 * Two task groups whose copies share a Node never share a Swamp behavior lock for a long step (docs/reference/tasks.md#two-groups-on-one-node):
 * Swamp holds a behavior's lock for the whole step and fails a second step after 60 s. Each name stays behind as an auto-definition, so
 * only the steps that run for a copy and can hold the lock near or past that wait carry the group (docs/cli/README.md#behavior-names).
 * Each workflow's behavior names are rendered with the pinned CEL compiler for two groups on Node 7.
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

/** The behavior name of each behavior step, for the run's inputs. */
const names = (file: string, inputs: Record<string, unknown>) =>
  steps(file).filter((step) => step.task.type === "behavior").map((step) =>
    [step.name, String(cel.evaluate(expression(step.task.behaviorName!), { inputs, run: { id: "run-1" } }))] as const
  );
/** The steps whose behavior name differs between two groups on one Node; every other step keeps one name. */
const scoped = (file: string, first: Record<string, unknown>, second: Record<string, unknown>) => {
  const other = new Map(names(file, second));
  return [...new Set(names(file, first).filter(([step, name]) => other.get(step) !== name).map(([step]) => step))].sort();
};

const node = 7;
const ssh = { target: { node_id: node, host: "10.6.0.7", user: "orbit", port: 22 } };
const workspace = { runtime: { project: "orbit-instances", name: "task-41" }, branch: "task-41", snapshot_id: "0b8a4c1e-5d1f-4c55-9d35-1c0f6a2b7e10" };
const publish = (group: number) => ({ operation_id: 900 + group, intent: { group_id: group, instance_id: 500 + group, node_id: node, workspace, ssh_execution: ssh } });

Deno.test("a publish names its group only for the content check, the import and the push", () => {
  assertEquals(scoped("workflow-operation-task-publish.yaml", publish(41), publish(42)), ["Check the .env content", "Import the bundle", "Push the branch"]);
});

Deno.test("the publish passes its group to every workspace phase", () => {
  const calls = steps("workflow-operation-task-publish.yaml").filter((step) => step.task.workflowIdOrName === "task-instance-workspace");
  // Preflight, export, the copy check (`scan`) and the discard.
  assertEquals(calls.map((call) => call.task.inputs?.phase), ["preflight", "export", "scan", "discard"]);
  for (const call of calls) {
    const group = call.task.inputs?.group_id;
    assertEquals(typeof group, "string", `${call.name} passes no group_id`);
    assertEquals(Number(cel.evaluate(expression(String(group)), { inputs: publish(42) })), 42, call.name);
  }
});

Deno.test("a workspace phase names its group only for the phase itself", () => {
  const phase = (group: number) => ({ node_id: node, group_id: group, phase: "export", intent: { ...workspace, ssh_execution: ssh } });
  assertEquals(scoped("workflow-task-instance-workspace.yaml", phase(41), phase(42)), ["Run workspace phase"]);
});

Deno.test("a copy check names its group only for the merge, check, commit and commit-ref phases", () => {
  const check = (group: number) => ({ operation_id: 900 + group, node_id: node, kind: "tasks.check", intent: { group_id: group, workspace }, run_id: "run-1" });
  assertEquals(scoped("workflow-task-instance-check.yaml", check(41), check(42)), ["Commit the candidate", "Merge the base", "Pin the candidate", "Run the check"]);
});

Deno.test("a copy's handoff check passes its group to both calls of the copy's base", () => {
  const calls = steps("workflow-task-instance-check.yaml").filter((step) => step.task.workflowIdOrName === "task-copy-base");
  assertEquals(calls.map((call) => [call.name, call.task.inputs?.phase]), [["Bring in the base", "base"], ["Discard the base scratch", "base-cleanup"]]);
  for (const call of calls) {
    const group = call.task.inputs?.group_id;
    assertEquals(Number(cel.evaluate(expression(String(group)), { inputs: { intent: { group_id: 42 } } })), 42, call.name);
  }
});

Deno.test("a sync names its group only for the clone and the bundle on 209", () => {
  const sync = (group: number) => ({ operation_id: 900 + group, intent: { group_id: group, node_id: node, ssh_execution: ssh } });
  assertEquals(scoped("workflow-operation-task-sync.yaml", sync(41), sync(42)), ["Bundle the refs", "Clone the refs"]);
});

Deno.test("a copy's base names its group only for the base phase, which runs up to 600 s", () => {
  const base = (group: number) => ({ node_id: node, group_id: group, phase: "base", intent: { ...workspace, ssh_execution: ssh },
    git_authorization: { vault: "orbit-control-inputs", key: "task-sync-token-0b8a4c1e-5d1f-4c55-9d35-1c0f6a2b7e10" } });
  assertEquals(scoped("workflow-task-copy-base.yaml", base(41), base(42)), ["Bring in the base"]);
});

Deno.test("the copy's sync passes its group to both calls of the copy's base", () => {
  const calls = steps("workflow-operation-task-sync-copy.yaml").filter((step) => step.task.workflowIdOrName === "task-copy-base");
  assertEquals(calls.map((call) => call.name), ["Bring in the base", "Discard the base scratch"]);
  for (const call of calls) {
    const group = call.task.inputs?.group_id;
    assertEquals(typeof group, "string", `${call.name} passes no group_id`);
    assertEquals(Number(cel.evaluate(expression(String(group)), { inputs: { intent: { group_id: 42 } } })), 42, call.name);
  }
});
