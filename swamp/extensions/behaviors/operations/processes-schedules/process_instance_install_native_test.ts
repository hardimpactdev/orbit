/**
 * The production Process-definition parent, and the deploy and transfer parents that install Processes,
 * with official cfgmgmt and SSH child DAGs on the shared Process host fixture.
 */
import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { parse, stringify } from "jsr:@std/yaml@1.0.12";
import { ROOT } from "../../../../tests/_support/native_harness.ts";
import { firstJson, processFixture, type ProcessFixture, sha256 } from "../process-commands/native_fixture.ts";

const Parent = "operation-process-install-instance-definitions";

type Member = { id: number; intent: Record<string, unknown> };

async function members() {
  const f = await processFixture();
  await f.repo.cli(["vault", "create", "local_encryption", "orbit-control-inputs"]);
  const source = parse(await Deno.readTextFile(`${ROOT}/workflows/workflow-${Parent}.yaml`)) as {
    jobs: { labels?: unknown; steps: { labels?: unknown }[] }[];
  };
  for (const job of source.jobs) {
    assertEquals(job.labels, { role: "swamp" });
    for (const step of job.steps) assertEquals(step.labels, { role: "swamp" });
  }
  await f.repo.workflow(Parent);
  const parent = {
    operation_id: 17,
    node_id: 9,
    kind: "process.install_instance_definitions",
    intent: { instance_id: 4, members: [] as Member[] },
    execution_secrets: {} as Record<string, { vault: string; key: string }>,
  };
  const environmentPaths: string[] = [];
  const addMember = async (processId: number, desired = "running", environment?: string) => {
    const intent = structuredClone(f.inputs.intent) as unknown as Record<string, Record<string, unknown>>;
    const name = `orbit-process-${processId}-proof.service`;
    Object.assign(intent.control, { process_id: processId, name });
    let content = f.content.replace("Process-ID=17", `Process-ID=${processId}`);
    const installation = intent.installation;
    Object.assign(installation, {
      process_id: processId,
      name,
      desired,
      has_environment: environment !== undefined,
      environment_path: `/etc/orbit/processes/${processId}/${"a".repeat(64)}.env`,
    });
    if (environment !== undefined) {
      const path = `/etc/orbit/processes/${processId}/${await sha256(environment)}.env`;
      content += "EnvironmentFile=" + path + "\n";
      installation.environment_path = path;
      environmentPaths.push(`${f.host}/orbit/processes/${processId}/${await sha256(environment)}.env`);
      const key = `process-instance-operation-17-member-${processId}`;
      await f.repo.cli(["vault", "put", "orbit-control-inputs", key], { input: environment });
      parent.execution_secrets[`member_${processId}`] = { vault: "orbit-control-inputs", key };
    }
    Object.assign(installation, { unit: content, content_hash: await sha256(content) });
    delete intent.vite_plan;
    parent.intent.members.push({ id: processId, intent });
    return `${f.host}/units/${name}`;
  };
  const runParent = async (success = true) => {
    const result = await f.repo.cli(["workflow", "run", Parent, "--stdin"], { input: JSON.stringify(parent), success });
    const run = firstJson(result.stdout) as { id: string; status: string };
    assertEquals(run.status, success ? "succeeded" : "failed");
    return run;
  };
  const units = () => f.state().units as Record<string, { active: string; enabled: boolean }>;
  return { f, parent, environmentPaths, addMember, runParent, units };
}

type Members = Awaited<ReturnType<typeof members>>;

const test = (name: string, body: (m: Members) => Promise<void>) =>
  Deno.test(name, async () => {
    const m = await members();
    await using _f: ProcessFixture = m.f;
    await body(m);
  });

test("two definitions keep distinct environment copies and native child scopes", async (m) => {
  const first = "# Orbit Process 7\nPASSWORD=first-private-fixture\n";
  const second = "# Orbit Process 11\nPASSWORD=second-private-fixture\n";
  const units = [await m.addMember(7, "running", first), await m.addMember(11, "stopped", second)];
  // The environment files live on the fake host, outside the repo the harness scans.
  m.f.repo.secrets = ["first-private-fixture", "second-private-fixture"];
  const run = await m.runParent();
  assertEquals(m.environmentPaths.map((path) => Deno.readTextFileSync(path)), [first, second]);
  for (const path of m.environmentPaths) assertEquals(Deno.statSync(path).mode! & 0o777, 0o600);
  for (const unit of units) {
    assert(Deno.statSync(unit).isFile);
    const running = unit === units[0];
    assertEquals(m.units()[unit.split("/").at(-1)!], { active: running ? "active" : "inactive", enabled: running });
  }
  await m.f.repo.cli(["workflow", "history", "get", run.id]);
  m.parent.intent.members = [];
  await m.runParent();
});

test("a failed child keeps its unit for normal forward convergence", async (m) => {
  const unit = await m.addMember(7);
  m.f.save({ units: {}, calls: [], failures: ["start"] });
  await m.runParent(false);
  assert(Deno.statSync(unit).isFile);

});

// The deploy and transfer parents install Processes with the same child, then continue only when no
// step failed. The proof keeps the real install step and a marker in place of the continuation step,
// with the continuation's own guard.
const Shared = {
  deploy: { file: "operation-instance-deploy-direct", job: "deploy", install: "Install Processes", next: "Restart Processes" },
  transfer: { file: "operation-instance-transfer", job: "transfer", install: "Create, start Processes", next: "Publish the Route" },
} as const;

async function runShared(m: Members, family: keyof typeof Shared, success = true) {
  const shape = Shared[family];
  const source = parse(await Deno.readTextFile(`${ROOT}/workflows/workflow-${shape.file}.yaml`)) as {
    jobs: { name: string; steps: Record<string, unknown>[] }[];
  };
  const job = source.jobs.find((job) => job.name === shape.job)!;
  const install = structuredClone(job.steps.find((step) => step.name === shape.install)!);
  const next = job.steps.find((step) => step.name === shape.next)!;
  assertEquals((install.task as { workflowIdOrName: string }).workflowIdOrName, "operation-process-create");
  assertEquals(install.labels, { role: "swamp" });
  const marker = `${m.f.repo.dir}/parent-completed`;
  await Deno.remove(marker).catch(() => {});
  delete install.labels;
  install.dependsOn = [];
  const proof = {
    id: "e4aba95a-13dd-4b45-bc5f-ad08d986eeba",
    name: "shared-parent-proof",
    inputs: (source as Record<string, unknown>).inputs,
    jobs: [{
      name: shape.job,
      steps: [install, {
        name: shape.next,
        dependsOn: [{ step: shape.install, condition: { type: "always" } }],
        guard: next.guard,
        task: {
          type: "behavior",
          behaviorType: "command/shell",
          behaviorName: "shared-parent-marker",
          methodName: "execute",
          inputs: { run: `touch ${marker}` },
        },
      }],
    }],
  };
  await Deno.writeTextFile(`${m.f.repo.dir}/workflows/workflow-shared-parent-proof.yaml`, stringify(proof));
  const nodeMembers = m.parent.intent.members.map((member) => ({
    ...member,
    node_id: 9,
    secret_field: `node_9_member_${member.id}`,
  }));
  const copies = Object.fromEntries(Object.entries(m.parent.execution_secrets).map(([key, value]) => [`node_9_${key}`, value]));
  const intent = family === "deploy"
    ? { deployment: { variant: "production" }, process_members: nodeMembers }
    : { processes: { destination: nodeMembers } };
  const inputs = { operation_id: 17, node_id: 9, kind: `instance.${family}`, intent, execution_secrets: copies };
  const result = await m.f.repo.cli(["workflow", "run", "shared-parent-proof", "--stdin"], {
    input: JSON.stringify(inputs),
    success,
  });
  assertEquals((firstJson(result.stdout) as { status: string }).status, success ? "succeeded" : "failed");
  let marked = true;
  await Deno.stat(marker).catch(() => marked = false);
  assertEquals(marked, success, `${family}: the continuation ran ${marked ? "" : "not "}after the install`);
}

test("deploy and transfer install native children before the parent continues", async (m) => {
  await m.addMember(7);
  await m.addMember(11, "stopped");
  for (const family of ["deploy", "transfer"] as const) {
    await runShared(m, family);
    assertEquals(m.units()["orbit-process-7-proof.service"].active, "active");
    assertEquals(m.units()["orbit-process-11-proof.service"].active, "inactive");
  }
});

test("a failed native install blocks the parent continuation", async (m) => {
  await m.addMember(7);
  m.f.save({ units: {}, calls: [], failures: ["start"] });
  await runShared(m, "deploy", false);
});
