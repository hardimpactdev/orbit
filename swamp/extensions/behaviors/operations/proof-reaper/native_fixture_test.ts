import { assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { nativeRepo, ROOT } from "../../../../tests/_support/native_harness.ts";
export const NOW = 1_800_000_000;
const KEY = "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAICfyJ+CMCGNc9YCsyn3OdkhwyeKL0qHT/+ASm3iPNAvT";
type Group = {
  id: number;
  title: string;
  started_at: number | null;
  running: boolean;
};
export async function reaperFixture() {
  const repo = await nativeRepo();
  repo.timeoutMs = 180000;
  await repo.copyBehaviors("operations/proof-reaper");
  const module = `${repo.dir}/extensions/behaviors/operations/proof-reaper/reaper.ts`;
  await Deno.writeTextFile(
    module,
    (await Deno.readTextFile(module)).replace(
      "...proofReaper()",
      `...proofReaper(() => ${NOW})`,
    ),
  );
  await repo.pull("@swamp/ssh");
  await Deno.writeTextFile(
    `${repo.dir}/key`,
    "synthetic fixture identity, never used by SSH",
    { mode: 0o600 },
  );
  const calls = `${repo.dir}/ssh-calls.jsonl`;
  const fake = await repo.fakeBinary(
    "ssh",
    `import {logCall} from ${JSON.stringify(`${ROOT}/tests/_support/fake_tool.ts`)};
logCall(${JSON.stringify(calls)},Deno.args);
const answers:Record<string,[number,number|string]>={'10.44.0.10':[0,${NOW - 60}],'10.44.0.20':[0,${
      NOW - 4000
    }],'10.44.0.30':[1,''],'10.44.0.40':[255,'']};
for(const [address,[code,out]] of Object.entries(answers)) if(Deno.args.some(a=>a.endsWith('@'+address))){console.log(out);Deno.exit(code);}
Deno.exit(255);`,
  );
  const source = parse(
    await Deno.readTextFile(`${ROOT}/workflows/workflow-proof-reaper.yaml`),
  ) as {
    trigger: { schedule: string };
    concurrency: number;
    queueTimeout: number;
    labels: unknown;
    jobs: { steps: { labels: unknown }[] }[];
  };
  assertEquals(source.trigger.schedule, "0 7-59/10 * * * *");
  assertEquals([source.concurrency, source.queueTimeout], [1, 300]);
  assertEquals(source.labels, { role: "swamp" });
  for (const job of source.jobs) {
    for (const step of job.steps) {
      assertEquals(step.labels, { role: "swamp" });
    }
  }
  await repo.workflow("proof-reaper", (flow) => {
    delete flow.labels;
    for (const job of flow.jobs) {
      for (const step of job.steps) {
        const task = step.task as {
          behaviorType?: string;
          globalArgs: Record<string, unknown>;
        };
        if (task.behaviorType === "@swamp/ssh") {
          task.globalArgs.sshBinary = fake;
        }
      }
    }
  });
  const run = async (groups: Group[]) => {
    const targets = groups.filter((g) => g.running).map((g) => ({
      group_id: g.id,
      node_id: g.id + 300,
      host: `10.44.0.${g.id}`,
      user: "orbit",
      port: 22,
      host_keys: [KEY],
      identity_file: `${repo.dir}/key`,
    }));
    const path = `${repo.dir}/inputs.json`;
    await Deno.writeTextFile(
      path,
      JSON.stringify({ revision: 3, groups, targets }),
    );
    const result = JSON.parse(
      (await repo.cli([
        "workflow",
        "run",
        "proof-reaper",
        "--input-file",
        path,
      ])).stdout,
    );
    assertEquals(result.status, "succeeded");
    const history = JSON.parse(
      (await repo.cli(["workflow", "history", "get", result.id])).stdout,
    );
    return history.jobs.flatMap((
      j: { steps: { name: string; outputs: unknown }[] },
    ) => j.steps).find((s: { name: string }) => s.name === "decide")
      .outputs;
  };
  return {
    repo,
    calls,
    run,
    async [Symbol.asyncDispose]() {
      await repo[Symbol.asyncDispose]();
    },
  };
}
