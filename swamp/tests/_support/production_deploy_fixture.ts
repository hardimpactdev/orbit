import { readCalls } from "./fake_tool.ts";
import { type Data, git, instanceFixture, TOOL } from "./instance_fixture.ts";
const DEPLOY = "operation-instance-deploy-direct";
export async function productionFixture() {
  const f = await instanceFixture("operations");
  const home = `${f.node}/home`, origin = `${f.node}/origin`, release = "20261102230000-op-00000000000000000017";
  const directory = `${home}/releases/${release}`;
  await Deno.mkdir(`${f.node}/etc/systemd/system`, { recursive: true });
  for (const path of ["releases/initial/public", "releases/old", "storage/app"]) {
    await Deno.mkdir(`${home}/${path}`, { recursive: true });
  }
  await Deno.symlink("releases/initial", `${home}/current`);
  await Deno.writeTextFile(`${home}/.env`, "APP_KEY=old\n");
  await Deno.writeTextFile(`${home}/storage/app/kept.txt`, "kept\n");
  git(f.node, "init", "-q", "-b", "main", origin);
  git(origin, "config", "user.name", "proof");
  git(origin, "config", "user.email", "proof@example.test");
  await Deno.mkdir(`${origin}/public`);
  await Deno.writeTextFile(`${origin}/public/index.php`, "<?php echo 1;\n");
  await Deno.mkdir(`${origin}/storage/app`, { recursive: true });
  await Deno.writeTextFile(`${origin}/storage/app/kept.txt`, "from the release\n");
  await Deno.writeTextFile(`${origin}/storage/app/new.txt`, "new\n");
  git(origin, "add", ".");
  git(origin, "commit", "-qm", "one");
  const first = git(origin, "rev-parse", "HEAD");
  git(origin, "checkout", "-qb", "side");
  await Deno.writeTextFile(`${origin}/side.txt`, "side\n");
  git(origin, "add", ".");
  git(origin, "commit", "-qm", "side");
  const side = git(origin, "rev-parse", "HEAD");
  git(origin, "checkout", "-q", "main");
  await Deno.writeTextFile(`${origin}/README`, "two\n");
  git(origin, "add", ".");
  git(origin, "commit", "-qm", "two");
  const head = git(origin, "rev-parse", "HEAD");
  const sudo = `${f.tools}/sudo`;
  await Deno.writeTextFile(
    sudo,
    (await Deno.readTextFile(sudo)).replace(
      'const name = args[0]?.split("/").at(-1);',
      `
const name = args[0]?.split("/").at(-1);
if (args[0] === "getent" && args[1] === "passwd") { console.log(args[2] + ":x:1000:1000::" + ${
        JSON.stringify(f.node + "/manager")
      } + ":/bin/bash"); Deno.exit(0); }
args = args.map(a => a.replaceAll("/opt/orbit/vite-plus/bin/vp", ${JSON.stringify(f.node + "/public/bin/vp")}));
if (name === "find" && args[1] === "/proc") {
  let failure = ""; try { failure = Deno.readTextFileSync(${JSON.stringify(f.fail)}); } catch { /* no failure */ }
  if (failure === "find") Deno.exit(1);
  const wanted = args[args.indexOf("-lname") + 1];
  for (const proc of Deno.readDirSync("/proc")) {
    if (!/^\\d+$/.test(proc.name)) continue;
    try { if (Deno.statSync("/proc/" + proc.name).uid !== Deno.statSync(${
        JSON.stringify(f.node)
      }).uid) continue; } catch { continue; }
    for (const link of ["cwd", "root", "exe"]) {
      try { const value = Deno.readLinkSync("/proc/" + proc.name + "/" + link);
        if (value === wanted || value.startsWith(wanted + "/")) { console.log("/proc/" + proc.name + "/" + link); Deno.exit(0); }
      } catch { /* process disappeared or cannot be read */ }
    }
  }
  Deno.exit(0);
}
`,
    ),
  );
  await f.ts(
    "systemctl",
    `
import { logCall } from ${JSON.stringify(TOOL)};
if (Deno.args[0] === "show") console.log(Deno.args[1].endsWith(".timer") ? "LoadState=loaded\\nActiveState=inactive\\nUnitFileState=disabled" : "LoadState=loaded\\nActiveState=active\\nUnitFileState=enabled");
else logCall(${JSON.stringify(f.calls)}, ["systemctl", ...Deno.args]);
`,
  );
  await f.ts(
    "find",
    `
import { passThrough, readStdinBytes } from ${JSON.stringify(TOOL)};
if (Deno.args[0] === "/opt" && Deno.args[1] === ".") console.log("/opt/orbit/vite-plus/bin/vp");
else await passThrough(["/usr/bin/find", ...Deno.args], await readStdinBytes());
`,
  );
  for (
    const name of [
      DEPLOY, "operation-process-create", "operation-process-destroy", "operation-process-vite",
      "operation-tool-manager-setup",
    ]
  ) await f.workflow(name);
  const sentinel = `native-private-${crypto.randomUUID()}`;
  f.repo.secrets = [sentinel];
  await f.repo.cli(["vault", "create", "local_encryption", "orbit-control-inputs"]);
  await f.repo.cli([
    "vault", "put", "orbit-control-inputs", "instance-deploy-operation-17-credential",
    "https://x-access-token:unused@github.com",
  ]);
  await f.repo.cli(["vault", "put", "orbit-control-inputs", "instance-environment-17", `APP_KEY="${sentinel}"\n`]);
  const safeGit = [
    "/usr/bin/env", "GIT_CONFIG_NOSYSTEM=1", "GIT_CONFIG_GLOBAL=/dev/null", "/usr/bin/git", "-c",
    "core.hooksPath=/dev/null",
  ];
  const action = (argv: string[], ok = [0]) => ({ argv, ok });
  const inputs: Data = {
    operation_id: 17, node_id: 9, kind: "instance.deploy",
    execution_secrets: {
      credential: { vault: "orbit-control-inputs", key: "instance-deploy-operation-17-credential" },
      environment: { vault: "orbit-control-inputs", key: "instance-environment-17" },
    },
    intent: {
      instance_id: 42, commit: null, application: directory, vp_setup: null, account_home: f.node,
      ssh_execution: f.connection,
      deployment: {
        variant: "production", needs_vp: false, steps: [], node_user: f.user, branch: "main", repository: origin, home,
      },
      environment: {
        instance_id: 42, environment: "production", base: home, user: f.user, keys: ["APP_KEY"], owned_keys: [],
        sha256: "", testing: null,
      },
      process_members: [], php_input: null,
      runtime_definitions: {
        schedules: [],
        restart: [{ process_id: 3, runtime: "systemd", name: "orbit-process-3-horizon.service" }],
      },
      production: {
        instance_id: 42, user: f.user, home, release, release_path: directory, commit: null, branch: "main",
        credential: `${f.node}/credential`,
        step_file: `${f.node}/step-`,
        prepare: [
          action([
            ...safeGit, "-c", "credential.helper=", "-c",
            `credential.helper=store --file=${f.node}/credential`,
            "-c", "protocol.allow=never", "-c", "protocol.file.allow=always", "clone", "--quiet", "--no-checkout",
            "--origin", "origin", "--", origin, directory,
          ]),
          action([...safeGit, "-C", directory, "checkout", "--quiet", "--detach", "refs/remotes/origin/main"]),
        ],
        links: [{ path: `${directory}/.env`, target: "../../.env" }, {
          path: `${directory}/storage`,
          target: `${home}/storage`,
        }, { path: `${directory}/public/storage`, target: "../storage/app/public" }],
        grants: [["/usr/bin/setfacl", "-m", "u:caddy:--x", "--", directory], [
          "/usr/bin/setfacl", "-P", "-R", "-m", "u:caddy:r-X", "--",
          `${directory}/public`,
        ]],
        steps: [], before: [], after: [],
        cleanup: [{ name: "old", path: `${home}/releases/old` }],
        shared: [
          action(["/usr/bin/mkdir", "-p", "--", `${home}/storage`]),
          action(["/usr/bin/find", `${home}/storage`, "-mindepth", "1", "-maxdepth", "1", "-print", "-quit"]),
          action(["/usr/bin/test", "-d", `${directory}/storage`], [0, 1]),
          {
            ...action(["/usr/bin/cp", "-a", "-T", "--", `${directory}/storage`, `${home}/storage`]),
            empty: 1, source: 2,
          },
          action(["/usr/bin/rm", "-rf", "--", `${directory}/storage`]),
          action(["/usr/bin/mkdir", "-p", "--", directory]),
          action(["/usr/bin/mkdir", "-p", "--", `${home}/storage/app/public`]),
        ],
      },
    },
  };
  const command = async (command: string, phase = "before_activation") => {
    const index = inputs.intent.production.steps.length, key = `instance-deploy-operation-17-step-${index}`;
    const step = { index, name: `step-${index}`, phase, timeout_seconds: 10 };
    inputs.intent.production.steps.push(step);
    inputs.intent.deployment.steps.push(step);
    inputs.intent.production[phase === "before_activation" ? "before" : "after"].push(step);
    await f.repo.cli([
      "vault", "put", "orbit-control-inputs", key,
      `exec >/dev/null 2>&1\n{\n${command}\n} </dev/null\n`,
    ]);
    inputs.execution_secrets[`step_${index}`] = { vault: "orbit-control-inputs", key };
  };
  const deploy = (success = true) => f.run(DEPLOY, inputs, success);
  const calls = () =>
    readCalls<string[]>(f.calls).filter((row) => !["show", "is-active", "is-enabled"].includes(row[1]));
  const pin = (commit: string) => {
    inputs.intent.production.commit = commit;
    inputs.intent.production.prepare[1].argv[inputs.intent.production.prepare[1].argv.length - 1] = commit;
  };
  return { f, home, origin, release, directory, first, side, head, inputs, sentinel, command, deploy, calls, pin };
}
