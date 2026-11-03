/** Disposable Git workspace and local SSH/SCP boundaries for the Tasks repository DAGs. */
import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { parse, stringify } from "jsr:@std/yaml@1.0.12";
import { nativeRepo, ROOT, type Run } from "./native_harness.ts";

// deno-lint-ignore no-explicit-any
export type Json = Record<string, any>;
export { ROOT };
export const tool = new URL("./fake_tool.ts", import.meta.url).href;
export const hostKey = "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAINqVuK+N4gL/sDvGNKnGrYhEtEFnZx9hvH+134PsQgND";
export const sha256 = async (value: string | Uint8Array) =>
  Array.from(
    new Uint8Array(
      await crypto.subtle.digest(
        "SHA-256",
        typeof value === "string" ? new TextEncoder().encode(value) : value as Uint8Array<ArrayBuffer>,
      ),
    ),
    (b) => b.toString(16).padStart(2, "0"),
  ).join("");
export const exists = (path: string) => Deno.stat(path).then(() => true, () => false);
export const text = (path: string) => Deno.readTextFile(path);
export const write = (path: string, value: string) => Deno.writeTextFile(path, value);
export function decode(stdout: string): Run {
  for (let i = stdout.indexOf("{"); i >= 0; i = stdout.indexOf("{", i + 1)) {
    try {
      const value = JSON.parse(stdout.slice(i));
      if (value.id && value.status) return value;
    } catch { /* public notice */ }
  }
  throw new Error(`Native run missing: ${stdout}`);
}
export const rows = (history: Json): Json =>
  Object.fromEntries(
    history.jobs.flatMap((j: Json) => j.steps).map((
      s: Json,
    ) => [s.name, s]),
  );
export const childSteps = (history: Json): Json => {
  const result = rows(history);
  for (const step of Object.values(result) as Json[]) {
    for (const [name, outputs] of Object.entries(step.outputs ?? {})) {
      if (
        outputs && typeof outputs === "object" &&
        !Array.isArray(outputs)
      ) result[name] = { name, status: "succeeded", outputs };
    }
  }
  return result;
};
export const checkResult = (history: Json) => {
  const steps = rows(history);
  return JSON.parse(
    steps.collect ? steps.collect.outputs.stdout : steps["Push the candidate"].outputs.finish.result_json,
  );
};
export async function git(root: string, ...args: string[]) {
  const out = await new Deno.Command("git", {
    args: ["-C", root, ...args],
    stdout: "piped",
    stderr: "piped",
  }).output();
  assert(out.success, new TextDecoder().decode(out.stderr));
  return new TextDecoder().decode(out.stdout).trim();
}
export const sshSource = `import {shellSplit,readStdinBytes,passThrough} from ${JSON.stringify(tool)};
if(Deno.args.includes("-M")||Deno.args.includes("-O")) Deno.exit(0);
await passThrough(shellSplit(Deno.args.at(-1)),await readStdinBytes());`;
const scpSource = `import {copy} from "jsr:@std/fs@1.0.21";
const local=v=>v.includes(":")?v.split(":").slice(1).join(":"):v;
const src=local(Deno.args.at(-2)),dst=local(Deno.args.at(-1));
const target=(await Deno.stat(dst).catch(()=>null))?.isDirectory?dst+"/"+src.split("/").at(-1):dst;
await copy(src,target,{overwrite:true});`;
export async function repository() {
  const repo = await nativeRepo();
  repo.timeoutMs = 90_000;
  await repo.copyBehaviors("operations/task-repository");
  for (
    const relative of [
      "operations/tasks/publish.ts",
      "operations/tasks/check.ts",
      "_lib/repository_analysis.ts",
      "_lib/public_output.ts",
      "operations/instance-deploy/app_step_runtime.ts",
      "operations/instance-deploy/project_environment.ts",
    ]
  ) {
    const target = `${repo.dir}/extensions/behaviors/${relative}`;
    await Deno.mkdir(target.slice(0, target.lastIndexOf("/")), {
      recursive: true,
    });
    await Deno.copyFile(`${ROOT}/extensions/behaviors/${relative}`, target);
  }
  await repo.pull("@swamp/ssh");
  const workspace = `${repo.dir}/workspace`;
  await Deno.mkdir(workspace);
  const g = (...args: string[]) => git(workspace, ...args);
  await g("init", "-qb", "task-1");
  await g("config", "user.email", "test@example.test");
  await g("config", "user.name", "Test");
  await g("remote", "add", "origin", "https://github.com/acme/test.git");
  await Deno.mkdir(`${repo.dir}/tools`);
  await repo.fakeBinary("tools/ssh", sshSource);
  await repo.fakeBinary("tools/scp", scpSource);
  repo.env.PATH = `${repo.dir}/tools:${Deno.env.get("PATH")}`;
  const inputs: Json = {
    operation_id: 91001,
    node_id: 3,
    execution_secrets: {},
    intent: {
      group_id: 1,
      instance_id: 2,
      node_id: 3,
      subtask: 4,
      checkout_path: workspace,
      ssr_port: null,
      ssh_execution: {
        target: {
          user: "orbit",
          port: 22,
          host: "192.0.2.3",
          host_keys: [hostKey],
        },
        identity_file: "/dev/null",
      },
    },
  };
  const scratch = async (prefix: string) => {
    const names = [];
    for await (const e of Deno.readDir(repo.dir)) {
      if (e.name.startsWith(prefix)) names.push(e.name);
    }
    return names.sort();
  };
  const flow = async (
    name: string,
    edit: (value: Json) => void = () => {},
  ) => {
    const tracked = parse(
      await text(`${ROOT}/workflows/workflow-${name}.yaml`),
    ) as Json;
    for (const job of tracked.jobs) {
      assertEquals(job.labels, { role: "swamp" });
      for (const step of job.steps) {
        assertEquals(step.labels, { role: "swamp" });
      }
    }
    return await repo.workflow(name, (value) => edit(value));
  };
  const run = async (
    name: string,
    envelope: Json = inputs,
    success = true,
  ) => {
    const result = decode(
      (await repo.cli(["workflow", "run", name, "--stdin"], {
        input: JSON.stringify(envelope),
        success,
      })).stdout,
    );
    const { history } = await repo.history(result.id);
    return {
      run: result,
      history: history as Json,
      steps: rows(history),
      result: rows(history).shape ? JSON.parse(rows(history).shape.outputs.result_json) : undefined,
    };
  };
  return {
    repo,
    workspace,
    inputs,
    git: g,
    flow,
    run,
    scratch,
    async install(source: string, path = "scripts/run") {
      await Deno.mkdir(
        `${workspace}/${path.slice(0, path.lastIndexOf("/"))}`,
        { recursive: true },
      );
      await repo.fakeBinary(`workspace/${path}`, source);
      await g("add", ".");
      await g("commit", "-qm", "fixture");
    },
    async analysisIntent() {
      const head = await g("rev-parse", "HEAD");
      Object.assign(inputs.intent, {
        plan: { id: "test", revision: 1 },
        plan_sha256: "b".repeat(64),
        base: head,
        head,
        tree: await g("rev-parse", "HEAD^{tree}"),
        branch: "task-1",
        repository: "https://github.com/acme/test.git",
      });
    },
    async script(
      kind: string,
      success = true,
      edit: (v: Json) => void = () => {},
    ) {
      inputs.kind = `tasks.${kind}`;
      await flow(`operation-task-${kind}`, edit);
      await repo.cli(["workflow", "validate", `operation-task-${kind}`]);
      return run(`operation-task-${kind}`, inputs, success);
    },
    [Symbol.asyncDispose]: () => repo[Symbol.asyncDispose](),
  };
}
export type Repository = Awaited<ReturnType<typeof repository>>;
export async function copyCheck(
  test: Repository,
  name = "operation-task-check",
) {
  const rewrite = (v: Json) =>
    JSON.parse(
      JSON.stringify(v).replaceAll(
        "/tmp/orbit-task-check-",
        `${test.repo.dir}/check-`,
      ),
    );
  await test.flow("operation-task-check", (flow) => {
    Object.assign(flow, rewrite(flow));
    flow.name = name;
  });
  for await (const e of Deno.readDir(`${ROOT}/workflows`)) {
    if (
      e.name.startsWith("workflow-task-check-") &&
      e.name.endsWith(".yaml") && e.name !== "workflow-task-check-ci.yaml"
    ) {
      await test.flow(
        e.name.slice(9, -5),
        (flow) => Object.assign(flow, rewrite(flow)),
      );
    }
  }
}
export async function checkFixture() {
  const test = await repository();
  const { inputs, workspace } = test;
  inputs.kind = "tasks.check";
  await write(`${workspace}/code`, "old\n");
  await test.git("add", ".");
  await test.git("commit", "-qm", "Base");
  Object.assign(inputs.intent, {
    check_id: 5,
    phase: "baseline",
    command: "test -f code",
    setup: [],
    deliverables: [],
    base: await test.git("rev-parse", "HEAD"),
    message: "Check",
    repository: null,
    check_name: null,
    branch: "task-1-handoff",
    admitted_at: "2026-10-31T22:00:00Z",
  });
  return Object.assign(test, {
    async check(success = true, hasResult = true) {
      inputs.intent.command_deliverables = inputs.intent.deliverables
        .filter((x: Json) => x.type === "command");
      inputs.intent.execution_references_json = JSON.stringify(
        inputs.execution_secrets,
      );
      await copyCheck(test);
      const result = await test.run(
        "operation-task-check",
        inputs,
        success,
      );
      return {
        ...result,
        steps: childSteps(result.history),
        result: hasResult ? checkResult(result.history) : null,
      };
    },
  });
}
export const command = (extra: Json = {}) => ({
  id: "test",
  type: "command",
  command: "true",
  directory: ".",
  fails_on_base: true,
  paths: [],
  ...extra,
});
export const yamlWrite = (path: string, value: unknown) => write(path, stringify(value));
