import { assert, assertEquals, assertFalse } from "jsr:@std/assert@1.0.19";
import { nativeRepo } from "../../../../tests/_support/native_harness.ts";

async function fixture(state: Record<string, unknown>) {
  const repo = await nativeRepo();
  await repo.copyBehaviors("operations", "node-worker", "automatic-deploy");
  await Deno.writeTextFile(
    `${repo.dir}/extensions/behaviors/cli-fixture.ts`,
    'import {z} from "npm:zod@4.4.3";\nexport const behavior={type:"@fixture/cli-transport",version:"2026.11.02.1",globalArguments:z.object({nodeHost:z.string(),path:z.string()}).partial(),resources:{output:{schema:z.object({stdout:z.string()}),lifetime:"1d",garbageCollection:10},state:{schema:z.record(z.string(),z.unknown()),lifetime:"1d",garbageCollection:10}},methods:{\nexec:{description:"Harmless SSH boundary",arguments:z.object({argv:z.array(z.string())}).passthrough(),execute:async(args,ctx)=>{const data=JSON.parse(Deno.readTextFileSync("fixture.json"));const candidate=args.argv.includes("/usr/local/bin/orbit-0.12.0");Deno.writeTextFileSync("calls.jsonl",JSON.stringify(candidate?"candidate":"read")+"\\n",{append:true,create:true});if(!candidate&&!data.current)throw new Error("CLI missing");if(!candidate&&args.argv.includes("self-update")&&data.stale)throw new Error("self-update missing");const result={stdout:candidate?"Orbit "+(data.bad?"0.11.0":"0.12.0")+"\\n":"Orbit "+data.current+"\\n"};await ctx.writeResource("output","output",result);return result;}},\napply:{description:"Harmless resource boundary",arguments:z.object({}),execute:async(_,ctx)=>{Deno.writeTextFileSync("calls.jsonl",JSON.stringify(ctx.globalArgs.path)+"\\n",{append:true,create:true});if(ctx.globalArgs.path==="/usr/local/bin/orbit-0.12.0"&&JSON.parse(Deno.readTextFileSync("fixture.json")).checksum)throw new Error("Checksum mismatch");return {dataHandles:[await ctx.writeResource("state",ctx.globalArgs.nodeHost,{status:"compliant",changes:[]})]};}}\n}};',
  );
  await repo.workflow("node-cli-install", (flow) => {
    for (const step of flow.jobs.flatMap((j) => j.steps)) {
      const task = step.task as {
        behaviorType?: string;
        globalArgs?: Record<string, unknown>;
        inputs: Record<string, unknown>;
      };
      if (task.behaviorType === "@swamp/ssh") {
        task.behaviorType = "@fixture/cli-transport";
        delete task.globalArgs;
        task.inputs = { argv: task.inputs.argv };
      } else if (task.behaviorType?.startsWith("@swamp/cfgmgmt/")) {
        task.behaviorType = "@fixture/cli-transport";
        task.globalArgs = Object.fromEntries(
          Object.entries(task.globalArgs!).filter(([k]) => ["nodeHost", "path"].includes(k)),
        );
      }
    }
  });
  await Deno.writeTextFile(`${repo.dir}/fixture.json`, JSON.stringify(state));
  await repo.cli(["workflow", "validate", "node-cli-install"]);
  return repo;
}
const inputs = {
  "node_id": 9,
  "intent": {
    "ssh": {
      "target": { "host": "fixture", "user": "orbit", "port": 22, "host_keys": ["synthetic"] },
      "identity_file": "/synthetic",
    },
    "cli": {
      "user": "orbit",
      "profile": "public profile",
      "release": {
        "version": "0.12.0",
        "url": "https://delivery.test/cli",
        "sha256": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
      },
    },
  },
};
for (
  const [name, state] of Object.entries({
    missing: { current: null },
    newer: { current: "0.13.0" },
    invalid: { current: null, bad: true },
    checksum: { current: null, checksum: true },
    foreign: { current: "9.9.9-foreign" },
    commit: { current: "a".repeat(40) },
  })
) {
  Deno.test(`CLI install: ${name}`, async () => {
    await using repo = await fixture(state);
    const { history } = await repo.runWorkflow("node-cli-install", inputs, {
      success: !["invalid", "checksum"].includes(name),
    });
    const calls = (await Deno.readTextFile(`${repo.dir}/calls.jsonl`)).trim().split("\n").map((s) => JSON.parse(s));
    const result = (history.jobs.flatMap((j) => j.steps) as { name: string; outputs?: Record<string, unknown> }[]).find(
      (s) => s.name === "result",
    )?.outputs;
    if (["missing", "foreign"].includes(name)) {
      assertEquals(result, { installed: "0.12.0", configured: false, version: "0.12.0" });
      assert(calls.indexOf("/usr/local/bin/orbit-0.12.0") < calls.indexOf("candidate"));
      assert(calls.indexOf("candidate") < calls.indexOf("/usr/local/bin/orbit"));
      assert(calls.includes("/root/.orbit/config.json"));
    } else if (["newer", "commit"].includes(name)) {
      assertEquals(result?.installed, null);
      assertEquals(result?.version, state.current);
      assertFalse(calls.includes("/usr/local/bin/orbit-0.12.0"));
      if (name === "newer") assert(calls.includes("/root/.orbit/config.json"));
    } else {
      assertFalse(calls.includes("/usr/local/bin/orbit"));
      if (name === "invalid") assertFalse(calls.includes("/root/.orbit/config.json"));
      else assertFalse(calls.includes("candidate"));
    }
  });
}
