import { assertEquals, assertStringIncludes } from "jsr:@std/assert@1.0.19";
import { bootstrapSsh } from "../../../../tests/_support/bootstrap_ssh.ts";

Deno.test("control probe uses the admitted target and keeps the vault identity private", async () => {
  const { repo, publicKey, reference } = await bootstrapSsh("operation-node-bootstrap-probe");
  await using _cleanup = repo;
  const audit = `${repo.dir}/calls`;
  await repo.fakeBinary("ssh", `
Deno.writeTextFileSync(${JSON.stringify(audit)}, Deno.args.join(" ") + "\\n", {append:true});
const command = Deno.args.at(-1);
if (command.includes("uname")) console.log("Linux x86_64");
else if (command.includes("ssh_host")) console.log(${JSON.stringify(publicKey)});
`);
  repo.env.PATH = `${repo.dir}:${Deno.env.get("PATH")}`;
  const inputs = {
    operation_id: 1, node_id: 14, kind: "node.bootstrap_probe",
    intent: { bootstrap: { node_id: 99, platform: "linux", expected_architecture: "x86_64",
      target: { host: "admitted-target", port: 2222, user: "bootstrap", host_keys: [publicKey] } } },
    execution_secrets: { bootstrap_identity: reference },
  };
  await repo.cli(["workflow", "validate", "operation-node-bootstrap-probe"]);
  const { run, history } = await repo.runWorkflow("operation-node-bootstrap-probe", inputs);
  assertEquals(run.status, "succeeded");
  assertEquals(JSON.parse(history.jobs[0].steps.at(-1)!.outputs!.stdout as string).probed, true);
  assertStringIncludes(JSON.stringify(history), "bootstrap-ssh-99");
  const calls = await Deno.readTextFile(audit);
  assertStringIncludes(calls, "bootstrap@admitted-target");
  assertEquals(calls.includes("gateway.orbit"), false);
});
