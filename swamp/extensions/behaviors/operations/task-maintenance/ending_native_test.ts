import { assert, assertEquals, assertFalse } from "jsr:@std/assert@1.0.19";
import { ending } from "../../../../tests/_support/task_maintenance.ts";
import { command, outputs, taskRemote, type Remote } from "../../../../tests/_support/task_remote.ts";
import { type History } from "../../../../tests/_support/native_harness.ts";

const receipt = (history: History) => JSON.parse(outputs(history, "Check the VM is gone").stdout);
const teardown = (command: string) => [{ timeout_seconds: 30, input_json: JSON.stringify({ name: "teardown", command, timeout_seconds: 30, required: true }) }];

for (const failed of [false, true]) Deno.test(`park preserves native provider ${failed ? "failure" : "completion"} without a printer`, async () => {
  await using f = await ending();
  const { t } = f;
  t.inputs.intent.eligible = true;
  t.inputs.intent.provider_input.fixture_running = false;
  await t.workflow("park");
  if (failed) await f.failProvider();
  const { run, steps } = await t.run(!failed);
  assertEquals(run.status, failed ? "failed" : "succeeded");
  assertEquals(steps.provider, failed ? "failed" : "succeeded");
  assertFalse("result" in steps);
});

Deno.test("empty workspace requires no guest or child effect", async () => {
  await using f = await ending();
  Object.assign(f.t.inputs.intent, { instance_id: null, task_vm_id: null, ssh_execution: null });
  assertEquals(receipt((await f.t.run()).history), { group_id: 1, instance_id: null, cleanup_complete: true, vm: {} });
  assertEquals(f.t.calls(), []);
});

Deno.test("owned workspace uses canonical child success as cleanup evidence", async () => {
  await using f = await ending();
  const { history, steps } = await f.t.run();
  assertEquals(steps["Destroy the task VM"], "succeeded");
  assertEquals(receipt(history), { group_id: 1, instance_id: 2, cleanup_complete: true, vm: { destroyed: true, cleanup_complete: true } });
});

Deno.test("successful teardown precedes canonical destroy", async () => {
  await using f = await ending();
  f.t.inputs.intent.teardown = teardown("printf complete > teardown-complete");
  const { history } = await f.t.run();
  assertEquals(await Deno.readTextFile(`${f.t.workspace}/teardown-complete`), "complete");
  assert(receipt(history).cleanup_complete);
});

Deno.test("teardown output is redacted on the worker before history", async () => {
  await using f = await ending();
  f.t.repo.secrets.push("ghp_" + "T".repeat(36), "e4".repeat(32));
  f.t.inputs.intent.teardown = teardown(`python3 -c 'print("ghp_" + "T" * 36); print("e4" * 32)'; echo visible >&2`);
  const { history } = await f.t.run();
  assert(receipt(history).cleanup_complete);
  const output = outputs(history, "Run teardown steps");
  assertEquals(output["teardown-0"].stdout, "");
  assertEquals(output.output.teardown["teardown-0"], { output: "[REDACTED]\n[REDACTED]\nvisible\n", truncated: false });
});

Deno.test("stopped provider skips guest teardown and still destroys", async () => {
  await using f = await ending();
  f.t.inputs.intent.provider_input.fixture_running = false;
  f.t.inputs.intent.teardown = teardown("exit 23");
  const { history, steps } = await f.t.run();
  assert(receipt(history).cleanup_complete);
  assertEquals(steps["Run teardown steps"], "succeeded");
  assertEquals(steps["Destroy the task VM"], "succeeded");
});

Deno.test("exclusive Route withdrawal precedes VM destroy", async () => {
  await using f = await ending();
  f.t.inputs.intent.workspace_routes = [{ fixture_ready: true }];
  const { history, steps } = await f.t.run();
  assert(receipt(history).cleanup_complete);
  assertEquals(steps["Withdraw the Route"], "succeeded");
  const values = history.jobs[0].steps as unknown as Record<string, string>[];
  assert(values.find((step) => step.name === "Withdraw the Route")!.completedAt <= values.find((step) => step.name === "Destroy the task VM")!.startedAt);
});

Deno.test("Route public failure refuses VM delete even when child succeeds", async () => {
  await using f = await ending();
  f.t.inputs.intent.workspace_routes = [{ fixture_ready: false }];
  const { steps } = await f.t.run(false);
  assertEquals(steps["Withdraw the Route"], "succeeded");
  assert(steps["Destroy the task VM"] !== "succeeded");
  assertEquals(steps["Check the VM is gone"], "failed");
});

Deno.test("teardown failure does not claim VM cleanup", async () => {
  await using f = await ending();
  f.t.inputs.intent.teardown = teardown("printf started > teardown-started; exit 23");
  const { run, steps } = await f.t.run(false);
  assertEquals(run.status, "failed");
  assertEquals(await Deno.readTextFile(`${f.t.workspace}/teardown-started`), "started");
  for (const name of ["Destroy the task VM", "Check the VM is gone"]) assert(steps[name] !== "succeeded");
});

async function pushIntent(t: Remote) {
  const remote = `${t.repo.dir}/remote.git`;
  await command(["git", "init", "-q", "--bare", remote]);
  await Deno.writeTextFile(`${t.workspace}/approved.txt`, "approved");
  await t.git("add", "approved.txt");
  await t.git("commit", "-qm", "approved");
  const commit = await t.git("rev-parse", "HEAD");
  await Deno.writeTextFile(`${t.workspace}/approved.txt`, "later");
  const key = "task-publish-token-aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa";
  await t.repo.cli(["vault", "create", "local_encryption", "orbit-control-inputs"]);
  await t.repo.cli(["vault", "put", "orbit-control-inputs", key], { input: "fixture-token" });
  t.inputs.intent.push = {
    commit, checkout_path: t.workspace, remote: `file://${remote}`, refspec: `${commit}:refs/heads/task-1`,
    object_format: "sha1", ssh_execution: t.inputs.intent.ssh_execution, github_token: { vault: "orbit-control-inputs", key },
  };
  return { remote, commit };
}

Deno.test("approved work is pushed without force before removal", async () => {
  await using f = await ending();
  const { remote, commit } = await pushIntent(f.t);
  const { history } = await f.t.run();
  assert(receipt(history).cleanup_complete);
  assertEquals((await command(["git", "-C", remote, "rev-parse", "refs/heads/task-1"])).stdout.trim(), commit);
  const steps = history.jobs[0].steps as unknown as Record<string, string>[];
  assert(steps.find((step) => step.name === "Push approved work")!.completedAt <= steps.find((step) => step.name === "Destroy the task VM")!.startedAt);
});

Deno.test("failed push stops before any removal", async () => {
  await using f = await ending();
  const { remote } = await pushIntent(f.t);
  await Deno.writeTextFile(`${remote}/hooks/pre-receive`, "#!/bin/sh\nexit 1\n", { mode: 0o755 });
  const { steps } = await f.t.run(false);
  assertEquals(steps["Push approved work"], "failed");
  for (const name of ["Withdraw the Route", "Destroy the task VM"]) assert(steps[name] !== "succeeded");
});

Deno.test("native resume after cleanup failure never reexecutes the repository program", async () => {
  await using t = await taskRemote();
  await t.install(`#!/usr/bin/env -S ${Deno.execPath()} run -A --quiet --no-config --no-lock\nconst p=".git/count";let n=0;try{n=Number(Deno.readTextFileSync(p));}catch{}Deno.writeTextFileSync(p,String(n+1));\n`);
  Object.assign(t.inputs.intent, { script: "scripts/run", args: [] });
  await t.workflow("script");
  const cleanup = t.step("cleanup-local"), marker = `${t.repo.dir}/cleanup-ready`;
  cleanup.allowFailure = false;
  cleanup.task.inputs.env.ORBIT_TEST_READY = marker;
  cleanup.task.inputs.run = 'set -e\ntest -f "$ORBIT_TEST_READY"\n' + cleanup.task.inputs.run;
  await t.save();
  const { run } = await t.run(false);
  await Deno.writeTextFile(marker, "");
  await t.repo.cli(["workflow", "resume", "task-fixture", "--run", run.id]);
  const { history } = await t.repo.history(run.id);
  assertEquals(history.status, "succeeded");
  assertEquals(await Deno.readTextFile(`${t.workspace}/.git/count`), "1");
});
