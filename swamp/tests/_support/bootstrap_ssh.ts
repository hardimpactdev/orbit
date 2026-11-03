import { assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { nativeRepo, ROOT } from "./native_harness.ts";

export async function bootstrapSsh(name: string, node = 14) {
  const repo = await nativeRepo();
  repo.timeoutMs = 120_000;
  await repo.pull("@swamp/ssh");
  await repo.copyBehaviors("operations/node-bootstrap");
  const source = parse(await Deno.readTextFile(`${ROOT}/workflows/workflow-${name}.yaml`)) as {
    jobs: { labels: unknown; steps: { labels: unknown }[] }[];
  };
  for (const job of source.jobs) {
    assertEquals(job.labels, { role: "swamp" });
    for (const step of job.steps) assertEquals(step.labels, { role: "swamp" });
  }
  await repo.workflow(name);
  const key = `${repo.dir}/identity`;
  assertEquals((await new Deno.Command("ssh-keygen", { args: ["-q", "-t", "ed25519", "-N", "", "-f", key] }).output()).code, 0);
  const privateKey = await Deno.readTextFile(key);
  const publicKey = (await Deno.readTextFile(`${key}.pub`)).trim();
  await Deno.remove(key);
  await Deno.remove(`${key}.pub`);
  repo.secrets = [privateKey];
  await repo.cli(["vault", "create", "local_encryption", "orbit-control-inputs"]);
  const reference = { vault: "orbit-control-inputs", key: `bootstrap-identity-node-${node}` };
  await repo.cli(["vault", "put", reference.vault, reference.key], { input: privateKey });
  return { repo, publicKey, reference };
}
