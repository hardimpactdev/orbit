/**
 * Run the shipped playbook of a default Instance's container (instance-container.yml) against a stateful Incus boundary: the first
 * build, a rerun that changes nothing, a deployment, the refusals of a deployment and the result file each phase writes. The Node is
 * this machine: the run's connection is local and nothing becomes root.
 */
import { assert, assertEquals, assertThrows } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";

const hasAnsible =
  new Deno.Command("sh", { args: ["-c", "command -v ansible-playbook"] })
    .outputSync().success;
const root = new URL("../ansible/", import.meta.url);

type Task = Record<string, unknown>;

/** Ownership needs root; the modes stay. UFW rules land in a file of the fixture, one JSON line each, as the module would add them. */
function transform(task: Task): Task {
  for (const part of ["block", "always", "rescue"]) {
    if (Array.isArray(task[part])) task[part] = (task[part] as Task[]).map(transform);
  }
  if (task["community.general.ufw"]) {
    delete task["community.general.ufw"];
    task["ansible.builtin.lineinfile"] = {
      path: "{{ lookup('ansible.builtin.env', 'HOST_FIXTURE') }}/ufw",
      line: "{{ item | to_json }}",
      create: true,
      mode: "0600",
    };
  }
  const args = task["ansible.builtin.file"] as Record<string, unknown> | undefined;
  if (args) {
    delete args.owner;
    delete args.group;
  }
  return task;
}

const git = (cwd: string, ...args: string[]) => {
  const done = new Deno.Command("git", {
    args: ["-c", "user.name=Orbit", "-c", "user.email=orbit@example.test", ...args],
    cwd,
  }).outputSync();
  if (!done.success) throw new Error(new TextDecoder().decode(done.stderr));
  return new TextDecoder().decode(done.stdout).trim();
};

function fixture() {
  const folder = Deno.makeTempDirSync();
  // Other worktrees run this fixture on the same host; run-scoped files on the Node must be unique too.
  const runId = crypto.randomUUID();
  Deno.mkdirSync(`${folder}/bin`);
  Deno.mkdirSync(`${folder}/tasks`);
  Deno.mkdirSync(`${folder}/home`);
  Deno.writeTextFileSync(`${folder}/state.json`, "{}");
  Deno.writeTextFileSync(
    `${folder}/bin/incus`,
    `#!/bin/sh\nexec '${Deno.execPath()}' run -A '${
      new URL("fixtures/instance-container-incus.ts", import.meta.url).pathname
    }' "$@"\n`,
  );
  Deno.chmodSync(`${folder}/bin/incus`, 0o755);
  const tasks = (parse(
    Deno.readTextFileSync(new URL("tasks/instance-container-phase.yml", root)),
  ) as Task[]).map((task) => transform(structuredClone(task)));
  Deno.writeTextFileSync(`${folder}/tasks/instance-container-phase.yml`, stringify(tasks));
  for (const name of ["instance-container.yml", "tasks/address-node.yml", "tasks/instance-container-process.yml"]) {
    Deno.copyFileSync(new URL(name, root), `${folder}/${name}`);
  }
  Deno.mkdirSync(`${folder}/run`);
  // The Project's repository, with one commit on main.
  const source = `${folder}/source`;
  Deno.mkdirSync(source);
  git(source, "init", "--quiet", "--initial-branch", "main");
  Deno.writeTextFileSync(`${source}/index.php`, "<?php echo 1;\n");
  git(source, "add", ".");
  git(source, "commit", "--quiet", "-m", "first");
  const container = {
    instance_id: 7,
    project: "orbit-instances",
    name: "instance-7",
    image: "orbit-instance",
    address: "10.253.0.2",
    network: "orbitinst0",
    acl: "instance-7",
    acl_rules: {
      description: "Orbit Instance 7",
      egress: [],
      ingress: [{ action: "allow", source: "10.253.0.1/32", protocol: "tcp", destination_port: "8080", state: "enabled" }],
    },
    mirror: `${folder}/mirror/7.git`,
    repository: `file://${source}`,
    protocols: ["file"],
    branch: "main",
    commit: null as string | null,
    workspace: "/srv/workspace",
    environment_file: "/srv/workspace/.env",
    units: [{ name: "orbit-frankenphp.service", content: "[Service]\nUser=orbit\n" }],
    php: "/opt/orbit-runtime/current/php/8.5/bin/php" as string | null,
    processes: [] as { id: number; name: string; content: string; environment: string | null; desired: string }[],
  };
  const ssh_execution = {
    identity_file: "/dev/null",
    target: {
      host: "127.0.0.1",
      user: "orbit",
      port: 22,
      host_keys: ["ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl"],
    },
  };
  const control = { process_id: 41, name: "orbit-process-41-worker.service" };
  const read = { action: "status", lines: 50, request: "6f1c4c1e-8a7d-4f11-9d0e-3b5a2c1d0e9f" };
  const write = () =>
    Deno.writeTextFileSync(`${folder}/run/intent.json`, JSON.stringify({ ssh_execution, container, control, read }));
  write();
  return {
    folder,
    source,
    container,
    write,
    read,
    run(phase: string, extra: Record<string, string> = {}) {
      const done = new Deno.Command("ansible-playbook", {
        args: ["-i", "localhost,", "--tags", phase, "-e", "ansible_connection=local", "-e", "ansible_become=false",
          "-e", "ansible_python_interpreter={{ ansible_playbook_python }}", `${folder}/instance-container.yml`],
        env: {
          HOST_FIXTURE: folder,
          PATH: `${folder}/bin:${Deno.env.get("PATH")}`,
          ANSIBLE_NOCOLOR: "1",
          ANSIBLE_HOME: `${folder}/ansible`,
          ANSIBLE_LOCAL_TEMP: `${folder}/tmp`,
          ORBIT_RUN: `${folder}/run`,
          ORBIT_RUN_ID: runId,
          ORBIT_GIT_CREDENTIAL: "",
          ORBIT_ENVIRONMENT: "APP_KEY=base64:secret\n",
          ORBIT_MODEL_ENVIRONMENT: "ORBIT_MODEL_KEY=placeholder\n",
          GIT_CONFIG_NOSYSTEM: "1",
          HOME: `${folder}/home`,
          ORBIT_PROCESS_ENVIRONMENTS: "{}",
          ...extra,
        },
      }).outputSync();
      const output = new TextDecoder().decode(done.stdout) + new TextDecoder().decode(done.stderr);
      // The Node's own recap line: the play that addresses it runs on localhost.
      return { ok: done.success, output, changed: Number(/^node\s*:.*changed=(\d+)/m.exec(output)?.[1] ?? -1) };
    },
    state: () => JSON.parse(Deno.readTextFileSync(`${folder}/state.json`)),
    result: () => JSON.parse(Deno.readTextFileSync(`${folder}/run/result.json`)),
    workspace: (...args: string[]) => git(`${folder}/containers/instance-7/srv/workspace`, ...args),
    cleanup: () => Deno.removeSync(folder, { recursive: true }),
  };
}

Deno.test({
  name: "the first build makes the container, checks out the default branch, starts FrankenPHP and seeds it; a rerun changes nothing",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      const head = git(f.source, "rev-parse", "HEAD");
      for (const phase of ["container", "workspace", "processes", "seed"]) {
        const result = f.run(phase);
        assert(result.ok, `${phase}: ${result.output}`);
        assertEquals(f.result(), { commit: phase === "workspace" || phase === "seed" ? head : null });
      }
      const state = f.state();
      assertEquals(state["network-acls"], [{ name: "instance-7", ...f.container.acl_rules }]);
      assertEquals(state.containers["instance-7"].devices, [
        "eth0,ipv4.address=10.253.0.2",
        "eth0,security.acls=orbitinst0-egress,instance-7",
        "eth0,security.acls.default.egress.action=reject",
        "eth0,security.acls.default.ingress.action=reject",
      ]);
      assertEquals(state.containers["instance-7"].config, "security.idmap.isolated=true");
      assertEquals(state.containers["instance-7"].status, "Running");
      // `php` on every PATH in the container is the Project's static build.
      assertEquals(
        Deno.readLinkSync(`${f.folder}/containers/instance-7/usr/local/bin/php`),
        "/opt/orbit-runtime/current/php/8.5/bin/php",
      );
      assertEquals(f.workspace("rev-parse", "HEAD"), head);
      assertEquals(f.workspace("symbolic-ref", "--short", "HEAD"), "main");
      assertEquals(state.files["/srv/workspace/.env"], { uid: "1000", gid: "2760", mode: "0640" });
      assertEquals(Deno.readTextFileSync(`${f.folder}/containers/instance-7/srv/workspace/.env`), "APP_KEY=base64:secret\n");
      assertEquals(state.containers["instance-7"].snapshots, [`seed-${head}`]);
      assert(state.systemctl.some((call: string[]) => call.join(" ") === "enable --now -- orbit-frankenphp.service"));
      // No bundle, credential or .env stays behind on the Node or in the container.
      assertEquals([...Deno.readDirSync(`${f.folder}/containers/instance-7/tmp`)], []);

      for (const phase of ["container", "workspace", "processes", "seed", "start", "check"]) {
        const again = f.run(phase);
        assert(again.ok, again.output);
        assertEquals(again.changed, 0, `${phase}: ${again.output}`);
      }
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a deployment checks out the branch's new commit, seeds it and deletes the older seed; dirty or diverged work refuses",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      for (const phase of ["container", "workspace", "processes", "seed"]) assert(f.run(phase).ok);
      const first = git(f.source, "rev-parse", "HEAD");
      Deno.writeTextFileSync(`${f.source}/index.php`, "<?php echo 2;\n");
      git(f.source, "commit", "--quiet", "-am", "second");
      const second = git(f.source, "rev-parse", "HEAD");

      for (const phase of ["start", "fetch", "checkout", "seed", "check"]) {
        const result = f.run(phase);
        assert(result.ok, `${phase}: ${result.output}`);
      }
      assertEquals(f.result(), { commit: second });
      assertEquals(f.workspace("rev-parse", "HEAD"), second);
      assertEquals(f.state().containers["instance-7"].snapshots, [`seed-${second}`]);

      // A pinned commit that is not on the branch refuses at the fetch.
      f.container.commit = "f".repeat(40);
      f.write();
      const stray = f.run("fetch");
      assert(!stray.ok);
      assertEquals(f.result(), { error_code: "deployment.commit_not_on_branch" });

      // Tracked changes in the workspace refuse the checkout and stay.
      f.container.commit = first;
      f.write();
      assert(f.run("fetch").ok);
      Deno.writeTextFileSync(`${f.folder}/containers/instance-7/srv/workspace/index.php`, "<?php echo 3;\n");
      const dirty = f.run("checkout");
      assert(!dirty.ok);
      assertEquals(f.result(), { error_code: "deployment.checkout_dirty" });
      f.workspace("checkout", "--", "index.php");

      // An older commit does not fast-forward the branch.
      const refetch = f.run("fetch");
      assert(refetch.ok, refetch.output);
      const diverged = f.run("checkout");
      assert(!diverged.ok);
      assertEquals(f.result(), { error_code: "deployment.branch_diverged" });
      assertEquals(f.workspace("rev-parse", "HEAD"), second);

      // A failure that is no refusal names its phase.
      f.container.commit = null;
      f.write();
      const state = f.state();
      state.inactive = ["orbit-frankenphp.service"];
      Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(state));
      assert(!f.run("check").ok);
      assertEquals(f.result(), { error_code: "deployment.processes_inactive" });
      delete state.containers["instance-7"];
      Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(state));
      assert(!f.run("start").ok);
      assertEquals(f.result(), { error_code: "instance.container_start_failed" });
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a start points php at the Project's PHP version, and removes the link of a Project without PHP",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    const link = `${f.folder}/containers/instance-7/usr/local/bin/php`;
    try {
      assert(f.run("container").ok);
      f.container.php = "/opt/orbit-runtime/current/php/8.4/bin/php";
      f.write();
      let result = f.run("start");
      assert(result.ok, result.output);
      assertEquals(Deno.readLinkSync(link), "/opt/orbit-runtime/current/php/8.4/bin/php");
      f.container.php = null;
      f.write();
      result = f.run("start");
      assert(result.ok, result.output);
      assertThrows(() => Deno.lstatSync(link));
      // A php that is no link is not Orbit's to remove.
      Deno.writeTextFileSync(link, "#!/bin/sh\n");
      result = f.run("start");
      assert(result.ok, result.output);
      assertEquals(result.changed, 0, result.output);
      assert(Deno.lstatSync(link).isFile);
      f.container.php = "/opt/orbit-runtime/current/php/8.5/bin/php";
      f.write();
      result = f.run("start");
      assert(result.ok, result.output);
      assertEquals(result.changed, 0, result.output);
      assert(Deno.lstatSync(link).isFile);
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a clone copies the seed, takes its own address, ACL, firewall rules and T3 port, starts T3 and hands over its sessions; a rerun changes nothing",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      for (const phase of ["container", "workspace", "processes", "seed"]) assert(f.run(phase).ok);
      const seed = git(f.source, "rev-parse", "HEAD");
      // A commit on main after the seed: a new branch starts at the seed, not at main.
      Deno.writeTextFileSync(`${f.source}/index.php`, "<?php echo 2;\n");
      git(f.source, "commit", "--quiet", "-am", "after the seed");
      const route = { route: "yes", interface_in: "orbit", interface_out: "orbitinst0", to_ip: "10.253.0.3", to_port: "3773", proto: "tcp" };
      Object.assign(f.container, {
        instance_id: 8, name: "instance-8", address: "10.253.0.3", acl: "instance-8", mirror: `${f.folder}/mirror/8.git`,
        branch: "feature", units: [{ name: "orbit-frankenphp.service", content: "[Service]\nUser=orbit\nExecStart=frankenphp --listen 10.253.0.3:8080\n" }],
        acl_rules: { description: "Orbit Instance 8", egress: [], ingress: [{ action: "allow", protocol: "tcp", destination_port: "3773", state: "enabled" }] },
        firewall: [
          { ...route, rule: "deny", from_ip: "10.44.0.128/25", comment: "orbit:instance-8-t3-task-vms" },
          { ...route, rule: "allow", from_ip: "any", comment: "orbit:instance-8-t3" },
        ],
        clone: {
          source: "instance-7", snapshot: `seed-${seed}`, seed_commit: seed, source_mirror: `${f.folder}/mirror/7.git`,
          t3: { port: 3800, listen: "10.44.0.7", device: "t3", unit: "t3.service", internal_port: 3773, ready_seconds: 20,
            url: "http://10.44.0.7:3800", label: "tool / feature", t3: "/opt/orbit-agent/current/agent/bin/t3",
            project_mark: "/home/agent/.config/orbit/t3-project" },
          model_environment: { path: "/home/agent/.config/orbit/model.env", uid: 2750, gid: 2750, mode: "0600" },
          files: [
            { path: "/etc/orbit/instance", uid: 0, gid: 0, mode: "0644", content: "ORBIT_INSTANCE_ID=8\n" },
            { path: "/home/agent/.codex/config.toml", uid: 2750, gid: 2750, mode: "0600", content: "model_provider = \"orbit\"\n" },
            { path: "/home/agent/.t3/userdata/settings.json", uid: 2750, gid: 2750, mode: "0600", json: { linkedEnvironmentsEnabled: true } },
          ],
          links: [{ path: "/home/agent/.claude/CLAUDE.md", target: "/opt/orbit-agent/current/notes/web-app.md" }],
        },
      });
      f.write();
      const clone = `${f.folder}/containers/instance-8`;
      for (const phase of ["clone", "workspace", "agent", "processes", "t3", "register"]) {
        const result = f.run(phase);
        assert(result.ok, `${phase}: ${result.output}`);
      }
      const state = f.state();
      assertEquals(state.containers["instance-8"].source, `instance-7/seed-${seed}`);
      assertEquals(state.containers["instance-8"].devices, ["eth0,ipv4.address=10.253.0.3", "eth0,security.acls=orbitinst0-egress,instance-8"]);
      assertEquals(state.containers["instance-8"].added, {
        t3: { type: "proxy", settings: ["listen=tcp:10.44.0.7:3800", "connect=tcp:10.253.0.3:3773", "nat=true"] },
      });
      assertEquals(state["network-acls"].find((acl: { name: string }) => acl.name === "instance-8"), { name: "instance-8", ...f.container.acl_rules });
      // The task VM range's deny comes before the allow.
      assertEquals(Deno.readTextFileSync(`${f.folder}/ufw`).trim().split("\n").map((line) => JSON.parse(line).comment),
        ["orbit:instance-8-t3-task-vms", "orbit:instance-8-t3"]);
      // The branch is new, at the seed's commit; the default's workspace and seed stay as they were.
      assertEquals(git(`${clone}/srv/workspace`, "symbolic-ref", "--short", "HEAD"), "feature");
      assertEquals(git(`${clone}/srv/workspace`, "rev-parse", "HEAD"), seed);
      assertEquals(f.workspace("symbolic-ref", "--short", "HEAD"), "main");
      assertEquals(state.containers["instance-7"].snapshots, [`seed-${seed}`]);
      assertEquals(Deno.readTextFileSync(`${clone}/etc/orbit/instance`), "ORBIT_INSTANCE_ID=8\n");
      assertEquals(JSON.parse(Deno.readTextFileSync(`${clone}/home/agent/.t3/userdata/settings.json`)), { linkedEnvironmentsEnabled: true });
      assertEquals(Deno.statSync(`${clone}/home/agent/.t3/userdata`).mode! & 0o777, 0o700);
      assertEquals(Deno.readTextFileSync(`${clone}/home/agent/.config/orbit/model.env`), "ORBIT_MODEL_KEY=placeholder\n");
      assertEquals(state.files["/home/agent/.config/orbit/model.env"], { uid: "2750", gid: "2750", mode: "0600" });
      assertEquals(Deno.readLinkSync(`${clone}/home/agent/.claude/CLAUDE.md`), "/opt/orbit-agent/current/notes/web-app.md");
      assert(state.systemctl.some((call: string[]) => call.join(" ") === "restart -- orbit-frankenphp.service"));
      assertEquals(state.projects, ["/srv/workspace"]);
      // The reply with both sessions waits in the run folder, 0600; no session reaches the run's output.
      const reply = JSON.parse(Deno.readTextFileSync(`${f.folder}/run/t3.json`));
      assertEquals(Deno.statSync(`${f.folder}/run/t3.json`).mode! & 0o777, 0o600);
      assertEquals(JSON.parse(reply.environment).environmentId, "env-instance-8");
      assertEquals([reply.gateway.token, reply.gateway.status, JSON.parse(reply.gateway.body).scopes], ["token-1", 200, ["access:read", "access:write"]]);
      assertEquals([reply.swamp.token, reply.swamp.status], ["token-2", 200]);
      assertEquals(state.sessions.map((session: { label: string }) => session.label), ["Orbit Gateway", "Orbit Swamp"]);

      for (const phase of ["clone", "workspace", "agent", "processes", "t3"]) {
        const again = f.run(phase);
        assert(again.ok, again.output);
        assertEquals(again.changed, 0, `${phase}: ${again.output}`);
      }
      assertEquals(f.state().projects, ["/srv/workspace"]);
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "the agent phase preserves runtime JSON keys, repairs managed keys and reruns without changes",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    const rootfs = `${f.folder}/containers/instance-7`;
    const claude = "/home/agent/.claude.json";
    const t3 = "/home/agent/.t3/userdata/settings.json";
    try {
      const created = f.run("container");
      assert(created.ok, created.output);
      Deno.mkdirSync(`${rootfs}/home/agent/.config/orbit`, { recursive: true });
      Object.assign(f.container, {
        clone: {
          files: [
            { path: claude, uid: 2750, gid: 2750, mode: "0600", json: { hasCompletedOnboarding: true } },
            { path: t3, uid: 2750, gid: 2750, mode: "0600", json: { linkedEnvironmentsEnabled: true } },
          ],
          model_environment: { path: "/home/agent/.config/orbit/model.env", uid: 2750, gid: 2750, mode: "0600" },
          links: [],
        },
      });
      f.write();
      const read = (path: string) => JSON.parse(Deno.readTextFileSync(rootfs + path));
      const first = f.run("agent");
      assert(first.ok, first.output);
      assertEquals(read(claude), { hasCompletedOnboarding: true });
      assertEquals(read(t3), { linkedEnvironmentsEnabled: true });
      for (const path of [claude, t3]) {
        assertEquals(Deno.statSync(rootfs + path).mode! & 0o777, 0o600);
        assertEquals(f.state().files[path], { uid: "2750", gid: "2750", mode: "0600" });
      }

      // T3 and Claude add their identity, migration state and preferences after the first start.
      const runtimeClaude = { machineID: "machine-7", userID: "user-7", firstStartTime: "2026-11-10T10:00:00Z",
        firstStartVersion: "2.1", migrationVersion: 4, pluginUsage: {}, seenNotifications: ["welcome"] };
      const runtimeT3 = { idleCompaction: { enabled: true }, projectSettingsFolded: { tool: false } };
      const populated = [
        { path: claude, value: { ...runtimeClaude, hasCompletedOnboarding: true } },
        { path: t3, value: { ...runtimeT3, linkedEnvironmentsEnabled: true } },
      ];
      for (const { path, value } of populated) Deno.writeTextFileSync(rootfs + path, JSON.stringify(value, null, 4));
      const unchanged = f.run("agent");
      assert(unchanged.ok, unchanged.output);
      for (const { path, value } of populated) {
        assertEquals(read(path), value);
        assertEquals(Deno.readTextFileSync(rootfs + path), JSON.stringify(value, null, 4));
      }
      assertEquals(unchanged.changed, 0, unchanged.output);

      // An incorrect managed value and a missing managed key are repaired without losing the runtime's keys.
      Deno.writeTextFileSync(rootfs + claude, JSON.stringify({ ...runtimeClaude, hasCompletedOnboarding: false }));
      Deno.writeTextFileSync(rootfs + t3, JSON.stringify(runtimeT3));
      const repaired = f.run("agent");
      assert(repaired.ok, repaired.output);
      assertEquals(repaired.changed, 1, repaired.output);
      for (const { path, value } of populated) assertEquals(read(path), value);
      const again = f.run("agent");
      assert(again.ok, again.output);
      assertEquals(again.changed, 0, again.output);

      // A damaged runtime file fails the phase rather than replacing the runtime's state with defaults.
      Deno.writeTextFileSync(rootfs + claude, '{"machineID":');
      const damaged = f.run("agent");
      assert(!damaged.ok, damaged.output);
      assertEquals(Deno.readTextFileSync(rootfs + claude), '{"machineID":');
      assertEquals(f.result(), { error_code: "instance.container_agent_failed" });
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a clone refuses a seed that a deployment replaced, and fails with instance.t3_unready when T3 does not answer",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      for (const phase of ["container", "workspace", "processes", "seed"]) assert(f.run(phase).ok);
      const seed = git(f.source, "rev-parse", "HEAD");
      Object.assign(f.container, {
        name: "instance-8", address: "10.253.0.3", acl: "instance-8", mirror: `${f.folder}/mirror/8.git`, branch: "main", firewall: [],
        clone: { source: "instance-7", snapshot: "seed-gone", seed_commit: seed, source_mirror: `${f.folder}/mirror/7.git`,
          t3: { port: 3800, listen: "10.44.0.7", device: "t3", unit: "other.service", internal_port: 3773, ready_seconds: 1, t3: "/t3",
            project_mark: "/home/agent/.config/orbit/t3-project" },
          model_environment: { path: "/home/agent/.config/orbit/model.env", uid: 2750, gid: 2750, mode: "0600" }, files: [], links: [] },
      });
      f.write();
      // A deployment of the default replaced the admitted seed before the copy.
      assert(!f.run("clone").ok);
      assertEquals(f.result(), { error_code: "instance.default_seed_moved" });
      (f.container as unknown as { clone: { snapshot: string } }).clone.snapshot = `seed-${seed}`;
      f.write();
      assert(f.run("clone").ok);
      const unready = f.run("t3");
      assert(!unready.ok);
      assertEquals(f.result(), { error_code: "instance.t3_unready" });
    } finally {
      f.cleanup();
    }
  },
});

const worker = {
  id: 41,
  name: "orbit-process-41-worker.service",
  content: "[Service]\nUser=orbit\nEnvironmentFile=/etc/orbit/processes/41/abc.env\nExecStart=php artisan queue:work\n",
  environment: "/etc/orbit/processes/41/abc.env",
  desired: "running",
};
const paused = {
  id: 42,
  name: "orbit-process-42-scheduler.service",
  content: "[Service]\nUser=orbit\nExecStart=php artisan schedule:work\n",
  environment: null,
  desired: "stopped",
};
const environments = JSON.stringify({ "/etc/orbit/processes/41/abc.env": "# Orbit Process 41\nQUEUE=\"high\"\n" });
const restarts = (state: { systemctl?: string[][] }) =>
  (state.systemctl ?? []).filter((call) => call[0] === "restart").map((call) => call.slice(2));

Deno.test({
  name: "the first build installs the Project's Processes as orbit units with environment files from the vault; a rerun changes nothing",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      f.container.processes = [worker, paused];
      f.write();
      for (const phase of ["container", "workspace"]) assert(f.run(phase).ok);
      const result = f.run("processes", { ORBIT_PROCESS_ENVIRONMENTS: environments });
      assert(result.ok, result.output);
      const rootfs = `${f.folder}/containers/instance-7`;
      assertEquals(Deno.readTextFileSync(`${rootfs}/etc/systemd/system/${worker.name}`), worker.content);
      assertEquals(Deno.readTextFileSync(`${rootfs}/etc/systemd/system/${paused.name}`), paused.content);
      // The values reach the container from the vault, root-only; they are never in the intent or the run's output.
      assertEquals(Deno.readTextFileSync(`${rootfs}${worker.environment}`), "# Orbit Process 41\nQUEUE=\"high\"\n");
      assertEquals(f.state().files[worker.environment], { uid: "0", gid: "0", mode: "0600" });
      assert(!result.output.includes("QUEUE"), result.output);
      // A Process whose desired state is stopped is installed, not started.
      const enabled = f.state().systemctl.find((call: string[]) => call[0] === "enable" && call[1] === "--now");
      assertEquals(enabled, ["enable", "--now", "--", "orbit-frankenphp.service", worker.name]);
      // The seed stops and starts the running ones only.
      assert(f.run("seed").ok);
      assert(f.state().systemctl.some((call: string[]) => call.join(" ") === `stop -- orbit-frankenphp.service ${worker.name}`));

      const again = f.run("processes", { ORBIT_PROCESS_ENVIRONMENTS: environments });
      assert(again.ok, again.output);
      assertEquals(again.changed, 0, again.output);
      assertEquals(restarts(f.state()), [["orbit-frankenphp.service", worker.name]]);
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a deployment's new seed restarts the running Processes on the new commit; a changed value replaces its environment file",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      f.container.processes = [worker, paused];
      f.write();
      for (const phase of ["container", "workspace", "processes", "seed"]) {
        const result = f.run(phase, { ORBIT_PROCESS_ENVIRONMENTS: environments });
        assert(result.ok, `${phase}: ${result.output}`);
      }
      Deno.writeTextFileSync(`${f.source}/index.php`, "<?php echo 2;\n");
      git(f.source, "commit", "--quiet", "-am", "second");
      const state = f.state();
      state.systemctl = [];
      Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(state));

      for (const phase of ["start", "fetch", "checkout", "processes", "seed", "check"]) {
        const result = f.run(phase, { ORBIT_PROCESS_ENVIRONMENTS: environments });
        assert(result.ok, `${phase}: ${result.output}`);
      }
      // Nothing changed in the units, so only the seed stops and starts the running ones; the stopped scheduler stays stopped.
      const calls = f.state().systemctl.map((call: string[]) => call.join(" "));
      assertEquals(restarts(f.state()), []);
      assert(calls.includes(`stop -- orbit-frankenphp.service ${worker.name}`), calls.join("\n"));
      assert(calls.includes(`start -- orbit-frankenphp.service ${worker.name}`), calls.join("\n"));

      // A changed value is a new environment file and a changed unit: the unit restarts and the former file goes.
      const changed = { ...worker, environment: "/etc/orbit/processes/41/def.env", content: worker.content.replace("abc.env", "def.env") };
      f.container.processes = [changed, paused];
      f.write();
      const rotated = f.run("processes", { ORBIT_PROCESS_ENVIRONMENTS: JSON.stringify({ [changed.environment]: "# Orbit Process 41\nQUEUE=\"low\"\n" }) });
      assert(rotated.ok, rotated.output);
      const rootfs = `${f.folder}/containers/instance-7/etc/orbit/processes/41`;
      assertEquals([...Deno.readDirSync(rootfs)].map((entry) => entry.name), ["def.env"]);
      assertEquals(restarts(f.state()), [[worker.name]]);
      assert(f.state().systemctl.some((call: string[]) => call[0] === "daemon-reload"));
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a Process's start, stop and restart change its unit in the container; a read returns its state and journal",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      f.container.processes = [worker];
      f.write();
      for (const phase of ["container", "workspace", "processes"]) {
        assert(f.run(phase, { ORBIT_PROCESS_ENVIRONMENTS: environments }).ok);
      }
      const calls = () => f.state().systemctl.map((call: string[]) => call.join(" "));

      const stop = f.run("process", { ORBIT_PROCESS_ACTION: "stop" });
      assert(stop.ok, stop.output);
      assert(calls().includes(`disable --now -- ${worker.name}`));
      assertEquals(f.result(), { state: "inactive", output: "" });

      assert(f.run("process", { ORBIT_PROCESS_ACTION: "start" }).ok);
      assert(calls().includes(`enable --now -- ${worker.name}`));
      assertEquals(f.result(), { state: "active", output: "" });

      assert(f.run("process", { ORBIT_PROCESS_ACTION: "restart" }).ok);
      assert(calls().join("\n").includes(`enable -- ${worker.name}\nrestart -- ${worker.name}`));

      const state = f.state();
      state.journal = { [worker.name]: ["2026-11-10T08:00:00+00:00 worker[7]: first", "2026-11-10T08:00:01+00:00 worker[7]: second"] };
      Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(state));
      const logs = f.run("process", { ORBIT_PROCESS_ACTION: "logs" });
      assert(logs.ok, logs.output);
      // Newest first, as the Node agent reads a journal; the lines stay out of the run's output.
      assertEquals(f.result(), { state: "active", output: "2026-11-10T08:00:01+00:00 worker[7]: second\n2026-11-10T08:00:00+00:00 worker[7]: first\n" });
      assert(!logs.output.includes("worker[7]"), logs.output);

      state.journal[worker.name] = ["2026-11-10T08:00:00+00:00 worker[7]: only"];
      Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(state));
      const single = f.run("process", { ORBIT_PROCESS_ACTION: "logs" });
      assert(single.ok, single.output);
      assertEquals(f.result(), { state: "active", output: "2026-11-10T08:00:00+00:00 worker[7]: only\n" });
      assert(!single.output.includes("worker[7]"), single.output);

      // A unit that does not come up fails the start with the shared code.
      const failing = f.state();
      failing.failing = [worker.name];
      failing.inactive = [worker.name];
      Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(failing));
      assert(!f.run("process", { ORBIT_PROCESS_ACTION: "start" }).ok);
      assertEquals(f.result(), { error_code: "process.start_failed" });

      // A stopped container has no unit to change.
      assert(f.run("stop").ok);
      assert(!f.run("process", { ORBIT_PROCESS_ACTION: "restart" }).ok);
      assertEquals(f.result(), { error_code: "process.target_inactive" });
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a clone runs its own Process units: the default's units and folders from the seed go, and its own start",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      f.container.processes = [worker];
      f.write();
      for (const phase of ["container", "workspace", "processes", "seed"]) {
        assert(f.run(phase, { ORBIT_PROCESS_ENVIRONMENTS: environments }).ok);
      }
      const seed = git(f.source, "rev-parse", "HEAD");
      // The clone's own record of the same definition has its own id, unit and environment file.
      const own = { ...worker, id: 51, name: "orbit-process-51-worker.service", environment: "/etc/orbit/processes/51/abc.env",
        content: worker.content.replace("/41/", "/51/") };
      Object.assign(f.container, {
        instance_id: 8, name: "instance-8", address: "10.253.0.3", acl: "instance-8", mirror: `${f.folder}/mirror/8.git`, branch: "feature",
        firewall: [], processes: [own],
        clone: { source: "instance-7", snapshot: `seed-${seed}`, seed_commit: seed, source_mirror: `${f.folder}/mirror/7.git`,
          t3: { port: 3800, listen: "10.44.0.7", device: "t3", unit: "t3.service", internal_port: 3773, ready_seconds: 1, t3: "/t3",
            project_mark: "/home/agent/.config/orbit/t3-project" },
          model_environment: { path: "/home/agent/.config/orbit/model.env", uid: 2750, gid: 2750, mode: "0600" }, files: [], links: [] },
      });
      f.write();
      const clone = `${f.folder}/containers/instance-8`;
      for (const phase of ["clone", "workspace", "processes"]) {
        const result = f.run(phase, { ORBIT_PROCESS_ENVIRONMENTS: JSON.stringify({ [own.environment]: "# Orbit Process 51\nQUEUE=\"high\"\n" }) });
        assert(result.ok, `${phase}: ${result.output}`);
      }
      const units = [...Deno.readDirSync(`${clone}/etc/systemd/system`)].map((entry) => entry.name).filter((name) => name.startsWith("orbit-process-"));
      assertEquals(units, [own.name]);
      assertEquals([...Deno.readDirSync(`${clone}/etc/orbit/processes`)].map((entry) => entry.name), ["51"]);
      const calls = f.state().systemctl.map((call: string[]) => call.join(" "));
      assert(calls.includes(`enable --now -- orbit-frankenphp.service ${own.name}`), calls.join("\n"));
      // The `processes` phase still removes a unit and folder the Instance does not own, such as one that came back with a copy.
      Deno.writeTextFileSync(`${clone}/etc/systemd/system/${worker.name}`, worker.content);
      Deno.mkdirSync(`${clone}/etc/orbit/processes/41`);
      assert(f.run("processes", { ORBIT_PROCESS_ENVIRONMENTS: JSON.stringify({ [own.environment]: "# Orbit Process 51\nQUEUE=\"high\"\n" }) }).ok);
      assert(f.state().systemctl.some((call: string[]) => call.join(" ") === `disable --now -- ${worker.name}`));
      assertEquals([...Deno.readDirSync(`${clone}/etc/orbit/processes`)].map((entry) => entry.name), ["51"]);
      assert(![...Deno.readDirSync(`${clone}/etc/systemd/system`)].some((entry) => entry.name === worker.name));
      // The default keeps its own unit.
      assert(Deno.statSync(`${f.folder}/containers/instance-7/etc/systemd/system/${worker.name}`).isFile);
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a clone's first boot starts no unit of the default and the clone has its own machine-id",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      f.container.processes = [worker];
      f.write();
      for (const phase of ["container", "workspace", "processes", "seed"]) {
        assert(f.run(phase, { ORBIT_PROCESS_ENVIRONMENTS: environments }).ok);
      }
      // The default's seed keeps FrankenPHP and its worker enabled.
      const wants = (name: string) => [...Deno.readDirSync(`${f.folder}/containers/${name}/etc/systemd/system/multi-user.target.wants`)]
        .map((entry) => entry.name).sort();
      assertEquals(wants("instance-7"), ["orbit-frankenphp.service", worker.name]);
      const seed = git(f.source, "rev-parse", "HEAD");
      const own = { ...worker, id: 51, name: "orbit-process-51-worker.service", environment: "/etc/orbit/processes/51/abc.env",
        content: worker.content.replace("/41/", "/51/") };
      Object.assign(f.container, {
        instance_id: 8, name: "instance-8", address: "10.253.0.3", acl: "instance-8", mirror: `${f.folder}/mirror/8.git`, branch: "feature",
        firewall: [], processes: [own],
        clone: { source: "instance-7", snapshot: `seed-${seed}`, seed_commit: seed, source_mirror: `${f.folder}/mirror/7.git`,
          t3: { port: 3800, listen: "10.44.0.7", device: "t3", unit: "t3.service", internal_port: 3773, ready_seconds: 1, t3: "/t3",
            project_mark: "/home/agent/.config/orbit/t3-project" },
          model_environment: { path: "/home/agent/.config/orbit/model.env", uid: 2750, gid: 2750, mode: "0600" }, files: [], links: [] },
      });
      f.write();
      const clone = `${f.folder}/containers/instance-8`;
      const result = f.run("clone");
      assert(result.ok, result.output);
      // At its first boot nothing of the default starts: no FrankenPHP on the default's address, no worker on its database.
      assertEquals(f.state().containers["instance-8"].booted, [[]]);
      assertEquals(f.state().containers["instance-8"].bootMachineIds, ["696e7374616e63652d382d2d2d2d2d2d"]);
      assertEquals([...Deno.readDirSync(`${clone}/etc/orbit/processes`)], []);
      assertEquals(Deno.readTextFileSync(`${clone}/etc/machine-id`), "696e7374616e63652d382d2d2d2d2d2d\n");
      assert(Deno.readTextFileSync(`${f.folder}/containers/instance-7/etc/machine-id`) !== Deno.readTextFileSync(`${clone}/etc/machine-id`));
      // Its own `processes` phase enables its own units; the next boot starts those only.
      for (const phase of ["workspace", "processes"]) {
        assert(f.run(phase, { ORBIT_PROCESS_ENVIRONMENTS: JSON.stringify({ [own.environment]: "# Orbit Process 51\nQUEUE=\"high\"\n" }) }).ok);
      }
      assertEquals(wants("instance-8"), ["orbit-frankenphp.service", own.name]);
      assert(f.run("stop").ok);
      assert(f.run("start").ok);
      assertEquals(f.state().containers["instance-8"].booted.at(-1), ["orbit-frankenphp.service", own.name]);
      // The default keeps its own.
      assertEquals(wants("instance-7"), ["orbit-frankenphp.service", worker.name]);
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "the workspace stays writable for its group before and after the deploy steps, whoever installed last",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      for (const phase of ["container", "workspace"]) assert(f.run(phase).ok);
      const workspace = `${f.folder}/containers/instance-7/srv/workspace`;
      // An install as `orbit` asks for 0755 folders and 0644 files, as Bun does in node_modules.
      Deno.mkdirSync(`${workspace}/node_modules/react`, { recursive: true, mode: 0o755 });
      Deno.chmodSync(`${workspace}/node_modules`, 0o755);
      Deno.chmodSync(`${workspace}/node_modules/react`, 0o755);
      Deno.writeTextFileSync(`${workspace}/node_modules/react/index.js`, "x");
      Deno.chmodSync(`${workspace}/node_modules/react/index.js`, 0o644);
      const mode = (path: string) => Deno.statSync(`${workspace}/${path}`).mode! & 0o777;
      const processes = f.run("processes");
      assert(processes.ok, processes.output);
      assertEquals([mode("node_modules"), mode("node_modules/react"), mode("node_modules/react/index.js")], [0o775, 0o775, 0o664]);
      // Nothing left to change: a rerun changes nothing.
      assertEquals(f.run("processes").changed, 0);

      // A later install as `agent` leaves 0755 folders again; the next deployment's checkout opens them before its steps run.
      Deno.mkdirSync(`${workspace}/node_modules/.vite`, { mode: 0o755 });
      Deno.chmodSync(`${workspace}/node_modules/.vite`, 0o755);
      assert(f.run("fetch").ok);
      const checkout = f.run("checkout");
      assert(checkout.ok, checkout.output);
      assertEquals(mode("node_modules/.vite"), 0o775);
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "the agent phase trusts only its workspace and preserves other Git settings on reruns",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    const rootfs = `${f.folder}/containers/instance-7`;
    try {
      const created = f.run("container");
      assert(created.ok, created.output);
      Object.assign(f.container, {
        clone: { files: [], links: [],
          model_environment: { path: "/home/agent/.config/orbit/model.env", uid: 2750, gid: 2750, mode: "0600" } },
      });
      f.write();
      const config = `${rootfs}/home/agent/.gitconfig`;
      const gitConfig = (...args: string[]) => git(rootfs, "config", "--file", config, ...args);
      const first = f.run("agent");
      assert(first.ok, first.output);
      assertEquals(gitConfig("--get-all", "safe.directory"), "/srv/workspace");

      gitConfig("user.name", "Feature Agent");
      gitConfig("user.email", "feature@example.test");
      const again = f.run("agent");
      assert(again.ok, again.output);
      assertEquals(again.changed, 0, again.output);
      assertEquals(gitConfig("user.name"), "Feature Agent");
      assertEquals(gitConfig("user.email"), "feature@example.test");

      // Repair even a broader trust list without replacing the agent's other settings.
      gitConfig("--add", "safe.directory", "*");
      gitConfig("--add", "safe.directory", "/another/workspace");
      const repaired = f.run("agent");
      assert(repaired.ok, repaired.output);
      assertEquals(gitConfig("--get-all", "safe.directory"), "/srv/workspace");
      assertEquals(gitConfig("user.name"), "Feature Agent");
      assertEquals(gitConfig("user.email"), "feature@example.test");
      const stable = f.run("agent");
      assert(stable.ok, stable.output);
      assertEquals(stable.changed, 0, stable.output);
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "the agent can commit with its default identity and keeps each configured identity value",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    const rootfs = `${f.folder}/containers/instance-7`;
    try {
      for (const phase of ["container", "workspace"]) {
        const result = f.run(phase);
        assert(result.ok, result.output);
      }
      Object.assign(f.container, {
        clone: { files: [], links: [],
          model_environment: { path: "/home/agent/.config/orbit/model.env", uid: 2750, gid: 2750, mode: "0600" } },
      });
      f.write();
      const prepared = f.run("agent");
      assert(prepared.ok, prepared.output);
      const commit = new Deno.Command("git", {
        args: ["-c", "core.hooksPath=/dev/null", "commit", "--allow-empty", "--no-gpg-sign", "-m", "agent commit"],
        cwd: `${rootfs}/srv/workspace`, clearEnv: true,
        env: { HOME: `${rootfs}/home/agent`, PATH: Deno.env.get("PATH")!, GIT_CONFIG_NOSYSTEM: "1" },
      }).outputSync();
      assert(commit.success, new TextDecoder().decode(commit.stderr));
      assertEquals(f.workspace("log", "-1", "--format=%an <%ae>"), "Orbit Agent <agent@orbit.local>");

      const config = (...args: string[]) => git(rootfs, "config", "--file", `${rootfs}/home/agent/.gitconfig`, ...args);
      config("user.name", "Feature Agent");
      config("user.email", "feature@example.test");
      const again = f.run("agent");
      assert(again.ok, again.output);
      assertEquals(again.changed, 0, again.output);
      assertEquals(config("user.name"), "Feature Agent");
      assertEquals(config("user.email"), "feature@example.test");

      config("--unset", "user.email");
      const partial = f.run("agent");
      assert(partial.ok, partial.output);
      assertEquals(config("user.name"), "Feature Agent");
      assertEquals(config("user.email"), "agent@orbit.local");
      const stable = f.run("agent");
      assert(stable.ok, stable.output);
      assertEquals(stable.changed, 0, stable.output);
    } finally {
      f.cleanup();
    }
  },
});
