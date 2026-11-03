import { assert, assertEquals, assertMatch } from "jsr:@std/assert@1.0.19";
import { decode, exists, repository, sha256, text, write } from "../../../../tests/_support/task_repository.ts";

Deno.test("script returns business failure and preserves literal arguments and the real index", async () => {
  await using t = await repository();
  await t.install(
    'await Deno.writeTextFile("args",JSON.stringify(Deno.args));console.log("done");Deno.exit(23);',
  );
  Object.assign(t.inputs.intent, {
    script: "scripts/run",
    args: ["$(touch never)", " spaces ", ""],
  });
  const index = await Deno.readFile(`${t.workspace}/.git/index`);
  const { run, steps, result } = await t.script("script");
  assertEquals(run.status, "succeeded");
  assertEquals(
    JSON.parse(await text(`${t.workspace}/args`)),
    t.inputs.intent.args,
  );
  assert(!await exists(`${t.workspace}/never`));
  assertEquals(await Deno.readFile(`${t.workspace}/.git/index`), index);
  assertEquals(steps["cleanup-index"].status, "succeeded");
  assert(!("result" in steps));
  assertEquals([
    result.outcome,
    result.exit_code,
    result.output,
    result.output_truncated,
    result.changed,
  ], ["failed", 23, "done\n", false, true]);
});
Deno.test("script output is redacted before native history and temporary output is removed", async () => {
  await using t = await repository();
  t.repo.secrets = [
    "ghp_" + "S".repeat(36),
    "a1".repeat(32),
    "synthetic-script-" + "password",
  ];
  await t.install(
    'console.log("ghp_"+"S".repeat(36));console.log("a1".repeat(32));console.error("DB_PASSWORD=synthetic-"+"script-password");console.log("visible");',
  );
  Object.assign(t.inputs.intent, { script: "scripts/run", args: [] });
  const { run, steps, result } = await t.script("script");
  assertEquals(run.status, "succeeded");
  assertEquals(steps.execute.outputs.stdout, "");
  assertEquals(result.exit_code, 0);
  assert(result.output.includes("visible"));
  assert(result.output.includes("DB_PASSWORD=[REDACTED]"));
  assertEquals(result.output.split("[REDACTED]").length - 1, 3);
  for (const suffix of ["", "-local"]) {
    assert(!await exists(`/tmp/orbit-task-repository-${run.id}${suffix}`));
  }
});
Deno.test("a non executable tracked script refuses before execution", async () => {
  await using t = await repository();
  await t.install('await Deno.writeTextFile("invoked","");');
  await t.git("update-index", "--chmod=-x", "scripts/run");
  await t.git("commit", "-qm", "not executable");
  Object.assign(t.inputs.intent, { script: "scripts/run", args: [] });
  const { steps, result } = await t.script("script", false);
  assertEquals(result, { error_code: "tasks.script_unavailable" });
  assertEquals(steps.execute.status, "skipped");
  assert(!await exists(`${t.workspace}/invoked`));
});
Deno.test("resume after cleanup failure never executes the program again", async () => {
  await using t = await repository();
  await t.install(
    'const p=".git/count";await Deno.writeTextFile(p,String(Number(await Deno.readTextFile(p).catch(()=>"0"))+1));',
  );
  Object.assign(t.inputs.intent, { script: "scripts/run", args: [] });
  const marker = `${t.repo.dir}/cleanup-ready`;
  const { run } = await t.script("script", false, (flow) => {
    const cleanup = flow.jobs[0].steps.find((s: { name: string }) => s.name === "cleanup-local");
    cleanup.allowFailure = false;
    cleanup.task.inputs.env.ORBIT_TEST_READY = marker;
    cleanup.task.inputs.run = 'set -e\ntest -f "$ORBIT_TEST_READY"\n' +
      cleanup.task.inputs.run;
  });
  await write(marker, "");
  const resumed = decode(
    (await t.repo.cli([
      "workflow",
      "resume",
      "operation-task-script",
      "--run",
      run.id,
    ])).stdout,
  );
  assertEquals(resumed.status, "succeeded");
  assertEquals(await text(`${t.workspace}/.git/count`), "1");
});
Deno.test("analysis retains the exact public report and provenance, including tracked ignored files", async () => {
  await using t = await repository();
  await t.install(
    'const v=JSON.parse(await new Response(Deno.stdin.readable).text());const r=Object.fromEntries(["execution_id","plan_sha256","base","head","tree"].map(k=>[k,v[k]]));console.log(JSON.stringify({...r,schema:1,status:"ready",diagnostics:[],report:{schema:"test.v1",data:{}}}));',
    "bin/task-analyze",
  );
  await write(`${t.workspace}/.gitignore`, "generated.txt\n");
  await write(`${t.workspace}/generated.txt`, "tracked\n");
  await t.git("add", "-f", ".gitignore", "generated.txt");
  await t.git("commit", "-qm", "tracked ignored file");
  await t.analysisIntent();
  const { run, steps, result } = await t.script("analyze");
  assertEquals(run.status, "succeeded");
  assert(!("result" in steps));
  assertEquals(result.outcome, "ready");
  const p = result.provenance;
  assertEquals(
    Object.keys(p).sort(),
    [
      "operation_id",
      "group",
      "subtask",
      "repository",
      "branch",
      "instance_id",
      "node_id",
      "plan_id",
      "plan_revision",
      "plan_sha256",
      "base",
      "head",
      "tree",
      "before",
      "after",
      "execution_id",
      "entrypoint_sha256",
      "started_at",
      "finished_at",
      "duration_ms",
      "exit_code",
      "mechanical_llm_calls",
    ].sort(),
  );
  assertEquals([p.node_id, p.operation_id], [3, 91001]);
  assertEquals(p.before, {
    head: t.inputs.intent.head,
    tree: t.inputs.intent.tree,
  });
  assertEquals(p.after, p.before);
  assertEquals(
    p.entrypoint_sha256,
    await sha256(await Deno.readFile(`${t.workspace}/bin/task-analyze`)),
  );
  assertEquals(
    p.execution_id,
    (await sha256("operation-91001")).slice(0, 32),
  );
  assertMatch(p.started_at, /^\d{4}-\d\d-\d\dT.*Z$/);
  assert(Number.isInteger(p.duration_ms));
  assertEquals(result.artifact.sha256, await sha256(result.artifact.content));
  assertEquals(
    result.artifact.bytes,
    new TextEncoder().encode(result.artifact.content).length,
  );
});
Deno.test("analyzer stderr and a private report never reach native history", async () => {
  await using t = await repository();
  t.repo.secrets = [
    "synthetic-analysis-" + "stderr-value",
    "synthetic-analysis-" + "report-value",
  ];
  await t.install(
    'console.error("SECRET=synthetic-analysis-"+"stderr-value");console.log(JSON.stringify({API_KEY:"synthetic-analysis-"+"report-value"}));',
    "bin/task-analyze",
  );
  await t.analysisIntent();
  const { steps, result } = await t.script("analyze", false);
  assertEquals(steps.execute.outputs.stdout, "");
  assertEquals(result, { error_code: "tasks.analysis_report_invalid" });
});
Deno.test("a moved candidate refuses as stale before running the analyzer", async () => {
  await using t = await repository();
  await t.install(
    'await Deno.writeTextFile("invoked","");',
    "bin/task-analyze",
  );
  await t.analysisIntent();
  t.inputs.intent.tree = "f".repeat(40);
  const { steps, result } = await t.script("analyze", false);
  assertEquals(result, { error_code: "tasks.analysis_stale" });
  assertEquals(steps.execute.status, "skipped");
  assert(!await exists(`${t.workspace}/invoked`));
});
Deno.test("private analysis is refused in the terminal result", async () => {
  await using t = await repository();
  const secret = "synthetic-private-" + "report-value";
  t.repo.secrets = [secret];
  await t.install(
    'console.log(JSON.stringify({API_KEY:"synthetic-private-"+"report-value"}));',
    "bin/task-analyze",
  );
  await t.analysisIntent();
  const { run, steps, result } = await t.script("analyze", false);
  assertEquals(run.status, "failed");
  assert(!("result" in steps));
  assert(!JSON.stringify(steps.shape).includes(secret));
  assertEquals(steps.outcome.status, "failed");
  assertEquals(result, { error_code: "tasks.analysis_report_invalid" });
});
