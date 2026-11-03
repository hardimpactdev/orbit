/**
 * Actual native cache-fill DAG with unmodified official SSH from 209 and vault-backed agent requests.
 *
 * The fake `ssh` answers like the pinned agent's package-cache.host on the host. No host or fleet mutation.
 */
import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { type History, nativeRepo, ROOT } from "../../../../tests/_support/native_harness.ts";
import { readCalls } from "../../../../tests/_support/fake_tool.ts";
import { HostKey } from "../../../../tests/_support/task_vm_lifecycle.ts";

const tool = new URL("../../../../tests/_support/fake_tool.ts", import.meta.url).href;
const Workflow = "operation-task-vm-cache-fill";
const Hash = "sha256-" + "a".repeat(64);

// Records each call without the token, then answers one compact agent line in the agent's field order.
const fakeSsh = (calls: string) => `
import { logCall, readStdin, shellSplit } from ${JSON.stringify(tool)};
if (Deno.args.includes("-M") || Deno.args.includes("-O")) Deno.exit(0);
const call = { argv: shellSplit(Deno.args.at(-1)) };
const body = await readStdin();
if (body) {
  const request = JSON.parse(body);
  call.token_matches = (request.input.github_token || "") === Deno.env.get("FIXTURE_TOKEN");
  delete request.input.github_token;
  call.stdin = request;
}
logCall(${JSON.stringify(calls)}, call);
const refuse = Deno.env.get("FIXTURE_REFUSE") ?? "";
const hashes = {}, failures = {};
for (const { key } of call.stdin.input.artifacts) {
  if (key === refuse) failures[key] = "Archive checksum mismatch";
  else hashes[key] = ${JSON.stringify(Hash)};
}
console.log(JSON.stringify({ ok: true, data: { hashes, failures } }));
`;

const artifact = (key: string) => (
  { manager: "npm", package: "demo", version: "1.0.0", url: "https://registry.npmjs.org/demo/-/demo-1.0.0.tgz", integrity: null, key }
);
const Batches = [[artifact("composer/acme/demo/" + "b".repeat(40) + ".zip")], [artifact("npm/" + "c".repeat(64) + ".tgz")]];

type Call = { argv: string[]; token_matches: boolean; stdin: { input: { artifacts: unknown[] } } };
type Step = History["jobs"][number]["steps"][number] & { outputs: { stdout: string } };

async function fill(
  { token = "ghs_private_" + crypto.randomUUID().replaceAll("-", ""), batches = Batches, conflicts = {}, refuse = "" } = {},
  success = true,
) {
  await using repo = await nativeRepo();
  repo.timeoutMs = 180_000;
  repo.secrets = token ? [token] : [];
  repo.env = { FIXTURE_TOKEN: token, FIXTURE_REFUSE: refuse };
  await repo.pull("@swamp/ssh");
  const calls = `${repo.dir}/ssh-calls.jsonl`;
  const identity = `${repo.dir}/existing-key`;
  await Deno.writeTextFile(identity, "fixture identity: not used to authenticate", { mode: 0o600 });
  const ssh = await repo.fakeBinary("fixture-ssh", fakeSsh(calls));
  const tracked = parse(await Deno.readTextFile(`${ROOT}/workflows/workflow-${Workflow}.yaml`)) as {
    jobs: { name: string; labels?: unknown; steps: { name: string; labels: unknown }[] }[];
  };
  assertEquals(tracked.jobs.map((job) => job.name), ["fill"]);
  for (const job of tracked.jobs) {
    assertEquals(job.labels, undefined);
    // Every step runs on a role=swamp worker on 209; no per-Node worker label.
    for (const step of job.steps) assertEquals(step.labels, { role: "swamp" }, step.name);
  }
  await repo.workflow(Workflow, (workflow) => {
    for (const step of workflow.jobs.flatMap((job) => job.steps)) {
      const task = step.task as { behaviorType?: string; globalArgs: Record<string, any> };
      if (task.behaviorType !== "@swamp/ssh") continue;
      task.globalArgs.sshBinary = ssh;
      task.globalArgs.transport.identityFile = identity;
    }
  });
  await repo.cli(["vault", "create", "local_encryption", "orbit-control-inputs"]);
  // As the Gateway admits it: public request bytes in the inputs, only the token's JSON literal in the vault.
  await repo.cli(["vault", "put", "orbit-control-inputs", "task-vm-cache-fill-github-13", "--force"], {
    input: '"github_token":' + JSON.stringify(token || null),
  });
  const requests = batches.map((artifacts) =>
    JSON.stringify({ agent_sha256: "d".repeat(64), deadline_ms: 1500000,
      input: { operation: "fill", artifacts, max_bytes: 1000000, github_token: null } }).slice(0, -'"github_token":null}}'.length)
  );
  const execution = { control_node_id: 209, identity_file: "/etc/orbit/swamp-role-worker/ssh-identity", public_key: "ssh-ed25519 PUBLIC",
    target: { name: "target", host: "192.0.2.13", user: "orbit", port: 22, host_keys: [HostKey] } };
  await repo.cli(["workflow", "validate", Workflow]);
  // A full batch exceeds one argv string, so the inputs go through a file as they go through a frame on serve.
  await Deno.writeTextFile(
    `${repo.dir}/inputs.json`,
    JSON.stringify({
      operation_id: 7, node_id: 13, kind: "task_vm.fill_package_cache",
      intent: { ssh_execution: execution, batches: requests.map((_, index) => index), requests, request_end: "}}\n", conflicts },
      execution_secrets: { github_token: { vault: "orbit-control-inputs", key: "task-vm-cache-fill-github-13" } },
    }),
  );
  const run = JSON.parse(
    (await repo.cli(["workflow", "run", Workflow, "--input-file", `${repo.dir}/inputs.json`], { success })).stdout,
  );
  const { history } = await repo.history(run.id);
  const steps = Object.fromEntries(history.jobs.flatMap((job) => job.steps).map((step) => [step.name, step as Step]));
  return { run, steps, calls: readCalls<Call>(calls) };
}

Deno.test("each batch is one pinned agent call from 209 and the assert passes", async () => {
  const { run, steps, calls } = await fill();
  assertEquals([run.status, steps["Check every archive"].status], ["succeeded", "succeeded"]);
  assertEquals(calls.map((call) => call.argv), Array(2).fill(
    ["timeout", "1530", "/usr/bin/sudo", "-n", "--", "/usr/local/bin/orbit-agent", "command", "package-cache.host"],
  ));
  assert(calls.every((call) => call.token_matches));
  assertEquals(calls.map((call) => call.stdin.input.artifacts), Batches);
  // The Gateway reads each batch reply in full from the fill step's own stdout.
  assertEquals(JSON.parse(steps["Fill the package cache-1"].outputs.stdout).data.hashes, { [Batches[1][0].key]: Hash });
});

Deno.test("a refused archive fails the end check and keeps the verified replies", async () => {
  const { run, steps } = await fill({ refuse: Batches[0][0].key }, false);
  assertEquals(run.status, "failed");
  assertEquals(steps["Check every archive"].status, "failed");
  assertEquals(steps["Fill the package cache-0"].status, "succeeded");
  assertEquals(JSON.parse(steps["Fill the package cache-0"].outputs.stdout).data.failures, {
    [Batches[0][0].key]: "Archive checksum mismatch",
  });
});

Deno.test("a full batch without a token sends null from the small vault member", async () => {
  const batch = Array.from({ length: 4096 }, (_, index) => artifact(`npm/${index.toString(16).padStart(64, "0")}.tgz`));
  const { run, calls } = await fill({ token: "", batches: [batch] });
  assertEquals(run.status, "succeeded");
  assertEquals(calls[0].stdin.input.artifacts.length, 4096);
  assert(calls[0].token_matches);
});

Deno.test("the vault is read only for the GitHub token", async () => {
  // Serve redacts every vault value it reads with a RegExp built from the value; a whole agent request
  // as a vault value fails the step before SSH (Operation 8235). Only the small token may come from the vault.
  const source = await Deno.readTextFile(`${ROOT}/workflows/workflow-${Workflow}.yaml`);
  assertEquals([...source.matchAll(/vault\.get\(([^)]*)\)/g)].map((match) => match[1]), [
    "inputs.execution_secrets.github_token.vault, inputs.execution_secrets.github_token.key",
  ]);
});

Deno.test("an admitted integrity conflict fails the end check", async () => {
  const { run, steps, calls } = await fill({
    batches: [Batches[0]],
    conflicts: { ["npm/" + "e".repeat(64) + ".tgz"]: "IntegrityConflict" },
  }, false);
  assertEquals([run.status, steps["Check every archive"].status, calls.length], ["failed", "failed", 1]);
});
