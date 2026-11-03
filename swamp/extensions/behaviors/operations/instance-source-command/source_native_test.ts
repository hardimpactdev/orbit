/** Guarded registration effects on the native harness, through official SSH run locally. */
import { assertEquals, assertFalse } from "jsr:@std/assert@1.0.19";
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
  await repo.copyBehaviors("operations/certificates");
  const calls = `${repo.dir}/calls.jsonl`;
  await Deno.mkdir(`${repo.dir}/tools`);
  const sudo = await repo.fakeBinary("tools/sudo", SUDO(calls));
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
  const copied = new Set<string>();
  async function workflow(name: string) {
    if (copied.has(name)) return;
    copied.add(name);
    const { parse } = await import("jsr:@std/yaml@1.0.12");
    const root = new URL("../../../../workflows/", import.meta.url);
    const flow = parse(await Deno.readTextFile(new URL(`workflow-${name}.yaml`, root))) as {
      jobs: { steps: { task?: { type?: string; workflowIdOrName?: string } }[] }[];
    };
    for (const step of flow.jobs.flatMap((job) => job.steps)) {
      if (step.task?.type === "workflow" && step.task.workflowIdOrName && !step.task.workflowIdOrName.includes("${{")) {
        await workflow(step.task.workflowIdOrName);
      }
    }
    await repo.workflow(name, (definition) => {
      for (const step of definition.jobs.flatMap((job) => job.steps)) {
        const task = step.task as { behaviorType?: string; globalArgs?: Record<string, unknown> };
        if (task.behaviorType === "@swamp/ssh") Object.assign(task.globalArgs!, { sshBinary: ssh, scpBinary: ssh });
      }
    });
  }
  await workflow("operation-process-create");
  await workflow("operation-process-start");
  await workflow("operation-instance-register");
  const intent = { checkout, application: checkout, user: "orbit", protect_environment: true, run_setup: false,
    setup_steps: [] as Record<string, unknown>[], processes: [], route: null, route_secrets: {},
    ssh_execution: { identity_file: "/fixture/identity", target: { host: "192.0.2.9", user: "orbit", port: 22, host_keys: [KEY] } } };
  return { repo, checkout, intent, calls };
}
async function register(repo: NativeRepo, intent: Record<string, unknown>, success = true) {
  const inputs = { operation_id: 7002, node_id: 9, kind: "instance.register", intent, execution_secrets: {} };
  const { run, history } = await repo.runWorkflow("operation-instance-register", inputs, { success });
  return { run, history: history as unknown as History };
}
const mode = async (path: string) => (await Deno.stat(path)).mode! & 0o777;
Deno.test("registration protects the user environment without reading source or making a group", async () => {
  const { repo, checkout, intent, calls } = await fixture();
  await using _ = repo;
  const commit = git(checkout, "rev-parse", "HEAD"), branch = git(checkout, "branch", "--show-current");
  const { history } = await register(repo, intent);
  assertEquals(await mode(checkout + "/.env"), 0o640);
  assertEquals(await Deno.readTextFile(checkout + "/.env"), "PRIVATE_SENTINEL=must-not-leave\n");
  assertEquals(git(checkout, "rev-parse", "HEAD"), commit);
  assertEquals(git(checkout, "branch", "--show-current"), branch);
  assertFalse(JSON.stringify(history).includes("must-not-leave"));
  const recorded = (await Deno.readTextFile(calls)).trim().split("\n").map((line) => JSON.parse(line));
  assertEquals(recorded, [["chgrp", "-h", "orbit-env", "--", checkout + "/.env"]]);
  assertEquals(history.jobs[0].steps.map((step) => step.name), ["Protect the .env", "Create the Route"]);
});
Deno.test("registration setup runs in order without changing a renamed worktree branch", async () => {
  const { repo, checkout, intent } = await fixture();
  await using _ = repo;
  const worktree = repo.dir + "/linked";
  git(checkout, "worktree", "add", "-q", "-b", "temporary", worktree);
  git(worktree, "branch", "-m", "renamed-feature");
  intent.checkout = intent.application = worktree;
  intent.protect_environment = false;
  intent.run_setup = true;
  intent.setup_steps = ["printf 'first\\n' > order; cp file .env", "printf 'second\\n' >> order"].map((command) => ({ command, directory: worktree, timeout_seconds: 20, instance_id: 42, instance_name: "linked", seed_path: "", seed_commit: "" }));
  const head = git(worktree, "rev-parse", "HEAD");
  await register(repo, intent);
  assertEquals(await Deno.readTextFile(worktree + "/order"), "first\nsecond\n");
  assertEquals(await mode(worktree + "/.env"), 0o600);
  assertEquals(git(worktree, "branch", "--show-current"), "renamed-feature");
  assertEquals(git(worktree, "rev-parse", "HEAD"), head);
});
Deno.test("failed registration setup skips later setup and publication", async () => {
  const { repo, checkout, intent } = await fixture();
  await using _ = repo;
  intent.protect_environment = false;
  intent.run_setup = true;
  intent.setup_steps = ["exit 7", "touch should-not-run"].map((command) => ({ command, directory: checkout, timeout_seconds: 20, instance_id: 42, instance_name: "fixture", seed_path: "", seed_commit: "" }));
  const { history } = await register(repo, intent, false);
  const statuses = Object.fromEntries(history.jobs[0].steps.map((step) => [step.name, step.status]));
  assertEquals(statuses["Run Project setup steps-0"], "failed");
  assertEquals(statuses["Run Project setup steps-1"], "skipped");
  assertEquals(statuses["Create the Route"], "skipped");
  await Deno.stat(checkout + "/should-not-run").then(() => { throw new Error("Later setup ran"); }, (error) => { if (!(error instanceof Deno.errors.NotFound)) throw error; });
});
Deno.test("registration guards every Node effect when none was requested", async () => {
  const { repo, checkout, intent, calls } = await fixture();
  await using _ = repo;
  intent.protect_environment = false;
  const { history } = await register(repo, intent);
  assertEquals(await mode(checkout + "/.env"), 0o644);
  assertEquals(history.jobs[0].steps.map((step) => step.status), ["skipped", "skipped"]);
  assertFalse(await Deno.stat(calls).then(() => true, () => false));
});

Deno.test("registration dispatches new Processes to create and existing containers to start", async () => {
  const { repo, intent } = await fixture();
  await using _ = repo;
  for (const [kind, processId] of [["create", 41], ["start", 42]] as const) {
    await repo.workflow(`operation-process-${kind}`, (definition) => {
      definition.inputs = { type: "object", additionalProperties: true };
      definition.jobs = definition.jobs.slice(0, 1);
      definition.jobs[0].steps = [{
        name: "Check member dispatch",
        task: { type: "assert", expr: `inputs.kind == "process.${kind}" && inputs.intent.control.process_id == ${processId}`,
          message: "Process was sent to the wrong lifecycle child" },
      }];
    });
  }
  const { history } = await register(repo, { ...intent, protect_environment: false, run_setup: true,
    processes: [
      { id: 41, kind: "process.create", intent: { control: { process_id: 41 } } },
      { id: 42, kind: "process.start", intent: { control: { process_id: 42 } } },
    ] });
  assertEquals(history.jobs[0].steps.filter((step) => step.name.startsWith("Create, start Processes-")).map((step) => step.status),
    ["succeeded", "succeeded"]);
});
