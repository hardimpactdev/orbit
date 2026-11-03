/**
 * instance.env_import on the native harness: official SSH stages and copies .env, the store behavior puts it in the vault.
 *
 * The fake `ssh` runs the remote argv locally (dropping `sudo … --`), and the fake `scp` copies locally.
 */
import { assert, assertEquals, assertStringIncludes } from "jsr:@std/assert@1.0.19";
import { nativeRepo } from "../../../../tests/_support/native_harness.ts";

const tool = new URL("../../../../tests/_support/fake_tool.ts", import.meta.url).href;
const NAME = "operation-instance-env-import";
const KEY = "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIJ111111111111111111111111111111111111111111";

const SSH = `
import { shellSplit } from ${JSON.stringify(tool)};
if (Deno.args.includes("-M") || Deno.args.includes("-O")) Deno.exit(0);
let argv = shellSplit(Deno.args.at(-1)!);
if (argv[0] === "sudo" || argv[0] === "/usr/bin/sudo") argv = argv.slice(argv.indexOf("--") + 1);
const child = new Deno.Command(argv[0], { args: argv.slice(1), stdin: "inherit", stdout: "inherit", stderr: "inherit" }).spawn();
Deno.exit((await child.status).code);
`;
const SCP = `
const local = (value: string) => value.includes("@") && value.includes(":") ? value.split(":").slice(1).join(":") : value;
const [source, destination] = Deno.args.slice(-2).map(local);
Deno.copyFileSync(source, destination);
`;

type Step = { name: string; status: string; outputs?: { size?: number }; [key: string]: unknown };

async function fixture() {
  const repo = await nativeRepo();
  repo.timeoutMs = 120_000;
  await repo.pull("@swamp/ssh");
  await repo.copyBehaviors("operations/instance-env-import");
  const base = await Deno.makeTempDir({ prefix: "orbit-env-import-node-" });
  const ssh = await repo.fakeBinary("ssh", SSH);
  const scp = await repo.fakeBinary("scp", SCP);
  await repo.workflow(NAME, (workflow) => {
    for (const step of workflow.jobs.flatMap((job) => job.steps)) {
      const task = step.task as { behaviorType?: string; globalArgs?: Record<string, unknown> };
      if (task.behaviorType === "@swamp/ssh") Object.assign(task.globalArgs!, { sshBinary: ssh, scpBinary: scp });
    }
  });
  await Deno.writeTextFile(`${repo.dir}/existing-key`, "fixture identity not consumed");
  const secret = `private-import-${crypto.randomUUID()}`;
  repo.secrets = [secret];
  await repo.cli(["vault", "create", "local_encryption", "orbit-control-inputs"]);
  const user = Deno.env.get("USER") ??
    new TextDecoder().decode(new Deno.Command("id", { args: ["-un"] }).outputSync().stdout).trim();
  const inputs = {
    operation_id: 41,
    node_id: 9,
    kind: "instance.env_import",
    execution_secrets: {},
    intent: {
      base,
      user,
      ssh_execution: {
        identity_file: `${repo.dir}/existing-key`,
        target: { host: "198.51.100.9", user, port: 22, host_keys: [KEY] },
      },
    },
  };
  const run = async (success = true) => {
    const { run, history } = await repo.runWorkflow(NAME, inputs, { success });
    const steps: Record<string, Step> = {};
    for (const step of (history as unknown as { jobs: { steps: Step[] }[] }).jobs.flatMap((job) => job.steps)) {
      steps[step.name] = step;
    }
    return { run, steps };
  };
  return {
    repo,
    base,
    secret,
    run,
    async [Symbol.asyncDispose]() {
      await Deno.remove(base, { recursive: true });
      await repo[Symbol.asyncDispose]();
    },
  };
}

Deno.test("the file reaches the vault and only its size and hash come back", async () => {
  await using f = await fixture();
  const content = `APP_KEY="${f.secret}"\n`;
  await Deno.writeTextFile(`${f.base}/.env`, content);
  const { run, steps } = await f.run();
  assertEquals(run.status, "succeeded");
  assertEquals(steps["Read .env into the vault"].outputs?.size, content.length);
  const keys = JSON.parse((await f.repo.cli(["vault", "list-keys", "orbit-control-inputs"])).stdout).secretKeys;
  assert(keys.includes("instance-env-import.content.41"));
  assertEquals(steps["Remove the staged copy"].status, "succeeded");
  for await (const entry of Deno.readDir("/tmp")) {
    assert(!entry.name.startsWith(`orbit-env-import-${run.id}`), entry.name);
  }
  assertEquals(await Deno.readTextFile(`${f.base}/.env`), content);
});

Deno.test("a missing or linked file is refused before any copy", async () => {
  await using f = await fixture();
  await f.run(false);
  await Deno.writeTextFile(`${f.base}/elsewhere`, "A=1\n");
  await Deno.symlink(`${f.base}/elsewhere`, `${f.base}/.env`);
  const { steps } = await f.run(false);
  assertEquals(steps["Stage .env for the copy"].status, "failed");
  assertStringIncludes(JSON.stringify(steps["Stage .env for the copy"]), "env.import_source_missing");
});
