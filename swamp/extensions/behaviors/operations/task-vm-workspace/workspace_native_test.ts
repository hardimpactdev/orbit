/**
 * The official SSH workspace child in a disposable runtime; never contacts a fleet VM.
 *
 * The fake `ssh` logs each remote argv and stdin, then runs the argv locally, so the checkout is created on disk.
 */
import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { stringify } from "jsr:@std/yaml@1.0.12";
import { nativeRepo } from "../../../../tests/_support/native_harness.ts";
import { readCalls } from "../../../../tests/_support/fake_tool.ts";

const tool = new URL("../../../../tests/_support/fake_tool.ts", import.meta.url).href;

const fake = (calls: string, block: string) => `
import { logCall, passThrough, readStdinBytes, shellSplit } from ${JSON.stringify(tool)};
const body = await readStdinBytes();
const argv = shellSplit(Deno.args.at(-1));
logCall(${JSON.stringify(calls)}, { argv, stdin: new TextDecoder().decode(body) });
try { Deno.statSync(${JSON.stringify(block)}); Deno.exit(255); } catch (error) { if (!(error instanceof Deno.errors.NotFound)) throw error; }
await passThrough(argv, body);
`;

const isDir = (path: string) => Deno.stat(path).then((stat) => stat.isDirectory, () => false);

async function fixture() {
  const repo = await nativeRepo();
  repo.timeoutMs = 120_000;
  await repo.pull("@swamp/ssh");
  await repo.copyBehaviors("operations/task-vm-workspace");
  const calls = `${repo.dir}/calls.jsonl`;
  const block = `${repo.dir}/refuse`;
  const ssh = await repo.fakeBinary("ssh", fake(calls, block));
  await repo.workflow("task-vm-workspace", (workflow) => {
    for (const step of workflow.jobs.flatMap((job) => job.steps)) {
      const task = step.task as { behaviorType?: string; globalArgs?: Record<string, unknown> };
      if (task.behaviorType === "@swamp/ssh") task.globalArgs!.sshBinary = ssh;
    }
  });
  const checkout = `${repo.dir}/apps/project/workspace`;
  const target = {
    name: "target",
    host: "192.0.2.184",
    user: "orbit",
    port: 22,
    host_keys: ["ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG"] as string[],
  };
  const identity = { task_vm_id: 184, group_id: 55, instance_id: 66, project_id: 77, checkout_path: checkout };
  const inputs = {
    operation_id: 7001,
    node_id: 14,
    kind: "tasks.provision",
    intent: {
      ...identity,
      ssh_execution: {
        control_node_id: 209,
        identity_file: "/etc/orbit/swamp-role-worker/ssh-identity",
        public_key: "ssh-ed25519 PUBLIC",
        target,
      },
    },
  };
  return {
    repo,
    inputs,
    target,
    identity,
    checkout,
    block,
    calls: () => readCalls<{ argv: string[]; stdin: string }>(calls),
    run: (success = true) => repo.runWorkflow("task-vm-workspace", inputs, { success }),
    [Symbol.asyncDispose]: () => repo[Symbol.asyncDispose](),
  };
}

Deno.test("a connection failure stops before the directory, then native resume finishes", async () => {
  await using test = await fixture();
  await Deno.writeTextFile(test.block, "");
  const { run } = await test.run(false);
  assertEquals(run.status, "failed");
  assert(!await isDir(test.checkout));
  await Deno.remove(test.block);
  const resumed = JSON.parse((await test.repo.cli(["workflow", "resume", "task-vm-workspace", "--run", run.id])).stdout);
  assertEquals(resumed.status, "succeeded");
  assert(await isDir(test.checkout));
});

Deno.test("a native parent passes the original envelope and consumes the child receipt", async () => {
  await using test = await fixture();
  const result = "steps.workspace.outputs.result";
  await Deno.writeTextFile(
    `${test.repo.dir}/workflows/workflow-workspace-parent.yaml`,
    stringify({
      id: "e31d830f-f76e-446a-a277-a30a01728711",
      name: "workspace-parent",
      inputs: { type: "object", properties: { envelope: { type: "object" } }, required: ["envelope"] },
      jobs: [{
        name: "parent",
        steps: [{
          name: "workspace",
          task: {
            type: "workflow",
            workflowIdOrName: "task-vm-workspace",
            inputs: Object.fromEntries(Object.keys(test.inputs).map((key) => [key, `\${{ inputs.envelope.${key} }}`])),
          },
        }, {
          name: "consume",
          dependsOn: [{ step: "workspace", condition: { type: "succeeded" } }],
          task: {
            type: "assert",
            expr: `${result}.ready && ${result}.task_vm_id == 184 && ${result}.group_id == 55`,
            message: "The parent must receive the child receipt",
          },
        }],
      }],
    }),
  );
  const { run, history } = await test.repo.runWorkflow("workspace-parent", { envelope: test.inputs });
  assertEquals(run.status, "succeeded");
  assertEquals(history.jobs[0].steps[0].outputs!.result, { ...test.identity, ready: true });
  const calls = test.calls();
  assertEquals(calls.filter((call) => call.argv[0] === "/usr/bin/mkdir").length, 1);
  assert(!JSON.stringify(calls).includes("worker connect"));
  assert(await isDir(test.checkout));
});
