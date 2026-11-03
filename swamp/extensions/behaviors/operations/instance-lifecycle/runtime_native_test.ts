/** The real Instance destroy parent removes its Process through the canonical Process destroy child before the source. */
import { assertEquals, assertFalse } from "jsr:@std/assert@1.0.19";
import { ROOT } from "../../../../tests/_support/native_harness.ts";
import { exists, processFixture } from "../process-commands/native_fixture.ts";

Deno.test("the parent removes the Process before the owned source", async () => {
  await using f = await processFixture();
  const repo = f.repo;
  await f.runKind("create");
  const control = f.inputs.intent.control;
  const connection = f.inputs.intent.ssh_execution;
  await repo.copyBehaviors(
    ...[
      "instance-dev-command",
      "instance-source-command",
      "routes/mutation-command",
      "routes/update-command",
      "firewall",
    ]
      .map((family) => `operations/${family}`),
  );
  for (
    const [family, files] of Object.entries({
      "instance-lifecycle": ["source_profile.ts", "source_identity.ts"],
      "routes/publication-command": ["sections.ts"],
      tools: ["constraints.ts", "composer-constraints.json"],
    })
  ) {
    const target = `${repo.dir}/extensions/behaviors/operations/${family}`;
    await Deno.mkdir(target, { recursive: true });
    for (const file of files) {
      await Deno.copyFile(`${ROOT}/extensions/behaviors/operations/${family}/${file}`, `${target}/${file}`);
    }
  }
  const checkout = `${f.host}/apps/project/proof`;
  await Deno.mkdir(checkout, { recursive: true });
  const git = (...args: string[]) => {
    const result = new Deno.Command("git", { args: ["-C", checkout, ...args] }).outputSync();
    if (!result.success) throw new Error(new TextDecoder().decode(result.stderr));
    return new TextDecoder().decode(result.stdout).trim();
  };
  git("init", "--initial-branch=main");
  git("config", "user.email", "fixture@example.test");
  git("config", "user.name", "Fixture");
  git("remote", "add", "origin", "https://github.com/example/project.git");
  await Deno.writeTextFile(`${checkout}/tracked`, "preserve until process removal");
  git("add", "tracked");
  git("commit", "-qm", "fixture");
  for (const name of ["operation-instance-destroy", "route-mutation-forward"]) {
    await repo.workflow(name, (workflow) => {
      delete (workflow as { labels?: unknown }).labels;
      for (const step of workflow.jobs.flatMap((job) => job.steps)) {
        const task = step.task as { behaviorType?: string; globalArgs?: Record<string, unknown> };
        if (task.behaviorType === "@swamp/ssh") {
          Object.assign(task.globalArgs!, { sshBinary: `${f.tools}/ssh`, scpBinary: `${f.tools}/scp` });
        }
      }
    });
  }
  const member = {
    instance_id: 17,
    checkout_path: checkout,
    source_root: `${f.host}/apps`,
    source_layout: "checkout",
    repository_identity: "github.com/example/project",
    branch: "main",
    starting_commit: git("rev-parse", "HEAD"),
    user: f.user,
    source_prepare_id: "fixture",
    registration_request_id: null,
    stored_status: "active",
    failed_creation: false,
  };
  const empty = Object.fromEntries(
    [
      "publications",
      "prepare_certificates",
      "certificates",
      "cleanup",
      "lan",
      "retire_lan",
      "url_writes",
      "routes",
      "ingress",
      "retire_ingress",
    ]
      .map((key) => [key, []]),
  );
  const runtime = { processes: [control], schedules: [] };
  const intent = {
    instance_id: 17,
    removal_id: crypto.randomUUID(),
    force: true,
    inventory_digest: "a".repeat(64),
    source_pending: true,
    repository: "https://github.com/example/project.git",
    protected_source_roots: [],
    members: [member],
    direct_destroy: { node_user: f.user, runtime, database: { sqlite: [], servers: [] } },
    native_runtime: runtime,
    source_capture: null,
    teardown_steps: [],
    route_mutations: { forward: empty, artifacts: { php: {}, caddy: {}, dns: {} } },
    ssh_execution: connection,
    push_check_paths: [],
    source_paths: [checkout],
  };
  const inputs = {
    operation_id: 71,
    node_id: 9,
    kind: "instance.destroy",
    intent,
    execution_secrets: { git_read: { vault: "orbit-control-inputs", key: "git" } },
  };
  const { run, history } = await repo.runWorkflow("operation-instance-destroy", inputs);
  assertEquals(run.status, "succeeded", JSON.stringify(history.jobs.flatMap((job) => job.steps)));
  assertFalse(exists(f.unit));
  assertEquals(f.current().active, "inactive");
  assertFalse(exists(checkout));
});
