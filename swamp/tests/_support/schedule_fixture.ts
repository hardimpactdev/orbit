import { nativeRepo, ROOT } from "./native_harness.ts";

export async function scheduleFixture(control = false) {
  const repo = await nativeRepo();
  for (
    const [folder, names] of Object.entries({
      "schedule-run": ["assets.ts"],
      "processes-schedules": ["contract.ts", "unit-templates.json"],
    })
  ) {
    const target = `${repo.dir}/extensions/behaviors/operations/${folder}`;
    await Deno.mkdir(target, { recursive: true });
    for (const name of names) {
      await Deno.copyFile(`${ROOT}/extensions/behaviors/operations/${folder}/${name}`, `${target}/${name}`);
    }
  }
  await Deno.writeTextFile(
    `${repo.dir}/extensions/behaviors/operations/resource_fixture.ts`,
    `import {z} from "npm:zod@4.4.3";
export const behavior={type:"@fixture/schedule-resource",version:"2026.11.03.1",globalArguments:z.record(z.string(),z.unknown()),resources:{state:{schema:z.object({status:z.string()}),lifetime:"1d",garbageCollection:10}},methods:Object.fromEntries(["apply","check"].map(method=>[method,{description:"Fixture resource boundary",arguments:z.object({}),execute:async(_,ctx)=>{const drift=Deno.env.get("ORBIT_SCHEDULE_DRIFT");const bad=method==="check"&&((drift==="file"&&ctx.globalArgs.path)||(drift==="timer"&&ctx.globalArgs.service));return {dataHandles:[await ctx.writeResource("state","state",{status:bad?"non_compliant":method==="check"?"compliant":"applied"})]};}}]))};`,
  );
  const name = `operation-schedule-${control ? "control" : "create"}`;
  await repo.workflow(name, (flow) => {
    for (const job of flow.jobs) {
      for (const step of job.steps) {
        const task = step.task as { behaviorType?: string };
        if (task.behaviorType?.startsWith("@swamp/cfgmgmt/")) task.behaviorType = "@fixture/schedule-resource";
      }
    }
  });
  const schedule = {
    id: "54df47ef-1847-4798-8c56-47fa09df8b57",
    user: "orbit",
    group: "orbit",
    home: "/home/orbit",
    working_directory: "/home/orbit",
    shell: "/bin/bash",
    login_shell: false,
    command_hash: "a".repeat(64),
    calendar: "daily",
    timeout_seconds: 60,
    desired: "disabled",
  };
  const inputs = {
    operation_id: 1,
    node_id: 211,
    kind: control ? "schedule.enable" : "schedule.create",
    execution_secrets: {},
    intent: {
      ...(control ? {} : { cascade: false }),
      schedule: control ? { id: schedule.id } : schedule,
      ssh_execution: { identity_file: "/public/key", target: { host: "192.0.2.3", user: "orbit", port: 22 } },
    },
  };
  const run = async (drift = "") => {
    repo.env.ORBIT_SCHEDULE_DRIFT = drift;
    await repo.cli(["workflow", "validate", name]);
    return await repo.runWorkflow(name, inputs, { success: !drift });
  };
  return { repo, run };
}
