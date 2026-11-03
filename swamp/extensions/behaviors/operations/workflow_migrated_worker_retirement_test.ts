import { assert, assertEquals, assertNotEquals } from "jsr:@std/assert@1.0.19";
import { exists } from "jsr:@std/fs@1.0.19/exists";
import { parse } from "jsr:@std/yaml@1.0.12";
import { ROOT } from "../../../tests/_support/native_harness.ts";

type Scope = {
  name?: string; id?: string; target?: unknown; labels?: unknown;
  task?: { type: string; workflowIdOrName: string }; jobs?: Scope[]; steps?: Scope[];
};
function* steps(value: Scope, inherited: Scope = {}): Generator<Scope> {
  const current = { ...inherited, ...Object.fromEntries(["target", "labels"].filter((key) => key in value).map((key) => [key, value[key as keyof Scope]])) };
  if (value.task) yield { ...current, task: value.task };
  for (const child of [...value.jobs ?? [], ...value.steps ?? []]) yield* steps(child, current);
}

Deno.test("migrated effect steps and child workflows use the Swamp worker without enrollment", async () => {
  const workflows = new Map<string, { source: string; workflow: Scope }>();
  for await (const file of Deno.readDir(`${ROOT}/workflows`)) {
    if (!file.name.endsWith(".yaml")) continue;
    const source = await Deno.readTextFile(`${ROOT}/workflows/${file.name}`);
    const workflow = parse(source) as Scope;
    for (const key of [workflow.name!, workflow.id!]) workflows.set(key, { source, workflow });
  }
  const pending = ["operation-schedule-create", "operation-schedule-control", "task-command-run", "operation-schedule-destroy",
    "operation-database-read", "operation-vault-sync", "operation-conn-pairing-revoke", "operation-route-update", "operation-task-publish"];
  const visited = new Set<Scope>();
  for (const name of pending) {
    const entry = workflows.get(name);
    assert(entry, name);
    const { source, workflow } = entry;
    if (visited.has(workflow)) continue;
    visited.add(workflow);
    for (const retired of ["node-worker-enroll", "node-proxy-workers", "worker_credentials", "enrollment_token"]) assert(!source.includes(retired), name);
    const placed = [...steps(workflow)];
    assert(placed.length > 0, name);
    for (const placement of placed) {
      assert(!("target" in placement), name);
      assertEquals(placement.labels, { role: "swamp" }, name);
      if (placement.task?.type === "workflow") pending.push(placement.task.workflowIdOrName);
    }
  }
});

Deno.test("nested effect Node placement overrides inherited Swamp placement", () => {
  const workflow = { labels: { role: "swamp" }, jobs: [{ steps: [{ labels: { node: "${{ string(inputs.node_id) }}" }, task: { type: "assert", workflowIdOrName: "" } }] }] };
  assertNotEquals([...steps(workflow)][0].labels, { role: "swamp" });
});

Deno.test("retired issuer workflows and proxy behaviors stay absent", async () => {
  for (const name of ["node-proxy-workers-setup", "node-proxy-preflight", "node-worker-enroll", "node-worker-rotation"]) {
    assert(!await exists(`${ROOT}/workflows/workflow-${name}.yaml`));
  }
  for (const name of ["proxy.ts", "proxy-install.ts", "proxy-runtime.ts", "rotation.ts"]) assert(!await exists(`${ROOT}/extensions/behaviors/node-worker/${name}`));
});
