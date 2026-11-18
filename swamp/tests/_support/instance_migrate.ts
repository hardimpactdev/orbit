/** The shared harness of the move fixtures: the shipped playbook of a move (instance-migrate.yml) and its container phases
 * (instance-container.yml) against a stateful Incus boundary. The Node is this machine; nothing becomes root. */
import { assert, assertEquals } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";
import { createHash } from "node:crypto";
import { legacyScheduleUnits, renderScheduleUnits } from "../../extensions/behaviors/operations/processes-schedules/contract.ts";

export const has = (program: string) => new Deno.Command("sh", { args: ["-c", `command -v ${program}`] }).outputSync().success;
export const hasAnsible = has("ansible-playbook");
export const root = new URL("../../ansible/", import.meta.url);
export type Task = Record<string, unknown>;
export type MigrationSchedule = {
  id: string;
  name: string;
  service: string;
  timer: string;
  user: string;
  group: string;
  home: string;
  shell: string;
  login_shell: boolean;
  directory: string;
  source_directory: string;
  command_hash: string;
  mapped_command_hash: string;
  source_script_hash: string;
  source_command_paths: string[];
  calendar: string;
  timeout_seconds: number;
  desired: "enabled" | "disabled";
  container_user: string;
  container_home: string;
  container_shell: string;
  path: string;
  environment_file: string;
  workspace_ids: { group: number; writer: number };
  app_account: { path: string; content: string };
  fixture_command: string;
};

/** Ownership needs root; the modes stay. UFW rules live in a file of the fixture, one JSON line each: added, and removed again. */
export function transform(task: Task): Task {
  for (const part of ["block", "always", "rescue"]) {
    if (Array.isArray(task[part])) task[part] = (task[part] as Task[]).map(transform);
  }
  if (task["ansible.builtin.systemd_service"]) {
    task["orbit_migrate_systemd"] = task["ansible.builtin.systemd_service"];
    delete task["ansible.builtin.systemd_service"];
  }
  const ufw = task["community.general.ufw"] as Record<string, unknown> | undefined;
  if (ufw) {
    delete task["community.general.ufw"];
    task["ansible.builtin.lineinfile"] = {
      path: "{{ lookup('ansible.builtin.env', 'HOST_FIXTURE') }}/ufw",
      line: "{{ item | to_json }}",
      state: ufw.delete === true ? "absent" : "present",
      create: true,
      mode: "0600",
    };
  }
  const args = (task["ansible.builtin.file"] ?? task["ansible.builtin.copy"]) as Record<string, unknown> | undefined;
  if (args) {
    delete args.owner;
    delete args.group;
  }
  return task;
}

/** Only the Schedule task's host paths are remapped; container paths and shared unit contracts stay literal. */
export function scheduleHostPaths(value: unknown): unknown {
  if (typeof value === "string" && (value.startsWith("{{ incus_project") || value.startsWith("{{ as_root"))) return value;
  if (typeof value === "string") return value
    .replaceAll("/etc/systemd/system/", "{{ lookup('ansible.builtin.env', 'HOST_FIXTURE') }}/host/systemd/")
    .replaceAll("/etc/orbit/schedules/", "{{ lookup('ansible.builtin.env', 'HOST_FIXTURE') }}/host/schedules/");
  if (Array.isArray(value)) return value.map(scheduleHostPaths);
  if (value !== null && typeof value === "object") return Object.fromEntries(Object.entries(value).map(([key, item]) => [key, scheduleHostPaths(item)]));
  return value;
}
export const git = (cwd: string, ...args: string[]) => {
  const done = new Deno.Command("git", {
    args: ["-c", "user.name=Orbit", "-c", "user.email=orbit@example.test", "-c", "maintenance.auto=false", ...args],
    cwd,
  }).outputSync();
  if (!done.success) throw new Error(new TextDecoder().decode(done.stderr));
  return new TextDecoder().decode(done.stdout).trim();
};
export const exists = (path: string) => {
  try {
    Deno.lstatSync(path);
    return true;
  } catch {
    return false;
  }
};

/**
 * A Node with one Project's checkouts under `apps`: the default's repository, and with `feature` a linked worktree of it. The
 * checkouts live outside the folders that the Incus boundary maps into a container, so their paths reach it as they are.
 */
export function fixture(instance: "default" | "feature" = "default", dirty = false, linked = true) {
  const folder = Deno.makeTempDirSync();
  const apps = Deno.makeTempDirSync({ dir: "/var/tmp" });
  for (const path of ["bin", "tasks", "home", "run", "markers"]) Deno.mkdirSync(`${folder}/${path}`);
  Deno.copyFileSync(new URL("instance_bridge.py", root), `${folder}/instance_bridge.py`);
    Deno.copyFileSync(new URL("instance_network.py", root), `${folder}/instance_network.py`);
    Deno.copyFileSync(new URL("instance-nic-filters.json", root), `${folder}/instance-nic-filters.json`);
  Deno.writeTextFileSync(`${folder}/bin/curl`, `#!/bin/sh\nexec '${Deno.execPath()}' run -A '${new URL("../fixtures/instance-bridge-curl.ts", import.meta.url).pathname}' "$@"\n`);
  Deno.chmodSync(`${folder}/bin/curl`, 0o755);
  Deno.writeTextFileSync(`${folder}/nat.txt`, "");
  Deno.writeTextFileSync(`${folder}/bin/iptables-save`, `#!/bin/sh\ncat '${folder}/nat.txt'\n`);
  Deno.chmodSync(`${folder}/bin/iptables-save`, 0o755);
  for (const shared of ["instance-network-nic-check.yml", "instance-bridge-rules.yml"]) {
    Deno.writeTextFileSync(`${folder}/tasks/${shared}`, stringify((parse(Deno.readTextFileSync(new URL(`tasks/${shared}`, root))) as Task[]).map(transform)).replaceAll("/usr/local/libexec", `${folder}/libexec`).replaceAll("/var/lib/orbit/instance-bridge", `${folder}/bridge-policy`));
  }
  Deno.mkdirSync(`${folder}/library`);
  Deno.copyFileSync(new URL("../fixtures/instance-migrate-systemd.py", import.meta.url), `${folder}/library/orbit_migrate_systemd.py`);
  Deno.copyFileSync(new URL("library/orbit_migrate_env.py", root), `${folder}/library/orbit_migrate_env.py`);
  Deno.writeTextFileSync(`${folder}/host-processes.json`, "{}");
  Deno.writeTextFileSync(`${folder}/process-environments.json`, "{}");
  Deno.writeTextFileSync(`${folder}/state.json`, JSON.stringify({"network-acls": [{name: "orbitinst0-egress", egress: [], ingress: []}]}));
  Deno.writeTextFileSync(
    `${folder}/bin/incus`,
    `#!/bin/sh\ncase " $* " in *" systemd-analyze verify "*) exit 0;; esac\nexec '${Deno.execPath()}' run -A '${new URL("../fixtures/instance-migrate-incus.ts", import.meta.url).pathname}' "$@"\n`,
  );
  Deno.chmodSync(`${folder}/bin/incus`, 0o755);
  Deno.copyFileSync(new URL("instance_migrate_files.py", root), `${folder}/instance_migrate_files.py`);
  Deno.copyFileSync(new URL("instance_migrate_links.py", root), `${folder}/instance_migrate_links.py`);
  Deno.copyFileSync(new URL("instance_migrate_git.py", root), `${folder}/instance_migrate_git.py`);
  Deno.copyFileSync(new URL("instance_machine_name.py", root), `${folder}/instance_machine_name.py`);
  Deno.copyFileSync(new URL("instance_node_services.py", root), `${folder}/instance_node_services.py`);
  for (const name of ["instance-container-handlers.yml", "instance-container-phase.yml", "instance-container-run-phase.yml", "instance-migrate-phase.yml", "instance-migrate-process-ready.yml", "instance-migrate-discard.yml", "instance-migrate-retire.yml", "instance-migrate-retirement-check.yml", "instance-migrate-schedules.yml", "instance-migrate-schedule-check.yml", "instance-migrate-link-result.yml", "instance-migrate-env-check.yml", "instance-private-names.yml", "instance-machine-name.yml"]) {
    const tasks = (parse(Deno.readTextFileSync(new URL(`tasks/${name}`, root))) as Task[]).map((task) => {
      const transformed = transform(structuredClone(task));
      return name.startsWith("instance-migrate-schedule") ? scheduleHostPaths(transformed) : transformed;
    });
    Deno.writeTextFileSync(`${folder}/tasks/${name}`, stringify(tasks));
  }
  Deno.copyFileSync(new URL("instance_node_services.py", root), `${folder}/instance_node_services.py`);
  Deno.copyFileSync(new URL("instance_node_runtime.py", root), `${folder}/instance_node_runtime.py`);
  Deno.copyFileSync(new URL("instance_workspace_owner.py", root), `${folder}/instance_workspace_owner.py`);
  Deno.copyFileSync(new URL("tasks/instance-node-runtime.yml", root), `${folder}/tasks/instance-node-runtime.yml`);
  Deno.mkdirSync(`${folder}/templates`);
  Deno.writeTextFileSync(`${folder}/templates/instance-migrate-schedule.j2`,
    Deno.readTextFileSync(new URL("templates/instance-migrate-schedule.j2", root)).replace("playbook_dir ~ '/../extensions/", "playbook_dir ~ '/extensions/"));
  const sharedContracts = "extensions/behaviors/operations/processes-schedules";
  Deno.mkdirSync(`${folder}/${sharedContracts}`, { recursive: true });
  Deno.copyFileSync(new URL(`../${sharedContracts}/unit-templates.json`, root), `${folder}/${sharedContracts}/unit-templates.json`);
  for (const name of ["instance-container.yml", "instance-migrate.yml", "tasks/address-node.yml"]) {
    const source = Deno.readTextFileSync(new URL(name, root));
    if (name === "instance-migrate.yml") {
      const plays = parse(source) as Task[];
      for (const play of plays) {
        if (Array.isArray(play.tasks)) play.tasks = (play.tasks as Task[]).map(transform);
        if (Array.isArray(play.handlers)) play.handlers = (play.handlers as Task[]).map(transform);
      }
      Deno.writeTextFileSync(`${folder}/${name}`, stringify(plays));
    } else Deno.writeTextFileSync(`${folder}/${name}`, source);
  }
  // The default's checkout: one pushed commit, then work the origin never saw.
  const main = `${apps}/default`;
  Deno.mkdirSync(main);
  git(main, "init", "--quiet", "--initial-branch", "main");
  Deno.writeTextFileSync(`${main}/.gitignore`, "vendor/\n.env\ndatabase/*.sqlite\n");
  Deno.writeTextFileSync(`${main}/composer.json`, "{}\n");
  Deno.writeTextFileSync(`${main}/package.json`, "{}\n");
  Deno.writeTextFileSync(`${main}/index.php`, "<?php echo 1;\n");
  Deno.writeTextFileSync(`${main}/old.php`, "<?php // goes\n");
  git(main, "add", ".");
  git(main, "commit", "--quiet", "-m", "first");
  if (instance === "feature") {
    if (linked) git(main, "worktree", "add", "--quiet", "-b", "feature", `${apps}/feature`);
    else {
      git(main, "clone", "--quiet", "--no-hardlinks", main, `${apps}/feature`);
      git(`${apps}/feature`, "checkout", "--quiet", "-b", "feature");
    }
  }
  const checkout = `${apps}/${instance}`;
  const branch = instance === "default" ? "main" : "feature";
  Deno.writeTextFileSync(`${checkout}/local.php`, "<?php // committed, never pushed\n");
  git(checkout, "add", "local.php");
  git(checkout, "commit", "--quiet", "-m", "local");
  // Uncommitted: a changed file, a staged new file, an untracked file, a deleted file, and ignored files.
  Deno.writeTextFileSync(`${checkout}/index.php`, "<?php echo 2;\n");
  Deno.writeTextFileSync(`${checkout}/staged.php`, "<?php // staged\n");
  git(checkout, "add", "staged.php");
  Deno.writeTextFileSync(`${checkout}/note.txt`, "untracked\n");
  Deno.removeSync(`${checkout}/old.php`);
  Deno.mkdirSync(`${checkout}/vendor/pkg`, { recursive: true });
  Deno.writeTextFileSync(`${checkout}/vendor/pkg/a.php`, "<?php // dependency\n");
  Deno.mkdirSync(`${checkout}/database`);
  Deno.writeTextFileSync(`${checkout}/database/database.sqlite`, "sqlite\n");
  Deno.writeTextFileSync(`${checkout}/.env`, `APP_KEY=base64:kept\nDB_DATABASE=${checkout}/database/database.sqlite\n`);
  if (!dirty) {
    git(checkout, "add", "--all");
    git(checkout, "commit", "--quiet", "-m", "save changes before retirement");
  }
  // Clean retirement fixtures have all commits reachable from a local remote-tracking ref.
  if (!dirty) git(checkout, "update-ref", "refs/remotes/origin/main", "HEAD");
  const id = instance === "default" ? 7 : 8;
  const clone = instance === "default" ? null : {
    t3: { port: 3800, listen: "10.44.0.7", device: "t3", unit: "t3.service", internal_port: 3773, ready_seconds: 2 },
    files: [],
    links: [],
  };
  const container = {
    instance_id: id,
    project: "orbit-instances",
    name: `instance-${id}`,
    machine_name: { hostname: `move-${id}.shop`, pretty: `move-${id}.shop` },
    image: "orbit-instance",
    config: { "limits.cpu": "4", "limits.memory": "8GiB", "limits.processes": "4096" },
    root_disk_pool: "orbit-instances",
    root_disk_size: "40GiB",
    address: `10.253.0.${id}`,
    network: "orbitinst0",
    nic_acl: "orbitinst0-proxy",
    bridge_cidr: "10.253.0.0/24",
    bridge_admitted_at: 1800000000000000,
    bridge_endpoints: [] as {host: string; port: number; docker: boolean}[],
    acl: `instance-${id}`,
    acl_rules: {
      description: `Orbit Instance ${id}`,
      egress: [{ action: "allow", destination: "10.44.0.7/32", protocol: "tcp", destination_port: "5432", state: "enabled" }],
      ingress: [{ action: "allow", source: "10.253.0.1/32", protocol: "tcp", destination_port: "8080", state: "enabled" }],
    },
    firewall: [{ rule: "allow", interface_in: "orbitinst0", from_ip: `10.253.0.${id}`, to_ip: "10.44.0.7", to_port: "5432", proto: "tcp", comment: `orbit:instance-${id}-database` }],
    mirror: `${folder}/mirror/${id}.git`,
    // The Node's mirror fetches from the checkout itself.
    repository: checkout,
    protocols: ["file"],
    branch,
    commit: null,
    workspace: "/srv/workspace",
    environment_file: "/srv/workspace/.env",
    workspace_ids: { group: 33, writer: 33 },
    app_account: { path: "/usr/local/libexec/orbit-app-user", content: Deno.readTextFileSync(new URL("files/instance-app-user.sh", root)) },
    units: [{ name: "orbit-frankenphp.service", content: "[Service]\nUser=www-data\n" }],
    php: null as string | null,
    processes: [] as { id: number; name: string; content: string; environment: string | null; desired: string }[],
    clone,
  };
  const migrate = {
    checkout,
    branch,
    default: instance === "default",
    application: checkout,
    awake: { directory: `${folder}/markers`, path: `${folder}/markers/app-instance-${id}.awake` },
    environment: {
      targets: [] as { host: string; port: number }[],
      node_addresses: ["10.44.0.7"],
      proxy_policy: { private_v4: ["10.0.0.0/8", "127.0.0.0/8"], private_domains: ["orbit"] },
    },
    databases: [] as { host: string; port: number }[],
    processes: [] as { name: string; path: string; environment_directory: string; desired: string; executables: string[] }[],
    schedules: [] as MigrationSchedule[],
    firewall_prefix: `orbit:instance-${id}-`,
    retire: [{ path: checkout, mirror: `${folder}/mirror/${id}.git` }] as { path: string; mirror: string; common_of?: string }[],
  };
  const ssh_execution = {
    identity_file: "/dev/null",
    target: { host: "127.0.0.1", user: "orbit", port: 22, host_keys: ["ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl"] },
  };
  const write = () => Deno.writeTextFileSync(`${folder}/run/intent.json`, JSON.stringify({ ssh_execution, container, migrate }));
  write();
  Deno.writeTextFileSync(migrate.awake.path, "");
  const rootfs = `${folder}/containers/instance-${id}`;
  return {
    folder,
    apps,
    checkout,
    main,
    container,
    migrate,
    rootfs,
    retained: () => {
      const root = `${checkout}.orbit-retired-git`;
      return `${root}/${[...Deno.readDirSync(root)].find((entry) => entry.isDirectory)!.name}`;
    },
    write,
    fault(phase: string) {
      const file = `${folder}/tasks/${["quiesce", "files"].includes(phase) ? "instance-migrate" : "instance-container"}-phase.yml`;
      const tasks = parse(Deno.readTextFileSync(file)) as Task[];
      tasks.push({ name: "Force a failure after the phase effects", tags: ["always"], when: `phase == '${phase}'`, "ansible.builtin.fail": { msg: "forced migration failure" } });
      Deno.writeTextFileSync(file, stringify(tasks));
    },
    run(phase: string | string[], rawContainer = false, runId = folder.slice(folder.lastIndexOf("/") + 1), extraEnv: Record<string, string> = {}) {
      // Batch unchanged production plays with their phase fixed instead of launching Ansible for each tag.
      // Task conditions still select the phase; all tags are enabled inside each separate play.
      const phases = Array.isArray(phase) ? phase : [phase];
      const batch = Array.isArray(phase);
      phase = phases[0];
      const batchFile = "batched-migrate.yml";
      if (batch) {
        const plays = phases.flatMap((selected) => {
          const source = ["container", "quiesce", "files", "retire", "restore"].includes(selected) ? "instance-migrate.yml" : "instance-container.yml";
          return JSON.parse(JSON.stringify(parse(Deno.readTextFileSync(`${folder}/${source}`)))
            .replaceAll("ansible_run_tags | first", `'${selected}'`)) as Task[];
        });
        for (const play of plays) play.tags = ["always"];
        Deno.writeTextFileSync(`${folder}/${batchFile}`, stringify(plays));
      }
      const playbook = batch ? batchFile : !rawContainer && ["container", "quiesce", "files", "retire", "restore"].includes(phase) ? "instance-migrate.yml" : "instance-container.yml";
      const done = new Deno.Command(phase === "processes" ? "timeout" : "ansible-playbook", {
        args: [...(phase === "processes" ? ["120", "ansible-playbook"] : []), "-i", "localhost,", "--tags", batch ? "all" : phase, "-e", "ansible_connection=local", "-e", "ansible_become=false",
          "-e", "ansible_python_interpreter={{ ansible_playbook_python }}", `${folder}/${playbook}`],
        env: {
          HOST_FIXTURE: folder,
          ORBIT_SCHEDULE_COMMANDS: JSON.stringify({schedule_commands: Object.fromEntries(migrate.schedules.map((row) => [row.id, (row as MigrationSchedule).fixture_command]))}),
          ORBIT_PROCESS_ENVIRONMENTS: Deno.readTextFileSync(`${folder}/process-environments.json`),
          PATH: `${folder}/bin:${Deno.env.get("PATH")}`,
          ANSIBLE_NOCOLOR: "1",
          ANSIBLE_PIPELINING: "true",
          ANSIBLE_HOME: `${folder}/ansible`,
          ANSIBLE_LOCAL_TEMP: `${folder}/tmp`,
          ORBIT_RUN: `${folder}/run`,
          ORBIT_RUN_ID: runId,
          // As in the workflow of a move, the container phases get no token and no .env from the Gateway.
          GIT_CONFIG_NOSYSTEM: "1",
          HOME: `${folder}/home`,
          ...extraEnv,
        },
      }).outputSync();
      return { ok: done.success, output: new TextDecoder().decode(done.stdout) + new TextDecoder().decode(done.stderr) };
    },
    buildBatched() {
      const result = this.run(["container", "quiesce", "workspace", "files"]);
      assert(result.ok, result.output);
    },
    /** The phases of a move up to the copied files. */
    build() {
      this.buildBatched();
      if (migrate.schedules.length) {
        const schedules = this.run("processes");
        assert(schedules.ok, schedules.output);
      }
    },
    state: () => JSON.parse(Deno.readTextFileSync(`${folder}/state.json`)),
    result: () => JSON.parse(Deno.readTextFileSync(`${folder}/run/result.json`)),
    ufw: () => exists(`${folder}/ufw`) ? Deno.readTextFileSync(`${folder}/ufw`).trim().split("\n").filter((line) => line !== "") : [],
    workspace: (...args: string[]) => git(`${rootfs}/srv/workspace`, ...args),
    read: (path: string) => Deno.readTextFileSync(`${rootfs}/srv/workspace/${path}`),
    cleanup: () => {
      Deno.removeSync(folder, { recursive: true });
      Deno.removeSync(apps, { recursive: true });
    },
  };
}
// Include bytes from HEAD, index, refs, objects and registry metadata; reads must not refresh the owner's index.
export function gitFiles(root: string, exclude = ""): Record<string, string> {
  const result: Record<string, string> = {};
  function walk(path: string) {
    for (const entry of Deno.readDirSync(path)) {
      const file = path + "/" + entry.name;
      if (file === exclude) continue;
      if (entry.isSymlink) result[file.slice(root.length)] = "link:" + Deno.readLinkSync(file);
      else if (entry.isDirectory) walk(file);
      else result[file.slice(root.length)] = createHash("sha256").update(Deno.readFileSync(file)).digest("hex");
    }
  }
  walk(root);
  return Object.fromEntries(Object.entries(result).sort(([a], [b]) => a.localeCompare(b)));
}
export function migrationProcesses(f: ReturnType<typeof fixture>, presetDesired: "running" | "stopped") {
  const states: Record<string, { state: string; enabled: boolean }> = {};
  const environments: Record<string, string> = {};
  for (const [id, desired, preset] of [[70, "running", false], [71, "stopped", false], [72, presetDesired, true]] as const) {
    const name = `orbit-process-${id}-${preset ? "vp-dev" : "worker"}.service`;
    const directory = `${f.folder}/host/${id}`;
    Deno.mkdirSync(directory, { recursive: true });
    const path = `${directory}/${name}`;
    Deno.writeTextFileSync(path, "old unit\n");
    Deno.writeTextFileSync(`${directory}/old.env`, "OLD_TOKEN=kept\n");
    f.migrate.processes.push({ name, path, environment_directory: directory, desired, executables: ["/opt/orbit-runtime/current/bin/node"] });
    const environment = `/etc/orbit/processes/${id}/vars.env`;
    const content = preset
      ? `[Service]\nUser=www-data\nWorkingDirectory=/srv/workspace\nEnvironmentFile=-/srv/workspace/.env\nEnvironmentFile=${environment}\nEnvironment=ORBIT_DEV_SERVER_PORT=5195\nExecStart=/opt/orbit-runtime/current/bin/vp dev --host=0.0.0.0 --port=5195 --strictPort --base=/__orbit/vite/\n`
      : "[Service]\nUser=www-data\n";
    f.container.processes.push({ id, name, content, environment, desired });
    environments[environment] = `QUEUE_TOKEN=process-secret-${id}\n`;
    states[name] = { state: desired === "running" ? "started" : "stopped", enabled: desired === "running" };
  }
  const unrelated = `${f.folder}/host/unrelated`;
  Deno.mkdirSync(unrelated, { recursive: true });
  Deno.writeTextFileSync(`${unrelated}/orbit-process-999-other.service`, "unrelated unit\n");
  Deno.writeTextFileSync(`${unrelated}/vars.env`, "OTHER_TOKEN=kept\n");
  states["orbit-process-999-other.service"] = { state: "started", enabled: true };
  Deno.writeTextFileSync(`${f.folder}/host-processes.json`, JSON.stringify(states));
  Deno.writeTextFileSync(`${f.folder}/process-environments.json`, JSON.stringify(environments));
  f.write();
  return () => JSON.parse(Deno.readTextFileSync(`${f.folder}/host-processes.json`));
}

/** The same UUID host timer before and after cutover; only its checkout-dependent service is replaced. */
export function migrationSchedules(f: ReturnType<typeof fixture>, desired: "enabled" | "disabled", kind: "current" | "agent" | "direct" = "current", command = "php artisan schedule:run", sourceDirectory = f.checkout) {
  const id = "11111111-1111-4111-8111-000000000001";
  const unrelated = "orbit-schedule-22222222-2222-4222-8222-000000000002";
  const digest = (value: string) => createHash("sha256").update(value).digest("hex");
  const schedule: MigrationSchedule = {
    id,
    name: "laravel-scheduler",
    service: `orbit-schedule-${id}.service`,
    timer: `orbit-schedule-${id}.timer`,
    user: "orbit",
    group: "orbit",
    home: "/home/orbit",
    shell: "/bin/bash",
    login_shell: true,
    directory: "/srv/workspace" + sourceDirectory.slice(f.checkout.length),
    source_directory: sourceDirectory,
    command_hash: digest(command),
    mapped_command_hash: digest(command.replaceAll(f.checkout, "/srv/workspace")),
    source_script_hash: "",
    source_command_paths: [],
    calendar: "*-*-* *:*:00",
    timeout_seconds: 600,
    desired,
    container_user: "www-data",
    container_home: "/var/www",
    container_shell: "/bin/bash",
    path: "/usr/bin:/bin",
    environment_file: "/srv/workspace/.env",
    workspace_ids: { group: 33, writer: 33 },
    app_account: { path: "/usr/local/libexec/orbit-app-user", content: Deno.readTextFileSync(new URL("files/instance-app-user.sh", root)) },
    fixture_command: command.replaceAll(f.checkout, "/srv/workspace"),
  };
  const input = {
    id,
    user: schedule.user,
    group: schedule.group,
    home: schedule.home,
    working_directory: schedule.source_directory,
    shell: schedule.shell,
    login_shell: schedule.login_shell,
    command_hash: schedule.command_hash,
    calendar: schedule.calendar,
    timeout_seconds: schedule.timeout_seconds,
    desired,
  };
  const canonical = renderScheduleUnits(input);
  const legacy = legacyScheduleUnits(input);
  const service = kind === "current" ? canonical.service : kind === "agent" ? legacy.agentService : legacy.directService;
  const systemd = `${f.folder}/host/systemd`;
  const scripts = `${f.folder}/host/schedules`;
  Deno.mkdirSync(systemd, { recursive: true });
  Deno.mkdirSync(scripts, { recursive: true });
  Deno.writeTextFileSync(`${systemd}/${schedule.service}`, service);
  Deno.writeTextFileSync(`${systemd}/${schedule.timer}`, canonical.timer);
  const script = `${legacy.script_prefix}${command}\n`;
  schedule.source_script_hash = digest(script);
  Deno.writeTextFileSync(`${scripts}/${id}.sh`, script);
  Deno.writeTextFileSync(`${scripts}/${id}.complete`, legacy.callback);
  Deno.writeTextFileSync(`${scripts}/${unrelated}.sh`, "unrelated script\n");
  for (const extension of ["service", "timer"]) Deno.writeTextFileSync(`${systemd}/${unrelated}.${extension}`, `unrelated ${extension}\n`);
  const states: Record<string, { state: string; enabled: boolean; status?: Record<string, string> }> = {
    [schedule.service]: { state: "stopped", enabled: false },
    [schedule.timer]: { state: desired === "enabled" ? "started" : "stopped", enabled: desired === "enabled" },
    [`${unrelated}.timer`]: { state: "started", enabled: true },
  };
  Deno.writeTextFileSync(`${f.folder}/host-processes.json`, JSON.stringify(states));
  f.migrate.schedules.push(schedule);
  f.write();
  return {
    schedule,
    service,
    timer: canonical.timer,
    servicePath: `${systemd}/${schedule.service}`,
    timerPath: `${systemd}/${schedule.timer}`,
    scriptPath: `${scripts}/${id}.sh`,
    callbackPath: `${scripts}/${id}.complete`,
    script,
    callback: legacy.callback,
    states,
    writeStates: () => Deno.writeTextFileSync(`${f.folder}/host-processes.json`, JSON.stringify(states)),
    host: () => JSON.parse(Deno.readTextFileSync(`${f.folder}/host-processes.json`)),
    assertUnrelated() {
      assertEquals(Deno.readTextFileSync(`${systemd}/${unrelated}.service`), "unrelated service\n");
      assertEquals(Deno.readTextFileSync(`${systemd}/${unrelated}.timer`), "unrelated timer\n");
      assertEquals(Deno.readTextFileSync(`${scripts}/${unrelated}.sh`), "unrelated script\n");
      assertEquals(this.host()[`${unrelated}.timer`], { state: "started", enabled: true });
    },
  };
}
export const real53Environment = "CACHE_STORE=database\nSESSION_DRIVER=database\nQUEUE_CONNECTION=database\nBROADCAST_CONNECTION=log\nMAIL_MAILER=log\nDB_CONNECTION=sqlite\nREDIS_CLIENT=phpredis\nREDIS_HOST=127.0.0.1\nREDIS_PORT=6379\nREDIS_PASSWORD=\n";
