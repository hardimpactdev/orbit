/** Production Process DAGs on pinned Swamp and unmodified official behaviors. */
import { assert, assertEquals, assertStringIncludes } from "jsr:@std/assert@1.0.19";
import { exists, processFixture, type ProcessFixture as Fixture, random, sha256 } from "./native_fixture.ts";

async function vault(f: Fixture, key: string, value: string) {
  await f.repo.cli(["vault", "create", "local_encryption", "orbit-control-inputs"]);
  await f.repo.cli(["vault", "put", "orbit-control-inputs", key], { input: value });
}

const test = (name: string, body: (f: Fixture) => Promise<void>) =>
  Deno.test(name, async () => {
    await using f = await processFixture();
    await body(f);
  });

test("all six kinds and safe reruns", async (f) => {
  await f.tool("docker", "#!/bin/sh\nexit 99\n");
  f.inputs.intent.installation!.retired = { runtime: "docker", name: "orbit-process-17-worker" };
  await f.runKind("create");
  assertEquals(Deno.readTextFileSync(f.unit), f.content);
  assertEquals(f.current(), { active: "active", enabled: true });
  f.inputs.intent.installation = undefined;
  f.inputs.intent.mounts = undefined;
  f.save({ units: { [f.unitName]: { active: "activating", enabled: true } } });
  for (const kind of ["stop", "start", "restart"]) {
    await f.runKind(kind);
    assertEquals(f.current().active, kind === "stop" ? "inactive" : "active");
  }
  f.inputs.intent.installation = await f.installation(f.content.replace("300", "400"), true);
  await f.runKind("update");
  assertStringIncludes(Deno.readTextFileSync(f.unit), "400");
  f.inputs.intent.installation.activation_pending = false;
  f.save({ calls: [] });
  await f.runKind("update");
  assert(!f.state().calls.some((call: string[]) => call[0] === "restart"));
  f.inputs.intent.installation = undefined;
  await f.runKind("destroy");
  await f.runKind("destroy");
  assert(!exists(f.unit));
  assertEquals(f.current(), { active: "inactive", enabled: false });
});

test("a missing mount prevents the unit write", async (f) => {
  f.inputs.intent.mounts = [{ path: `${f.repo.dir}/missing`, index: 0 }];
  await f.runKind("create", false);
  assert(!exists(f.unit));
});

test("the environment uses cfgmgmt file with mode 0600", async (f) => {
  const secret = "native-private-" + random();
  const content = '# Orbit Process 17\nTOKEN="' + secret + '"\n';
  const logical = "/etc/orbit/processes/17/" + await sha256(content) + ".env";
  const install = f.inputs.intent.installation!;
  install.unit = f.content + "EnvironmentFile=" + logical + "\n";
  install.has_environment = true;
  install.environment_path = logical;
  install.content_hash = await sha256(install.unit as string);
  await vault(f, "process-create-operation-17", content);
  f.inputs.execution_secrets = { environment: { vault: "orbit-control-inputs", key: "process-create-operation-17" } };
  f.repo.secrets = [secret];
  f.save({ failures: ["start"] });
  await f.runKind("create", false);
  assert(exists(f.unit));
  await f.runKind("create");
  assertEquals(f.current().active, "active");
  const path = `${f.host}/${logical.slice("/etc/".length)}`;
  assertEquals(Deno.readTextFileSync(path), content);
  assertEquals(Deno.statSync(path).mode! & 0o777, 0o600);
  // The harness scans native output and every repo file, history included, for the secret.
  await f.repo.cli(["workflow", "history", "get", f.lastRun().id]);
  await f.repo.assertPrivateAbsent();
});

const DockerFake = (statePath: string, failRun = false) => `
const path = ${JSON.stringify(statePath)};
const state = JSON.parse(Deno.readTextFileSync(path));
let args = Deno.args;
const save = () => Deno.writeTextFileSync(path, JSON.stringify(state));
if (args[0] === "image" && args[1] === "inspect") {
  if (!state.image) Deno.exit(1);
  console.log('sha256:fixture|||["alpine:fixture"]|||now|||1');
  Deno.exit(0);
}
if ((args[0] === "image" && args[1] === "pull") || args[0] === "pull") {
  state.image = true;
  save();
  Deno.exit(0);
}
if (args[0] === "container") args = args.slice(1);
const action = args[0];
const container = state.container;
if (action === "ls") {
  if (container) console.log("fixture " + container.Config.Labels["orbit.process.spec"]);
  Deno.exit(0);
}
if (action === "run") {
  ${failRun ? "Deno.exit(1);" : ""}
  if (args[1] !== "-d") throw new Error(args.join(" "));
  const options: Record<string, string[]> = {};
  let index = 2;
  while (args[index].startsWith("--")) {
    (options[args[index]] ??= []).push(args[index + 1]);
    index += 2;
  }
  const environment: string[] = [];
  for (const file of options["--env-file"] ?? []) environment.push(...Deno.readTextFileSync(file).split("\\n").filter(Boolean));
  const labels = Object.fromEntries((options["--label"] ?? []).map((value) => {
    const at = value.indexOf("=");
    return [value.slice(0, at), value.slice(at + 1)];
  }));
  const mounts = (options["--mount"] ?? []).map((value) => {
    const fields = Object.fromEntries(value.split(",").map((field) => {
      const at = field.indexOf("=");
      return at < 0 ? [field, "true"] : [field.slice(0, at), field.slice(at + 1)];
    }));
    return { Type: fields.type, Source: fields.source, Target: fields.target, ReadOnly: fields.readonly === "true" };
  });
  const body = {
    Image: args[index],
    Cmd: args.slice(index + 1),
    Env: environment,
    WorkingDir: (options["--workdir"] ?? [""])[0],
    Labels: labels,
    HostConfig: { Mounts: mounts, RestartPolicy: { Name: options["--restart"][0] } },
  };
  state.body = body;
  state.creates = (state.creates ?? 0) + 1;
  state.container = { Config: body, State: { Status: "running" }, Image: "sha256:fixture", HostConfig: body.HostConfig, Mounts: [] };
} else if (action === "rm") delete state.container;
else if (!container) Deno.exit(1);
else if (action === "inspect") {
  console.log(args.includes("--format") ? container.State.Status : JSON.stringify([container]));
  Deno.exit(0);
} else if (["start", "restart", "stop"].includes(action)) container.State.Status = action === "stop" ? "exited" : "running";
save();
`;

test("docker: exact argv, private environment, update and lifecycle", async (f) => {
  await f.ts("docker", DockerFake(f.statePath));
  const secret = "docker-private-" + random();
  await vault(f, "process-create-operation-17", "TOKEN=" + secret + "\n");
  const control = f.inputs.intent.control;
  Object.assign(control, { runtime: "docker", name: "orbit-process-17-worker", image: "alpine:fixture" });
  const { vite: _vite, image: _image, ...rest } = control;
  const definition = {
    image: "alpine:fixture",
    command: ["/bin/sh", "-c", 'printf "$TOKEN"; sleep infinity'],
    working_directory: "/tmp",
    ports: ["127.0.0.1:8080:80"],
    mounts: [],
    restart: "unless-stopped",
  };
  f.inputs.intent.installation = {
    ...rest,
    desired: "running",
    has_environment: true,
    environment_path: "/etc/orbit/processes/17/" + "a".repeat(64) + ".env",
    retired: null,
    spec: "a".repeat(64),
    definition,
  };
  f.inputs.execution_secrets = { environment: { vault: "orbit-control-inputs", key: "process-create-operation-17" } };
  f.repo.secrets = [secret];
  await f.runKind("create");
  assertEquals(f.state().body.Cmd, definition.command);
  assertEquals(f.state().body.WorkingDir, "/tmp");
  assertEquals(f.state().body.Env, ["TOKEN=" + secret]);
  assertEquals(f.state().creates, 1);
  f.inputs.intent.installation.spec = "b".repeat(64);
  definition.command = ["sleep", "infinity"];
  await f.runKind("update");
  assertEquals(f.state().creates, 2);
  // A failed replacement keeps the environment file for the rerun.
  f.inputs.intent.installation.spec = "c".repeat(64);
  await f.ts("docker", DockerFake(f.statePath, true));
  await f.runKind("update", false);
  const envFile = `${f.host}/orbit/processes/17/${"a".repeat(64)}.env`;
  assertEquals(Deno.readTextFileSync(envFile).split("\n").filter(Boolean), ["TOKEN=" + secret]);
  await f.ts("docker", DockerFake(f.statePath));
  await f.runKind("update");
  f.inputs.intent.installation = undefined;
  f.inputs.intent.mounts = undefined;
  f.inputs.execution_secrets = {};
  for (const kind of ["stop", "start", "restart"]) {
    await f.runKind(kind);
    assertEquals(f.state().container.State.Status, kind === "stop" ? "exited" : "running");
  }
  await f.runKind("destroy");
  await f.runKind("destroy");
  assert(!("container" in f.state()));
  await f.repo.assertPrivateAbsent();
});

test("the Vite listener must exist before the awake marker", async (f) => {
  // Caddy publication is a separate, already tested child boundary.
  await f.repo.workflow("operation-process-vite", (workflow) => {
    workflow.jobs = [{
      name: "publication",
      steps: [{
        name: "publish",
        task: {
          type: "behavior",
          behaviorType: "command/shell",
          behaviorName: "vite-publication-fixture",
          methodName: "execute",
          inputs: { run: "true" },
        },
      }],
    }] as unknown as typeof workflow.jobs;
  });
  const marker = `${f.host}/awake`;
  f.inputs.intent.vite_plan = { listener: { port: 5173 }, awake: { marker } };
  await f.tool("ss", "#!/bin/sh\nexit 0\n");
  await f.runKind("create", false);
  assert(!exists(marker));
  await f.tool("ss", '#!/bin/sh\nprintf "LISTEN 0 4096 127.0.0.1:5173\\n"\n');
  await f.runKind("create");
  assert(exists(marker));
});

test("update retires the previous runtime before installing its replacement", async (f) => {
  await f.repo.cli(["vault", "create", "local_encryption", "orbit-control-inputs"]);
  f.save({ retired_present: true });
  await f.ts(
    "docker",
    `const path = ${JSON.stringify(f.statePath)};
const state = JSON.parse(Deno.readTextFileSync(path));
const args = Deno.args;
if (args[0] === "inspect") {
  if (!state.retired_present) Deno.exit(1);
  console.log(JSON.stringify([{ State: { Status: "running" }, Config: { Image: "fixture" } }]));
} else if (args[0] === "rm" && args[1] === "-f") state.retired_present = false;
else Deno.exit(1);
Deno.writeTextFileSync(path, JSON.stringify(state));
`,
  );
  f.inputs.intent.installation!.retired = { runtime: "docker", name: "orbit-process-17-worker" };
  await f.runKind("update");
  assert(!f.state().retired_present);
  assert(exists(f.unit));
  assertEquals(f.current().active, "active");
});
