import { assert, assertEquals, assertFalse, assertStringIncludes } from "jsr:@std/assert@1.0.19";
import { maintenance, releases } from "../../../../tests/_support/task_maintenance.ts";
import { outputs, result, taskRemote } from "../../../../tests/_support/task_remote.ts";

Deno.test("routine maintenance composes workspace and updates once over official SSH", async () => {
  await using t = await maintenance();
  const { run, history } = await t.run();
  assertEquals(run.status, "succeeded");
  assertEquals(await Deno.readTextFile(`${t.workspace}/.git/updates`), "1");
  assertFalse(result(history).changed);
  assertEquals(result(history).group_id, 1);
});

Deno.test("failed update preserves native failure without a success receipt", async () => {
  await using t = await maintenance();
  await t.repo.fakeBinary("composer", `if(Deno.args[0]==="audit") console.log('{"advisories":{}}'); else Deno.exit(7);`);
  const { run, steps } = await t.run(false);
  assertEquals(run.status, "failed");
  assertEquals(steps.shape, "skipped");
  assertEquals(steps["Check the result"], "skipped");
  const child = await t.repo.history("task-maintenance-path");
  assertEquals(child.steps.update, "failed");
  assertEquals(child.steps["Check the path"], "skipped");
});

Deno.test("task VM receives plain argv without a shipped program", async () => {
  await using t = await maintenance();
  assertEquals((await t.run()).run.status, "succeeded");
  const calls = t.calls(), contains = (argv: string[]) => calls.some((call) => JSON.stringify(call) === JSON.stringify(argv));
  assert(contains(["cat", "--", `${t.workspace}/composer.json`]));
  assert(contains(["test", "-d", `${t.workspace}/node_modules`]));
  assert(contains(["env", "-C", t.workspace, "composer", "update", "--no-interaction"]));
  const start = calls.findIndex((call) => call[0] === "cat" && call.at(-1) === `${t.workspace}/composer.json`);
  for (const call of calls.slice(start)) {
    assert(["cat", "test", "env", "tee", "git"].includes(call[0]));
    assertFalse(call.some((part) => part.includes("python3")));
  }
});

Deno.test("committed and untracked repairs count against provision base", async () => {
  await using t = await maintenance();
  await Deno.writeTextFile(`${t.workspace}/repair.txt`, "repair before maintenance");
  await t.git("add", ".");
  await t.git("commit", "-qm", "repair");
  await Deno.writeTextFile(`${t.workspace}/untracked.txt`, "untracked repair");
  const { run, history } = await t.run();
  assertEquals(run.status, "succeeded");
  assert(result(history).changed);
});

for (const manager of ["composer", "js"]) Deno.test(`package ${manager} solve preserves the new range and exact lock`, async () => {
  await using t = await maintenance();
  await releases(t);
  const composer = manager === "composer";
  const manifest = composer ? "composer.json" : "package.json", lock = composer ? "composer.lock" : "package-lock.json";
  await Deno.writeTextFile(`${t.workspace}/${manifest}`, JSON.stringify(composer ? { require: { "acme/lib": "^1.0.0" } } : { packageManager: "npm@11", dependencies: { lib: "^1.0.0" } }));
  await Deno.writeTextFile(`${t.workspace}/${lock}`, JSON.stringify(composer ? { packages: [{ name: "acme/lib", version: "1.0.0" }] } : { packages: { "node_modules/lib": { version: "1.0.0" } } }));
  await t.git("add", ".");
  await t.git("commit", "-qm", "package");
  Object.assign(t.inputs.intent.maintenance, {
    mode: "package", targets: [{ path: ".", manager, package: composer ? "acme/lib" : "lib", version: "2.0.0", purpose: "major", advisories: [], advisoryUrls: [] }],
  });
  if (!composer) Object.assign(t.inputs.intent.maintenance.project.worker, { composerPaths: [], jsPaths: ["."] });
  await t.repo.fakeBinary(composer ? "composer" : "npm", composer ? `
if(Deno.args[0]==="audit") console.log('{"advisories":{}}');
else if(Deno.args[0]==="update") Deno.writeTextFileSync("composer.lock",JSON.stringify({packages:[{name:"acme/lib",version:"2.0.0"}]}));
else Deno.exit(1);
` : `
if(Deno.args[0]==="audit") console.log('{"vulnerabilities":{}}');
else if(Deno.args[0]==="install") {
 const version=JSON.parse(Deno.readTextFileSync("package.json")).dependencies.lib.replace(/^[~^]/,"");
 Deno.writeTextFileSync("package-lock.json",JSON.stringify({packages:{"node_modules/lib":{version}}}));
} else Deno.exit(1);
`);
  assertEquals((await t.run()).run.status, "succeeded");
  const declared = JSON.parse(await Deno.readTextFile(`${t.workspace}/${manifest}`));
  const solved = JSON.parse(await Deno.readTextFile(`${t.workspace}/${lock}`));
  assertEquals(composer ? declared.require["acme/lib"] : declared.dependencies.lib, "^2.0.0");
  assertEquals(composer ? solved.packages[0].version : solved.packages["node_modules/lib"].version, "2.0.0");
});

const shebang = `#!/usr/bin/env -S ${Deno.execPath()} run -A --quiet --no-config --no-lock\n`;
Deno.test("repository script output is redacted on the worker before history", async () => {
  await using t = await taskRemote();
  t.repo.secrets.push("ghp_" + "S".repeat(36), "a1".repeat(32), ["synthetic-script-", "password"].join(""));
  await t.install(shebang + `console.log("ghp_"+"S".repeat(36));console.log("a1".repeat(32));console.error("DB_PASSWORD=synthetic-"+"script-password");console.log("visible");\n`);
  Object.assign(t.inputs.intent, { script: "scripts/run", args: [] });
  await t.workflow("script");
  const { run, history } = await t.run();
  assertEquals(run.status, "succeeded");
  assertEquals(outputs(history, "execute").stdout, "");
  const value = result(history);
  assertEquals(value.exit_code, 0);
  assertStringIncludes(value.output, "visible");
  assertStringIncludes(value.output, "DB_PASSWORD=[REDACTED]");
  assertEquals(value.output.match(/\[REDACTED\]/g)?.length, 3);
  for (const suffix of ["", "-local"]) assertFalse(await Deno.stat(`${t.repo.dir}/assets-${run.id}${suffix}`).then(() => true, () => false));
});

Deno.test("untracked or nonexecutable repository scripts refuse without running", async () => {
  await using t = await taskRemote();
  await t.install(shebang + 'Deno.writeTextFileSync("invoked","");\n');
  await t.git("update-index", "--chmod=-x", "scripts/run");
  await t.git("commit", "-qm", "not executable");
  Object.assign(t.inputs.intent, { script: "scripts/run", args: [] });
  await t.workflow("script");
  const { history, steps } = await t.run(false);
  assertEquals(result(history), { error_code: "tasks.script_unavailable" });
  assertEquals(steps.execute, "skipped");
  assertFalse(await Deno.stat(`${t.workspace}/invoked`).then(() => true, () => false));
});

Deno.test("native resume after cleanup failure never reexecutes the program", async () => {
  await using t = await taskRemote();
  await t.install(shebang + 'const p=".git/count";let n=0;try{n=Number(Deno.readTextFileSync(p));}catch{}Deno.writeTextFileSync(p,String(n+1));\n');
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
  assertEquals((await t.repo.history(run.id)).history.status, "succeeded");
  assertEquals(await Deno.readTextFile(`${t.workspace}/.git/count`), "1");
});

Deno.test("analyzer stderr and private reports never reach native history", async () => {
  await using t = await taskRemote();
  t.repo.secrets.push(["synthetic-analysis-", "stderr-value"].join(""), ["synthetic-analysis-", "report-value"].join(""));
  await t.install(shebang + 'console.error("SECRET=synthetic-analysis-"+"stderr-value");console.log(JSON.stringify({API_KEY:"synthetic-analysis-"+"report-value"}));\n', "bin/task-analyze");
  await t.analysisIntent();
  await t.workflow("analyze");
  const { history } = await t.run(false);
  assertEquals(outputs(history, "execute").stdout, "");
  assertEquals(result(history), { error_code: "tasks.analysis_report_invalid" });
});

Deno.test("moved candidate refuses as stale without running the analyzer", async () => {
  await using t = await taskRemote();
  await t.install(shebang + 'Deno.writeTextFileSync("invoked","");\n', "bin/task-analyze");
  await t.analysisIntent();
  t.inputs.intent.tree = "f".repeat(40);
  await t.workflow("analyze");
  const { history, steps } = await t.run(false);
  assertEquals(result(history), { error_code: "tasks.analysis_stale" });
  assertEquals(steps.execute, "skipped");
  assertFalse(await Deno.stat(`${t.workspace}/invoked`).then(() => true, () => false));
});

Deno.test("private analysis is refused in the terminal result", async () => {
  await using t = await taskRemote();
  await t.install(shebang + 'console.log(JSON.stringify({API_KEY:"synthetic-private-"+"report-value"}));\n', "bin/task-analyze");
  await t.analysisIntent();
  await t.workflow("analyze");
  const { run, history, steps } = await t.run(false);
  assertEquals(run.status, "failed");
  assertFalse("result" in steps);
  assertFalse(JSON.stringify(outputs(history, "shape")).includes(["synthetic-private-", "report-value"].join("")));
  assertEquals(steps.outcome, "failed");
  assertEquals(result(history), { error_code: "tasks.analysis_report_invalid" });
});
