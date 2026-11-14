import { assertEquals } from "jsr:@std/assert@1.0.19";
import { behavior } from "../extensions/behaviors/operations/instance-dev-command/assets.ts";
import { completeResult } from "../extensions/behaviors/operations/instance-dev-command/complete_result.ts";
import { failures, levelsOf, type Outcome, type Ran, runWorkflow } from "./_support/swamp_workflow.ts";

/** The clone completion (docs/cli/instance/complete.mmd) on the pinned engine's levels, with the real `finish` receipt. */
const workflow = "workflow-operation-instance-complete.yaml";
const copy = (key: string) => ({ vault: "orbit-control-inputs", key });
const secrets = { process_environments: copy("instance-complete-processes-1") };
const databaseSecrets = { ...secrets, server_database_create: copy("database-create-intent-4"), server_database_check: copy("database-create-owner-4"),
  server_valkey_create: copy("database-create-intent-5"), server_valkey_check: copy("database-create-owner-5") };
const plain = { instance_id: 8, container: { clone: { source: "instance-7" }, project: "orbit-instances" }, database: null, server_database: null,
  server_valkey: null, route: null, route_mutations: null };
const routed = { ...plain, route: { leaf_indexes: [0] }, route_mutations: { artifacts: {}, restore: {} } };
const serverDatabase = { node_id: 5, intent: { ssh_execution: { target: { host: "10.0.0.5", user: "orbit", port: 22, host_keys: [] }, identity_file: "/k" },
  owner_client: ["psql"] }, fill: ["docker", "exec"] };
const withDatabase = { ...routed, server_database: serverDatabase, server_valkey: { ...serverDatabase, fill: null } };
const checked = { stdout: '{"phase": "check", "changes": []}', exitCode: 0 };
const dead = { error_code: "instance.container_process_inactive", process_failure: { process_id: 41, unit: "orbit-process-41-worker.service", state: "failed" } };

function run(intent: Record<string, unknown>, outcomes: Record<string, Outcome>): Record<string, Ran> {
  return runWorkflow(workflow, "instance.complete", intent, { "clone-check": { duration: 1.7, outputs: checked }, ...outcomes }, (step, cel) => {
    const values = Object.fromEntries(Object.entries(step.task.inputs!).map(([key, value]) => [key, cel(value)]));
    const result = completeResult(intent, behavior.methods.complete_result.arguments.parse(values));
    return { outputs: { ready: result.ready, stdout: result.stdout } };
  });
}
const receipt = (ran: Record<string, Ran>) => JSON.parse(String(ran.finish.outputs!.stdout));

Deno.test("the completion's jobs and steps sit in the levels the plan names, by the pinned engine's sort", () => {
  const { jobs, steps } = levelsOf(workflow);
  assertEquals(jobs, [["input"], ["complete", "publication"], ["result"]]);
  const sorted = (levels: string[][]) => levels.map((level) => [...level].sort());
  assertEquals(sorted(steps.complete), [
    ["clone-input", "database-snapshot", "server-database", "server-valkey"],
    ["clone-fleet", "database-install", "server-database-check", "server-valkey-check"],
    ["database-cleanup", "server-database-fill"],
    ["clone-services"],
    ["clone-check"],
  ]);
  assertEquals(sorted(steps.publication), [["route-csr"], ["route"]]);
  assertEquals(sorted(steps.result), [["clone-private-cleanup", "finish"], ["cleanup", "withdraw-route"], ["accepted"]]);
});

Deno.test("a plain clone, a clone with a database and a nested-fleet clone read only the keys they have", () => {
  assertEquals(failures(workflow, { execution_secrets: secrets, intent: plain }), []);
  assertEquals(failures(workflow, { execution_secrets: secrets, intent: { ...routed, fleet: { seed: "e".repeat(40) } } }), []);
  assertEquals(failures(workflow, { execution_secrets: databaseSecrets, intent: withDatabase }), []);
  assertEquals(failures(workflow, { execution_secrets: secrets, intent: { ...routed, database: { ssh_execution: {} } } }), []);
});

Deno.test("a plain clone starts the services when the stage ends, checks them, and is ready", () => {
  // Seconds from the plan: stage 2.0, services 6, check 1.7, Route 1.3 + 11.5.
  const ran = run(routed, { "clone-input": { duration: 2 }, "clone-services": { duration: 6 }, "route-csr-0": { duration: 1.3, outputs: { sign: { stdout: "pem" } } },
    route: { duration: 11.5 } });
  assertEquals(ran["clone-services"].start, ran["clone-input"].end);
  assertEquals(ran["clone-check"].start, ran["clone-services"].end);
  // The Route job runs beside the container steps from the start.
  assertEquals(ran["route-csr-0"].start, ran["clone-input"].start);
  for (const name of ["clone-fleet", "database-install", "server-database", "withdraw-route"]) assertEquals(ran[name].status, "skipped", name);
  assertEquals([ran.finish.outputs!.ready, ran.accepted.status, ran["clone-private-cleanup"].status, ran.cleanup.status], [true, "succeeded", "succeeded", "succeeded"]);
  assertEquals(receipt(ran).failed_step, undefined);
  // The result job waits for the longer of the two lines: the Route.
  assertEquals(ran.finish.start, Math.max(ran["clone-check"].end, ran.route.end));
});

Deno.test("a clone with a database starts the services only after the fill and the Valkey check", () => {
  const ran = run(withDatabase, { "server-database": { duration: 2.5 }, "server-database-check": { duration: 1 }, "server-database-fill": { duration: 9 },
    "server-valkey": { duration: 2 }, "server-valkey-check": { duration: 1 }, "route-csr-0": { outputs: { sign: { stdout: "pem" } } } });
  assertEquals(ran["clone-services"].start, ran["server-database-fill"].end);
  assertEquals(ran["clone-services"].start >= ran["server-valkey-check"].end, true);
  assertEquals([ran.finish.outputs!.ready, receipt(ran).failed_step], [true, undefined]);
});

Deno.test("a nested-fleet clone starts its fleet before the services; a failed fleet names the fleet and withdraws the Route", () => {
  const fleet = { ...routed, fleet: { seed: "e".repeat(40) } };
  const ran = run(fleet, { "clone-fleet": { duration: 40 }, "route-csr-0": { outputs: { sign: { stdout: "pem" } } } });
  assertEquals([ran["clone-fleet"].status, ran["clone-services"].start], ["succeeded", ran["clone-fleet"].end]);
  assertEquals(ran.finish.outputs!.ready, true);
  const failed = run(fleet, { "clone-fleet": { status: "failed", duration: 40 }, "route-csr-0": { outputs: { sign: { stdout: "pem" } } } });
  assertEquals([failed["clone-services"].status, failed["clone-check"].status], ["skipped", "skipped"]);
  assertEquals([receipt(failed).failed_step, receipt(failed).error_code], ["fleet", "instance.create_fleet_failed"]);
  assertEquals([failed["withdraw-route"].status, failed.accepted.status], ["succeeded", "failed"]);
  // The fleet phase's own code and why come from the run's result file.
  const file = { error_code: "instance.container_fleet_resume_failed", detail: "Doctor did not answer" };
  const intent = { ...plain, fleet: {} };
  const own = JSON.parse(completeResult(intent, { stage: "succeeded", database: "skipped", fleet: "failed", services: "skipped", check: null, route: "skipped",
    certificates: [], route_observation: {} }, file).stdout);
  assertEquals([own.failed_step, own.error_code, own.detail], ["fleet", "instance.container_fleet_resume_failed", "Doctor did not answer"]);
});

Deno.test("each failure names its step and code, and a published Route is withdrawn", () => {
  const signed = { "route-csr-0": { outputs: { sign: { stdout: "pem" } } } };
  const cases: [string, Record<string, unknown>, Record<string, Outcome>, string, string][] = [
    ["services", routed, { "clone-services": { status: "failed" } }, "workspace", "instance.create_workspace_failed"],
    ["stage", routed, { "clone-input": { status: "failed" } }, "workspace", "instance.create_workspace_failed"],
    ["dead unit", routed, { "clone-check": { outputs: { stdout: JSON.stringify(dead), exitCode: 1 } } }, "check", "instance.container_process_inactive"],
    ["check", routed, { "clone-check": { outputs: { stdout: "", exitCode: 1 } } }, "check", "instance.complete_check_failed"],
    ["check timeout", routed, { "clone-check": { status: "failed" } }, "check", "instance.complete_check_failed"],
    ["database", withDatabase, { "server-database-fill": { status: "failed" } }, "database", "instance.create_database_failed"],
    ["Route", routed, { route: { status: "failed" } }, "route", "instance.create_route_failed"],
  ];
  for (const [label, intent, outcomes, step, code] of cases) {
    const ran = run(intent, { ...signed, ...outcomes });
    const result = receipt(ran);
    assertEquals([ran.finish.outputs!.ready, result.failed_step, result.error_code], [false, step, code], label);
    assertEquals(ran["withdraw-route"].status, "succeeded", label);
    assertEquals([ran.accepted.status, ran["clone-private-cleanup"].status, ran.cleanup.status], ["failed", "succeeded", "succeeded"], label);
    if (label === "dead unit") assertEquals(result.process_failure, dead.process_failure);
    if (label === "database") assertEquals([ran["clone-services"].status, ran["clone-check"].status], ["skipped", "skipped"]);
  }
  // An early Route failure never stops the container steps: the services start and the check runs.
  const early = run(routed, { "route-csr-0": { status: "failed", duration: 0.5 }, "clone-input": { duration: 2 } });
  assertEquals([early["clone-services"].status, early["clone-check"].status], ["succeeded", "succeeded"]);
  assertEquals([receipt(early).failed_step, early["withdraw-route"].status], ["route", "succeeded"]);
  // A clone without a Route has nothing to withdraw.
  const bare = run(plain, { "clone-services": { status: "failed" } });
  assertEquals([receipt(bare).failed_step, bare["withdraw-route"].status], ["workspace", "skipped"]);
});

Deno.test("a completion of a Route its create published carries no Route: no Route step, no withdrawal on a failed check (D3)", () => {
  // Guards: `inputs.intent.route == null` skips `route`, and the CSR's forEach list is empty.
  const ran = run(plain, { "clone-input": { duration: 2 }, "clone-services": { duration: 6 } });
  assertEquals([ran.route.status, "route-csr-0" in ran, ran["withdraw-route"].status], ["skipped", false, "skipped"]);
  assertEquals(ran.finish.start, ran["clone-check"].end);
  assertEquals(ran.finish.outputs!.ready, true);
  // A dead unit fails the completion; the address the create published stays up (502) until a retry or a removal.
  const failed = run(plain, { "clone-check": { duration: 1.7, outputs: { stdout: JSON.stringify(dead), exitCode: 1 } } });
  assertEquals([failed.finish.outputs!.ready, receipt(failed).failed_step, failed["withdraw-route"].status], [false, "check", "skipped"]);
  // A completion that captured the Route (the create's Nodes were held, or its publication failed) withdraws it on its own failure.
  const fallback = run(routed, { "route-csr-0": { outputs: { sign: { stdout: "pem" } } }, route: { status: "failed", duration: 6 } });
  assertEquals([receipt(fallback).failed_step, receipt(fallback).error_code, fallback["withdraw-route"].status], ["route", "instance.create_route_failed", "succeeded"]);
});
