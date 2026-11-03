import { assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { nativeRepo, ROOT } from "./native_harness.ts";
import { copy, identity, type Json } from "./platform_fixture.ts";

export async function bootstrapFixture(mac = false, remote = false) {
  const repo = await nativeRepo();
  repo.timeoutMs = 120_000;
  await repo.pull("@swamp/ssh");
  await copy(repo, `extensions/behaviors/operations/node-bootstrap/${mac ? "macos_result" : "probe_result"}.ts`);
  const publicKey = await identity(repo);
  const calls = `${repo.dir}/calls`, failure = `${repo.dir}/fail-observation`;
  const ssh = await repo.fakeBinary(
    "ssh",
    `
const args = Deno.args;
if (args.some(a => ["-M", "-O", "-N", "-MNf"].includes(a))) Deno.exit(0);
const command = args.at(-1);
Deno.writeTextFileSync(${JSON.stringify(calls)}, args.join(" ")+"\\n", {append:true});
if (command.includes("uname")) {
  try { Deno.statSync(${JSON.stringify(failure)}); Deno.exit(1); } catch { /* No injected failure. */ }
  console.log(${JSON.stringify(mac ? "Darwin arm64" : "Linux x86_64")});
} else if (command.includes("ifconfig")) console.log("utun0:\\n\\tinet "+Deno.env.get("MAC_ADDRESS")+" netmask 0xffffffff");
else if (command.includes("cat ")) console.log(${JSON.stringify(publicKey)});
else Deno.exit(99);
`,
  );
  repo.env.MAC_ADDRESS = "10.44.0.12";
  const name = `operation-node-${mac ? "macos-inspect" : "bootstrap-probe"}`;
  const workflow = parse(await Deno.readTextFile(`${ROOT}/workflows/workflow-${name}.yaml`)) as Json;
  for (const job of workflow.jobs) {
    assertEquals(job.labels, { role: "swamp" });
    if (!remote) delete job.labels;
    for (const step of job.steps) {
      assertEquals(step.labels, { role: "swamp" });
      if (!remote) delete step.labels;
      if (step.task.behaviorType === "@swamp/ssh") step.task.globalArgs.sshBinary = ssh;
    }
  }
  // JSON is YAML; preserve every production setting except placement and the official binary seam.
  await Deno.writeTextFile(`${repo.dir}/workflows/workflow-${name}.yaml`, JSON.stringify(workflow));
  const inputs = {
    operation_id: 1,
    node_id: 14,
    kind: mac ? "node.macos_inspect" : "node.bootstrap_probe",
    intent: {
      bootstrap: {
        node_id: mac ? 12 : 99,
        platform: "linux",
        expected_architecture: mac ? "arm64" : "x86_64",
        address: "10.44.0.12",
        target: {
          host: mac ? "admitted-mac" : "admitted-target",
          port: 2222,
          user: "bootstrap",
          host_keys: [publicKey],
        },
      },
    },
    execution_secrets: { bootstrap_identity: { vault: "orbit-control-inputs", key: "bootstrap-identity-node-14" } },
  };
  return { repo, name, inputs, publicKey, calls, failure };
}
