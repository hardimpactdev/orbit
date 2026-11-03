/** Horizon's production DAG on unmodified cfgmgmt and SSH, on the shared Process host fixture. */
import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { ROOT } from "../../../../tests/_support/native_harness.ts";
import {
  exists,
  firstJson,
  processFixture,
  type ProcessFixture,
  sha256,
} from "../process-commands/native_fixture.ts";

const Workflow = "operation-process-horizon-handoff";

async function horizon() {
  const f = await processFixture();
  const php = `${f.repo.dir}/horizon-command`;
  const terminated = `${f.repo.dir}/terminated`;
  const writePhp = (body: string) => Deno.writeTextFileSync(php, body, { mode: 0o700 });
  writePhp(`#!/bin/sh\ntouch ${terminated}\n`);
  const source = parse(await Deno.readTextFile(`${ROOT}/workflows/workflow-${Workflow}.yaml`)) as {
    jobs: { labels?: unknown; steps: { labels?: unknown }[] }[];
  };
  for (const job of source.jobs) {
    assertEquals(job.labels, { role: "swamp" });
    for (const step of job.steps) assertEquals(step.labels, { role: "swamp" });
  }
  await f.repo.workflow(Workflow, (workflow) => {
    for (const job of workflow.jobs) {
      for (const step of job.steps) {
        const task = step.task as { behaviorType?: string; globalArgs?: Record<string, unknown> };
        if (task.behaviorType === "@swamp/ssh") {
          Object.assign(task.globalArgs!, { sshBinary: `${f.tools}/ssh`, scpBinary: `${f.tools}/scp` });
        }
      }
    }
  });
  const inputs = {
    operation_id: 17,
    node_id: 9,
    kind: "process.horizon_handoff",
    execution_secrets: {},
    intent: {
      process_id: 17,
      unit: f.unitName,
      content: f.content,
      content_hash: await sha256(f.content),
      command: [php, "artisan", "horizon"],
      directory: f.repo.dir,
      user: f.user,
      created: true,
      ssh_execution: structuredClone(f.inputs.intent.ssh_execution),
    },
  };
  const handoff = async (success = true) => {
    await f.repo.cli(["workflow", "validate", Workflow]);
    const result = await f.repo.cli(["workflow", "run", Workflow, "--stdin"], { input: JSON.stringify(inputs), success });
    return firstJson(result.stdout);
  };
  const active = () => {
    Deno.writeTextFileSync(f.unit, f.content);
    f.save({ units: { [f.unitName]: { active: "active", enabled: true } }, calls: [], failures: [] });
  };
  const calls = () => f.state().calls as string[][];
  return { f, php, terminated, writePhp, handoff, active, calls };
}

const test = (name: string, body: (h: Awaited<ReturnType<typeof horizon>>) => Promise<void>) =>
  Deno.test(name, async () => {
    const h = await horizon();
    await using _f: ProcessFixture = h.f;
    await body(h);
  });

const has = (calls: string[][], call: string[]) => calls.some((c) => JSON.stringify(c) === JSON.stringify(call));

test("a missing unit is written, enabled and started", async (h) => {
  await h.handoff();
  assertEquals(Deno.readTextFileSync(h.f.unit), h.f.content);
  assert(has(h.calls(), ["daemon-reload"]));
  assert(has(h.calls(), ["start", h.f.unitName]));
  assert(!exists(h.terminated));
});

test("an active matching unit only hands off", async (h) => {
  h.active();
  await h.handoff();
  assert(exists(h.terminated));
  assert(!h.calls().some((c) => ["daemon-reload", "enable", "start", "restart", "stop"].includes(c[0])));
});

test("a changed active unit is reloaded and handed off without a restart", async (h) => {
  h.active();
  Deno.writeTextFileSync(h.f.unit, "[Unit]\nX-Orbit-Process-ID=99\n");
  await h.handoff();
  assertEquals(Deno.readTextFileSync(h.f.unit), h.f.content);
  assert(has(h.calls(), ["daemon-reload"]));
  assert(exists(h.terminated));
  assert(!h.calls().some((c) => ["start", "restart", "stop"].includes(c[0])));
});

test("a failed enable keeps the unit, and a rerun converges", async (h) => {
  h.f.save({ units: {}, calls: [], failures: ["enable"] });
  await h.handoff(false);
  assertEquals(Deno.readTextFileSync(h.f.unit), h.f.content);
  assert(!h.calls().some((c) => c[0] === "start"));
  await h.handoff();
  assertEquals(h.f.current().active, "active");
});

test("a failed termination fails the run, and a rerun hands off", async (h) => {
  h.active();
  h.writePhp("#!/bin/sh\nexit 1\n");
  await h.handoff(false);
  assert(!exists(h.terminated));
  h.writePhp(`#!/bin/sh\ntouch ${h.terminated}\n`);
  await h.handoff();
  assert(exists(h.terminated));
});
