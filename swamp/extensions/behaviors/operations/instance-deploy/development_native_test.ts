import { assert, assertEquals, assertFalse } from "jsr:@std/assert@1.0.19";
import { type Data, exists, git, instanceFixture, sha256, TOOL } from "../../../../tests/_support/instance_fixture.ts";
const NAME = "operation-instance-deploy-direct";
async function fixture() {
  const f = await instanceFixture("operations");
  const origin = `${f.node}/origin`, checkout = `${f.node}/checkout`;
  git(f.node, "init", "-q", "-b", "main", origin);
  git(origin, "config", "user.name", "proof");
  git(origin, "config", "user.email", "proof@example.test");
  await Deno.mkdir(`${origin}/packages/old`, { recursive: true });
  await Deno.writeTextFile(`${origin}/packages/old/index.js`, "old\n");
  await Deno.writeTextFile(`${origin}/README`, "one\n");
  git(origin, "add", ".");
  git(origin, "commit", "-qm", "one");
  git(f.node, "clone", "-q", origin, checkout);
  await Deno.mkdir(`${checkout}/packages/old/node_modules`);
  await Deno.writeTextFile(`${checkout}/packages/old/node_modules/dep.js`, "stale\n");
  await Deno.writeTextFile(`${checkout}/.env`, "KEEP=1\n");
  git(origin, "rm", "-qr", "packages");
  await Deno.writeTextFile(`${origin}/README`, "two\n");
  git(origin, "commit", "-qam", "two");
  const target = git(origin, "rev-parse", "HEAD");
  git(checkout, "fetch", "-q", "origin");
  await f.ts(
    "ssh",
    `
import { shellSplit, passThrough, readStdinBytes } from ${JSON.stringify(TOOL)};
if (Deno.args.some(a => ["-M", "-O", "-N"].includes(a))) Deno.exit(0);
const argv = shellSplit(Deno.args.at(-1)!); if (argv.includes("fetch")) Deno.exit(0);
await passThrough(argv, await readStdinBytes());
`,
  );
  await f.workflow(NAME);
  await f.repo.cli(["vault", "create", "local_encryption", "orbit-control-inputs"]);
  const inputs: Data = {
    operation_id: 17, node_id: 9, kind: "instance.deploy",
    execution_secrets: {},
    intent: {
      production: {
        instance_id: 0, user: "", home: "", release: "", release_path: "", commit: null, branch: "", credential: "",
        shared: [], prepare: [], links: [], grants: [], steps: [], before: [], after: [], cleanup: [],
      },
      environment: {
        instance_id: 0, environment: "production", base: "", user: "", keys: [], owned_keys: [], sha256: "",
        testing: null,
      },
      process_members: [],
      runtime_definitions: { schedules: [], restart: [] },
      php_input: null, instance_id: 4, commit: null, application: checkout, vp_setup: null, account_home: f.node,
      ssh_execution: f.connection,
      deployment: {
        variant: "development", needs_vp: false, steps: [], node_user: f.user, branch: "main", repository: origin,
        home: checkout,
      },
    },
  };
  const command = async (command: string, required = true) => {
    const index = inputs.intent.deployment.steps.length, key = `development-deploy-operation-17-step-${index}`;
    inputs.intent.deployment.steps.push({
      name: `command-${index}`,
      phase: "before_activation", required, timeout_seconds: 5, command_digest: await sha256(command),
    });
    await f.repo.cli(["vault", "put", "orbit-control-inputs", key, command]);
    inputs.execution_secrets[`step_${index}`] = { vault: "orbit-control-inputs", key };
  };
  const run = async (success = true) => {
    inputs.execution_secrets.credential = {
      vault: "orbit-control-inputs", key: "development-deploy-operation-17-credential",
    };
    await f.repo.cli([
      "vault", "put", "orbit-control-inputs", "development-deploy-operation-17-credential",
      "https://x-access-token:unused@github.com",
    ]);
    return await f.run(NAME, inputs, success);
  };
  return { f, inputs, checkout, target, command, run };
}
Deno.test("development deploy cleans removed directories, preserves environment and hides private commands", async () => {
  const { f, checkout, target, command, run } = await fixture();
  await using _ = f;
  const sentinel = `native-private-${crypto.randomUUID()}`;
  f.repo.secrets = [sentinel];
  await command(`printf %s ${sentinel}; printf x >> .git/proof-marker`);
  await command(`printf %s ${sentinel}; exit 3`, false);
  await command("printf y >> .git/proof-marker");
  const { steps } = await run();
  assertEquals(git(checkout, "rev-parse", "HEAD"), target);
  assertEquals(git(checkout, "symbolic-ref", "--short", "HEAD"), "main");
  assertEquals(await Deno.readTextFile(`${checkout}/.git/proof-marker`), "xy");
  assertFalse(exists(`${checkout}/packages`));
  assert(exists(`${checkout}/.env`));
  assertEquals(steps["Check the checkout"].status, "succeeded");
});
Deno.test("tracked changes stop development deploy before any change", async () => {
  const { f, checkout, command, run } = await fixture();
  await using _ = f;
  await Deno.writeTextFile(`${checkout}/README`, "local edit\n");
  const before = git(checkout, "rev-parse", "HEAD");
  await command("printf x >> .git/proof-marker");
  const { steps } = await run(false);
  assertEquals(steps["Check tracked changes"].status, "failed");
  assertEquals(git(checkout, "rev-parse", "HEAD"), before);
  assertFalse(exists(`${checkout}/.git/proof-marker`));
});
Deno.test("a failed required development deploy command skips the rest", async () => {
  const { f, checkout, command, run } = await fixture();
  await using _ = f;
  await command("exit 4");
  await command("printf x >> .git/proof-marker");
  await run(false);
  assertFalse(exists(`${checkout}/.git/proof-marker`));
});
Deno.test("development deploy skips every production step", async () => {
  const { f, run } = await fixture();
  await using _ = f;
  const { steps } = await run();
  const names = [
    "Write release credential", "Remove release credential", "Read the release commit", "Check for foreign keys",
    "Activate release", "Swap current", "Refresh PHP cache", "Read current back", "Check the deployment",
  ];
  assertEquals(names.map((n) => steps[n].status), Array(9).fill("skipped"));
});
Deno.test("development deploy checks out when nothing needs cleaning", async () => {
  const { f, checkout, target, run } = await fixture();
  await using _ = f;
  await Deno.remove(`${checkout}/packages/old/node_modules`, { recursive: true });
  await run();
  assertEquals(git(checkout, "rev-parse", "HEAD"), target);
});
Deno.test("best effort timeout continues and a command cannot read its own rest", async () => {
  const { f, inputs, checkout, command, run } = await fixture();
  await using _ = f;
  await command("sleep 10", false);
  inputs.intent.deployment.steps[0].timeout_seconds = 1;
  await command("read -r line || true\nprintf x >> .git/proof-marker");
  const { run: record, steps } = await run();
  assertEquals(await Deno.readTextFile(`${checkout}/.git/proof-marker`), "x");
  assertEquals(steps["Run the deploy steps-0"].outputs.stdout.trim(), "124");
  assertFalse(exists(`/tmp/orbit-git-credential-${record.id}`));
});
Deno.test("a held checkout lock stops development deploy before checkout", async () => {
  const { f, checkout, run } = await fixture();
  await using _ = f;
  using handle = await Deno.open(checkout);
  await handle.lock(true);
  const before = git(checkout, "rev-parse", "HEAD");
  const { steps } = await run(false);
  assertEquals(steps["Check the checkout lock"].status, "failed");
  assertEquals(git(checkout, "rev-parse", "HEAD"), before);
});
