/**
 * The Process host fixture that Process and Horizon native tests share.
 *
 * Fake tools on PATH stand in for the Node: `ssh` runs the command locally against a host directory,
 * `systemctl` keeps its state in a JSON file. The host directory sits outside the repo, so the harness
 * can scan every repo file for private values.
 */
import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { nativeRepo, ROOT } from "../../../../tests/_support/native_harness.ts";

const Kinds = ["create", "control", "start", "stop", "destroy"] as const;
const shebang = `#!/usr/bin/env -S ${Deno.execPath()} run -A --quiet --no-config --no-lock\n`;

async function id(flag: string) {
  const out = await new Deno.Command("id", { args: [flag], stdout: "piped" }).output();
  return new TextDecoder().decode(out.stdout).trim();
}

export const sha256 = async (text: string) =>
  Array.from(
    new Uint8Array(await crypto.subtle.digest("SHA-256", new TextEncoder().encode(text))),
    (byte) => byte.toString(16).padStart(2, "0"),
  ).join("");

export const random = () => Array.from(crypto.getRandomValues(new Uint8Array(12)), (b) => b.toString(16).padStart(2, "0")).join("");

export type ProcessFixture = Awaited<ReturnType<typeof processFixture>>;

export async function processFixture() {
  const repo = await nativeRepo();
  repo.timeoutMs = 120_000;
  await repo.copyBehaviors("operations/process-commands");
  const contracts = `${repo.dir}/extensions/behaviors/operations/processes-schedules`;
  await Deno.mkdir(contracts, { recursive: true });
  for (const file of ["process-input.ts", "installation-input.ts", "required-mount-paths.ts", "container-input.ts"]) {
    await Deno.copyFile(`${ROOT}/extensions/behaviors/operations/processes-schedules/${file}`, `${contracts}/${file}`);
  }
  await repo.pull("@swamp/ssh");
  await repo.pull("@swamp/cfgmgmt");

  const host = await Deno.makeTempDir({ prefix: "orbit-process-host-" });
  await Deno.mkdir(`${host}/units`);
  const statePath = `${host}/state.json`;
  const tools = `${repo.dir}/tools`;
  await Deno.mkdir(tools);
  repo.env.PATH = `${tools}:${Deno.env.get("PATH")}`;
  const tool = async (name: string, source: string) =>
    await Deno.writeTextFile(`${tools}/${name}`, source, { mode: 0o700 });
  const ts = async (name: string, source: string) => await tool(name, shebang + source);

  await tool("chown", "#!/bin/sh\nexit 0\n");
  await tool("chgrp", "#!/bin/sh\nexit 0\n");
  await ts(
    "ssh",
    `const argv = Deno.args;
if (argv.includes("-M") || argv.includes("-O")) Deno.exit(0);
const command = argv.at(-1)!.replaceAll("/etc/systemd/system", ${JSON.stringify(`${host}/units`)})
  .replaceAll("/etc/orbit", ${JSON.stringify(`${host}/orbit`)});
const result = await new Deno.Command("/bin/sh", { args: ["-c", command] }).output();
Deno.stdout.writeSync(result.stdout);
Deno.stderr.writeSync(result.stderr);
Deno.exit(result.code);
`,
  );
  await ts("scp", `Deno.copyFileSync(Deno.args.at(-2)!, Deno.args.at(-1)!.split(":").slice(1).join(":"));\n`);
  await ts(
    "sudo",
    `const args = Deno.args.slice(Deno.args.indexOf("--") + 1);
const result = await new Deno.Command(args[0], { args: args.slice(1), stdin: "inherit", stdout: "inherit", stderr: "inherit" }).output();
Deno.exit(result.code);
`,
  );
  await ts(
    "systemctl",
    `// systemd serializes host requests; parallel child runs share this state, so serialize too.
const lock = Deno.openSync(${JSON.stringify(`${host}/systemd.lock`)}, { create: true, write: true });
lock.lockSync(true);
const path = ${JSON.stringify(statePath)};
const state = JSON.parse(Deno.readTextFileSync(path));
const [action, unit = ""] = Deno.args;
state.calls.push(Deno.args);
const current = state.units[unit] ??= { active: "inactive", enabled: false };
const failed = state.failures.length > 0 && state.failures[0] === action;
if (failed) state.failures.shift();
else if (action === "enable" || action === "disable") current.enabled = action === "enable";
else if (["start", "restart", "stop"].includes(action)) current.active = action === "stop" ? "inactive" : "active";
Deno.writeTextFileSync(path, JSON.stringify(state));
if (failed) Deno.exit(1);
if (action === "show") {
  let loaded = true;
  try { Deno.statSync(${JSON.stringify(`${host}/units`)} + "/" + unit); } catch { loaded = false; }
  console.log("LoadState=" + (loaded ? "loaded" : "not-found"));
  console.log("ActiveState=" + current.active);
  console.log("UnitFileState=" + (current.enabled ? "enabled" : "disabled"));
}
`,
  );

  const user = await id("-un");
  const group = await id("-gn");
  const names: Record<string, string> = {};
  for (const kind of Kinds) {
    const source = parse(await Deno.readTextFile(`${ROOT}/workflows/workflow-operation-process-${kind}.yaml`)) as {
      jobs: { labels?: unknown; steps: { labels?: unknown }[] }[];
    };
    // Every step runs on the swamp role; the harness strips placement labels for local runs.
    for (const job of source.jobs) {
      assertEquals(job.labels, { role: "swamp" });
      for (const step of job.steps) assertEquals(step.labels, { role: "swamp" });
    }
    const workflow = await repo.workflow(`operation-process-${kind}`, (workflow) => {
      for (const job of workflow.jobs) {
        for (const step of job.steps) {
          const task = step.task as {
            type: string;
            behaviorType?: string;
            globalArgs?: Record<string, unknown>;
            inputs?: Record<string, unknown>;
          };
          if (task.type !== "behavior") continue;
          if (task.behaviorType === "@swamp/ssh") {
            Object.assign(task.globalArgs!, { sshBinary: `${tools}/ssh`, scpBinary: `${tools}/scp` });
            const argv = task.inputs?.argv;
            if (Array.isArray(argv) && argv.includes("install")) {
              argv[argv.indexOf("-o") + 1] = user;
              argv[argv.indexOf("-g") + 1] = group;
            }
          } else if (task.behaviorType?.startsWith("@swamp/cfgmgmt/") && task.globalArgs && "owner" in task.globalArgs) {
            Object.assign(task.globalArgs, { owner: user, group });
          }
        }
      }
    });
    names[kind] = workflow.name as string;
  }

  const unitName = "orbit-process-17-worker.service";
  const content = "[Unit]\nX-Orbit-Process-ID=17\n[Service]\nExecStart=/bin/sleep 300\n";
  await Deno.writeTextFile(`${repo.dir}/key`, "fixture key never consumed");
  const installation = async (unit: string, pending = false): Promise<Record<string, unknown>> => ({
    process_id: 17,
    runtime: "systemd",
    name: unitName,
    desired: "running",
    on_demand: false,
    unit,
    content_hash: await sha256(unit),
    activation_pending: pending,
    has_environment: false,
    environment_path: "/etc/orbit/processes/17/" + "a".repeat(64) + ".env",
    retired: null,
  });
  const inputs = {
    operation_id: 17,
    node_id: 9,
    kind: "process.create",
    execution_secrets: {} as Record<string, unknown>,
    intent: {
      control: { process_id: 17, runtime: "systemd", name: unitName, on_demand: false, vite: null } as Record<
        string,
        unknown
      >,
      installation: await installation(content) as Record<string, unknown> | undefined,
      mounts: [{ path: "/", index: 0 }] as { path: string; index: number }[] | undefined,
      vite_plan: undefined as unknown,
      ssh_execution: {
        identity_file: `${repo.dir}/key`,
        target: {
          host: "fixture",
          user,
          port: 22,
          host_keys: ["ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIJ111111111111111111111111111111111111111111"],
        },
      },
    },
  };

  const state = () => JSON.parse(Deno.readTextFileSync(statePath));
  const save = (changes: Record<string, unknown>) =>
    Deno.writeTextFileSync(statePath, JSON.stringify({ ...(exists(statePath) ? state() : {}), ...changes }));
  save({ units: {}, calls: [], failures: [] });

  let lastRun: { id: string } = { id: "" };
  const runKind = async (kind: string, success = true) => {
    inputs.kind = "process." + kind;
    const workflow = names[kind === "update" ? "create" : kind === "restart" ? "control" : kind];
    await repo.cli(["workflow", "validate", workflow]);
    const result = await repo.cli(["workflow", "run", workflow, "--stdin"], {
      input: JSON.stringify(inputs, (_, value) => value === undefined ? undefined : value),
      success,
    });
    assert(result.stdout.trim(), result.stderr);
    lastRun = firstJson(result.stdout) as { id: string };
    return lastRun;
  };

  return {
    repo,
    user,
    group,
    host,
    tools,
    tool,
    ts,
    statePath,
    state,
    save,
    inputs,
    installation,
    content,
    unit: `${host}/units/${unitName}`,
    unitName,
    runKind,
    lastRun: () => lastRun,
    current: () => state().units[unitName],
    async [Symbol.asyncDispose]() {
      await repo[Symbol.asyncDispose]();
      await Deno.remove(host, { recursive: true });
    },
  };
}

export function exists(path: string) {
  try {
    Deno.statSync(path);
    return true;
  } catch {
    return false;
  }
}

/** The run record is the first JSON value on stdout; progress lines may follow. */
export function firstJson(text: string): unknown {
  const start = text.indexOf("{");
  let depth = 0, inString = false, escaped = false;
  for (let index = start; index < text.length; index++) {
    const char = text[index];
    if (inString) {
      if (escaped) escaped = false;
      else if (char === "\\") escaped = true;
      else if (char === '"') inString = false;
    } else if (char === '"') inString = true;
    else if (char === "{") depth++;
    else if (char === "}" && --depth === 0) return JSON.parse(text.slice(start, index + 1));
  }
  throw new Error("No JSON value in output: " + text);
}

