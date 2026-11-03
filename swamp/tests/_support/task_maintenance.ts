import { stringify } from "jsr:@std/yaml@1.0.12";
import { type Json, rewrite, taskRemote } from "./task_remote.ts";

export async function maintenance() {
  const t = await taskRemote();
  const { repo, workspace, git, inputs } = t;
  await repo.copyBehaviors("operations/task-maintenance", "operations/task-vm-workspace", "_lib", "js-maintenance", "operations/_lib");
  Object.assign(inputs.intent, {
    task_vm_id: 10, project_id: 20,
    maintenance: {
      mode: "routine", targets: [],
      project: { projectId: 20, repository: "https://github.com/acme/test.git", defaultBranch: "main", check: "composer check", worker: { composerPaths: ["."], jsPaths: [], policy: {} } },
    },
  });
  Object.assign(inputs.intent.ssh_execution, { public_key: "ssh-ed25519 PUBLIC", control_node_id: 209 });
  inputs.intent.ssh_execution.target.name = "fixture";
  await Deno.writeTextFile(`${workspace}/composer.json`, '{"require":{}}\n');
  await Deno.writeTextFile(`${workspace}/composer.lock`, '{"packages":[]}\n');
  await git("add", ".");
  await git("commit", "-qm", "fixture");
  inputs.intent.base = await git("rev-parse", "HEAD");
  await repo.fakeBinary("composer", `
if(Deno.args[0]==="audit") console.log('{"advisories":{}}');
else if(Deno.args[0]==="update") {
 const path=".git/updates";let old=0;try{old=Number(Deno.readTextFileSync(path));}catch{}
 Deno.writeTextFileSync(path,String(old+1));
} else Deno.exit(1);
`);
  for (const name of ["getent", "id"]) await repo.fakeBinary(name, "Deno.exit(0);");
  await repo.fakeBinary("scp", "Deno.exit(99);");
  await repo.workflow("task-vm-workspace");
  await repo.workflow("task-maintenance-path");
  await t.workflow("maintenance");
  return t;
}

export async function releases(t: Awaited<ReturnType<typeof taskRemote>>) {
  const path = `${t.repo.dir}/extensions/behaviors/operations/task-maintenance/metadata.ts`;
  const source = await Deno.readTextFile(path);
  const before = "const response = await fetch(url, { signal: ctx.signal });";
  if (!source.includes(before)) throw new Error("Release fixture boundary moved");
  await Deno.writeTextFile(path, source.replace(before, `const response = new Response(JSON.stringify(args.manager === 'composer' ? {packages:{[args.name]:[{version:'2.0.0',time:'2020-01-01T00:00:00Z'}]}} : {time:{'2.0.0':'2020-01-01T00:00:00Z'}}));`));
}

export async function ending() {
  const t = await taskRemote();
  const { repo, inputs } = t;
  const directory = `${repo.dir}/extensions/behaviors/fixtures`;
  await Deno.mkdir(directory, { recursive: true });
  await Deno.writeTextFile(`${directory}/ending.ts`, `
import {z} from "npm:zod@4.4.3";
export const behavior={type:"@fixture/ending",version:"2026.11.01.1",resources:{result:{schema:z.record(z.string(),z.unknown()),lifetime:"infinite",garbageCollection:1}},methods:{result:{description:"Typed provider, Route and destroy boundary",arguments:z.object({intent:z.record(z.string(),z.unknown()),destroy:z.boolean()}),execute:async(args,ctx)=>({dataHandles:[await ctx.writeResource("result","result",args.destroy?{result_json:JSON.stringify(args.intent.fixture_result)}:{ready:args.intent.fixture_ready!==false,running:args.intent.fixture_running!==false})]})}}};
`);
  const children: Record<string, Json> = {};
  for (const name of ["task-vm-destroy", "task-vm-provider-power", "route-mutation-withdraw"]) {
    children[name] = {
      id: crypto.randomUUID(), name, inputs: { type: "object", additionalProperties: true },
      jobs: [{ name: "fixture", steps: [{ name: "result", task: {
        type: "behavior", behaviorType: "@fixture/ending", behaviorName: `${name}-fixture`, methodName: "result",
        inputs: { intent: "${{ inputs.intent }}", destroy: name === "task-vm-destroy" },
      } }] }],
    };
    await Deno.writeTextFile(`${repo.dir}/workflows/workflow-${name}.yaml`, stringify(children[name]));
  }
  Object.assign(inputs.intent, {
    project_id: 5, task_vm_id: 10, teardown: [], workspace_routes: [], push: null, provider: "incus",
    provider_input: { fixture_running: true },
    fixture_result: { task_vm_id: 10, guest_node_id: 3, destroyed: true, cleanup_complete: true },
  });
  await t.workflow("cancel");
  for (const name of ["task-workspace-teardown", "task-approved-push"]) {
    let flow = await repo.workflow(name);
    flow = rewrite(flow, "/tmp/orbit-task-teardown-", `${repo.dir}/orbit-task-teardown-`);
    await Deno.writeTextFile(`${repo.dir}/workflows/workflow-${name}.yaml`, stringify(flow));
  }
  return {
    t, children,
    async failProvider() {
      children["task-vm-provider-power"].jobs[0].steps[0].task = { type: "assert", expr: "false" };
      await Deno.writeTextFile(`${repo.dir}/workflows/workflow-task-vm-provider-power.yaml`, stringify(children["task-vm-provider-power"]));
    },
    [Symbol.asyncDispose]: () => t[Symbol.asyncDispose](),
  };
}
