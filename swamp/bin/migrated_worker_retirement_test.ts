import { assert, assertEquals, assertFalse, assertNotEquals } from "jsr:@std/assert@1.0.19";
import { expandGlob } from "jsr:@std/fs@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { ROOT } from "../tests/_support/native_harness.ts";
import { type Json } from "../tests/_support/platform_fixture.ts";

function* steps(value: Json, inherited: Json = {}): Generator<[Json, Json]> {
  const current = {
    ...inherited,
    ...Object.fromEntries(["target", "labels"].filter((key) => key in value).map((key) => [key, value[key]])),
  };
  if ("task" in value) yield [current, value.task];
  for (const key of ["jobs", "steps"]) for (const child of value[key] ?? []) yield* steps(child, current);
}
Deno.test("migrated effect steps use the control role without worker enrollment", async () => {
  const workflows = new Map<string, string>();
  for await (const file of expandGlob(`${ROOT}/workflows/*.yaml`)) {
    const workflow = parse(await Deno.readTextFile(file.path)) as Json;
    for (const key of [workflow.name, workflow.id]) workflows.set(key, file.path);
  }
  const pending = [
    "operation-schedule-create",
    "operation-schedule-control",
    "task-command-run",
    "operation-schedule-destroy",
    "operation-database-read",
    "operation-vault-sync",
    "operation-conn-pairing-revoke",
    "operation-route-update",
    "operation-task-publish",
  ];
  const visited = new Set();
  while (pending.length) {
    const path = workflows.get(pending.pop()!)!;
    if (visited.has(path)) continue;
    visited.add(path);
    const source = await Deno.readTextFile(path);
    for (const name of ["node-worker-enroll", "node-proxy-workers", "worker_credentials", "enrollment_token"]) {
      assertFalse(source.includes(name), path);
    }
    const placed = [...steps(parse(source) as Json)];
    assert(placed.length > 0, path);
    for (const [placement, task] of placed) {
      assertFalse("target" in placement, path);
      assertEquals(placement.labels, { role: "swamp" }, path);
      if (task.type === "workflow") pending.push(task.workflowIdOrName);
    }
  }
});
Deno.test("nested effect-node placement overrides are detected", () => {
  const workflow = {
    labels: { role: "swamp" },
    jobs: [{ steps: [{ labels: { node: "${{ string(inputs.node_id) }}" }, task: { type: "assert" } }] }],
  };
  assertNotEquals([...steps(workflow)][0][0].labels, { role: "swamp" });
});
Deno.test("retired worker issuers and proxy behaviors are absent", async () => {
  const paths = [
    ...["node-proxy-workers-setup", "node-proxy-preflight", "node-worker-enroll", "node-worker-rotation"].map((name) =>
      `workflows/workflow-${name}.yaml`
    ),
    ...["proxy.ts", "proxy-install.ts", "proxy-runtime.ts", "rotation.ts"].map((name) =>
      `extensions/behaviors/node-worker/${name}`
    ),
  ];
  for (const path of paths) assertFalse(await Deno.stat(`${ROOT}/${path}`).then(() => true, () => false), path);
});
