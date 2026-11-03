/** The production Schedule lifecycle DAGs with unmodified official cfgmgmt and SSH behaviors. */
import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { exists } from "../process-commands/native_fixture.ts";
import { id, type Inputs, placedWorkflow, scheduleFixture, shebang } from "./native_fixture.ts";

type Unit = { enabled: boolean; active: boolean | string };
type State = { units: Record<string, Unit>; calls: string[][]; failures: string[]; loaded?: Record<string, string> };

/** The fake host holds a private obsolete command, so it sits outside the scanned repo. */
async function lifecycleFixture() {
  const f = await scheduleFixture();
  const { repo, inputs } = f;
  await repo.pull("@swamp/cfgmgmt");
  const host = await Deno.makeTempDir({ prefix: "orbit-schedule-host-" });
  const units = `${host}/units`;
  await Deno.mkdir(units);
  await Deno.mkdir(`${host}/schedules`);
  const statePath = `${host}/systemd.json`;
  const state = () => JSON.parse(Deno.readTextFileSync(statePath)) as State;
  const save = (changes: Partial<State>) =>
    Deno.writeTextFileSync(statePath, JSON.stringify({ ...(exists(statePath) ? state() : {}), ...changes }));
  save({ units: {}, calls: [], failures: [] });

  const tools = `${repo.dir}/cfgmgmt-tools`;
  await Deno.mkdir(tools);
  repo.env.PATH = `${tools}:${Deno.env.get("PATH")}`;
  const scripts: Record<string, string> = {
    ssh: shebang + `if (Deno.args.includes("-M") || Deno.args.includes("-O")) Deno.exit(0);
const command = Deno.args.at(-1)!.replaceAll("/etc/systemd/system", ${JSON.stringify(units)})
  .replaceAll("/etc/orbit/schedules", ${JSON.stringify(`${host}/schedules`)});
Deno.exit((await new Deno.Command("/bin/sh", { args: ["-c", command], stdin: "inherit", stdout: "inherit", stderr: "inherit" }).output()).code);
`,
    scp: shebang + `Deno.copyFileSync(Deno.args.at(-2)!, Deno.args.at(-1)!.split(":").slice(1).join(":"));\n`,
    sudo: shebang + `const args = Deno.args.slice(Deno.args.indexOf("--") + 1);
Deno.exit((await new Deno.Command(args[0], { args: args.slice(1), stdin: "inherit", stdout: "inherit", stderr: "inherit" }).output()).code);
`,
    chown: "#!/bin/sh\nexit 0\n",
    chgrp: "#!/bin/sh\nexit 0\n",
    rm: shebang + `const flag = ${JSON.stringify(`${host}/fail-remove`)};
let failing = false;
try {
  failing = Deno.args.includes(Deno.readTextFileSync(flag));
} catch {
  // No failure armed.
}
if (failing) {
  Deno.removeSync(flag);
  Deno.exit(1);
}
Deno.exit((await new Deno.Command("/usr/bin/rm", { args: Deno.args, stdin: "inherit", stdout: "inherit", stderr: "inherit" }).output()).code);
`,
    // Members of one parent run in parallel, so each call holds an exclusive lock on the state.
    systemctl: shebang + `Deno.openSync(${JSON.stringify(`${host}/systemd.lock`)}, { create: true, append: true }).lockSync(true);
const path = ${JSON.stringify(statePath)};
const units = ${JSON.stringify(units)};
const state = JSON.parse(Deno.readTextFileSync(path));
const save = () => Deno.writeTextFileSync(path, JSON.stringify(state));
const [action, unit = ""] = Deno.args;
state.calls.push(Deno.args);
if (state.failures.length > 0 && state.failures[0] === action) {
  state.failures.shift();
  save();
  Deno.exit(1);
}
const installed = () => {
  try {
    Deno.statSync(units + "/" + unit);
    return true;
  } catch {
    return false;
  }
};
const current = state.units[unit] ??= { enabled: false, active: false };
const active = typeof current.active === "string" ? current.active : current.active ? "active" : "inactive";
if (action === "show") {
  console.log("LoadState=" + (installed() ? "loaded" : "not-found"));
  console.log("ActiveState=" + active);
  console.log("UnitFileState=" + (current.enabled ? "enabled" : "disabled"));
} else if (action === "is-active") console.log(active);
else if (action === "enable" || action === "disable") current.enabled = action === "enable";
else if (action === "start" || action === "stop") {
  if (action === "start" && !installed()) {
    save();
    Deno.exit(5);
  }
  current.active = action === "start";
} else if (action === "daemon-reload") {
  state.loaded = {};
  for (const entry of Deno.readDirSync(units)) state.loaded[entry.name] = Deno.readTextFileSync(units + "/" + entry.name);
}
save();
`,
  };
  for (const [name, body] of Object.entries(scripts)) {
    await Deno.writeTextFile(`${tools}/${name}`, body, { mode: 0o700 });
  }
  await Deno.copyFile(`${tools}/ssh`, f.ssh);
  await Deno.copyFile(`${tools}/scp`, f.scp);

  // Map declared root ownership to this disposable fixture account.
  const user = await id("-un");
  const group = await id("-gn");
  for (const kind of ["create", "destroy", "control"]) {
    const source = await placedWorkflow(repo, `operation-schedule-${kind}`, (task) => {
      if (task.behaviorType === "@swamp/cfgmgmt/file" && "owner" in task.globalArgs!) {
        Object.assign(task.globalArgs!, { owner: user, group });
      }
      if (task.behaviorType === "@swamp/ssh") Object.assign(task.globalArgs!, { sshBinary: f.ssh, scpBinary: f.scp });
    });
    const lines = source.split("\n").length - (source.endsWith("\n") ? 1 : 0);
    assert(lines <= (kind === "create" ? 300 : 200), `${kind} has ${lines} lines`);
  }
  // Unit rendering needs the full public account context.
  Object.assign(inputs.intent.schedule, {
    group,
    home: Deno.env.get("HOME"),
    shell: "/bin/bash",
    calendar: "hourly",
    desired: "disabled",
  });
  inputs.intent.cascade = false;
  inputs.execution_secrets = {};

  const unitName = (kind: string) => "orbit-schedule-" + inputs.intent.schedule.id + "." + kind;
  const runLifecycle = (kind = "create", success = true) => {
    inputs.kind = "schedule." + kind;
    return f.runWorkflow("operation-schedule-" + kind, inputs, success);
  };
  const history = async () =>
    (await f.history()) as { jobs: { steps: { name: string; status: string; outputs?: { stdout?: string } }[] }[] };

  return {
    ...f,
    host,
    state,
    save,
    unitName,
    unit: (kind: string) => `${units}/${unitName(kind)}`,
    unitFiles: () => Array.from(Deno.readDirSync(units), (entry) => entry.name),
    current: (kind: string) => state().units[unitName(kind)],
    drift: (kind: string, changes: Partial<Unit>) => {
      const value = state();
      Object.assign(value.units[unitName(kind)] ??= { enabled: false, active: false }, changes);
      save(value);
    },
    runLifecycle,
    runEnable: async (success = true) => {
      inputs.kind = "schedule.enable";
      inputs.intent = { schedule: { id: inputs.intent.schedule.id }, ssh_execution: inputs.intent.ssh_execution };
      await f.runWorkflow("operation-schedule-control", inputs, success);
    },
    history,
    /** Destroy prints the public result; create and enable end with a native compliance check. */
    result: async () => {
      const steps = (await history()).jobs[0].steps;
      const result = steps.find((step) => step.name === "result");
      if (result) return JSON.parse(result.outputs?.stdout ?? "{}");
      const check = steps.find((step) => ["Check the installation", "Check the timer"].includes(step.name));
      return check?.status === "succeeded" ? { ready: true } : {};
    },
    async [Symbol.asyncDispose]() {
      await f[Symbol.asyncDispose]();
      await Deno.remove(host, { recursive: true });
    },
  };
}

type Fixture = Awaited<ReturnType<typeof lifecycleFixture>>;

const test = (name: string, body: (f: Fixture) => Promise<void>) =>
  Deno.test(name, async () => {
    await using f = await lifecycleFixture();
    await body(f);
  });

/** Model names carry the target host with dots as dashes. */
const host = (f: Fixture) => String((f.inputs.intent.ssh_execution.target as { host: string }).host).replaceAll(".", "-");

const actions = (f: Fixture) => f.state().calls.map((call) => call[0]);

function* walk(directory: string): Generator<string> {
  for (const entry of Deno.readDirSync(directory)) {
    yield entry.name;
    if (entry.isDirectory) yield* walk(`${directory}/${entry.name}`);
  }
}

const unitTexts = (f: Fixture) =>
  Object.fromEntries(f.unitFiles().map((name) => [name, Deno.readTextFileSync(`${f.host}/units/${name}`)]));

const modelGet = async (f: Fixture, name: string) =>
  JSON.parse((await f.repo.cli(["model", "get", name])).stdout) as {
    name: string;
    globalArguments: Record<string, unknown>;
  };

test("create converges timer intent and a repeated create is compliant", async (f) => {
  f.inputs.intent.schedule.desired = "enabled";
  await f.runLifecycle();
  assertEquals(await f.result(), { ready: true });
  assertEquals(f.current("timer"), { active: true, enabled: true });
  assert(Deno.readTextFileSync(f.unit("service")).includes("/api/v1/schedules/"));
  f.drift("timer", { active: false, enabled: false });
  await f.runLifecycle();
  assertEquals(f.current("timer"), { active: true, enabled: true });
  f.save({ calls: [] });
  await f.runLifecycle();
  assert(!actions(f).some((action) => !["show", "daemon-reload"].includes(action)));
  assert(![...walk(f.host)].some((name) => name.includes("snapshot")));
});

test("enable starts only the installed timer without private material", async (f) => {
  await f.runLifecycle();
  const before = unitTexts(f);
  f.save({ calls: [] });
  await f.runEnable();
  assertEquals(await f.result(), { ready: true });
  assertEquals(f.current("timer"), { active: true, enabled: true });
  assertEquals(unitTexts(f), before);
  // The apply reads, enables and starts, then reads again; the timer check adds the last read.
  assertEquals(actions(f), ["show", "enable", "start", "show", "show"]);
  f.save({ calls: [] });
  await f.runEnable();
  assertEquals(await f.result(), { ready: true });
  assertEquals(actions(f), ["show", "show"]);
});

test("a failed enable skips success and a rerun converges without rollback", async (f) => {
  await f.runLifecycle();
  f.save({ calls: [], failures: ["start"] });
  await f.runEnable(false);
  assertEquals(f.lastRun().status, "failed");
  assertEquals(await f.result(), {});
  assertEquals(f.current("timer"), { active: false, enabled: true });
  assert(!actions(f).includes("disable"));
  await f.runEnable();
  assertEquals(f.current("timer"), { active: true, enabled: true });
  assertEquals(await f.result(), { ready: true });
});

test("enable of a missing timer fails without creating units", async (f) => {
  await f.runEnable(false);
  assertEquals(f.lastRun().status, "failed");
  assertEquals(await f.result(), {});
  assertEquals(f.unitFiles(), []);
});

test("a disabled create removes obsolete files without reading private bytes", async (f) => {
  const identity = f.inputs.intent.schedule.id;
  const secret = "obsolete-private-command";
  for (const suffix of [".sh", ".complete"]) {
    await Deno.writeTextFile(`${f.host}/schedules/${identity}${suffix}`, secret);
  }
  await f.runLifecycle();
  assertEquals(await f.result(), { ready: true });
  assertEquals(f.current("timer"), { active: false, enabled: false });
  assertEquals([...Deno.readDirSync(`${f.host}/schedules`)], []);
  assert(!JSON.stringify(await f.history()).includes(secret));
});

test("a failed apply keeps files and a rerun converges without rollback", async (f) => {
  f.inputs.intent.schedule.desired = "enabled";
  f.save({ failures: ["start"] });
  await f.runLifecycle("create", false);
  assertEquals(f.lastRun().status, "failed");
  assertEquals(await f.result(), {});
  assert(exists(f.unit("service")));
  assert(exists(f.unit("timer")));
  assert(!(await f.history()).jobs[0].steps.some((step) => step.name.startsWith("rollback")));
  await f.runLifecycle();
  assertEquals(await f.result(), { ready: true });
  assert(f.current("timer").active);
});

test("destroy checks busy work before effects and cascade stops it", async (f) => {
  await f.runLifecycle();
  f.drift("service", { active: true });
  f.save({ calls: [] });
  await f.runLifecycle("destroy", false);
  assertEquals(f.lastRun().status, "failed");
  assert(exists(f.unit("timer")));
  assert(f.current("service").active);
  assert(actions(f).every((action) => action === "is-active"));
  f.inputs.intent.cascade = true;
  await f.runLifecycle("destroy");
  assertEquals(await f.result(), { ready: true });
  assert(!f.current("service").active);
  assert(!exists(f.unit("service")));
  assert(!exists(f.unit("timer")));
});

test("a partial destroy reruns and an absent destroy is compliant", async (f) => {
  await f.runLifecycle();
  await Deno.writeTextFile(`${f.host}/fail-remove`, f.unit("service"));
  await f.runLifecycle("destroy", false);
  assertEquals(await f.result(), {});
  assert(exists(f.unit("service")));
  for (let attempt = 0; attempt < 2; attempt++) {
    await f.runLifecycle("destroy");
    assertEquals(await f.result(), { ready: true });
    assert(!exists(f.unit("service")));
    assert(!exists(f.unit("timer")));
  }
});

test("cascade stops an activating oneshot before removing units", async (f) => {
  await f.runLifecycle();
  f.drift("service", { active: "activating" });
  f.inputs.intent.cascade = true;
  await f.runLifecycle("destroy");
  assertEquals(await f.result(), { ready: true });
  assert(!f.current("service").active);
  assert(f.state().calls.some((call) => call.length === 2 && call[0] === "stop" && call[1] === f.unitName("service")));
  assert(!exists(f.unit("service")));
});

test("a rerun reloads a failed replacement before removing the old runner", async (f) => {
  f.inputs.intent.schedule.desired = "enabled";
  await f.runLifecycle();
  await Deno.writeTextFile(f.unit("service"), "obsolete-service-definition");
  const loaded = f.state().loaded!;
  loaded[f.unitName("service")] = "obsolete-service-definition";
  f.save({ loaded, failures: ["daemon-reload"] });
  const obsolete = `${f.host}/schedules/${f.inputs.intent.schedule.id}.sh`;
  await Deno.writeTextFile(obsolete, "obsolete-private-command");
  await f.runLifecycle("create", false);
  assert(exists(obsolete));
  assertEquals(f.state().loaded![f.unitName("service")], "obsolete-service-definition");
  await f.runLifecycle();
  assertEquals(await f.result(), { ready: true });
  assertEquals(f.state().loaded![f.unitName("service")], Deno.readTextFileSync(f.unit("service")));
  assert(!exists(obsolete));
});

test("the obsolete file fan-out uses distinct model names", async (f) => {
  await f.runLifecycle();
  const names = [];
  for (const [index, kind] of ["script", "complete"].entries()) {
    names.push((await modelGet(f, `schedule-remove_obsolete-${host(f)}-${kind}-${index}`)).name);
  }
  assertEquals(new Set(names).size, 2);
});

test("the destroy file fan-out uses one model per path", async (f) => {
  await f.runLifecycle();
  await f.runLifecycle("destroy");
  const names = [];
  const paths = [];
  for (let index = 0; index < 4; index++) {
    const model = await modelGet(f, `schedule-remove_files-${host(f)}-${index}`);
    names.push(model.name);
    paths.push(model.globalArguments.path);
  }
  assertEquals(new Set(names).size, 4);
  assertEquals(new Set(paths).size, 4);
  assert(!exists(f.unit("timer")));
  assert(!exists(f.unit("service")));
});

test("the definition parent converges two members without model lock collisions", async (f) => {
  const name = "operation-schedule-install-instance-definitions";
  await placedWorkflow(f.repo, name);
  const members = [];
  for (let index = 0; index < 2; index++) {
    const intent = structuredClone(f.inputs.intent) as Inputs["intent"];
    Object.assign(intent.schedule, { id: crypto.randomUUID(), desired: "disabled" });
    members.push({ id: intent.schedule.id, intent });
  }
  const value = {
    operation_id: 17,
    node_id: 9,
    kind: "schedule.install_instance_definitions",
    intent: { instance_id: 4, members },
    execution_secrets: {},
  };
  assertEquals((await f.runWorkflow(name, value)).status, "succeeded");
  for (const member of members) {
    const unit = "orbit-schedule-" + member.id;
    for (const suffix of [".service", ".timer"]) assert(exists(`${f.host}/units/${unit}${suffix}`));
    const timer = f.state().units[unit + ".timer"];
    assert(!timer.active);
    assert(!timer.enabled);
  }
  value.intent.members = [];
  assertEquals((await f.runWorkflow(name, value)).status, "succeeded");
});
