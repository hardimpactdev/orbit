import { assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { nativeRepo, ROOT } from "../../../../tests/_support/native_harness.ts";
import { sha256 } from "../../../../tests/_support/route_fixture.ts";
Deno.test("public pins replace account known_hosts through one native command", async () => {
  await using repo = await nativeRepo();
  repo.timeoutMs = 120_000;
  const home = `${repo.dir}/fixture-account-home`, name = "operation-node-worker-known-hosts";
  const original = parse(await Deno.readTextFile(`${ROOT}/workflows/workflow-${name}.yaml`)) as {
    jobs: { labels: unknown; steps: { labels: unknown }[] }[];
  };
  for (const job of original.jobs) {
    assertEquals(job.labels, { role: "swamp" });
    for (const step of job.steps) assertEquals(step.labels, { role: "swamp" });
  }
  await repo.workflow(name, (flow) => {
    for (const job of flow.jobs) {
      for (const step of job.steps) {
        const inputs = (step.task as { inputs: { run: string } }).inputs;
        inputs.run = inputs.run.replaceAll("/home/$USER", home);
      }
    }
  });
  await repo.cli(["workflow", "validate", name]);
  for (
    const content of ["10.44.0.9 ssh-ed25519 public-fixture-key\n", "10.44.0.9 ssh-ed25519 replaced-fixture-key\n"]
  ) {
    const { run } = await repo.runWorkflow(name, {
      operation_id: 1,
      node_id: 209,
      kind: "node.worker_known_hosts",
      execution_secrets: {},
      intent: { control_node_id: 209, known_hosts_content: content, digest: await sha256(content) },
    });
    assertEquals(run.status, "succeeded");
    assertEquals(await Deno.readTextFile(`${home}/.ssh/known_hosts`), content);
    assertEquals((await Deno.stat(`${home}/.ssh/known_hosts`)).mode! & 0o777, 0o600);
  }
});
