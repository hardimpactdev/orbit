/** Exercise the workflow's actual commands using fixture modules, without Swamp. */
import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { git, run, temporary, write } from "./gate-test-support.ts";
type Step = {name: string; run: string; env: Record<string, string>};
type Shard = {name: string; path: string; shard: string; ignore?: string};
type Job = {name: string; strategy: {"fail-fast": boolean; matrix: {shard: string[]; include: Shard[]}}; steps: Step[]; needs: string[]; if: string};
const workflow = parse(await Deno.readTextFile(new URL("../../.github/workflows/swamp-shape.yml", import.meta.url))) as {concurrency: unknown; jobs: Record<string, Job>};
const jobs = workflow.jobs;
const step = (job: Job, name: string) => job.steps.find(s => s.name === name)!;
function strict(job: Job) {
  assertEquals(job.strategy["fail-fast"], false);
  assert(!("continue-on-error" in job));
}
async function shell(cwd: string, command: string, env: Record<string, string>) {
  // Bound each fixture command, including Deno's internal parallel worker socket.
  const controller = new AbortController(), timer = setTimeout(() => controller.abort(), 120_000);
  try {
    const result = await run("bash", ["-e", "-o", "pipefail", "-c", command], {cwd, env, signal: controller.signal});
    controller.signal.throwIfAborted();
    return result;
  } finally { clearTimeout(timer); }
}
function coverage(stdout: string) {
  return Array.from(stdout.matchAll(/COVERED_FILE_(\d+)/g), m => Number(m[1]));
}
Deno.test("main Swamp runs do not share the pending slot", () => {
  assertEquals(workflow.concurrency, {
    group: "swamp-shape-${{ github.workflow }}-${{ github.ref == 'refs/heads/main' && github.sha || github.ref }}",
    "cancel-in-progress": "${{ github.event_name == 'pull_request' }}",
  });
});
Deno.test("account-gated native shards cover each tracked module once", async () => {
  const job = jobs["native-tests"], command = step(job, "Run the native workflow tests");
  strict(job);
  assertEquals(command.env.NATIVE_SHARD, "${{ matrix.shard }}");
  await temporary(async root => {
    await git(root, "init", "--quiet");
    await write(root + "/tests/_support/native_harness.ts", 'console.log("unused-fixture-runtime");\n');
    const modules = root + "/extensions/behaviors/operations/fixture";
    for (let index = 0; index < 16; index++) {
      await write(modules + "/" + (index === 0 ? "native_test.ts" : `${String(index).padStart(2, "0")}_native_test.ts`), `Deno.test("module ${index}", () => console.log("COVERED_FILE_${index}"));\n`);
    }
    await git(root, "add", ".");
    for (const name of ["untracked_native_test.ts", "ordinary_test.ts"]) {
      await write(modules + "/" + name, 'Deno.test("excluded", () => { throw new Error("ran"); });\n');
    }
    const shards = job.strategy.matrix.shard;
    assertEquals(shards, ["1/4", "2/4", "3/4", "4/4"]);
    const execute = (shard: string) => shell(root, command.run, {NATIVE_SHARD: shard});
    const covered: number[] = [];
    for (const shard of shards) {
      const result = await execute(shard);
      assertEquals(result.code, 0, result.stdout + result.stderr);
      covered.push(...coverage(result.stdout));
    }
    assertEquals(covered.sort((a, b) => a - b), Array.from({length: 16}, (_, i) => i));
    await write(modules + "/native_test.ts", 'Deno.test("failure", () => { throw new Error("expected"); });\n');
    let failures = 0;
    for (const shard of shards) if ((await execute(shard)).code !== 0) failures++;
    assertEquals(failures, 1);
  });
});
Deno.test("Operations shards cover every module once and preserve failure", async () => {
  const job = jobs["test-shards"], command = step(job, "Run Operations test shard");
  strict(job);
  for (const [key, value] of Object.entries({DENO_SHARD: "shard", DENO_TEST_PATH: "path", DENO_IGNORE: "ignore"})) {
    assertEquals(command.env[key], "${{ matrix." + value + " }}");
  }
  await temporary(async root => {
    const files = root + "/extensions/behaviors/operations", groups = [".", "database", "process-commands"];
    let identifier = 0;
    for (const path of groups) for (let index = 0; index < 9; index++) {
      await write(`${files}/${path}/${String(index).padStart(2, "0")}_test.ts`, `Deno.test("module ${identifier}", () => console.log("COVERED_FILE_${identifier}"));\n`);
      identifier++;
    }
    await Deno.mkdir(root + "/bin");
    await Deno.symlink(Deno.execPath(), root + "/bin/deno");
    const execute = (shard: Shard) => shell(root, command.run, {
      PATH: root + "/bin:" + Deno.env.get("PATH"), DENO_SHARD: shard.shard, DENO_TEST_PATH: shard.path,
      DENO_IGNORE: shard.ignore ?? "", DENO_JOBS: String(command.env.DENO_JOBS),
    });
    const shards = job.strategy.matrix.include, covered: number[] = [];
    for (const shard of shards) {
      const result = await execute(shard);
      assertEquals(result.code, 0, result.stdout + result.stderr);
      covered.push(...coverage(result.stdout));
    }
    assertEquals(covered.sort((a, b) => a - b), Array.from({length: identifier}, (_, i) => i));
    for (const path of groups) for (const name of ["zz_native_test.ts", "native_test.ts"]) {
      await write(`${files}/${path}/${name}`, 'Deno.test("native", () => { throw new Error("ran"); });\n');
    }
    for (const shard of shards) {
      const result = await execute(shard);
      assertEquals(result.code, 0, result.stdout + result.stderr);
    }
    await write(files + "/00_test.ts", 'Deno.test("real failure", () => { throw new Error("expected"); });\n');
    assert((await execute(shards[0])).code !== 0);
  });
});
Deno.test("aggregate requires success from the entire matrix", async () => {
  const aggregate = jobs.tests;
  assertEquals(aggregate.name, "Swamp Deno tests");
  assertEquals(aggregate.needs, ["test-shards", "native-tests"]);
  assertEquals(aggregate.if, "always()");
  assert(!("continue-on-error" in aggregate));
  const command = aggregate.steps[0];
  assertEquals(command.env.SHARDS_RESULT, "${{ needs.test-shards.result }}");
  assertEquals(command.env.NATIVE_RESULT, "${{ needs.native-tests.result }}");
  for (const shards of ["success", "failure", "cancelled", "skipped", "", "unknown"]) {
    for (const native of ["success", "failure", "skipped", ""]) {
      const result = await shell(".", command.run, {SHARDS_RESULT: shards, NATIVE_RESULT: native});
      assertEquals(result.code === 0, shards === "success" && native === "success", JSON.stringify([shards, native]));
    }
  }
});
