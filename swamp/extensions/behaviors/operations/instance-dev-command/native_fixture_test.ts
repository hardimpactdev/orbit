import { assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { nativeRepo, ROOT } from "../../../../tests/_support/native_harness.ts";
import { firstJson } from "../process-commands/native_fixture.ts";
export const WORKFLOW = "operation-instance-setup-direct";
export async function runStepsFixture() {
  const repo = await nativeRepo();
  await repo.copyBehaviors("automatic-deploy", "operations");
  await repo.pull("@swamp/ssh");
  const calls = `${repo.dir}/calls.jsonl`;
  const ssh = await repo.fakeBinary(
    "ssh-fixture",
    `import {shellSplit,readStdin,logCall} from ${JSON.stringify(`${ROOT}/tests/_support/fake_tool.ts`)};
if (Deno.args.includes('-M') || Deno.args.includes('-O')) Deno.exit(0);
let argv=shellSplit(Deno.args.at(-1)!);logCall(${JSON.stringify(calls)},argv);
if (['sudo','/usr/bin/sudo'].includes(argv[0])) argv=argv.slice(argv.indexOf('--')+1);
const child=new Deno.Command(argv[0],{args:argv.slice(1),stdin:'piped',stdout:'piped',stderr:'piped'}).spawn();
const writer=child.stdin.getWriter();await writer.write(new TextEncoder().encode(await readStdin()));await writer.close();
const result=await child.output();await Deno.stdout.write(result.stdout);await Deno.stderr.write(result.stderr);Deno.exit(result.code);`,
  );
  await Deno.writeTextFile(
    `${repo.dir}/existing-key`,
    "fixture identity not consumed",
  );
  const source = parse(
    await Deno.readTextFile(`${ROOT}/workflows/workflow-${WORKFLOW}.yaml`),
  ) as { jobs: { labels: unknown; steps: { labels: unknown }[] }[] };
  for (const job of source.jobs) {
    assertEquals(job.labels, { role: "swamp" });
    for (const step of job.steps) {
      assertEquals(step.labels, { role: "swamp" });
    }
  }
  await repo.workflow(WORKFLOW, (flow) => {
    for (const job of flow.jobs) {
      for (const step of job.steps) {
        const task = step.task as {
          behaviorType?: string;
          globalArgs: Record<string, unknown>;
        };
        if (task.behaviorType === "@swamp/ssh") {
          task.globalArgs.sshBinary = ssh;
        }
      }
    }
  });
  const checkout = await Deno.makeTempDir({ prefix: "orbit-steps-node-" });
  const user = new TextDecoder().decode(
    (await new Deno.Command("id", { args: ["-un"] }).output()).stdout,
  ).trim();
  const inputs = {
    operation_id: 41,
    node_id: 9,
    kind: "instance.run_steps",
    intent: {
      instance_id: 42,
      list: "setup",
      user,
      directory: checkout,
      steps: [] as Record<string, unknown>[],
      ssh_execution: {
        control_node_id: 209,
        identity_file: `${repo.dir}/existing-key`,
        public_key: "ssh-ed25519 public-fixture",
        target: {
          name: "target",
          host: "198.51.100.9",
          user: "orbit",
          port: 22,
          host_keys: [
            "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIJ111111111111111111111111111111111111111111",
          ],
        },
      },
    },
    execution_secrets: {},
  };
  const steps = async (...commands: [string, string][]) => {
    inputs.intent.steps = [];
    for (const [name, command] of commands) {
      const hash = await crypto.subtle.digest(
        "SHA-256",
        new TextEncoder().encode(command),
      );
      const sha256 = Array.from(
        new Uint8Array(hash),
        (b) => b.toString(16).padStart(2, "0"),
      ).join("");
      inputs.intent.steps.push({
        name,
        command,
        sha256,
        timeout_seconds: 10,
        directory: inputs.intent.directory,
        instance_id: 42,
        instance_name: "default",
        seed_path: "",
        seed_commit: "",
      });
    }
  };
  const runList = async (success = true) => {
    const reply = await repo.cli(["workflow", "run", WORKFLOW, "--stdin"], {
      input: JSON.stringify(inputs),
      success,
    });
    const run = firstJson(reply.stdout) as { id: string; status: string };
    const history = JSON.parse(
      (await repo.cli(["workflow", "history", "get", run.id])).stdout,
    );
    return {
      run,
      steps: Object.fromEntries(
        history.jobs[0].steps.map((s: { name: string }) => [s.name, s]),
      ),
    };
  };
  return {
    repo,
    checkout,
    inputs,
    steps,
    runList,
    async [Symbol.asyncDispose]() {
      await repo[Symbol.asyncDispose]();
      await Deno.remove(checkout, { recursive: true });
    },
  };
}
