import { assertEquals, assertFalse, assertStringIncludes } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { nativeRepo, ROOT } from "../../tests/_support/native_harness.ts";
import { type Json, rewrite } from "../../tests/_support/platform_fixture.ts";
import { readCalls } from "../../tests/_support/fake_tool.ts";

const tool = new URL("../../tests/_support/fake_tool.ts", import.meta.url).href;
Deno.test("official SSH canary passes script and comparison stdin and always cleans up", async () => {
  await using repo = await nativeRepo();
  repo.timeoutMs = 120_000;
  await repo.pull("@swamp/ssh");
  const key = `${repo.dir}/key`, calls = `${repo.dir}/calls`, fail = `${repo.dir}/fail-read`;
  await Deno.writeTextFile(key, "synthetic fixture identity, never used by SSH", { mode: 0o600 });
  const ssh = await repo.fakeBinary(
    "ssh",
    `
import {shellSplit, logCall, readStdinBytes, passThrough} from ${JSON.stringify(tool)};
const body = await readStdinBytes(), args = Deno.args;
logCall(${JSON.stringify(calls)}, {argv:args, stdin:new TextDecoder().decode(body)});
let argv = shellSplit(args.at(-1));
if (argv.slice(0,3).join(" ") === "sudo -n --") argv = argv.slice(3);
if (argv[0] === "/usr/bin/cmp") {
  try { Deno.statSync(${JSON.stringify(fail)}); Deno.exit(1); } catch { /* No injected failure. */ }
}
if (argv[0] === "/usr/bin/hostname") { console.log(args.includes("orbit@10.44.0.1") ? "vpn1" : "usexit18"); Deno.exit(0); }
await passThrough(argv, body);
`,
  );
  const production = parse(await Deno.readTextFile(`${ROOT}/workflows/workflow-node-proxy-canary.yaml`)) as Json;
  for (const job of production.jobs) {
    assertEquals(job.labels, { role: "swamp" });
    for (const step of job.steps) {
      assertEquals(step.labels, { role: "swamp" });
      assertEquals(step.task.behaviorType, "@swamp/ssh");
      assertFalse("sshBinary" in step.task.globalArgs);
      assertEquals(step.task.globalArgs.transport.identityFile, "/etc/orbit/swamp-role-worker/ssh-identity");
    }
  }
  const workflow = rewrite(production, "/run/orbit-official-ssh-canary-", `${repo.dir}/canary-`);
  for (const job of workflow.jobs) {
    delete job.labels;
    for (const step of job.steps) {
      delete step.labels;
      step.task.globalArgs.sshBinary = ssh;
      step.task.globalArgs.transport.identityFile = key;
    }
  }
  await Deno.writeTextFile(`${repo.dir}/workflows/workflow-node-proxy-canary.yaml`, JSON.stringify(workflow));
  await repo.cli(["workflow", "validate", "node-proxy-canary"]);
  const cleaned = async () =>
    assertEquals((await Array.fromAsync(Deno.readDir(repo.dir))).filter((e) => e.name.startsWith("canary-")), []);
  for (const node_id of [1, 18]) {
    for (const effect of [false, true]) {
      const { run, history } = await repo.runWorkflow("node-proxy-canary", { node_id, effect });
      assertEquals(run.status, "succeeded");
      const steps = history.jobs[0].steps;
      assertEquals(steps[0].status, "succeeded");
      for (const step of steps.slice(1)) assertEquals(step.status, effect ? "succeeded" : "skipped");
    }
  }
  await cleaned();
  await Deno.writeTextFile(fail, "");
  const { run, steps } = await repo.runWorkflow("node-proxy-canary", { node_id: 1, effect: true }, { success: false });
  assertEquals(run.status, "failed");
  assertEquals(steps.read, "failed");
  assertEquals(steps["remove-file"], "succeeded");
  assertEquals(steps["remove-directory"], "succeeded");
  await cleaned();
  const records = readCalls<{ argv: string[]; stdin: string }>(calls);
  const scripts = records.filter((row) =>
    row.stdin.includes("orbit-official-ssh-canary") && row.stdin.includes("printf")
  );
  assertEquals(scripts.length, 3);
  for (const row of scripts) {
    assertFalse(row.argv.join(" ").includes("orbit-official-ssh-canary"));
    assertStringIncludes(row.argv.at(-1)!, "sh -s --");
  }
  assertEquals(
    records.filter((row) => row.argv.at(-1)!.includes("/usr/bin/cmp")).map((row) => row.stdin),
    Array(3).fill("orbit-official-ssh-canary\n"),
  );
});
