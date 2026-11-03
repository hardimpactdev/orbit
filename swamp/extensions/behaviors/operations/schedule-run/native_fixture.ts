/**
 * The Schedule SSH fixture that the run and lifecycle native tests share.
 *
 * A fake `ssh` runs the official argv locally: it logs the argv, drops `sudo`, and swaps `systemd-cat`
 * and `systemd-run` for fixtures that keep the unit's working directory, timeout and journal.
 */
import { assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { nativeRepo, type NativeRepo, ROOT, type Run } from "../../../../tests/_support/native_harness.ts";
import { firstJson, random, sha256 } from "../process-commands/native_fixture.ts";

export const shebang = `#!/usr/bin/env -S ${Deno.execPath()} run -A --quiet --no-config --no-lock\n`;

export type Inputs = {
  operation_id?: number;
  node_id: number;
  kind: string;
  intent: {
    schedule: Record<string, unknown> & { id: string };
    ssh_execution: Record<string, unknown>;
    cascade?: boolean;
  };
  execution_secrets: Record<string, unknown>;
};

type Task = { behaviorType?: string; globalArgs?: Record<string, unknown> };

export async function id(flag: string) {
  const out = await new Deno.Command("id", { args: [flag], stdout: "piped" }).output();
  return new TextDecoder().decode(out.stdout).trim();
}

/** Copy a tracked workflow whose every job and step runs on the swamp role, editing each task. */
export async function placedWorkflow(repo: NativeRepo, name: string, edit: (task: Task) => void = () => {}) {
  const source = await Deno.readTextFile(`${ROOT}/workflows/workflow-${name}.yaml`);
  const parsed = parse(source) as { jobs: { labels?: unknown; steps: { labels?: unknown }[] }[] };
  for (const job of parsed.jobs) {
    assertEquals(job.labels, { role: "swamp" });
    for (const step of job.steps) assertEquals(step.labels, { role: "swamp" });
  }
  await repo.workflow(name, (workflow) => {
    for (const job of workflow.jobs) for (const step of job.steps) edit(step.task as Task);
  });
  return source;
}

export type ScheduleFixture = Awaited<ReturnType<typeof scheduleFixture>>;

export async function scheduleFixture() {
  const repo = await nativeRepo();
  await repo.copyBehaviors("operations/schedule-run");
  const contracts = `${repo.dir}/extensions/behaviors/operations/processes-schedules`;
  await Deno.mkdir(contracts, { recursive: true });
  for (const file of ["contract.ts", "unit-templates.json"]) {
    await Deno.copyFile(`${ROOT}/extensions/behaviors/operations/processes-schedules/${file}`, `${contracts}/${file}`);
  }
  await repo.pull("@swamp/ssh");

  const calls = `${repo.dir}/calls.jsonl`;
  const journal = `${repo.dir}/journal`;
  const write = (path: string, source: string) => Deno.writeTextFile(path, source, { mode: 0o755 });
  // systemd-cat: run the command with its output in the journal fixture, keeping stdin and the exit code.
  const logger = `${repo.dir}/journal-fixture`;
  await write(logger, `#!/bin/sh\nshift\nexec "$@" >>'${journal}' 2>&1\n`);
  // systemd-run: the unit's WorkingDirectory (~ is the home) and RuntimeMaxSec, then its command.
  const runner = `${repo.dir}/systemd-run-fixture`;
  await write(
    runner,
    shebang + `const args = [...Deno.args];
const props: Record<string, string> = {};
while (args[0]?.startsWith("-")) {
  if (args.shift() === "-p") {
    const value = args.shift()!;
    props[value.slice(0, value.indexOf("="))] = value.slice(value.indexOf("=") + 1);
  }
}
const directory = props.WorkingDirectory ?? "~";
const cwd = directory === "~" ? Deno.env.get("HOME")! : directory;
try {
  if (!Deno.statSync(cwd).isDirectory) Deno.exit(200);
} catch {
  Deno.exit(200);
}
const child = new Deno.Command(args[0], {
  args: args.slice(1),
  cwd,
  env: { SHELL: "/bin/bash" },
  stdin: "inherit",
  stdout: "inherit",
  stderr: "inherit",
}).spawn();
setTimeout(() => {
  child.kill();
  Deno.exit(1);
}, Number(props.RuntimeMaxSec ?? "90") * 1000);
Deno.exit((await child.status).code);
`,
  );
  const ssh = `${repo.dir}/ssh-fixture`;
  await write(
    ssh,
    shebang + `const args = Deno.args;
if (args.includes("-M") || args.includes("-O")) Deno.exit(0);
// The official model POSIX-quotes each argument; the shell splits them back.
const split = await new Deno.Command("/bin/sh", {
  args: ["-c", 'eval "set -- $1"; printf "%s\\\\0" "$@"', "sh", args.at(-1)!],
  stdout: "piped",
}).output();
let argv = new TextDecoder().decode(split.stdout).split("\\0").slice(0, -1);
Deno.writeTextFileSync(${JSON.stringify(calls)}, JSON.stringify(argv) + "\\n", { append: true });
if (argv[0] === "/usr/bin/sudo") argv = argv.slice(argv.indexOf("--") + 1);
const swap: Record<string, string> = {
  "/usr/bin/systemd-cat": ${JSON.stringify(logger)},
  "/usr/bin/systemd-run": ${JSON.stringify(runner)},
};
argv = argv.map((arg) => swap[arg] ?? arg);
const result = await new Deno.Command(argv[0], {
  args: argv.slice(1),
  stdin: "inherit",
  stdout: "inherit",
  stderr: "inherit",
}).output();
Deno.exit(result.code);
`,
  );
  const scp = `${repo.dir}/scp-fixture`;
  await write(
    scp,
    shebang + `const [source, target] = Deno.args.slice(-2);
const result = await new Deno.Command("cp", { args: ["-R", source, target.split(":").slice(1).join(":") + "/"] }).output();
Deno.exit(result.code);
`,
  );
  await Deno.writeTextFile(`${repo.dir}/existing-key`, "fixture identity not consumed");
  await placedWorkflow(repo, "task-command-run", (task) => {
    if (task.behaviorType === "@swamp/ssh") Object.assign(task.globalArgs!, { sshBinary: ssh, scpBinary: scp });
  });

  const inputs: Inputs = {
    operation_id: 17,
    node_id: 9,
    kind: "schedule.run",
    intent: {
      schedule: {
        id: "123e4567-e89b-42d3-a456-426614174000",
        user: await id("-un"),
        working_directory: repo.dir,
        shell: "",
        login_shell: false,
        timeout_seconds: 2,
        command_hash: "a".repeat(64),
      },
      ssh_execution: {
        control_node_id: 209,
        identity_file: `${repo.dir}/existing-key`,
        public_key: "ssh-ed25519 public-fixture",
        target: {
          name: "target",
          host: "198.51.100.9",
          user: "orbit",
          port: 22,
          host_keys: ["ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIJ111111111111111111111111111111111111111111"],
        },
      },
    },
    execution_secrets: { command: { vault: "orbit-control-inputs", key: "schedule-run-operation-17" } },
  };
  await repo.cli(["vault", "create", "local_encryption", "orbit-control-inputs"]);

  const setCommand = async (command: string) => {
    repo.secrets = [command];
    inputs.intent.schedule.command_hash = await sha256(command);
    await repo.cli(["vault", "put", "orbit-control-inputs", "schedule-run-operation-17"], { input: command });
  };
  await setCommand("printf schedule-journal-" + random());

  let lastRun: Run = { id: "", status: "" };
  const runWorkflow = async (name: string, value: unknown, success = true) => {
    const reply = await repo.cli(["workflow", "run", name, "--stdin"], { input: JSON.stringify(value), success });
    lastRun = firstJson(reply.stdout) as Run;
    return lastRun;
  };

  return {
    repo,
    inputs,
    calls,
    journal,
    ssh,
    scp,
    setCommand,
    runWorkflow,
    runSchedule: (success = true) => runWorkflow("task-command-run", inputs, success),
    lastRun: () => lastRun,
    history: async () => JSON.parse((await repo.cli(["workflow", "history", "get", lastRun.id])).stdout),
    async [Symbol.asyncDispose]() {
      await repo[Symbol.asyncDispose]();
    },
  };
}
