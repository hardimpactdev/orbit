/**
 * instance.transfer on the native harness with real disposable source and archive effects; no fleet publication.
 *
 * The fake `ssh` runs the remote argv locally and the fake `scp` copies locally, so the source checkout,
 * the archive and the destination are real directories inside the repo.
 */
import { assert, assertEquals, assertFalse } from "jsr:@std/assert@1.0.19";
import { type NativeRepo, nativeRepo, swampBinary } from "../../../../tests/_support/native_harness.ts";

const tool = new URL("../../../../tests/_support/fake_tool.ts", import.meta.url).href;
const KEY = "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG";
const NAME = "operation-instance-transfer";

/** Run the remote command locally with the caller's stdin. */
const SSH = `
import { shellSplit } from ${JSON.stringify(tool)};
if (Deno.args.includes("-M") || Deno.args.includes("-O")) Deno.exit(0);
const [program, ...args] = shellSplit(Deno.args.at(-1)!);
const child = new Deno.Command(program, { args, stdin: "inherit", stdout: "inherit", stderr: "inherit" }).spawn();
Deno.exit((await child.status).code);
`;

/** Copy a file or directory; a remote operand is \`user@host:'path'\`. */
const SCP = `
import { shellSplit } from ${JSON.stringify(tool)};
const local = (value: string) => value.includes("@") && value.includes(":") ? shellSplit(value.split(":").slice(1).join(":"))[0] : value;
const [source, destination] = Deno.args.slice(-2).map(local);
const copy = (from: string, to: string) => {
  if (Deno.statSync(from).isDirectory) {
    Deno.mkdirSync(to, { recursive: true });
    for (const entry of Deno.readDirSync(from)) copy(from + "/" + entry.name, to + "/" + entry.name);
  } else Deno.copyFileSync(from, to);
};
copy(source, Deno.statSync(source).isDirectory ? destination + "/" + source.split("/").at(-1) : destination);
`;

/** The cfgmgmt file behavior's PATH tools: \`ssh\` runs its command in a local shell, \`scp\` copies to the local path. */
const PATH_SSH = `
if (Deno.args.includes("-M") || Deno.args.includes("-O")) Deno.exit(0);
const child = new Deno.Command("/bin/sh", { args: ["-c", Deno.args.at(-1)!], stdin: "inherit", stdout: "inherit", stderr: "inherit" }).spawn();
Deno.exit((await child.status).code);
`;
const PATH_SCP = `
const [source, destination] = Deno.args.slice(-2);
Deno.copyFileSync(source, destination.split(":").slice(1).join(":"));
`;

type Step = { name: string; status: string; outputs?: { result?: { ready?: boolean } }; [key: string]: unknown };
type History = { jobs: { steps: Step[] }[] };
type Workflow = { jobs: { steps: Record<string, unknown>[] }[] };

const git = (cwd: string, ...args: string[]) => new Deno.Command("git", { args, cwd }).outputSync();
const exists = (path: string) => {
  try {
    Deno.statSync(path);
    return true;
  } catch {
    return false;
  }
};

/** Whether a Route child (Publish or Restore) succeeded with a ready result, as the Gateway reads it. */
const moved = (history: History, child: string) => {
  const step = history.jobs[0].steps.find((step) => step.name === child);
  return step?.status === "succeeded" && step.outputs?.result?.ready === true;
};

async function fixture(edit: (workflow: Workflow) => void = () => {}) {
  const repo = await nativeRepo();
  repo.timeoutMs = 120_000;
  await repo.pull("@swamp/ssh");
  await repo.pull("@swamp/cfgmgmt");
  // The official file and group behaviors run ssh, scp, chown, chgrp and groupadd from PATH; these fakes act locally.
  await Deno.mkdir(`${repo.dir}/tools`);
  await repo.fakeBinary("tools/ssh", PATH_SSH);
  await repo.fakeBinary("tools/scp", PATH_SCP);
  for (const name of ["chown", "chgrp", "groupadd"]) {
    await Deno.writeTextFile(`${repo.dir}/tools/${name}`, "#!/bin/sh\nexit 0\n", { mode: 0o755 });
  }
  // Writing .env with group orbit-env takes root: a fake sudo runs the command after "--" as is, with these fakes on PATH.
  await Deno.writeTextFile(`${repo.dir}/tools/sudo`, '#!/bin/sh\nwhile [ "$#" -gt 0 ] && [ "$1" != "--" ]; do shift; done\nshift\nexec "$@"\n', { mode: 0o755 });
  repo.env.PATH = `${repo.dir}/tools:${Deno.env.get("PATH")}`;
  await repo.cli(["vault", "create", "local_encryption", "orbit-control-inputs"]);
  await repo.cli(["vault", "put", "orbit-control-inputs", "environment", "RENDERED_SENTINEL=from-gateway\n"]);
  await repo.copyBehaviors(
    "operations/instance-source-command",
    "operations/instance-lifecycle",
    "operations/tools",
    "operations/routes/mutation-command",
    "operations/routes/publication-command",
    "operations/routes/update-command",
  );
  const ssh = await repo.fakeBinary("ssh", SSH);
  const scp = await repo.fakeBinary("scp", SCP);
  const source = `${repo.dir}/source`;
  await Deno.mkdir(source);
  git(source, "init", "-q");
  git(source, "config", "user.name", "Fixture");
  git(source, "config", "user.email", "fixture@example.test");
  git(source, "remote", "add", "origin", "https://github.com/example/project.git");
  await Deno.writeTextFile(`${source}/tracked`, "committed\n");
  git(source, "add", "tracked");
  git(source, "commit", "-qm", "fixture");
  await Deno.writeTextFile(`${source}/.env`, "PRIVATE_SENTINEL=retained\n");
  const destination = `${repo.dir}/destination`;
  await repo.workflow(NAME, (workflow) => {
    for (const step of workflow.jobs.flatMap((job) => job.steps)) {
      const task = step.task as { behaviorType?: string; globalArgs?: Record<string, unknown> };
      if (task.behaviorType === "@swamp/ssh") Object.assign(task.globalArgs!, { sshBinary: ssh, scpBinary: scp });
    }
    edit(workflow as Workflow);
  });
  for (const mode of ["forward", "restore"]) {
    await repo.workflow(`route-mutation-${mode}`, (workflow) => delete (workflow as { labels?: unknown }).labels);
  }
  const target = (host: string) => ({
    identity_file: "/fixture/identity",
    target: { host, port: 22, user: "orbit", host_keys: [KEY] },
  });
  const stage = Object.fromEntries(
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
  const intent = {
    instance_id: 8,
    transfer_id: "15427c8e-0a63-431a-84c7-835bf2730818",
    source_node_id: 9,
    destination_node_id: 13,
    source: {
      source,
      repository_identity: "github.com/example/project",
      environment_relative: ".env",
      ssh_execution: target("192.0.2.9"),
    },
    destination: {
      destination,
      root: repo.dir,
      application: destination,
      environment: `${destination}/.env`,
      domain: "",
      ssh_execution: target("192.0.2.13"),
    },
    processes: { source: [] as unknown[], destination: [] as unknown[] },
    setup_steps: [] as unknown[],
    workloads: [],
    route_mutations: { forward: stage, restore: stage },
  };
  return { repo, source, destination, intent };
}

const inputs = (intent: unknown) => ({
  operation_id: 7003,
  node_id: 9,
  kind: "instance.transfer",
  intent,
  execution_secrets: { environment: { vault: "orbit-control-inputs", key: "environment" } },
});

async function transfer(repo: NativeRepo, intent: unknown, success = true) {
  const { run, history } = await repo.runWorkflow(NAME, inputs(intent), { success });
  return { run, history: history as unknown as History };
}

Deno.test("transfer keeps private archive bytes out of results and retires only the source", async () => {
  const { repo, source, destination, intent } = await fixture();
  await using _ = repo;
  const connection = intent.source.ssh_execution;
  const control = {
    process_id: 8,
    runtime: "systemd",
    name: "orbit-process-8-vite.service",
    on_demand: true,
    directory: source,
    vite: { path: "/fixture/vite", instance_id: 8 },
  };
  const { process_id, runtime, name, on_demand } = control;
  intent.processes.source = [{
    id: 8,
    secret_field: "unused",
    intent: { control, ssh_execution: connection },
    control_intent: { control, ssh_execution: connection },
    stop_intent: { control: { process_id, runtime, name, on_demand, vite: null }, ssh_execution: connection },
  }];
  for (const action of ["control", "create", "destroy"]) {
    await repo.workflow(`operation-process-${action}`, (workflow) => {
      const child = workflow as Record<string, unknown>;
      delete child.target;
      delete child.labels;
      child.jobs = [{
        name: "fixture",
        steps: [{
          name: "accepted",
          task: {
            type: "behavior",
            behaviorType: "command/shell",
            behaviorName: '${{ "fixture-' + action + '-" + run.id }}',
            methodName: "execute",
            inputs: { run: "true" },
          },
        }],
      }];
    });
  }
  const { run, history } = await transfer(repo, intent);
  const steps = Object.fromEntries(history.jobs[0].steps.map((step) => [step.name, step.status]));
  assertEquals(steps["Stop source Processes-0"], "succeeded");
  assertEquals(steps["Remove source Processes-0"], "succeeded");
  assertEquals(run.status, "succeeded");
  assertFalse(exists(source));
  assert((await Deno.stat(`${destination}/.git`)).isDirectory);
  // The Gateway-rendered file, never the source's: the archive leaves the source .env out.
  assertEquals(await Deno.readTextFile(`${destination}/.env`), "RENDERED_SENTINEL=from-gateway\n");
  assertEquals((await Deno.stat(`${destination}/.env`)).mode! & 0o777, 0o640);
  assertFalse(JSON.stringify(history).includes("PRIVATE_SENTINEL"));
  assertFalse(JSON.stringify(history).includes("from-gateway"));
  assert(moved(history, "Publish the Route"));
  assertFalse(moved(history, "Restore the Route"));
});

Deno.test("setup steps run in the destination and a failure restores the source", async () => {
  const { repo, source, destination, intent } = await fixture();
  await using _ = repo;
  const step = (index: number, name: string, command: string) => ({
    index,
    name,
    command,
    timeout_seconds: 30,
    directory: destination,
    instance_id: 8,
    instance_name: "moved",
  });
  intent.setup_steps = [step(0, "mark", "touch setup-ran"), step(1, "fail", "exit 7")];
  const { run, history } = await transfer(repo, intent, false);
  const steps = Object.fromEntries(history.jobs[0].steps.map((step) => [step.name, step.status]));
  assertEquals(run.status, "failed");
  assertEquals(steps["Run Project setup steps-0"], "succeeded");
  assertEquals(steps["Run Project setup steps-1"], "failed");
  assertEquals(steps["Publish the Route"], "skipped");
  assert(moved(history, "Restore the Route"));
  assert(exists(source));
  assertFalse(exists(destination));
});

Deno.test("a retirement failure keeps the cutover result and the source residue", async () => {
  const { repo, source, destination, intent } = await fixture((workflow) => {
    const step = workflow.jobs[0].steps.find((step) => step.name === "Retire the source")!;
    const task = step.task as Record<string, unknown>;
    task.methodName = "exec";
    task.inputs = { hosts: "name:target", argv: ["/usr/bin/false"] };
  });
  await using _ = repo;
  const { run, history } = await transfer(repo, intent, false);
  assertEquals(run.status, "failed");
  assert(exists(source));
  assert(exists(destination));
  assert(moved(history, "Publish the Route"));
});

Deno.test("a native cancel after the forward Route keeps the terminal placement receipt", async () => {
  const barrier = `${Deno.makeTempDirSync({ prefix: "orbit-transfer-barrier-" })}/ready`;
  const { repo, destination, intent } = await fixture((workflow) => {
    const steps = workflow.jobs[0].steps;
    const index = steps.findIndex((step) => step.name === "Remove source Processes");
    const receipt = steps[index];
    steps.splice(index, 0, {
      name: "cancel-barrier",
      dependsOn: receipt.dependsOn,
      task: {
        type: "behavior",
        behaviorType: "command/shell",
        behaviorName: '${{ "cancel-barrier-" + run.id }}',
        methodName: "execute",
        inputs: { run: 'touch "$BARRIER" && sleep 300', env: { BARRIER: barrier } },
      },
    });
    receipt.dependsOn = [{ step: "cancel-barrier", condition: { type: "always" } }];
  });
  await using _ = repo;
  const args = ["workflow", "run", NAME];
  for (const [key, value] of Object.entries(inputs(intent))) args.push("--input", `${key}=${JSON.stringify(value)}`);
  const child = await repo.spawn(args);
  try {
    const deadline = Date.now() + 90_000;
    while (!exists(barrier)) {
      assert(Date.now() < deadline, "native forward did not reach the cancellation barrier");
      await new Promise((resolve) => setTimeout(resolve, 200));
    }
    child.kill("SIGINT");
    const output = await child.output();
    const stdout = new TextDecoder().decode(output.stdout);
    const run = JSON.parse(stdout) as { id: string; status: string };
    assertEquals(run.status, "cancelled", new TextDecoder().decode(output.stderr));
    const history = JSON.parse((await repo.cli(["workflow", "history", "get", run.id])).stdout) as History;
    assert(moved(history, "Publish the Route"));
    assert(exists(destination));
  } finally {
    try {
      child.kill("SIGKILL");
    } catch {
      // Already exited.
    }
    await Deno.remove(barrier.replace(/\/ready$/, ""), { recursive: true });
  }
});
