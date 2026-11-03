import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { provision } from "../../../../tests/_support/task_repository_provision.ts";
import { exists, type Json, text, write, yamlWrite } from "../../../../tests/_support/task_repository.ts";
import { walk } from "jsr:@std/fs@1.0.21/walk";

Deno.test("proof club token reaches a private file through SSH stdin and stays out of history", async () => {
  await using t = await provision();
  const { history, steps, result, home, secret } = await t.proofDelivery();
  assertEquals(history.status, "succeeded");
  assert("proof_club" in steps["Set up the workspace"].outputs);
  assert(!JSON.stringify(history).includes(secret));
  const path = `${home}/.config/swamp/club-api-key`;
  assert(!(await Deno.lstat(path)).isSymlink);
  assertEquals(await text(path), secret);
  assertEquals((await Deno.stat(path)).mode! & 0o777, 0o600);
  assert(!("error_code" in result));
});
Deno.test("private delivery refusal retains VM facts without claiming source success", async () => {
  await using t = await provision();
  const { history, result } = await t.proofDelivery(true);
  assertEquals(history.status, "failed");
  assertEquals(result.failed_step, "proof_club");
  assert(result.ready);
  assert(!("source" in result));
});
Deno.test("full source and ordered setup use the original root and return their own receipt", async () => {
  await using t = await provision();
  await t.git("branch", "-m", "main", "develop");
  t.inputs.intent.workspace.default_branch = "develop";
  t.setup(["printf first > order", "test $(cat order) = first; printf second >> order"]);
  const { history, steps, result } = await t.execute();
  assertEquals(history.status, "succeeded");
  assert(!("proof_club" in steps["Set up the workspace"].outputs));
  assertEquals(await text(`${t.checkout}/order`), "firstsecond");
  assertEquals(result.starting_commit, t.start);
  assertEquals(result.analysis_base.base, t.start);
  assertEquals(result.instance_id, 50);
  assertEquals(result.node_id, 20);
  assertEquals(result.source.branch, "task-30");
  assert(result.ready);
  assert(!("result_json" in result));
  assertEquals(steps["Create and prepare the VM"].outputs.result.operation_id, 7);
  assertEquals(steps["Create and prepare the VM"].outputs.result.root_kind, "tasks.provision");
});
Deno.test("task branch starts at the admitted commit rather than the newer default head", async () => {
  await using t = await provision();
  await write(`${t.workspace}/red`, "newer head\n");
  await t.git("add", ".");
  await t.git("commit", "-qm", "Red head");
  const { history, result } = await t.execute();
  assertEquals(history.status, "succeeded");
  assertEquals(result.starting_commit, t.start);
  assertEquals(result.analysis_base.base, t.start);
  assert(!await exists(`${t.checkout}/red`));
});
Deno.test("Git execution copy stays on209 and out of guest configuration and history", async () => {
  await using t = await provision();
  const secret = "AUTHORIZATION: basic " + crypto.randomUUID().replaceAll("-", "") + crypto.randomUUID().slice(0, 16);
  t.repo.secrets = [secret];
  await t.repo.cli(["vault", "create", "local_encryption", "orbit-control-inputs"]);
  await t.repo.cli(["vault", "put", "orbit-control-inputs", "task-provision-git-10"], { input: secret });
  t.inputs.intent.git_authorization = { vault: "orbit-control-inputs", key: "task-provision-git-10" };
  const { history, steps } = await t.execute();
  assertEquals(steps["Create and prepare the VM"].outputs.result.vm_copy_keys, []);
  assertEquals(history.status, "succeeded");
  assert(!JSON.stringify(history).includes(secret));
  assert(!(await text(`${t.checkout}/.git/config`)).includes(secret));
  assertEquals(await text(`${t.checkout}/code`), "fixture\n");
});
Deno.test("cloud provider descriptors stay in the VM child", async () => {
  await using t = await provision();
  t.inputs.execution_secrets = {
    bootstrap: { vault: "orbit-control-inputs", key: "task-vm-provider-bootstrap-10" },
    upcloud: { vault: "orbit-control-inputs", key: "task-vm-upcloud-token" },
  };
  const { history, steps } = await t.execute();
  assertEquals(history.status, "succeeded");
  assertEquals(steps["Create and prepare the VM"].outputs.result.vm_copy_keys.sort(), ["bootstrap", "upcloud"]);
});
Deno.test("monorepo skips irrelevant Composer metadata and keeps the setup seed empty", async () => {
  await using t = await provision();
  await write(`${t.workspace}/composer.json`, "{invalid composer metadata");
  await t.git("add", ".");
  await t.git("commit", "-qm", "Irrelevant profile");
  t.setup(['set -u; test -z "$ORBIT_SEED_PATH"; test -z "$ORBIT_SEED_COMMIT"; printf seeded > setup-proof']);
  const { history, result } = await t.execute();
  assertEquals(history.status, "succeeded");
  assertEquals(result.source.php_version, null);
  assertEquals(await text(`${t.checkout}/setup-proof`), "seeded");
});
Deno.test("invalid Laravel profile keeps its specific refusal and VM readiness", async () => {
  await using t = await provision();
  await write(`${t.workspace}/composer.json`, JSON.stringify({ require: { php: "^9" } }));
  await t.git("add", ".");
  await t.git("commit", "-qm", "Unsupported PHP");
  Object.assign(t.inputs.intent.workspace, {
    project_type: "laravel-app",
    skip_profile: false,
    start_commit: await t.git("rev-parse", "HEAD"),
  });
  const { history, result } = await t.execute(false);
  assertEquals(history.status, "failed");
  assertEquals(result.error_code, "app-dev.php_version_unsupported");
  assertEquals(result.failed_step, "profile");
  assert(result.ready);
  assert(!("source" in result));
});
Deno.test("setup output is redacted before native history", async () => {
  await using t = await provision();
  t.repo.secrets = ["ghp_" + "P".repeat(36), "d3".repeat(32)];
  const emit = await t.repo.fakeBinary(
    "emit",
    'console.log("ghp_"+"P".repeat(36));console.log("d3".repeat(32));console.error("visible");',
  );
  t.setup([emit, "exit 7"]);
  const { history, steps } = await t.execute(false);
  assertEquals(history.status, "failed");
  const source = steps["Set up the workspace"].outputs;
  assertEquals(source["setup-0"].stdout, "");
  assertEquals(source.setup_output.setup["setup-0"], { output: "[REDACTED]\n[REDACTED]\nvisible\n", truncated: false });
  assertEquals(await t.scratch("assets-"), []);
  assertEquals(await Array.fromAsync(Deno.readDir(`${t.repo.dir}/control`)).catch(() => []), []);
});
Deno.test("setup failure returns VM readiness without source success and skips later setup", async () => {
  await using t = await provision();
  t.setup(["exit 23", "touch never"]);
  const { history, result } = await t.execute(false);
  assertEquals(history.status, "failed");
  assert(result.ready);
  assertEquals(result.error_code, "tasks.provision_failed");
  assertEquals(result.failed_step, "setup-0");
  assert(!("source" in result));
  assert(!await exists(`${t.checkout}/never`));
});
Deno.test("unavailable VM child cannot prepare the workspace or claim success", async () => {
  await using t = await provision();
  t.inputs.intent.fixture_vm.task_vm_id = 99;
  const { history, result } = await t.execute(false);
  assertEquals(history.status, "failed");
  assertEquals(result.failed_step, "guest");
  assert(!await exists(t.checkout));
});
Deno.test("setup runs in the checkout without exporting its dotenv", async () => {
  await using t = await provision();
  t.setup([
    "printf 'SETUP_VALUE=ready\\n' > .env",
    'test -z "${SETUP_VALUE-}"; test "$COMPOSER_NO_INTERACTION" = 1; printf clean > setup-proof',
  ]);
  const { history, result } = await t.execute();
  assertEquals(history.status, "succeeded");
  assertEquals(await text(`${t.checkout}/setup-proof`), "clean");
  assert(result.ready);
});
Deno.test("Laravel profile reads Composer metadata and the entry point over SSH", async () => {
  await using t = await provision();
  await write(
    `${t.workspace}/composer.json`,
    JSON.stringify({ require: { php: "^8.5", "laravel/framework": "^13.0" } }),
  );
  await write(`${t.workspace}/artisan`, "#!/usr/bin/env php\n");
  await t.git("add", ".");
  await t.git("commit", "-qm", "Laravel");
  Object.assign(t.inputs.intent.workspace, {
    project_type: "laravel-app",
    skip_profile: false,
    start_commit: await t.git("rev-parse", "HEAD"),
  });
  const { history, result } = await t.execute();
  assertEquals(history.status, "succeeded");
  assertEquals(result.source.php_version, "8.5");
  assert(result.source.laravel);
});
Deno.test("Composer metadata is copied to the worker and only public facts reach history", async () => {
  await using t = await provision();
  const secret = "ghp_" + "C".repeat(36);
  await write(
    `${t.workspace}/composer.json`,
    JSON.stringify({ require: { php: "^8.5", "laravel/framework": "^13.0" }, extra: { token: secret } }),
  );
  await write(`${t.workspace}/artisan`, "#!/usr/bin/env php\n");
  await t.git("add", ".");
  await t.git("commit", "-qm", "Laravel with credential");
  Object.assign(t.inputs.intent.workspace, {
    project_type: "laravel-app",
    skip_profile: false,
    start_commit: await t.git("rev-parse", "HEAD"),
  });
  const { history, steps } = await t.execute();
  assertEquals(history.status, "succeeded");
  assertEquals(steps["Set up the workspace"].outputs.profile.source, {
    branch: "task-30",
    commit: t.inputs.intent.workspace.start_commit,
    php_version: "8.5",
    laravel: true,
  });
  const logs = await t.repo.cli(["workflow", "history", "logs", history.id]);
  assert(!(JSON.stringify(history) + logs.stdout + logs.stderr).includes(secret));
  for await (
    const entry of walk(t.repo.dir, {
      includeDirs: false,
      skip: [new RegExp(`^${t.workspace}(?:/|$)`), new RegExp(`^${t.repo.dir}/guest(?:/|$)`)],
    })
  ) if (entry.isFile && !entry.isSymlink) assert(!(await text(entry.path)).includes(secret), entry.path);
  assertEquals(await Array.fromAsync(Deno.readDir(`${t.repo.dir}/control`)).catch(() => []), []);
});
Deno.test("source acquisition failure still returns VM readiness", async () => {
  await using t = await provision();
  t.inputs.intent.workspace.repository = `${t.repo.dir}/missing-repository`;
  const { history, result } = await t.execute(false);
  assertEquals(history.status, "failed");
  assert(result.ready);
  assertEquals(result.error_code, "tasks.provision_failed");
  assertEquals(result.failed_step, "clone");
});
Deno.test("official extension loader resolves the shared environment import", async () => {
  await using t = await provision();
  await write(
    `${t.repo.dir}/extensions/behaviors/environment-import.ts`,
    'import {z} from "npm:zod@4.4.3";import {appStepRuntime} from "./operations/instance-deploy/app_step_runtime.ts";export const behavior={type:"@nckrtl/test-environment-import",version:"2026.11.01.1",resources:{public:{schema:z.object({valid:z.boolean()}),lifetime:"infinite",garbageCollection:1}},methods:{read:{description:"Check shared source import",arguments:z.object({}),execute:async(a,ctx)=>({dataHandles:[await ctx.writeResource("public","output",{valid:appStepRuntime.includes("def parse_project_environment")})]})}}};',
  );
  await yamlWrite(`${t.repo.dir}/workflows/workflow-test-environment-import.yaml`, {
    id: "855f7c0b-83ab-46c6-8e64-3ec7765873b1",
    name: "test-environment-import",
    jobs: [{
      name: "import",
      steps: [{
        name: "result",
        task: {
          type: "behavior",
          behaviorType: "@nckrtl/test-environment-import",
          behaviorName: "environment-import",
          methodName: "read",
          inputs: {},
        },
      }],
    }],
  });
  const { history } = await t.run("test-environment-import", {});
  assert((history as Json).jobs[0].steps[0].outputs.valid);
});
