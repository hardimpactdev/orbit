import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { Environment } from "npm:@marcbachmann/cel-js@7.6.1";
import { swampExpression, swampExpressions } from "./_support/swamp_expression.ts";

/**
 * Evaluates every expression of `operation-instance-restore` against the input that InstanceRestoreProjection::input renders
 * (InstanceRestoreTest checks that shape), so a key the run lacks or a misspelled field fails here, not on the live restore.
 */
type Step = {
  name: string;
  guard?: string;
  forEach?: { item: string; in: string };
  dependsOn?: { step: string; condition: Record<string, unknown> }[];
  task: Record<string, unknown> & { inputs: Record<string, unknown> };
};
const read = (name: string) => parse(Deno.readTextFileSync(new URL(`../workflows/${name}`, import.meta.url))) as {
  inputs: { properties: Record<string, unknown>; required: string[] };
  jobs: { name: string; steps: Step[] }[];
};
const flow = read("workflow-operation-instance-restore.yaml");
const steps = flow.jobs.flatMap((job) => job.steps);
const step = (name: string) => steps.find((s) => s.name === name)!;
const cel = new Environment({ enableOptionalTypes: true, unlistedVariablesAreDyn: true, homogeneousAggregateLiterals: false })
  .registerFunction("vaultget(dyn, dyn): string", (vault: unknown, key: unknown) => `${vault}/${key}`);

const ssh = {
  control_node_id: 2,
  identity_file: "/etc/orbit/swamp-role-worker/ssh-identity",
  public_key: "ssh-ed25519 AAAA",
  target: { name: "target", node_id: 17, host: "10.44.0.3", user: "orbit", port: 22, host_keys: ["ssh-ed25519 fixture"] },
};
const admin = ["docker", "exec", "-i", "orbit-process-6-fixture-postgres", "sh", "-c", "IFS= read -r PGPASSWORD && export ...", "sh", "postgres", "postgres"];
const routeCreate = { route_id: 269, site_nodes: [9], leaf_indexes: [0], caddy: [{ node_id: 9, content: "https://ps-server.hauzer.test {}" }] };
const routeSecrets = { route_material_0: { vault: "orbit-control-inputs", key: "route-0123456789abcdef-forward-route_material_0" } };
const inputs = (servers: unknown[]) => ({
  operation_id: 4242,
  node_id: 9,
  kind: "instance.restore",
  intent: { instance_id: 606, servers, route_create: routeCreate, route_secrets: routeSecrets },
  execution_secrets: {
    server_6_check: { vault: "orbit-control-inputs", key: "instance-restore-606-6-check" },
    server_6_clear: { vault: "orbit-control-inputs", key: "instance-restore-606-6-clear" },
  },
});
const server = { server_id: 6, node_id: 17, admin_client: admin, ssh_execution: ssh };

function evaluate(value: unknown, context: Record<string, unknown>): unknown {
  if (typeof value === "string") {
    if (!value.includes("${{")) return value;
    const expression = swampExpression(value).replaceAll("vault.get(", "vaultget(");
    return cel.evaluate(expression, { run: { id: "run-1" }, ...context });
  }
  if (Array.isArray(value)) return value.map((item) => evaluate(item, context));
  if (value !== null && typeof value === "object") {
    return Object.fromEntries(Object.entries(value).map(([key, item]) => [key, evaluate(item, context)]));
  }
  return value;
}

Deno.test("the step names are the chart's", () => {
  assertEquals(steps.map((s) => s.name), ["Check the databases", "Republish the Route", "Clear the fence journal"]);
  const chart = Deno.readTextFileSync(new URL("../../docs/cli/instance/restore.mmd", import.meta.url));
  for (const s of steps) assert(chart.includes(`: ${s.name}<br/>`), s.name);
  for (const s of steps) {
    for (const expression of swampExpressions(JSON.stringify(s))) assert(expression.length > 0);
  }
});

Deno.test("each server's database steps reach its Node with its own batch", () => {
  const context = { inputs: inputs([server]), self: { server, _index: 0 } };
  assertEquals(evaluate(step("Check the databases").forEach!.in, context), [server]);
  for (const [name, batch] of [["Check the databases", "check"], ["Clear the fence journal", "clear"]]) {
    const task = evaluate(step(name).task, context) as { behaviorName: string; globalArgs: Record<string, unknown>; inputs: Record<string, unknown> };
    assertEquals(task.behaviorName, "instance-restore-ssh-17");
    assertEquals(task.globalArgs.transport, { kind: "ssh", user: "orbit", port: 22, identityFile: ssh.identity_file });
    assertEquals(task.globalArgs.hosts, [{ name: "target", address: "10.44.0.3", transport: { hostKeys: ["ssh-ed25519 fixture"] } }]);
    assertEquals(task.inputs.argv, ["timeout", "60", ...admin]);
    assertEquals(task.inputs.stdin, `orbit-control-inputs/instance-restore-606-6-${batch}`);
    assertEquals(task.inputs.sudo, true);
  }
});

Deno.test("the Route is republished by the Route create workflow, only after the database check, with its own secrets", () => {
  const republish = step("Republish the Route");
  for (const servers of [[server], []]) {
    const context = { inputs: inputs(servers) };
    // A Route-only Instance has no server: the forEach over none is skipped, which still lets the Route publish.
    assertEquals(evaluate(republish.guard, { ...context, steps: { "Check the databases": { status: servers.length ? "succeeded" : "skipped" } } }), false);
    assertEquals(evaluate(republish.guard, { ...context, steps: { "Check the databases-0": { status: "failed" } } }), true);
    assertEquals(republish.task.workflowIdOrName, "operation-route-create");
    const child = evaluate(republish.task.inputs, context) as Record<string, unknown>;
    assertEquals(child, { operation_id: 4242, node_id: 9, parent_run_id: "run-1", kind: "route.create", intent: routeCreate, execution_secrets: routeSecrets });
    // The child refuses unknown input keys: every key restore passes is one Route create declares.
    const route = read("workflow-operation-route-create.yaml");
    for (const key of Object.keys(child)) assert(key in route.inputs.properties, key);
    for (const key of route.inputs.required) assert(key in child, key);
  }
  assertEquals(step("Clear the fence journal").dependsOn, [{ step: "Republish the Route", condition: { type: "succeeded" } }]);
});
