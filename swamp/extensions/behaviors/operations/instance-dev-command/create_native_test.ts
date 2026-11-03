import { assert, assertEquals, assertFalse, assertStringIncludes } from "jsr:@std/assert@1.0.19";
import { exists, git } from "../../../../tests/_support/instance_fixture.ts";
import { createFixture } from "../../../../tests/_support/instance_create_fixture.ts";
Deno.test("create writes rendered environment and returns only the commit", async () => {
  const { f, application, commit, run } = await createFixture();
  await using _ = f;
  const { steps, result } = await run();
  assertEquals(
    ["prepare", "source", "environment", "finish", "accepted"].map((n) => steps[n].status),
    Array(5).fill("succeeded"),
  );
  assertEquals(["credential", "fetch", "credential-remove"].map((n) => steps[n].status), Array(3).fill("skipped"));
  assertEquals(result, { instance_id: 17, ready: true, name_converted_from: null, commit });
  assertEquals(await Deno.readTextFile(`${application}/.env`), 'STORED="stored-private-sentinel"\n');
});
Deno.test("nested application gets its environment while setup runs at checkout root", async () => {
  const { f, application, environment, setup, run } = await createFixture();
  await using _ = f;
  const nested = `${application}/apps/site`;
  await Deno.mkdir(nested, { recursive: true });
  await environment('NESTED="yes"\n', nested);
  await setup(["test -d apps/site && touch .git/root-setup"]);
  await run();
  assertEquals(await Deno.readTextFile(`${nested}/.env`), 'NESTED="yes"\n');
  assertFalse(exists(`${application}/.env`));
  assert(exists(`${application}/.git/root-setup`));
});
Deno.test("changed source identity skips later phases and reports source failure", async () => {
  const { f, application, inputs, run } = await createFixture();
  await using _ = f;
  inputs.intent.retained_source.commit = "b".repeat(40);
  const { steps, result } = await run(false);
  assertEquals(steps.environment.status, "skipped");
  assertEquals(result.ready, false);
  assertEquals([result.failed_step, result.error_code], ["source", "instance.create_source_failed"]);
  assertFalse(exists(`${application}/.env`));
});
Deno.test("new branch uses captured seed without moving default", async () => {
  const { f, application, inputs, commit, environment, run } = await createFixture();
  await using _ = f;
  const target = `${f.node}/apps/project/fresh-branch`;
  inputs.intent.retained_source = null;
  Object.assign(inputs.intent.source, { checkout: target, seed_repository: application });
  Object.assign(inputs.intent.resolve, { branch: "fresh-branch", seed_commit: commit, network: false });
  Object.assign(inputs.intent, { checkout: target, application: target });
  await environment('NEW="branch"\n', target);
  await run();
  assertEquals(await Deno.readTextFile(`${target}/.env`), 'NEW="branch"\n');
  assertEquals(git(target, "rev-parse", "--abbrev-ref", "HEAD"), "fresh-branch");
  assertEquals(git(application, "rev-parse", "HEAD"), commit);
  assertFalse(exists(`${application}/.env`));
});
Deno.test("fresh checkout fetches through credential file and removes it", async () => {
  const { f, application, inputs, commit, environment, run } = await createFixture();
  await using _ = f;
  const bare = `${f.node}/origin.git`, target = `${f.node}/apps/project/fetched`;
  git(f.node, "clone", "-q", "--bare", application, bare);
  inputs.intent.retained_source = null;
  Object.assign(inputs.intent.source, { checkout: target, repository: bare });
  inputs.intent.resolve.protocols = ["file"];
  Object.assign(inputs.intent, { checkout: target, application: target });
  await environment('FETCHED="yes"\n', target);
  const out = await run();
  assertEquals(
    ["credential", "fetch", "credential-remove"].map((n) => out.steps[n].status),
    Array(3).fill("succeeded"),
  );
  assertEquals(out.result.commit, commit);
  assertFalse(exists(`/tmp/orbit-git-credential-${out.run.id}`));
});
Deno.test("seed branch collision refuses before preparing target", async () => {
  const { f, application, inputs, commit, run } = await createFixture();
  await using _ = f;
  const target = `${f.node}/apps/project/collision`;
  inputs.intent.retained_source = null;
  Object.assign(inputs.intent.source, { checkout: target, seed_repository: application });
  inputs.intent.resolve.seed_commit = commit;
  Object.assign(inputs.intent, { checkout: target, application: target });
  const { steps, result } = await run(false);
  assertEquals(steps.source.status, "skipped");
  assertEquals([result.failed_step, result.error_code], ["prepare", "instance.create_prepare_failed"]);
  assertStringIncludes(JSON.stringify(steps.prepare), "instance.branch_in_use");
  assertFalse(exists(target));
  assertEquals(git(application, "rev-parse", "HEAD"), commit);
  assertFalse(exists(`${application}/.env`));
});
Deno.test("setup commands run in order and retain no stdout", async () => {
  const { f, application, setup, run } = await createFixture();
  await using _ = f;
  await setup(["printf setup-out-$((6*7)); touch .git/first", "test -f .git/first; touch .git/second"]);
  const { history } = await run();
  assert(exists(`${application}/.git/second`));
  assertFalse(JSON.stringify(history).includes("setup-out-42"));
});
Deno.test("active create repeats neither setup nor environment write", async () => {
  const { f, application, inputs, setup, run } = await createFixture();
  await using _ = f;
  inputs.intent.already_active = true;
  await setup(["touch .git/should-not-run"]);
  await Deno.writeTextFile(`${application}/.env`, "preserve-active");
  assertEquals((await run()).result, { ready: true, instance_id: 17, already_active: true });
  assertFalse(exists(`${application}/.git/should-not-run`));
  assertEquals(await Deno.readTextFile(`${application}/.env`), "preserve-active");
});
Deno.test("failed setup skips later commands and a fresh run converges", async () => {
  const { f, application, setup, run } = await createFixture();
  await using _ = f;
  await setup(["test -f .git/allow", "touch .git/second", "touch .git/third"]);
  const { result } = await run(false);
  assertFalse(exists(`${application}/.git/second`));
  assertFalse(exists(`${application}/.git/third`));
  assertEquals([result.failed_step, result.error_code, result.failed_command], [
    "setup", "instance.setup_step_failed", "step-0",
  ]);
  await Deno.writeTextFile(`${application}/.git/allow`, "");
  await run();
  assert(exists(`${application}/.git/third`));
});
