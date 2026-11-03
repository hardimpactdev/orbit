/** instance.register source readers on the native harness, through official SSH run locally. */
import { assertEquals, assertFalse, assertMatch, assertStringIncludes } from "jsr:@std/assert@1.0.19";
import { type NativeRepo, nativeRepo } from "../../../../tests/_support/native_harness.ts";

const tool = new URL("../../../../tests/_support/fake_tool.ts", import.meta.url).href;
const KEY = "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG";

/** Run the remote argv locally with the caller's stdin; sudo goes to the fake beside it. */
const SSH = (sudo: string) => `
import { shellSplit } from ${JSON.stringify(tool)};
const [program, ...args] = shellSplit(Deno.args.at(-1)!).map((part) => part.replaceAll("/usr/bin/sudo", ${JSON.stringify(sudo)}));
const child = new Deno.Command(program, { args, stdin: "inherit", stdout: "inherit", stderr: "inherit" }).spawn();
Deno.exit((await child.status).code);
`;

/** The official group behavior's own SSH, from PATH: runs its shell command locally, with sudo going to the fake. */
const PATH_SSH = (sudo: string) => `
import { passThrough, readStdinBytes } from ${JSON.stringify(tool)};
if (Deno.args.some((a) => ["-M", "-O", "-N", "-MNf"].includes(a))) Deno.exit(0);
const command = Deno.args.at(-1)!.replace(/(?<![\\w/])'?(?:[/]usr[/]bin[/])?sudo'?(?= )/g, ${JSON.stringify(sudo)});
await passThrough(["/bin/sh", "-c", command], await readStdinBytes());
`;

/** Drops sudo's options; records chgrp, which needs root, and runs anything else as is. */
const SUDO = (calls: string) => `
import { logCall, passThrough, readStdinBytes } from ${JSON.stringify(tool)};
let args = Deno.args;
while (args[0]?.startsWith("-") && args[0] !== "--") args = args.slice(["-u", "--user", "-g"].includes(args[0]) ? 2 : 1);
if (args[0] === "--") args = args.slice(1);
if (["chgrp", "groupadd"].includes(args[0]?.split("/").at(-1)!)) {
  logCall(${JSON.stringify(calls)}, [args[0].split("/").at(-1), ...args.slice(1)]);
  Deno.exit(0);
}
await passThrough(args, await readStdinBytes());
`;

type Step = { name: string; status: string; outputs?: { stdout?: string } };
type History = { jobs: { steps: Step[] }[] };

const git = (cwd: string, ...args: string[]) =>
  new TextDecoder().decode(new Deno.Command("git", { args: ["-C", cwd, ...args] }).outputSync().stdout).trim();

async function fixture() {
  const repo = await nativeRepo();
  repo.timeoutMs = 120_000;
  await repo.pull("@swamp/ssh");
  await repo.pull("@swamp/cfgmgmt");
  await repo.copyBehaviors("operations/instance-source-command", "operations/instance-lifecycle", "operations/tools");
  const calls = `${repo.dir}/calls.jsonl`;
  await Deno.mkdir(`${repo.dir}/tools`);
  const sudo = await repo.fakeBinary("tools/sudo", SUDO(calls));
  await repo.fakeBinary("tools/ssh", PATH_SSH(sudo));
  repo.env.PATH = `${repo.dir}/tools:${Deno.env.get("PATH")}`;
  const ssh = await repo.fakeBinary("ssh", SSH(sudo));
  const checkout = `${repo.dir}/checkout`;
  await Deno.mkdir(checkout);
  git(checkout, "init", "-q");
  git(checkout, "config", "user.email", "fixture@example.test");
  git(checkout, "config", "user.name", "Fixture");
  git(checkout, "remote", "add", "origin", "https://github.com/example/proof.git");
  await Deno.writeTextFile(`${checkout}/file`, "source\n");
  git(checkout, "add", "file");
  git(checkout, "commit", "-qm", "fixture");
  git(checkout, "branch", "-M", "main");
  await Deno.writeTextFile(`${checkout}/.env`, "PRIVATE_SENTINEL=must-not-leave\n", { mode: 0o644 });
  await Deno.chmod(`${checkout}/.env`, 0o644);
  await repo.workflow("operation-instance-register", (workflow) => {
    for (const step of workflow.jobs.flatMap((job) => job.steps)) {
      const task = step.task as { behaviorType?: string; globalArgs?: Record<string, unknown> };
      if (task.behaviorType === "@swamp/ssh") task.globalArgs!.sshBinary = ssh;
    }
  });
  const intent = {
    instance_id: 7,
    project_id: 8,
    checkout,
    application: checkout,
    repository_identity: "github.com/example/proof",
    project_type: "monorepo",
    php_versions: ["8.5"],
    branch: "main",
    kind: "instance.register",
    ssh_execution: {
      identity_file: "/fixture/identity",
      target: { host: "192.0.2.9", user: "orbit", port: 22, host_keys: [KEY] },
    },
  };
  return { repo, checkout, intent, calls };
}

async function register(repo: NativeRepo, intent: Record<string, unknown>, success = true) {
  const inputs = { operation_id: 7002, node_id: 9, kind: "instance.register", intent, execution_secrets: {} };
  const { run, history } = await repo.runWorkflow("operation-instance-register", inputs, { success });
  return { run, history: history as unknown as History };
}

const stdout = (history: History, name: string) =>
  history.jobs[0].steps.find((step) => step.name === name)!.outputs!.stdout!;
const mode = async (path: string) => (await Deno.stat(path)).mode! & 0o777;

Deno.test("register reads public facts and protects the environment file", async () => {
  const { repo, checkout, intent, calls } = await fixture();
  await using _ = repo;
  const { run, history } = await register(repo, intent);
  assertEquals(run.status, "succeeded");
  assertEquals(stdout(history, "Read the commit").trim(), git(checkout, "rev-parse", "HEAD"));
  assertEquals(stdout(history, "Read the Git top level").trim(), checkout);
  assertEquals(stdout(history, "Read the branch").trim(), "main");
  assertFalse(JSON.stringify(history).includes("PRIVATE_SENTINEL"));
  // Only the group change runs as root, and -h never follows a link; the account sets the mode.
  assertEquals(await mode(`${checkout}/.env`), 0o640);
  const chgrp = Deno.readTextFileSync(calls).split("\n").filter(Boolean).map((line) => JSON.parse(line))
    .filter((call) => call[0] === "chgrp");
  assertEquals(chgrp, [["chgrp", "-h", "orbit-env", "--", `${checkout}/.env`]]);
});

Deno.test("an authenticated origin is masked before it enters history", async () => {
  const { repo, checkout, intent } = await fixture();
  await using _ = repo;
  git(checkout, "remote", "set-url", "origin", "https://PRIVATE_ORIGIN_SENTINEL@github.com/example/proof.git");
  const { run, history } = await register(repo, intent);
  assertEquals(run.status, "succeeded");
  assertFalse(JSON.stringify(history).includes("PRIVATE_ORIGIN_SENTINEL"));
  assertStringIncludes(stdout(history, "Read the origin"), "REDACTED@github.com");
});

Deno.test("a missing checkout fails before the environment step", async () => {
  const { repo, checkout, intent } = await fixture();
  await using _ = repo;
  const { run, history } = await register(repo, { ...intent, checkout: `${repo.dir}/missing` }, false);
  assertEquals(run.status, "failed");
  assertEquals(history.jobs[0].steps.at(-1)!.status, "skipped");
  assertEquals(await mode(`${checkout}/.env`), 0o644);
});

Deno.test("register reads the entry point and composer.json", async () => {
  const { repo, checkout, intent } = await fixture();
  await using _ = repo;
  const composer = JSON.stringify({ require: { php: "^8.4", "laravel/framework": "^12.0" } });
  await Deno.writeTextFile(`${checkout}/composer.json`, composer);
  await Deno.writeTextFile(`${checkout}/artisan`, "#!/usr/bin/env php\n");
  git(checkout, "add", "composer.json", "artisan");
  git(checkout, "commit", "-qm", "laravel");
  const { run, history } = await register(repo, { ...intent, project_type: "laravel-app" });
  assertEquals(run.status, "succeeded");
  const [digest, entryPoint, ...rest] = stdout(history, "Read the checkout files").split("\n");
  assertMatch(digest, /^[a-f0-9]{64}$/);
  assertEquals([entryPoint, rest.join("\n").trim()], ["regular", composer]);
});
