import { assertFalse } from "jsr:@std/assert@1.0.19";
import { nativeRepo } from "./native_harness.ts";
import { readCalls } from "./fake_tool.ts";
const TOOL = new URL("./fake_tool.ts", import.meta.url).href;
export const SSH = {
  identity_file: "/fixture",
  target: {
    host: "node.invalid",
    port: 22,
    user: "orbit",
    host_keys: ["ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG"],
  },
};
export const repository = {
  name: "vendor",
  uris: ["https://packages.example.test/apt"],
  suites: ["stable"],
  components: ["main"],
  signing_key: { url: "https://packages.example.test/key.asc", fingerprint: "A".repeat(40) },
};
export async function toolFixture() {
  const repo = await nativeRepo();
  repo.timeoutMs = 120_000;
  await repo.copyBehaviors("operations/tools", "operations/node-bootstrap");
  await repo.pull("@swamp/ssh");
  const state = `${repo.dir}/state.json`, audit = `${repo.dir}/audit.jsonl`;
  const ssh = await repo.fakeBinary(
    "fixture-ssh",
    `import {shellSplit,logCall} from ${JSON.stringify(TOOL)};
if (Deno.args.includes("-M") || Deno.args.includes("-O")) Deno.exit(0);
const path=${JSON.stringify(state)},s=JSON.parse(Deno.readTextFileSync(path)),a=shellSplit(Deno.args.at(-1)!);
logCall(${JSON.stringify(audit)},a);let code=0,changed=false;
if(a.includes("--version") && s.manager_missing) code=127;
else if(a.includes("getent")){console.log("orbit:x:1000:1000::/home/orbit:/bin/bash");}
else if(a.some(p=>p.endsWith("/bin/brew"))) {
 if(a.includes("info")) {const key="a".repeat(64);console.log(JSON.stringify({formulae:[{name:"hello",full_name:"hello",tap:"homebrew/core",disabled:false,installed:s.installed?[{version:s.installed,installed_on_request:true}]:[],versions:{stable:"2.0",bottle:true},bottle:{stable:{files:{x86_64_linux:{sha256:key,url:"https://ghcr.io/v2/homebrew/core/hello/sha256:"+key}}}}}]}));}
 else if(a.includes("uninstall")) {s.installed=null;changed=true;}
 else if(a.includes("install")||a.includes("upgrade")){s.installed="2.0";changed=true;}
 else if(a.includes("--version"))console.log("Homebrew 4.0");
} else if(a.includes("/usr/bin/dpkg-query")) {
 if(s.installed)console.log("hello\\tinstall ok installed\\t"+s.installed);else {console.error("dpkg-query: no packages found matching hello");code=1;}
} else if(a.includes("/usr/bin/apt-cache")) {if(!s.requires_refresh||s.refreshed)console.log("hello:\\n  Candidate: "+s.candidate);}
else if(a.includes("/usr/bin/apt-get")&&a.includes("update")){s.refreshed=true;changed=true;}
else if(a.includes("--simulate"))console.log("Remv hello [1.0]\\n"+(s.unsafe?"Remv other [1.0]\\n":""));
else if(a.includes("install")){changed=true;if(s.fail_write)code=1;else if(!s.no_write)s.installed=a.at(-1)!.split("=")[1];}
else if(a.includes("--remove")){s.installed=null;changed=true;}
else if(a.includes("--version"))console.log("apt 3.0");
if(changed){const pending=path+"."+Deno.pid;Deno.writeTextFileSync(pending,JSON.stringify(s));Deno.renameSync(pending,path);}Deno.exit(code);`,
  );
  await Deno.mkdir(`${repo.dir}/worker-bin`);
  for (const name of ["curl", "gpg"]) {
    await repo.fakeBinary(
      `worker-bin/${name}`,
      `const s=JSON.parse(Deno.readTextFileSync(${JSON.stringify(state)})),key=Deno.env.get("ORBIT_KEY_FILE")!;
if(${
        JSON.stringify(name)
      }==="curl") {Deno.mkdirSync(key.slice(0,key.lastIndexOf("/")),{recursive:true});Deno.writeTextFileSync(key+".asc","fixture key");}
else if(Deno.args.includes("--show-keys"))console.log("pub:::::::::\\nfpr:::::::::"+(s.bad_key?"B":"A").repeat(40)+":");
else Deno.writeFileSync(key+".gpg",new Uint8Array([153,...new TextEncoder().encode("fixture binary key")]));`,
    );
  }
  repo.env.PATH = `${repo.dir}/worker-bin:${Deno.env.get("PATH")}`;
  await Deno.writeTextFile(
    `${repo.dir}/extensions/behaviors/fixture.ts`,
    `import {z} from "npm:zod@4.4.3";
export const behavior={type:"@fixture/config",version:"2026.11.03.1",globalArguments:z.record(z.string(),z.unknown()),resources:{state:{schema:z.record(z.string(),z.unknown()),lifetime:"1d",garbageCollection:10}},methods:Object.fromEntries(["check","apply","refresh"].map(method=>[method,{description:"Transport fixture",arguments:z.object({}),execute:async(_,ctx)=>{
const g=ctx.globalArgs,path=${JSON.stringify(state)},s=JSON.parse(Deno.readTextFileSync(path));
Deno.writeTextFileSync(${JSON.stringify(audit)},JSON.stringify(["cfgmgmt",g.path??"package"])+"\\n",{append:true});
if(method==="apply"&&g.repo){if(s.fail_setup)throw new Error("fixture install refused");s.manager_missing=false;Deno.writeTextFileSync(path,JSON.stringify(s));}
const installed=Boolean(s.installed),compliant=g.ensure==="absent"?!installed:installed;
return {dataHandles:[await ctx.writeResource("state",g.nodeHost,{status:compliant?"compliant":"non_compliant",packages:g.packages?[{name:g.packages[0],installed,version:s.installed}]:[]})]};}}]))};`,
  );
  const workflow = async (name: string) =>
    await repo.workflow(name, (flow) => {
      Object.assign(
        flow,
        JSON.parse(JSON.stringify(flow).replaceAll("/tmp/orbit-tool-keys/", `${repo.dir}/worker-keys/`)),
      );
      for (const job of flow.jobs) {
        for (const step of job.steps) {
          const task = step.task as {
            type: string;
            behaviorType?: string;
            inputs: Record<string, unknown>;
            globalArgs?: string | Record<string, unknown>;
          };
          if (step.name === "Set up the Tool Manager") assertFalse(Boolean(task.inputs.report));
          if (task.type !== "behavior") continue;
          if (task.behaviorType?.startsWith("@swamp/cfgmgmt/")) task.behaviorType = "@fixture/config";
          if (typeof task.globalArgs === "string") {
            task.globalArgs = task.globalArgs.replaceAll(
              '"transport":',
              `"sshBinary":${JSON.stringify(ssh)},"transport":`,
            );
          } else if (task.globalArgs && "transport" in task.globalArgs) task.globalArgs.sshBinary = ssh;
        }
      }
    });
  for (const name of ["write-209", "remove-209"]) await workflow(`operation-tool-${name}`);
  const writeState = (value: Record<string, unknown>) =>
    Deno.writeTextFile(state, JSON.stringify({ installed: null, candidate: "2.0-1", ...value }));
  const run = async (
    kind = "tool.install",
    success = true,
    initial: Record<string, unknown> = {},
    intent: Record<string, unknown> = {},
  ) => {
    await writeState(initial);
    const inputs = {
      operation_id: 1,
      node_id: 9,
      kind,
      execution_secrets: {},
      ssh: SSH,
      intent: {
        kind,
        node_id: 9,
        manager: "apt",
        package: "hello",
        user: "orbit",
        platform: "linux",
        architecture: "x86_64",
        tool_id: null,
        setup: null,
        repository: null,
        repository_given: false,
        remove_repository: false,
        manager_ready: true,
        ...intent,
      },
    };
    const name = `operation-tool-${kind === "tool.remove" ? "remove" : "write"}-209`;
    await repo.cli(["workflow", "validate", name]);
    const result = await repo.runWorkflow(name, inputs, { success });
    const steps = Object.fromEntries(result.history.jobs[0].steps.map((s) => [s.name, s]));
    assertFalse("result" in steps);
    const output = { ...steps["Choose package action"]?.outputs, ...steps["Decode package state"]?.outputs };
    if (kind === "tool.remove" && result.history.status === "succeeded") output.outcome = "applied";
    if (steps["Assert the key fingerprint"]?.status === "failed") output.error_code = "tool.repository_key_mismatch";
    return { ...result, steps, output };
  };
  return {
    repo,
    state,
    audit,
    writeState,
    workflow,
    run,
    readState: () => JSON.parse(Deno.readTextFileSync(state)),
    calls: () => readCalls<string[]>(audit),
  };
}
