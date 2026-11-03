import { assertEquals } from "jsr:@std/assert@1.0.19";
import { stringify } from "jsr:@std/yaml@1.0.12";
import { type History, nativeRepo, ROOT } from "./native_harness.ts";
import { readCalls } from "./fake_tool.ts";

// deno-lint-ignore no-explicit-any
export type Json = Record<string, any>;
const tool = new URL("./fake_tool.ts", import.meta.url).href;
export async function command(argv: string[], cwd?: string, env?: Record<string, string>, success = true) {
  const result = await new Deno.Command(argv[0], {
    args: argv.slice(1), cwd, env, stdout: "piped", stderr: "piped",
  }).output();
  const stdout = new TextDecoder().decode(result.stdout), stderr = new TextDecoder().decode(result.stderr);
  assertEquals(result.success, success, stdout + stderr);
  return { stdout, stderr };
}
export const outputs = (history: History, name: string): Json =>
  history.jobs.flatMap((job) => job.steps).find((step) => step.name === name)!.outputs!;
export const result = (history: History, name = "shape") => JSON.parse(outputs(history, name).result_json);
export function rewrite<T>(value: T, from: string, to: string): T {
  return JSON.parse(JSON.stringify(value).replaceAll(from, to));
}

export async function taskRemote() {
  const repo = await nativeRepo();
  repo.timeoutMs = 150_000;
  await repo.pull("@swamp/ssh");
  await repo.copyBehaviors("operations/task-repository");
  for (const name of [
    "operations/tasks/publish.ts", "operations/tasks/check.ts", "_lib/repository_analysis.ts", "_lib/public_output.ts",
    "operations/instance-deploy/app_step_runtime.ts", "operations/instance-deploy/project_environment.ts",
  ]) {
    const target = `${repo.dir}/extensions/behaviors/${name}`;
    await Deno.mkdir(target.slice(0, target.lastIndexOf("/")), { recursive: true });
    await Deno.copyFile(`${ROOT}/extensions/behaviors/${name}`, target);
  }
  const workspace = `${repo.dir}/workspace`;
  await Deno.mkdir(workspace);
  const git = async (...args: string[]) => (await command(["git", "-C", workspace, ...args])).stdout.trim();
  await git("init", "-qb", "task-1");
  await git("config", "user.email", "test@example.test");
  await git("config", "user.name", "Test");
  await git("remote", "add", "origin", "https://github.com/acme/test.git");
  const calls = `${repo.dir}/ssh.jsonl`;
  await repo.fakeBinary("ssh", `
import { logCall, passThrough, readStdinBytes, shellSplit } from ${JSON.stringify(tool)};
if (Deno.args.includes("-M") || Deno.args.includes("-O")) Deno.exit(0);
const argv = shellSplit(Deno.args.at(-1));
logCall(${JSON.stringify(calls)}, argv);
await passThrough(argv, await readStdinBytes());
`);
  await repo.fakeBinary("scp", `
const path = (value) => value.replace(/^[^:]+:/, "");
const src = path(Deno.args.at(-2)), dst = path(Deno.args.at(-1));
const exists = (p) => { try { return Deno.statSync(p); } catch { return null; } };
async function copy(from, to) {
  if (Deno.statSync(from).isDirectory) {
    await Deno.mkdir(to, { recursive: true });
    for await (const e of Deno.readDir(from)) await copy(from+"/"+e.name,to+"/"+e.name);
  } else await Deno.copyFile(from, to);
}
await copy(src, exists(dst)?.isDirectory ? dst.replace(/\\/$/, "")+"/"+src.split("/").at(-1) : dst);
`);
  repo.env.PATH = `${repo.dir}:${Deno.env.get("PATH")}`;
  const inputs: Json = {
    operation_id: 91001, node_id: 3, execution_secrets: {},
    intent: {
      group_id: 1, instance_id: 2, node_id: 3, subtask: 4, checkout_path: workspace, ssr_port: null,
      ssh_execution: {
        identity_file: "/dev/null", target: {
          user: "orbit", port: 22, host: "192.0.2.3",
          host_keys: ["ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAINqVuK+N4gL/sDvGNKnGrYhEtEFnZx9hvH+134PsQgND"],
        },
      },
    },
  };
  let flow: Json;
  let workflowPath = "";
  async function workflow(kind: string) {
    if (workflowPath) await Deno.remove(workflowPath);
    workflowPath = `${repo.dir}/workflows/workflow-operation-task-${kind}.yaml`;
    inputs.kind = `tasks.${kind}`;
    flow = await repo.workflow(`operation-task-${kind}`) as Json;
    flow.name = "task-fixture";
    await save();
  }
  async function save() {
    // Keep all run assets inside the disposable repo so private-byte scans include them.
    flow = rewrite(flow, "/tmp/orbit-task-repository-", `${repo.dir}/assets-`);
    flow = rewrite(flow, "/tmp/orbit-task-turn-local-", `${repo.dir}/local-`);
    flow = rewrite(flow, "/tmp/orbit-task-turn-", `${repo.dir}/remote-`);
    await Deno.writeTextFile(workflowPath, stringify(flow));
  }
  async function run(success = true) {
    await repo.cli(["workflow", "validate", "task-fixture"]);
    return await repo.runWorkflow("task-fixture", inputs, { success });
  }
  async function install(source: string, path = "scripts/run") {
    const target = `${workspace}/${path}`;
    await Deno.mkdir(target.slice(0, target.lastIndexOf("/")), { recursive: true });
    await Deno.writeTextFile(target, source, { mode: 0o755 });
    await git("add", ".");
    await git("commit", "-qm", "fixture");
  }
  function analysisIntent() {
    return Promise.all([git("rev-parse", "HEAD"), git("rev-parse", "HEAD^{tree}")]).then(([head, tree]) => {
      Object.assign(inputs.intent, {
        plan: { id: "test", revision: 1 }, plan_sha256: "b".repeat(64), base: head, head, tree,
        branch: "task-1", repository: "https://github.com/acme/test.git",
      });
    });
  }
  return {
    repo, workspace, inputs, git, workflow, save, run, install, analysisIntent,
    get flow() { return flow; },
    step: (name: string): Json => flow.jobs.flatMap((job: Json) => job.steps).find((step: Json) => step.name === name),
    calls: () => readCalls<string[]>(calls),
    [Symbol.asyncDispose]: () => repo[Symbol.asyncDispose](),
  };
}
export type Remote = Awaited<ReturnType<typeof taskRemote>>;
