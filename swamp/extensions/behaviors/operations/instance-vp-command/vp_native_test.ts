import { assertEquals } from "jsr:@std/assert@1.0.19";
import { stringify } from "jsr:@std/yaml@1.0.12";
import { readCalls } from "../../../../tests/_support/fake_tool.ts";
import { type Data, instanceFixture, TOOL } from "../../../../tests/_support/instance_fixture.ts";
import { vpPreparationJobs } from "./steps.ts";
async function fixture() {
  const f = await instanceFixture(
    "operations/instance-vp-command", "operations/instance-deploy", "operations/tools", "operations/node-bootstrap",
  );
  const application = `${f.node}/app`, scope = `${f.node}/vp-store`, calls = `${f.node}/vp-calls`;
  await Deno.mkdir(application);
  await Deno.writeTextFile(`${application}/package.json`, "{}");
  await Deno.mkdir(`${scope}/bin`, { recursive: true });
  const vp = `${scope}/bin/vp`;
  const manager = async (version = "1.4.0") => {
    await Deno.writeTextFile(
      vp,
      `#!/usr/bin/env -S ${Deno.execPath()} run -A --quiet --no-config --no-lock\n` + `
import { logCall } from ${JSON.stringify(TOOL)};
logCall(${JSON.stringify(calls)}, Deno.args);
if (Deno.args[0] === "--version") console.log("vp ${version}");
else if (Deno.args.includes("list")) console.log(JSON.stringify({ node: [{ version: "24.2.0" }], package_managers: { pnpm: [{ version: "10.0.0" }] } }));
`,
      { mode: 0o700 },
    );
  };
  await manager();
  const requirements = `${f.repo.dir}/extensions/behaviors/operations/instance-vp-command/requirements.ts`;
  await Deno.writeTextFile(
    requirements,
    (await Deno.readTextFile(requirements)).replaceAll('"/opt/orbit/vite-plus"', JSON.stringify(scope)),
  );
  const jobs = JSON.parse(
    JSON.stringify(vpPreparationJobs("vp", "inputs.intent", "before"))
      .replaceAll("/opt/orbit/vite-plus", scope).replaceAll('"/opt/orbit"', JSON.stringify(`${f.node}/orbit`))
      .replaceAll('"/opt"', JSON.stringify(f.node)),
  );
  for (const job of jobs) {
    delete job.labels;
    delete job.target;
    for (const step of job.steps) {
      delete step.labels;
      delete step.target;
      if (step.task.behaviorType === "@swamp/ssh") {
        Object.assign(step.task.globalArgs, { sshBinary: f.ssh, scpBinary: f.scp });
      }
    }
  }
  const workflow = {
    id: "97d29bb9-9313-4e16-95fb-f4db2850b3fb", name: "instance-vp-fixture", version: 1,
    inputs: { intent: { type: "object" }, operation_id: { type: "integer" }, node_id: { type: "integer" } },
    jobs: [{
      name: "before",
      steps: [{ name: "ready", task: { type: "assert", expr: "true", message: "Fixture ready" } }],
    }, ...jobs],
  };
  await Deno.writeTextFile(`${f.repo.dir}/workflows/workflow-vp.yaml`, stringify(workflow));
  // The current graph captures the admitted manager owner even when its setup fan-out is empty.
  const inputs: Data = {
    operation_id: 71, node_id: 9,
    intent: { application, vp_setup: { input: { user: f.user } }, ssh_execution: f.connection },
  };
  const pins = async () => {
    await Deno.writeTextFile(
      `${application}/package.json`,
      JSON.stringify({ devDependencies: { "vite-plus": "0.2.1" }, packageManager: "pnpm@10.0.0" }),
    );
    await Deno.writeTextFile(`${application}/.node-version`, "24.2.0");
    await Deno.writeTextFile(
      `${application}/pnpm-lock.yaml`,
      "importers:\n  .:\n    devDependencies:\n      vite-plus:\n        version: 0.2.1\n",
    );
  };
  const locked = `${f.node}/locked`;
  await Deno.mkdir(`${locked}/inner`, { recursive: true });
  const run = async (success = true) => {
    await Deno.chmod(locked, 0);
    try {
      return await f.run("instance-vp-fixture", inputs, success);
    } finally {
      await Deno.chmod(locked, 0o700);
    }
  };
  return { f, calls, manager, pins, run };
}
Deno.test("existing VP manager uses stock install then read-only inventory", async () => {
  const { f, calls, pins, run } = await fixture();
  await using _ = f;
  await pins();
  await run();
  const rows = readCalls<string[]>(calls);
  assertEquals(rows[0], ["--version"]);
  assertEquals(rows[1].slice(-4), ["env", "install", "node@24.2.0", "pnpm@10.0.0"]);
  assertEquals(rows[2].slice(-3), ["env", "list", "--json"]);
});
