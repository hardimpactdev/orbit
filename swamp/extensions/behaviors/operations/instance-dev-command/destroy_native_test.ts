import { assert, assertEquals, assertFalse } from "jsr:@std/assert@1.0.19";
import { trackedStep } from "../../../../tests/_support/native_harness.ts";
import { type Data, exists, git, sha256 } from "../../../../tests/_support/instance_fixture.ts";
import { createFixture } from "../../../../tests/_support/instance_create_fixture.ts";
const NAME = "operation-instance-destroy";
async function fixture() {
  const { f, application } = await createFixture();
  await Deno.remove(`${f.repo.dir}/workflows/workflow-operation-instance-create.yaml`);
  for (const name of [NAME, "route-mutation-forward"]) {
    await f.workflow(name, (flow) => {
      for (const job of flow.jobs) {
        for (const step of job.steps) {
          const argv = step.task.inputs?.argv;
          if (Array.isArray(argv) && argv[0] === "/usr/bin/sudo") {
            step.task.inputs.argv = argv.slice(argv.indexOf("--") + 1);
          }
        }
      }
    });
  }
  const member = {
    instance_id: 17, checkout_path: application,
    source_root: `${f.node}/apps`,
    source_layout: "checkout", repository_identity: "github.com/example/project", branch: "main",
    starting_commit: git(application, "rev-parse", "HEAD"), user: f.user, source_prepare_id: "admitted-prepare",
    registration_request_id: null, stored_status: "active", failed_creation: false,
  };
  const empty = Object.fromEntries(
    [
      "publications", "prepare_certificates", "certificates", "cleanup", "lan", "retire_lan", "url_writes", "routes",
      "ingress", "retire_ingress",
    ].map((k) => [k, []]),
  );
  const inputs: Data = {
    operation_id: 71, node_id: 9, kind: "instance.destroy",
    execution_secrets: {
      git_credential: { vault: "orbit-control-inputs", key: "instance-git-credential-71" },
    },
    intent: {
      instance_id: 17, removal_id: crypto.randomUUID(), force: true, inventory_digest: "a".repeat(64),
      source_pending: true,
      repository: "https://github.com/example/project.git",
      protected_source_roots: [], members: [member],
      direct_destroy: {
        node_user: f.user,
        runtime: { processes: [], schedules: [] },
        database: { sqlite: [], servers: [] },
      },
      native_runtime: { processes: [], schedules: [] },
      source_capture: null, teardown_steps: [],
      route_mutations: { forward: empty, artifacts: { php: {}, caddy: {}, dns: {} } },
      ssh_execution: f.connection, push_check_paths: [], source_paths: [application],
    },
  };
  const teardown = async (directory: string, owner: number, commands: string[]) => {
    inputs.intent.teardown_steps.push(
      ...await Promise.all(
        commands.map(async (command, index) => ({
          name: `step-${index}`,
          command, sha256: await sha256(command), timeout_seconds: 30, directory, instance_id: owner,
          instance_name: String(owner), seed_path: "", seed_commit: "",
        })),
      ),
    );
  };
  const destroy = async (success = true) => {
    const result = await f.run(NAME, inputs, success);
    assertFalse(JSON.stringify(result.history).includes("No such key"));
    return result;
  };
  return { f, application, inputs, teardown, destroy };
}
Deno.test("destroy with empty children removes only its owned checkout", async () => {
  const { f, application, destroy } = await fixture();
  await using _ = f;
  assertEquals((await destroy()).steps["Check it is gone"].status, "succeeded");
  assertFalse(exists(application));
  assert((await Deno.stat(`${f.node}/apps`)).isDirectory);
});
Deno.test("destroy runs two members' teardown in order and removes the worktree first", async () => {
  const { f, application, inputs, teardown, destroy } = await fixture();
  await using _ = f;
  const sibling = `${f.node}/apps/project/sibling`;
  git(application, "worktree", "add", "-qb", "sibling", sibling);
  inputs.intent.members.push({
    ...inputs.intent.members[0], instance_id: 18, checkout_path: sibling, source_layout: "worktree", branch: "sibling",
  });
  inputs.intent.source_paths.push(sibling);
  const log = `${f.node}/teardown-observed`;
  for (const [owner, directory] of [[17, application], [18, sibling]] as const) {
    await teardown(
      directory, owner,
      [0, 1, 2].map((i) => `printf ${owner}-${i}_ >> ${log}`),
    );
  }
  await destroy();
  assertEquals(await Deno.readTextFile(log), "17-0_17-1_17-2_18-0_18-1_18-2_");
  assertFalse(exists(sibling));
  assertFalse(exists(application));
});
Deno.test("unready Route withdrawal keeps the source and fails destroy", async () => {
  const { f, application, destroy } = await fixture();
  await using _ = f;
  await f.workflow("route-mutation-forward", (flow) => {
    for (const step of flow.jobs[0].steps) {
      if (step.name === "forward") {
        delete step.forEach;
        step.name = "forward-0";
        step.task = {
          type: "behavior", behaviorType: "command/shell",
          behaviorName: '${{ "unready-forward-" + run.id }}',
          methodName: "execute",
          inputs: { run: "exit 3" },
        };
      }
      for (const dep of step.dependsOn ?? []) if (dep.step === "forward") dep.step = "forward-0";
    }
  });
  const { steps } = await destroy(false);
  assertEquals(steps["Withdraw the Route"].status, "succeeded");
  assert(exists(application));
  for (const name of ["Run teardown steps", "Remove the source"]) assertFalse(steps[name]?.status === "succeeded");
  assertEquals(steps["Check it is gone"].status, "failed");
});
Deno.test("failed teardown keeps source and skips later teardown commands", async () => {
  const { f, application, teardown, destroy } = await fixture();
  await using _ = f;
  await teardown(application, 17, ["true", "echo broke >&2; exit 4", "touch never"]);
  const { steps } = await destroy(false);
  assertEquals(steps["Run teardown steps-1"].status, "failed");
  assert(exists(application));
  assertFalse(exists(`${application}/never`));
  assertEquals(steps["Remove the source"].status, "skipped");
});
Deno.test("dirty source refuses normal destroy before effects and removes the credential", async () => {
  const { f, application, inputs, destroy } = await fixture();
  await using _ = f;
  Object.assign(inputs.intent, { force: false, push_check_paths: [application] });
  const bare = `${f.node}/origin.git`;
  git(f.node, "clone", "-q", "--bare", application, bare);
  git(application, "remote", "set-url", "origin", bare);
  git(application, "fetch", "-q", "origin");
  await Deno.writeTextFile(`${application}/uncommitted`, "work");
  const { run, steps } = await destroy(false);
  assertEquals(steps["Check work is pushed"].status, "failed");
  assert(exists(`${application}/uncommitted`));
  assertEquals(steps["Remove the source"].status, "skipped");
  assertFalse(exists(`/tmp/orbit-git-credential-${run.id}`));
});
Deno.test("destroy removes VPN DNS records before removing source", async () => {
  const { f, application, inputs, destroy } = await fixture();
  await using _ = f;
  const log = `${f.node}/dns-calls`;
  await f.repo.stubWorkflow("route-dns-publish", `test -d ${application} && printf x >> ${log}`);
  const dns = { caddy: null, php: null, dns: 0 };
  inputs.intent.route_mutations.forward.publications = [{
    index: 0, node_id: 1, user: "orbit", serves_routes: false, control_node_id: 209, host_keys: [],
    connection: {},
    prepared: dns, withdrawn: dns, after: dns, before: dns, direction: "forward",
  }];
  inputs.intent.route_mutations.artifacts.dns = [{
    node_id: 1, records: "# Managed by Orbit.\n",
    catalog: "{}\n",
    digest: "d".repeat(64),
  }];
  const { steps } = await destroy();
  assertEquals(steps["Remove DNS records-0"].status, "succeeded");
  assert((await Deno.readTextFile(log)).length >= 1);
  assertFalse(exists(application));
});
Deno.test("destroy skips DNS when no publication exists", async () => {
  const { f, destroy } = await fixture();
  await using _ = f;
  const { steps } = await destroy();
  assertFalse(
    Object.entries(steps).some(([name, step]) => name.startsWith("Remove DNS records") && step.status === "succeeded"),
  );
});
Deno.test("destroy removes owned databases through official steps", () => {
  const sqlite = trackedStep(NAME, "Remove SQLite files").task as Data;
  assertEquals([sqlite.behaviorType, sqlite.globalArgs.ensure], ["@swamp/cfgmgmt/file", "absent"]);
  const servers = trackedStep(NAME, "Drop owned databases").task as Data;
  assertEquals([servers.workflowIdOrName, servers.inputs.kind], ["operation-database-destroy", "database.destroy"]);
  assertEquals(Object.keys(servers.inputs.execution_secrets), ["fence", "drop", "gone", "restore"]);
});
