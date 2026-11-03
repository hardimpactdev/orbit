import { assert, assertEquals, assertFalse, assertStringIncludes } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { ROOT } from "../../../../tests/_support/native_harness.ts";
import { result } from "../../../../tests/_support/task_remote.ts";
import { nativeTurn } from "../../../../tests/_support/task_turn.ts";

Deno.test("native turn uses SSH workspace and sends one Pi message", async () => {
  await using t = await nativeTurn();
  const { run, history } = await t.test.run();
  assertEquals(run.status, "succeeded");
  assertEquals(result(history, "shape_result").receipt.outcome, "ready_for_review");
  assertEquals(t.pi.calls.filter(([path]) => path.endsWith("/messages")).length, 1);
  assertFalse(await Deno.stat(`${t.workspace}/never`).then(() => true, () => false));
});
Deno.test("native reviewer restores candidate and redacts receipt and difference", async () => {
  await using t = await nativeTurn();
  t.pi.role = "reviewer";
  const credential = "a1".repeat(32);
  t.pi.agentText = `MODEL_KEY=${credential}`;
  Object.assign(t.inputs.intent, { role: "reviewer", candidate: t.head });
  await Deno.writeTextFile(`${t.workspace}/${credential}`, "untracked\n");
  const { run, history } = await t.test.run();
  assertEquals(run.status, "succeeded");
  const value = result(history, "shape_result");
  assertEquals(value.receipt.outcome, "approved");
  assertFalse(JSON.stringify(history).includes(credential));
  assertStringIncludes(value.receipt.summary, "[REDACTED]");
  assertStringIncludes(value.difference.diff, "[REDACTED]");
  assertEquals(value.after_reset.head, t.head);
  assertEquals(await Deno.readTextFile(`${t.workspace}/file`), "original\n");
});
Deno.test("snapshots preserve the real index and include untracked files", async () => {
  await using t = await nativeTurn();
  t.pi.role = t.inputs.intent.role = "consult";
  await Deno.writeTextFile(`${t.workspace}/untracked`, "new file\n");
  const index = `${t.workspace}/.git/index`, original = await Deno.readFile(index);
  const { run, history } = await t.test.run();
  assertEquals(run.status, "succeeded");
  assertEquals(await Deno.readFile(index), original);
  const value = result(history, "shape_result");
  assert(value.before.tree !== await t.git("rev-parse", "HEAD^{tree}"));
  assertEquals(value.difference.summary.files, 1);
  for await (const entry of Deno.readDir(t.repo.dir)) {
    if (entry.name.startsWith("remote-")) assertFalse(Array.from(Deno.readDirSync(`${t.repo.dir}/${entry.name}`)).some((file) => file.name.endsWith(".index")));
  }
});
Deno.test("prompt overflow retains its public refusal without Pi calls", async () => {
  await using t = await nativeTurn();
  t.inputs.intent.prompt = "x".repeat(100000);
  const { run, history } = await t.test.run(false);
  assertEquals(run.status, "failed");
  assertEquals(result(history, "shape_result").error_code, "tasks.analysis_prompt_too_large");
  assertEquals(t.pi.calls, []);
});
Deno.test("missing receipt remains a completed turn without a receipt", async () => {
  await using t = await nativeTurn();
  t.pi.omitReceipt = true;
  const { run, history } = await t.test.run();
  assertEquals(run.status, "succeeded");
  assertEquals(result(history, "shape_result").receipt, null);
});
Deno.test("stale receipt is cleared before the turn", async () => {
  await using t = await nativeTurn();
  t.pi.omitReceipt = true;
  await Deno.mkdir(`${t.workspace}/.git/orbit`);
  const stale = `${t.workspace}/.git/orbit/receipt.json`;
  await Deno.writeTextFile(stale, JSON.stringify({ thread: 14, outcome: "ready_for_review", summary: "Old" }));
  const { run, history } = await t.test.run();
  assertEquals(run.status, "succeeded");
  const value = result(history, "shape_result");
  assertEquals([value.receipt, value.receipt_error], [null, null]);
  assertFalse(await Deno.stat(stale).then(() => true, () => false));
});
Deno.test("receipt from another thread is evidence with a fixed code", async () => {
  await using t = await nativeTurn();
  t.pi.thread = 99;
  const { run, history } = await t.test.run();
  assertEquals(run.status, "succeeded");
  const value = result(history, "shape_result");
  assertEquals(value.receipt, null);
  assertEquals(value.receipt_error, "tasks.receipt_thread_mismatch");
});
Deno.test("prompt tells the agent to write the receipt file", async () => {
  await using t = await nativeTurn();
  await t.test.run();
  const text = t.pi.calls.find(([path]) => path.endsWith("/messages"))![1]!.text as string;
  assertStringIncludes(text, "writing .git/orbit/receipt.json");
  assertStringIncludes(text, '"thread": 14');
});
Deno.test("failed receipt copy fails without another Pi message", async () => {
  await using t = await nativeTurn();
  t.test.step("receipt_copy").task.inputs.src = `${t.workspace}/missing`;
  await t.test.save();
  const { history } = await t.test.run(false);
  assertEquals(result(history, "shape_result").state, "failed");
  assertEquals(t.pi.calls.filter(([path]) => path.endsWith("/messages")).length, 1);
});
Deno.test("failed review difference retains failure and restores candidate", async () => {
  await using t = await nativeTurn();
  t.pi.role = "reviewer";
  Object.assign(t.inputs.intent, { role: "reviewer", candidate: t.head });
  t.test.step("difference").task.inputs.run = "false";
  await t.test.save();
  const { run, history } = await t.test.run(false);
  assertEquals(run.status, "failed");
  assertEquals(result(history, "shape_result").state, "failed");
  assertEquals(await Deno.readTextFile(`${t.workspace}/file`), "original\n");
});
Deno.test("failed snapshot keeps its index for native resume", async () => {
  await using t = await nativeTurn();
  t.test.step("snapshot_before_add").task.inputs.argv = ["false"];
  await t.test.save();
  assertEquals((await t.test.run(false)).run.status, "failed");
  const indexes = [];
  for await (const entry of Deno.readDir(t.repo.dir)) {
    if (entry.name.startsWith("remote-")) indexes.push(...Array.from(Deno.readDirSync(`${t.repo.dir}/${entry.name}`)).map((file) => file.name));
  }
  assert(indexes.includes("before.index"));
  assertEquals(t.pi.calls, []);
  assertEquals(await t.git("diff", "--cached", "--name-only"), "");
});
Deno.test("native resume reuses the completed message send", async () => {
  await using t = await nativeTurn();
  const marker = `${t.repo.dir}/cleanup-ready`, inputs = t.test.step("delete_local_artifacts").task.inputs;
  inputs.env.ORBIT_READY = marker;
  inputs.run = 'set -e\ntest -f "$ORBIT_READY"\n' + inputs.run;
  await t.test.save();
  const { run, history } = await t.test.run(false);
  assertEquals(run.status, "failed");
  assertEquals(result(history, "shape_result").state, "done");
  await Deno.writeTextFile(marker, "");
  const resumed = JSON.parse((await t.repo.cli(["workflow", "resume", "task-fixture", "--run", run.id])).stdout);
  assertEquals(resumed.status, "succeeded");
  assertEquals(t.pi.calls.filter(([path]) => path.endsWith("/messages")).length, 1);
});
Deno.test("disconnected stream fails and interrupts once", async () => {
  await using t = await nativeTurn();
  t.pi.disconnect = true;
  const { run, history } = await t.test.run(false);
  assertEquals(run.status, "failed");
  assertEquals(result(history, "shape_result").state, "failed");
  assertEquals(t.pi.calls.filter(([path]) => path.endsWith("/interrupt")).length, 1);
});
Deno.test("no turn step copies a file to the Node", async () => {
  const flow = parse(await Deno.readTextFile(`${ROOT}/workflows/workflow-operation-task-turn.yaml`)) as { jobs: { steps: { task: { methodName?: string; inputs: { direction?: string } } }[] }[] };
  assertEquals(flow.jobs.flatMap((job) => job.steps).filter((step) => step.task.methodName === "copy" && step.task.inputs.direction !== "from"), []);
  for (const marker of ["/scripts", "python3", ".git/orbit/turn"]) assertFalse(JSON.stringify(flow).includes(marker));
});
