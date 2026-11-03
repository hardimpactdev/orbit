import { assertEquals } from "jsr:@std/assert@1.0.19";
import { nativeRepo } from "../../tests/_support/native_harness.ts";
import { localWorker } from "../../tests/_support/local_worker.ts";

Deno.test("serve reload refreshes externally indexed behaviors and workflows without a run", async () => {
  await using repo = await nativeRepo();
  repo.env.SWAMP_HOME = `${repo.dir}/home`;
  const directory = `${repo.dir}/extensions/behaviors`;
  await Deno.mkdir(directory, { recursive: true });
  const behavior = (version: string, type = "@nckrtl/reload-probe") =>
    `import {z} from "npm:zod@4.4.3";
export const behavior={type:${JSON.stringify(type)},version:${
      JSON.stringify(version)
    },globalArguments:z.object({}),resources:{},methods:{inspect:{description:"Probe",arguments:z.object({}),execute:async()=>({dataHandles:[]})}}};`;
  const workflow = async (description: string) =>
    await Deno.writeTextFile(
      `${repo.dir}/workflows/workflow-probe.yaml`,
      JSON.stringify({
        id: "f4b456ba-6e1e-4524-8e8e-b445f1d07ea4",
        name: "reload-probe",
        description,
        labels: { pool: "migration" },
        vaults: [],
        jobs: [{
          name: "probe",
          steps: [{
            name: "inspect",
            task: {
              type: "behavior",
              behaviorType: "@nckrtl/reload-probe",
              behaviorName: "reload-probe",
              methodName: "inspect",
            },
          }],
        }],
      }),
    );
  await Deno.writeTextFile(`${directory}/probe.ts`, behavior("2026.10.30.1"));
  await workflow("Before reload");
  await localWorker(repo, async (flags, { serve }) => {
    const cli = async (args: string[], remote = true) =>
      JSON.parse((await repo.cli([...args, ...remote ? flags : []])).stdout);
    const describe = ["model", "type", "describe", "@nckrtl/reload-probe"];
    assertEquals((await cli(describe)).version, "2026.10.30.1");
    await Deno.writeTextFile(`${directory}/probe.ts`, behavior("2026.10.30.2"));
    await workflow("After reload");
    await Deno.writeTextFile(`${directory}/new.ts`, behavior("2026.10.30.1", "@nckrtl/reload-added"));
    assertEquals((await cli(describe, false)).version, "2026.10.30.2");
    const response = await cli(["serve", "reload"]);
    assertEquals(response.success, true);
    assertEquals(response.errors, []);
    assertEquals((await cli(["workflow", "get", "reload-probe"])).description, "After reload");
    assertEquals((await cli(describe)).version, "2026.10.30.2");
    assertEquals((await cli(["model", "type", "describe", "@nckrtl/reload-added"])).version, "2026.10.30.1");
    assertEquals(Number(await Deno.readTextFile(`${repo.dir}/.swamp/serve.pid`)), serve.pid);
  }, { worker: false, serveArgs: ["--no-schedule", "--hot-reload"] });
});
