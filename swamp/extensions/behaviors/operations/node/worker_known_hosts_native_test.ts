/** Run the production known-hosts command in an isolated native repository. */
import { assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { nativeRepo, ROOT } from "../../../../tests/_support/native_harness.ts";

Deno.test("public pins reach account known_hosts through one native command", async () => {
  await using repo = await nativeRepo();
  repo.timeoutMs = 120_000;
  const name = "operation-node-worker-known-hosts";
  type Flow = { jobs: { labels: unknown; steps: { labels: unknown }[] }[] };
  const tracked = parse(await Deno.readTextFile(`${ROOT}/workflows/workflow-${name}.yaml`)) as Flow;
  for (const job of tracked.jobs) {
    assertEquals(job.labels, { role: "swamp" });
    for (const step of job.steps) assertEquals(step.labels, { role: "swamp" });
  }
  const home = `${repo.dir}/fixture-account-home`;
  await repo.workflow(name, (flow) => {
    for (const step of flow.jobs.flatMap((job) => job.steps)) {
      const inputs = (step.task as { inputs: { run: string } }).inputs;
      inputs.run = inputs.run.replace("/home/$USER", home);
    }
  });
  await repo.cli(["workflow", "validate", name]);
  for (const content of ["10.44.0.9 ssh-ed25519 public-fixture-key\n", "10.44.0.9 ssh-ed25519 replaced-fixture-key\n"]) {
    const hash = new Uint8Array(await crypto.subtle.digest("SHA-256", new TextEncoder().encode(content)));
    const digest = Array.from(hash, (byte) => byte.toString(16).padStart(2, "0")).join("");
    const intent = { control_node_id: 209, known_hosts_content: content, digest };
    const { run } = await repo.runWorkflow(name, { operation_id: 1, node_id: 209, kind: "node.worker_known_hosts", intent, execution_secrets: {} });
    assertEquals(run.status, "succeeded");
    const target = `${home}/.ssh/known_hosts`;
    assertEquals(await Deno.readTextFile(target), content);
    assertEquals((await Deno.stat(target)).mode! & 0o777, 0o600);
  }
});
