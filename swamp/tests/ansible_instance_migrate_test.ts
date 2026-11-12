/**
 * Run the shipped playbook of a move (instance-migrate.yml) with the container phases it builds on (instance-container.yml)
 * against a stateful Incus boundary: a default with work that was never committed or pushed, a non-default worktree with the
 * default's old checkout, the restore after a failure and the refusals. The Node is this machine: the run's connection is
 * local and nothing becomes root.
 */
import { assert, assertEquals, assertMatch } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";
import { createHash } from "node:crypto";
import { migrateResult } from "../extensions/behaviors/operations/instance-dev-command/migrate_result.ts";
import {
  legacyScheduleUnits,
  renderScheduleUnits,
} from "../extensions/behaviors/operations/processes-schedules/contract.ts";

const has = (program: string) => new Deno.Command("sh", { args: ["-c", `command -v ${program}`] }).outputSync().success;
const hasAnsible = has("ansible-playbook");
const root = new URL("../ansible/", import.meta.url);

type Task = Record<string, unknown>;
type MigrationSchedule = {
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
  fixture_command: string;
};

/** Ownership needs root; the modes stay. UFW rules live in a file of the fixture, one JSON line each: added, and removed again. */
function transform(task: Task): Task {
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
function scheduleHostPaths(value: unknown): unknown {
  if (typeof value === "string" && (value.startsWith("{{ incus_project") || value.startsWith("{{ as_root"))) return value;
  if (typeof value === "string") return value
    .replaceAll("/etc/systemd/system/", "{{ lookup('ansible.builtin.env', 'HOST_FIXTURE') }}/host/systemd/")
    .replaceAll("/etc/orbit/schedules/", "{{ lookup('ansible.builtin.env', 'HOST_FIXTURE') }}/host/schedules/");
  if (Array.isArray(value)) return value.map(scheduleHostPaths);
  if (value !== null && typeof value === "object") return Object.fromEntries(Object.entries(value).map(([key, item]) => [key, scheduleHostPaths(item)]));
  return value;
}

const git = (cwd: string, ...args: string[]) => {
  const done = new Deno.Command("git", {
    args: ["-c", "user.name=Orbit", "-c", "user.email=orbit@example.test", ...args],
    cwd,
  }).outputSync();
  if (!done.success) throw new Error(new TextDecoder().decode(done.stderr));
  return new TextDecoder().decode(done.stdout).trim();
};
const exists = (path: string) => {
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
function fixture(instance: "default" | "feature" = "default", dirty = false, linked = true) {
  const folder = Deno.makeTempDirSync();
  const apps = Deno.makeTempDirSync({ dir: "/var/tmp" });
  for (const path of ["bin", "tasks", "home", "run", "markers"]) Deno.mkdirSync(`${folder}/${path}`);
  Deno.copyFileSync(new URL("instance_bridge.py", root), `${folder}/instance_bridge.py`);
    Deno.copyFileSync(new URL("instance_network.py", root), `${folder}/instance_network.py`);
  Deno.writeTextFileSync(`${folder}/bin/curl`, `#!/bin/sh\nexec '${Deno.execPath()}' run -A '${new URL("fixtures/instance-bridge-curl.ts", import.meta.url).pathname}' "$@"\n`);
  Deno.chmodSync(`${folder}/bin/curl`, 0o755);
  Deno.writeTextFileSync(`${folder}/nat.txt`, "");
  Deno.writeTextFileSync(`${folder}/bin/iptables-save`, `#!/bin/sh\ncat '${folder}/nat.txt'\n`);
  Deno.chmodSync(`${folder}/bin/iptables-save`, 0o755);
  for (const shared of ["instance-network-nic-check.yml", "instance-bridge-rules.yml"]) {
    Deno.writeTextFileSync(`${folder}/tasks/${shared}`, stringify((parse(Deno.readTextFileSync(new URL(`tasks/${shared}`, root))) as Task[]).map(transform)).replaceAll("/usr/local/libexec", `${folder}/libexec`).replaceAll("/var/lib/orbit/instance-bridge", `${folder}/bridge-policy`));
  }
  Deno.mkdirSync(`${folder}/library`);
  Deno.copyFileSync(new URL("fixtures/instance-migrate-systemd.py", import.meta.url), `${folder}/library/orbit_migrate_systemd.py`);
  Deno.copyFileSync(new URL("library/orbit_migrate_env.py", root), `${folder}/library/orbit_migrate_env.py`);
  Deno.writeTextFileSync(`${folder}/host-processes.json`, "{}");
  Deno.writeTextFileSync(`${folder}/process-environments.json`, "{}");
  Deno.writeTextFileSync(`${folder}/state.json`, JSON.stringify({"network-acls": [{name: "orbitinst0-egress", egress: [], ingress: []}]}));
  Deno.writeTextFileSync(
    `${folder}/bin/incus`,
    `#!/bin/sh\ncase " $* " in *" systemd-analyze verify "*) exit 0;; esac\nexec '${Deno.execPath()}' run -A '${new URL("fixtures/instance-migrate-incus.ts", import.meta.url).pathname}' "$@"\n`,
  );
  Deno.chmodSync(`${folder}/bin/incus`, 0o755);
  Deno.copyFileSync(new URL("instance_migrate_files.py", root), `${folder}/instance_migrate_files.py`);
  Deno.copyFileSync(new URL("instance_migrate_links.py", root), `${folder}/instance_migrate_links.py`);
  for (const name of ["instance-container-handlers.yml", "instance-container-phase.yml", "instance-migrate-phase.yml", "instance-migrate-process-ready.yml", "instance-migrate-discard.yml", "instance-migrate-retire.yml", "instance-migrate-retirement-check.yml", "instance-migrate-schedules.yml", "instance-migrate-schedule-check.yml", "instance-migrate-link-result.yml", "instance-migrate-env-check.yml"]) {
    const tasks = (parse(Deno.readTextFileSync(new URL(`tasks/${name}`, root))) as Task[]).map((task) => {
      const transformed = transform(structuredClone(task));
      return name.startsWith("instance-migrate-schedule") ? scheduleHostPaths(transformed) : transformed;
    });
    Deno.writeTextFileSync(`${folder}/tasks/${name}`, stringify(tasks));
  }
  Deno.copyFileSync(new URL("instance_node_runtime.py", root), `${folder}/instance_node_runtime.py`);
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
    units: [{ name: "orbit-frankenphp.service", content: "[Service]\nUser=orbit\n" }],
    php: null,
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

Deno.test({
  name: "file copy rewrites tracked, untracked and ignored absolute checkout links, keeps relative links and dependency contents, and never changes source links",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      f.migrate.retire = [];
      f.write();
      Deno.mkdirSync(`${f.checkout}/public`);
      Deno.mkdirSync(`${f.checkout}/storage/app/public`, { recursive: true });
      Deno.writeTextFileSync(`${f.checkout}/storage/app/public/file.txt`, "kept storage\n");
      const absolute = `${f.checkout}/storage/app/public`;
      Deno.symlinkSync(absolute, `${f.checkout}/public/storage`);
      Deno.writeTextFileSync(`${f.checkout}/.gitignore`, "vendor/\nnode_modules/\nstorage/\nignored-link\n.env\ndatabase/*.sqlite\n");
      git(f.checkout, "add", ".gitignore", "public/storage");
      git(f.checkout, "commit", "--quiet", "-m", "tracked absolute storage link");
      Deno.symlinkSync(absolute, `${f.checkout}/local package`);
      Deno.symlinkSync(absolute, `${f.checkout}/ignored-link`);
      Deno.symlinkSync("../storage/./app/public", `${f.checkout}/public/relative`);
      for (const tree of ["vendor/acme/installed", "node_modules/installed", "apps/web/vendor/acme/installed", "apps/web/node_modules/installed"]) {
        Deno.mkdirSync(`${f.checkout}/${tree}`, { recursive: true });
        Deno.symlinkSync(absolute, `${f.checkout}/${tree}/absolute`);
        Deno.symlinkSync("missing-dependency", `${f.checkout}/${tree}/broken`);
      }

      Deno.writeTextFileSync(`${f.checkout}/apps/web/composer.json`, "{}\n");
      Deno.writeTextFileSync(`${f.checkout}/apps/web/package.json`, "{}\n");
      for (const path of ["vendor/acme/package", "vendor/composer/package", "node_modules/package", "node_modules/@acme/package", "resources/views/vendor/storage"]) {
        Deno.mkdirSync(`${f.checkout}/${path.slice(0, path.lastIndexOf("/"))}`, { recursive: true });
        Deno.symlinkSync(absolute, `${f.checkout}/${path}`);
      }
      const bytes = new Deno.Command("python3", { args: ["-c", `
import os, sys
root = os.fsencode(sys.argv[1])
os.mkdir(root + b'/storage/app/public/data-\\xff')
with open(root + b'/storage/app/public/data-\\xff/file.txt', 'wb') as file:
    file.write(b'copied data')
os.symlink(root + b'/storage/app/public/data-\\xff', root + b'/public/link-\\xfe')
os.symlink(b'../storage/app/public/data-\\xff', root + b'/public/relative-\\xff')
`, f.checkout] }).outputSync();
      assert(bytes.success, new TextDecoder().decode(bytes.stderr));

      f.build();

      const workspace = `${f.rootfs}/srv/workspace`;
      for (const [path, target] of [["public/storage", "../storage/app/public"], ["local package", "storage/app/public"], ["ignored-link", "storage/app/public"],
        ["public/relative", "../storage/./app/public"], ["vendor/acme/package", "../../storage/app/public"], ["vendor/composer/package", "../../storage/app/public"],
        ["node_modules/package", "../storage/app/public"], ["node_modules/@acme/package", "../../storage/app/public"],
        ["resources/views/vendor/storage", "../../../storage/app/public"]]) {
        assertEquals(Deno.readLinkSync(`${workspace}/${path}`), target);
        assertEquals(Deno.readLinkSync(`${f.checkout}/${path}`), path === "public/relative" ? target : absolute);
        assertEquals(Deno.readTextFileSync(`${workspace}/${path}/file.txt`), "kept storage\n");
      }
      for (const tree of ["vendor/acme/installed", "node_modules/installed", "apps/web/vendor/acme/installed", "apps/web/node_modules/installed"]) {
        assertEquals(Deno.readLinkSync(`${workspace}/${tree}/absolute`), absolute);
        assertEquals(Deno.readLinkSync(`${workspace}/${tree}/broken`), "missing-dependency");
      }
      const byteCheck = new Deno.Command("python3", { args: ["-c", `
import os, sys
source, workspace = map(os.fsencode, sys.argv[1:])
assert os.readlink(source + b'/public/link-\\xfe') == source + b'/storage/app/public/data-\\xff'
assert os.readlink(workspace + b'/public/link-\\xfe') == b'../storage/app/public/data-\\xff'
assert os.readlink(workspace + b'/public/relative-\\xff') == b'../storage/app/public/data-\\xff'
assert os.path.isdir(workspace + b'/public/link-\\xfe')
`, f.checkout, workspace] }).outputSync();
      assert(byteCheck.success, new TextDecoder().decode(byteCheck.stderr));
      assertEquals(f.workspace("status", "--short", "--", "public/storage"), "M public/storage");
    } finally {
      f.cleanup();
    }
  },
});

for (const reason of ["outside checkout", "broken"]) {
  Deno.test({
    name: `${reason} symlinks refuse before downtime, name every path and target, and preserve the source and earlier resources`,
    ignore: !hasAnsible,
    fn() {
      const f = fixture();
      try {
        const schedules = migrationSchedules(f, "enabled");
        const timerStates = Deno.readTextFileSync(`${f.folder}/host-processes.json`);
        f.migrate.retire = [];
        f.write();
        Deno.mkdirSync(`${f.checkout}/public`);
        const target = reason === "broken" ? `${f.checkout}/missing/storage` : f.apps;
        for (const path of ["public/storage", "local package"]) Deno.symlinkSync(target, `${f.checkout}/${path}`);
        const before = Deno.readTextFileSync(`${f.folder}/state.json`);

        const result = f.run("container");

        assert(!result.ok, result.output);
        assertEquals(f.result().error_code, "instance.migrate_symlink_unsupported");
        assertEquals(f.result().preflight_refused, true);
        assertEquals(Deno.readTextFileSync(`${f.folder}/host-processes.json`), timerStates);
        assertEquals(Deno.readTextFileSync(schedules.timerPath), schedules.timer);
        for (const path of ["public/storage", "local package"]) {
          assert(f.result().error_message.includes(path), f.result().error_message);
          assertEquals(Deno.readLinkSync(`${f.checkout}/${path}`), target);
        }
        assert(f.result().error_message.includes(target), f.result().error_message);
        assert(f.result().error_message.includes(reason), f.result().error_message);
        assert(f.run("restore").ok);
        assert(exists(f.migrate.awake.path));
        assertEquals(Deno.readTextFileSync(`${f.folder}/state.json`), before);
        assert(!exists(`${f.folder}/calls`), "No container command may run after a preflight refusal.");
      } finally {
        f.cleanup();
      }
    },
  });
}

Deno.test({
  name: "a link introduced after preparation refuses quiesce with zero downtime and removes only the prepared container",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      f.migrate.retire = [];
      f.write();
      const processes = migrationProcesses(f, "running");
      const before = processes();
      const markerBefore = Deno.statSync(f.migrate.awake.path).mtime?.getTime();
      const prepared = f.run("container");
      assert(prepared.ok, prepared.output);
      Deno.symlinkSync(f.apps, `${f.checkout}/late-link`);
      const refused = f.run("quiesce");
      assert(!refused.ok, refused.output);
      assertEquals(f.result().error_code, "instance.migrate_symlink_unsupported");
      assertEquals(f.result().preflight_refused, false);
      assertEquals(processes(), before);
      assert(!exists(`${f.folder}/run/quiesced`));
      const restored = f.run("restore");
      assert(restored.ok, restored.output);
      assertEquals(f.state().containers, {});
      assertEquals(f.state()["network-acls"].filter((acl: {name: string}) => acl.name !== "orbitinst0-egress"), []);
      assertEquals(f.ufw(), []);
      assertEquals(processes(), before);
      assertEquals(Deno.statSync(f.migrate.awake.path).mtime?.getTime(), markerBefore);
      assertEquals(Deno.readLinkSync(`${f.checkout}/late-link`), f.apps);
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "file targets with directory suffixes refuse before downtime and in the copied tree without repairing links",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      f.migrate.retire = [];
      f.write();
      Deno.mkdirSync(`${f.checkout}/public`);
      Deno.mkdirSync(`${f.checkout}/storage`);
      const file = `${f.checkout}/storage/file`;
      Deno.writeTextFileSync(file, "data\n");
      const targets = new Map<string, string>();
      for (const [kind, prefix] of [["absolute", f.checkout], ["relative", ".."]]) {
        for (const [label, suffix] of [["slash", "/"], ["dot", "/."]]) {
          const path = `public/${kind}-${label}`;
          const target = `${prefix}/storage/file${suffix}`;
          targets.set(path, target);
          Deno.symlinkSync(target, `${f.checkout}/${path}`);
        }
      }
      const preflight = f.run("container");
      assert(!preflight.ok, preflight.output);
      assertEquals(f.result().error_code, "instance.migrate_symlink_unsupported");
      assertEquals(f.result().preflight_refused, true);
      for (const [path, target] of targets) {
        assert(f.result().error_message.includes(path), f.result().error_message);
        assert(f.result().error_message.includes(target), f.result().error_message);
        assertEquals(Deno.readLinkSync(`${f.checkout}/${path}`), target);
      }
      // Make every link valid for preflight, then copy a file in place of that directory.
      Deno.removeSync(file);
      Deno.mkdirSync(file);
      for (const phase of ["container", "quiesce", "workspace"]) {
        const result = f.run(phase);
        assert(result.ok, result.output);
      }
      Deno.writeTextFileSync(`${f.folder}/bin/tar`, `#!/usr/bin/env python3
import os, sys
if os.environ.get("ORBIT_CHECKOUT") == ${JSON.stringify(f.checkout)}:
    os.rmdir(${JSON.stringify(file)})
    with open(${JSON.stringify(file)}, "w") as copied:
        copied.write("copied file")
os.execv("/usr/bin/tar", ["tar", *sys.argv[1:]])
`);
      Deno.chmodSync(`${f.folder}/bin/tar`, 0o755);
      const copied = f.run("files");
      assert(!copied.ok, copied.output);
      assertEquals(f.result().error_code, "instance.migrate_symlink_unsupported");
      assertEquals(f.result().preflight_refused, false);
      assert(f.result().error_message.includes("broken"), f.result().error_message);
      for (const [path, target] of targets) {
        assert(f.result().error_message.includes(path), f.result().error_message);
        assert(f.result().error_message.includes(target), f.result().error_message);
        assertEquals(Deno.readLinkSync(`${f.rootfs}/srv/workspace/${path}`), target);
        assertEquals(Deno.readLinkSync(`${f.checkout}/${path}`), target);
      }
      const restored = f.run("restore");
      assert(restored.ok, restored.output);
      assert(!exists(f.rootfs));
      assert(exists(f.migrate.awake.path));
      assertEquals(Deno.readTextFileSync(file), "copied file");
    } finally {
      f.cleanup();
    }
  },
});

for (const change of ["absolute", "relative", "outside", "broken"]) {
  Deno.test({
    name: `the exact archive tree handles ${change} link changes after preflight and preserves rollback`,
    ignore: !hasAnsible,
    fn() {
      const f = fixture();
      try {
        f.migrate.retire = [];
        f.write();
        Deno.mkdirSync(`${f.checkout}/public`);
        Deno.mkdirSync(`${f.checkout}/storage/app/public`, { recursive: true });
        Deno.mkdirSync(`${f.checkout}/alternate`);
        Deno.writeTextFileSync(`${f.checkout}/alternate/file.txt`, "copied target\n");
        const link = `${f.checkout}/public/storage`;
        Deno.symlinkSync(`${f.checkout}/storage/app/public`, link);
        const target = change === "absolute" ? `${f.checkout}/alternate` : change === "relative" ? "../alternate"
          : change === "outside" ? f.apps : `${f.checkout}/missing`;
        // A developer changes the source at the last possible point before tar copies it, after any source-side scan.
        Deno.writeTextFileSync(`${f.folder}/bin/tar`, `#!/usr/bin/env python3
import os, sys
if os.environ.get("ORBIT_CHECKOUT") == ${JSON.stringify(f.checkout)}:
    os.unlink(${JSON.stringify(link)})
    os.symlink(${JSON.stringify(target)}, ${JSON.stringify(link)})
os.execv("/usr/bin/tar", ["tar", *sys.argv[1:]])
`);
        Deno.chmodSync(`${f.folder}/bin/tar`, 0o755);
        for (const phase of ["container", "quiesce", "workspace"]) {
          const result = f.run(phase);
          assert(result.ok, result.output);
        }

        const result = f.run("files");

        assertEquals(Deno.readLinkSync(link), target);
        if (change === "absolute" || change === "relative") {
          assert(result.ok, result.output);
          assertEquals(Deno.readLinkSync(`${f.rootfs}/srv/workspace/public/storage`), "../alternate");
        } else {
          assert(!result.ok, result.output);
          assertEquals(f.result().error_code, "instance.migrate_symlink_unsupported");
          assertEquals(f.result().preflight_refused, false);
          assert(f.result().error_message.includes("public/storage"), f.result().error_message);
          assert(f.result().error_message.includes(target), f.result().error_message);
          const restored = f.run("restore");
          assert(restored.ok, restored.output);
          assert(!exists(f.rootfs));
          assert(exists(f.migrate.awake.path));
          assertEquals(Deno.readLinkSync(link), target);
        }
      } finally {
        f.cleanup();
      }
    },
  });
}

for (const count of [0, 1]) {
  Deno.test({
    name: `migration counts ${count} files that Git does not ignore beside a large ignored tree`,
    ignore: !hasAnsible,
    fn() {
      const f = fixture();
      try {
        Deno.mkdirSync(`${f.checkout}/vendor/generated`);
        for (let i = 0; i < 1000; i++) Deno.writeTextFileSync(`${f.checkout}/vendor/generated/file-${i}`, "ignored dependency\n");
        if (count === 1) Deno.writeTextFileSync(`${f.checkout}/draft.txt`, "not ignored\n");
        assertEquals(git(f.checkout, "ls-files", "--others", "--exclude-standard"), count === 1 ? "draft.txt" : "");
        f.buildBatched();
        const receipt = JSON.parse(Deno.readTextFileSync(`${f.folder}/run/untracked-files.json`));
        assertEquals(receipt, { untracked_files_copied: count });
        // The ignored tree, .env and SQLite still enter the unchanged inclusive archive.
        assertEquals(f.read("vendor/generated/file-999"), "ignored dependency\n");
        assertEquals(f.read("database/database.sqlite"), "sqlite\n");
        assert(f.read(".env").includes("APP_KEY="));
        if (count === 1) assertEquals(f.read("draft.txt"), "not ignored\n");
        assertEquals([...Deno.readDirSync(`${f.folder}/mirror`)].map((entry) => entry.name), ["7.git"]);
      } finally {
        f.cleanup();
      }
    },
  });
}

Deno.test({
  name: "untracked non-ignored files and nested repository leaves migrate with an exact archived file count",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      // Include a newline in a name: manifests must use NUL separators, not lines.
      Deno.writeTextFileSync(`${f.checkout}/generated\nwayfinder.ts`, "generated output\n");
      const nested = `${f.checkout}/nested`;
      Deno.mkdirSync(nested);
      git(nested, "init", "--quiet");
      for (let i = 0; i < 31; i++) Deno.writeTextFileSync(`${nested}/file-${i}`, `nested ${i}\n`);
      const leaves = (path: string): number => [...Deno.readDirSync(path)].reduce(
        (count, entry) => count + (entry.isDirectory ? leaves(`${path}/${entry.name}`) : 1), 0);
      const nestedLeaves = leaves(nested);
      f.buildBatched();
      assertEquals(f.read("generated\nwayfinder.ts"), "generated output\n");
      assertEquals(f.read("vendor/pkg/a.php"), "<?php // dependency\n");
      assertEquals(f.read("database/database.sqlite"), "sqlite\n");
      for (let i = 0; i < 31; i++) assertEquals(f.read(`nested/file-${i}`), `nested ${i}\n`);
      const receipt = JSON.parse(Deno.readTextFileSync(`${f.folder}/run/untracked-files.json`));
      assertEquals(receipt.untracked_files_copied, 1 + nestedLeaves);
      const completed = f.run("retire");
      assert(completed.ok, completed.output);
      assert(exists(f.migrate.awake.path));
      assertEquals(f.state().containers["instance-7"].status, "Running");
      assertEquals(Deno.readTextFileSync(`${f.retained()}/generated\nwayfinder.ts`), "generated output\n");
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "late ignored and non-ignored files do not refuse a move and remain in the retained checkout",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      Deno.writeTextFileSync(`${f.checkout}/.gitignore`, Deno.readTextFileSync(`${f.checkout}/.gitignore`) + "*.log\ncache/\n");
      git(f.checkout, "add", ".gitignore");
      git(f.checkout, "commit", "--quiet", "-m", "ignore generated output");
      git(f.checkout, "update-ref", "refs/remotes/origin/main", "HEAD");
      Deno.writeTextFileSync(`${f.checkout}/draft.txt`, "copied version\n");
      f.buildBatched();
      Deno.writeTextFileSync(`${f.checkout}/draft.txt`, "late edited version\n");
      Deno.writeTextFileSync(`${f.checkout}/late.txt`, "late untracked work\n");
      Deno.writeTextFileSync(`${f.checkout}/runtime.log`, "late ignored output\n");
      Deno.mkdirSync(`${f.checkout}/cache`);
      Deno.writeTextFileSync(`${f.checkout}/cache/generated`, "late ignored cache\n");
      const completed = f.run(["processes", "seed", "retire"]);
      assert(completed.ok, completed.output);
      const commit = f.workspace("rev-parse", "HEAD");
      const copied = JSON.parse(Deno.readTextFileSync(`${f.folder}/run/untracked-files.json`));
      const projected = JSON.parse(migrateResult(
        { instance_id: f.container.instance_id, container: f.container, route_mutations: null },
        { container: "succeeded", quiesce: "succeeded", workspace: "succeeded", files: "succeeded",
          processes: "succeeded", seed: "succeeded", agent: "skipped", t3: "skipped", register: "skipped",
          route: "skipped", retire: "succeeded", restore_route: "skipped", restore: "skipped" },
        { status: "succeeded", outputs: { stdout: `orbit-commit=${commit}` } }, null, copied,
      ).stdout);
      assertEquals([projected.ready, projected.moved, projected.restored], [true, true, false]);
      assertEquals(f.read("draft.txt"), "copied version\n");
      for (const path of ["late.txt", "runtime.log", "cache/generated"]) assert(!exists(`${f.rootfs}/srv/workspace/${path}`));
      const retained = f.retained();
      assertEquals(Deno.readTextFileSync(`${retained}/draft.txt`), "late edited version\n");
      assertEquals(Deno.readTextFileSync(`${retained}/late.txt`), "late untracked work\n");
      assertEquals(Deno.readTextFileSync(`${retained}/runtime.log`), "late ignored output\n");
      assertEquals(Deno.readTextFileSync(`${retained}/cache/generated`), "late ignored cache\n");
      assert(exists(f.migrate.awake.path));
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "untracked files inside a tracked submodule retain the named retirement refusal even when Git config ignores it",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      const source = `${f.folder}/module-source`;
      Deno.mkdirSync(source);
      git(source, "init", "--quiet");
      Deno.writeTextFileSync(`${source}/tracked.txt`, "module seed\n");
      git(source, "add", ".");
      git(source, "commit", "--quiet", "-m", "module seed");
      git(f.checkout, "-c", "protocol.file.allow=always", "submodule", "add", "--quiet", source, "sub");
      git(f.checkout, "commit", "--quiet", "-m", "tracked submodule");
      git(f.checkout, "update-ref", "refs/remotes/origin/main", "HEAD");
      git(f.checkout, "config", "submodule.sub.ignore", "all");
      Deno.writeTextFileSync(`${f.checkout}/sub/new-untracked`, "cannot be copied by the parent archive\n");
      assertEquals(git(f.checkout, "status", "--porcelain=v1", "--untracked-files=no"), "");
      const refused = f.run("container");
      assert(!refused.ok, refused.output);
      assertEquals(f.result().error_code, "instance.migrate_retirement_blocked");
      assert(f.result().error_message.includes("sub"));
      assert(f.result().error_message.includes("parent checkout archive"));
      assertEquals(f.state().containers ?? {}, {});
      assert(exists(f.migrate.awake.path));
      assertEquals(Deno.readTextFileSync(`${f.checkout}/sub/new-untracked`), "cannot be copied by the parent archive\n");
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a retained default copies its unpushed commit and changed, staged, untracked, deleted and ignored files",
  ignore: !hasAnsible,
  fn() {
    const f = fixture("default", true);
    try {
      // A retained default can copy its local files; its checkout is not scheduled for deletion yet.
      f.migrate.retire = [];
      f.write();
      const head = git(f.checkout, "rev-parse", "HEAD");
      const status = git(f.checkout, "status", "--porcelain");
      f.buildBatched();
      // The Route shows the wake page from the first phase on.
      assert(!exists(f.migrate.awake.path));
      assertEquals(f.result(), { commit: head });
      // A standalone repository at the checkout's own commit, which no origin has.
      assertEquals(f.workspace("rev-parse", "HEAD"), head);
      assertEquals(f.workspace("symbolic-ref", "--short", "HEAD"), "main");
      assert(Deno.statSync(`${f.rootfs}/srv/workspace/.git`).isDirectory);
      assertEquals(f.read("local.php"), "<?php // committed, never pushed\n");
      assertEquals(f.read("index.php"), "<?php echo 2;\n");
      assertEquals(f.read("staged.php"), "<?php // staged\n");
      assertEquals(f.read("note.txt"), "untracked\n");
      assertEquals(f.read("vendor/pkg/a.php"), "<?php // dependency\n");
      assertEquals(f.read("database/database.sqlite"), "sqlite\n");
      assert(!exists(`${f.rootfs}/srv/workspace/old.php`));
      // .env came as it was; the path into the checkout points into the workspace now.
      assertEquals(f.read(".env"), `APP_KEY=base64:kept\nDB_DATABASE=${f.rootfs}/srv/workspace/database/database.sqlite\n`);
      // The way to the database server came with the container.
      assertEquals(f.state()["network-acls"].find((acl: {name: string}) => acl.name === f.container.acl).egress, f.container.acl_rules.egress);
      assertEquals(f.ufw().map((line) => JSON.parse(line).comment), ["orbit:instance-7-database"]);
      // The move uses create's resource limits and full root device, even for an image-based migration.
      assertEquals(f.state().containers["instance-7"].config, ["security.idmap.isolated=true", "limits.cpu=4", "limits.memory=8GiB", "limits.processes=4096"]);
      assertEquals(f.state().containers["instance-7"].devices.filter((device: string) => device.startsWith("root,")), [
        "root,type=disk", "root,path=/", "root,pool=orbit-instances", "root,size=40GiB",
      ]);
      // Nothing of the copy stays behind, and the checkout is as it was: a failure from here could still restore it.
      assertEquals([...Deno.readDirSync(`${f.rootfs}/srv`)].map((entry) => entry.name), ["workspace"]);
      assertEquals([...Deno.readDirSync(`${f.folder}/mirror`)].map((entry) => entry.name), ["7.git"]);
      assertEquals(git(f.checkout, "status", "--porcelain"), status);
      assertEquals(Deno.readTextFileSync(`${f.checkout}/.env`), `APP_KEY=base64:kept\nDB_DATABASE=${f.checkout}/database/database.sqlite\n`);

      const retire = f.run("retire");
      assert(retire.ok, retire.output);
      assert(exists(f.checkout));
      assertEquals(git(f.checkout, "status", "--porcelain"), status);
      assert(exists(f.migrate.awake.path));
      assertEquals(f.state().containers["instance-7"].status, "Running");
      const again = f.run("retire");
      assert(again.ok, again.output);
      assertMatch(again.output, /changed=0\s/);
    } finally {
      f.cleanup();
    }
  },
});

// Include bytes from HEAD, index, refs, objects and registry metadata; reads must not refresh the owner's index.
function gitFiles(root: string, exclude = ""): Record<string, string> {
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

Deno.test({
  name: "a linked worktree migrates before its active owner and preserves every file and sibling registration",
  ignore: !hasAnsible,
  fn() {
    const f = fixture("feature");
    try {
      const sibling = f.apps + "/sibling";
      git(f.main, "worktree", "add", "--quiet", "-b", "sibling", sibling);
      Deno.writeTextFileSync(f.checkout + "/untracked-kept.txt", "untracked survives");
      const admin = git(f.checkout, "rev-parse", "--absolute-git-dir");
      const owner = gitFiles(f.main, admin);
      const other = gitFiles(sibling);
      const child = gitFiles(f.checkout);
      const head = git(f.checkout, "rev-parse", "HEAD");
      f.buildBatched();
      assertEquals(f.read("untracked-kept.txt"), "untracked survives");
      assertEquals(f.read("vendor/pkg/a.php"), "<?php // dependency\n");
      assert(f.read(".env").includes("APP_KEY=base64:kept"));
      const retired = f.run("retire");
      assert(retired.ok, retired.output);
      assertEquals(gitFiles(f.main), owner);
      assertEquals(gitFiles(sibling), other);
      assert(!exists(admin));
      assert(!exists(f.checkout));
      for (const [name, digest] of Object.entries(child)) {
        if (name === "/.git") continue;
        assertEquals(createHash("sha256").update(Deno.readFileSync(f.retained() + name)).digest("hex"), digest);
      }
      assertEquals(git(f.retained(), "rev-parse", "HEAD"), head);
      assertEquals(git(f.retained(), "symbolic-ref", "--short", "HEAD"), "feature");
      assert(!exists(f.retained() + "/.git/objects/info/alternates"));
      assert(!git(f.main, "worktree", "list", "--porcelain").includes("worktree " + f.checkout + "\n"));
      assert(git(f.main, "worktree", "list", "--porcelain").includes("worktree " + sibling + "\n"));
      const repeat = f.run("retire");
      assert(repeat.ok, repeat.output);
      assertEquals(gitFiles(f.main), owner);
    } finally { f.cleanup(); }
  },
});

for (const blocker of ["unstaged", "staged", "unpushed", "stash", "identity", "nested"] as const) {
  Deno.test({
    name: "linked worktree preflight refuses " + blocker + " without touching its active owner or sibling",
    ignore: !hasAnsible,
    fn() {
      const f = fixture("feature");
      try {
        const sibling = blocker === "nested" ? f.checkout + "/nested-sibling" : f.apps + "/sibling";
        git(f.main, "worktree", "add", "--quiet", "-b", "sibling", sibling);
        if (blocker === "unstaged" || blocker === "staged") {
          Deno.writeTextFileSync(f.checkout + "/index.php", "tracked edit");
          if (blocker === "staged") git(f.checkout, "add", "index.php");
        } else if (blocker === "unpushed") git(f.checkout, "commit", "--quiet", "--allow-empty", "-m", "not pushed");
        else if (blocker === "stash") {
          Deno.writeTextFileSync(f.checkout + "/index.php", "stashed edit");
          git(f.checkout, "stash", "push", "--quiet");
        } else if (blocker === "identity") {
          const admin = git(f.checkout, "rev-parse", "--absolute-git-dir");
          Deno.writeTextFileSync(admin + "/gitdir", sibling + "/.git\n");
        }
        const before = gitFiles(f.apps);
        const refused = f.run("container");
        assert(!refused.ok, refused.output);
        assertEquals(f.result().error_code, blocker === "unpushed" ? "instance.migrate_unpushed_commits"
          : blocker === "stash" ? "instance.migrate_stashes_present" : "instance.migrate_retirement_blocked");
        assertEquals(gitFiles(f.apps), before);
        assert(exists(f.migrate.awake.path));
        assertEquals(f.state().containers ?? {}, {});
        assert(!exists(f.checkout + ".orbit-retired-git"));
        if (blocker === "nested") {
          assert(f.result().error_message.includes(sibling));
          const retirement = f.run("retire");
          assert(!retirement.ok, retirement.output);
          assertEquals(f.result().error_code, "instance.migrate_retirement_blocked");
          assert(f.result().error_message.includes(sibling));
          assertEquals(gitFiles(f.apps), before);
          assertEquals(git(sibling, "symbolic-ref", "--short", "HEAD"), "sibling");
        }
      } finally { f.cleanup(); }
    },
  });
}

Deno.test({
  name: "linked retirement interrupted before unregister fails explicitly on repeat",
  ignore: !hasAnsible,
  fn() {
    const f = fixture("feature");
    try {
      const admin = git(f.checkout, "rev-parse", "--absolute-git-dir");
      const file = f.folder + "/tasks/instance-migrate-retire.yml";
      const original = Deno.readTextFileSync(file);
      Deno.writeTextFileSync(file, original.replace("shutil.rmtree(private)", "raise OSError('fixture: interrupted before unregister')"));
      const first = f.run("retire");
      assert(!first.ok, first.output);
      assertEquals(f.result().error_code, "instance.migrate_retire_failed");
      assert(!exists(f.checkout));
      assert(exists(f.retained() + "/.git/index"));
      assert(exists(admin));
      const before = gitFiles(f.apps);
      Deno.writeTextFileSync(file, original);
      const repeat = f.run("retire");
      assert(!repeat.ok, repeat.output);
      assertEquals(f.result().error_code, "instance.migrate_retire_failed");
      assert(f.result().error_message.includes("Incomplete linked retirement"));
      assertEquals(gitFiles(f.apps), before);
      assert(exists(admin));
    } finally { f.cleanup(); }
  },
});

Deno.test({
  name: "linked worktree quiesce rechecks new tracked changes before stopping any writer",
  ignore: !hasAnsible,
  fn() {
    const f = fixture("feature");
    try {
      git(f.main, "worktree", "add", "--quiet", "-b", "sibling", f.apps + "/sibling");
      const prepared = f.run("container");
      assert(prepared.ok, prepared.output);
      Deno.writeTextFileSync(f.checkout + "/index.php", "late tracked edit");
      const before = gitFiles(f.apps);
      const state = f.state();
      const refused = f.run("quiesce");
      assert(!refused.ok, refused.output);
      assertEquals(f.result().error_code, "instance.migrate_retirement_blocked");
      assertEquals(gitFiles(f.apps), before);
      assertEquals(f.state(), state);
      assert(exists(f.migrate.awake.path));
    } finally { f.cleanup(); }
  },
});

Deno.test({
  name: "an owner refuses retirement while its child is in the same retirement batch",
  ignore: !hasAnsible,
  fn() {
    const f = fixture("feature");
    try {
      f.migrate.retire.push({ path: f.main, mirror: `${f.folder}/mirror/7.git`, common_of: f.checkout });
      f.write();
      const quiesce = f.run("container");
      assert(!quiesce.ok, quiesce.output);
      assertEquals(f.result().error_code, "instance.migrate_retirement_blocked");
      assert(f.result().error_message.includes(f.checkout));
      assert(f.result().error_message.includes("Other worktrees (1 total; first 20)"));
      assert(exists(f.checkout));
      assert(exists(`${f.main}/.git`));
      assert(exists(f.migrate.awake.path));
      assertEquals(f.state().containers ?? {}, {});
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a clean standalone non-default checkout moves with T3 and leaves an unrelated default repository intact",
  ignore: !hasAnsible,
  fn() {
    const f = fixture("feature", false, false);
    try {
      f.migrate.retire.push({ path: f.main, mirror: `${f.folder}/mirror/7.git`, common_of: f.checkout });
      f.write();
      const head = git(f.checkout, "rev-parse", "HEAD");
      f.build();
      assertEquals(f.workspace("rev-parse", "HEAD"), head);
      assertEquals(f.workspace("symbolic-ref", "--short", "HEAD"), "feature");
      assert(Deno.statSync(`${f.rootfs}/srv/workspace/.git`).isDirectory);
      assertEquals(f.state().containers["instance-8"].added.t3, {
        type: "proxy", settings: ["listen=tcp:10.44.0.7:3800", "connect=tcp:10.253.0.8:3773", "nat=true"],
      });
      const retire = f.run("retire");
      assert(retire.ok, retire.output);
      assert(!exists(f.checkout));
      assert(exists(`${f.main}/.git`));
      assertEquals(f.state().containers["instance-8"].status, "Running");
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a failed move removes its container, its ACL, its firewall rule and its mirror, and the checkout serves as it was",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      const status = git(f.checkout, "status", "--porcelain");
      const head = git(f.checkout, "rev-parse", "HEAD");
      f.build();
      const restore = f.run("restore");
      assert(restore.ok, restore.output);
      assertEquals(f.state().containers, {});
      assertEquals(f.state()["network-acls"].filter((acl: {name: string}) => acl.name !== "orbitinst0-egress"), []);
      assertEquals(f.ufw(), []);
      assert(!exists(f.container.mirror));
      assert(!exists(f.rootfs));
      assert(exists(f.migrate.awake.path));
      assertEquals(git(f.checkout, "status", "--porcelain"), status);
      assertEquals(git(f.checkout, "rev-parse", "HEAD"), head);
      // The restore keeps the result of the phase that failed before it, and a second restore finds nothing to remove.
      assertEquals(f.result(), { commit: head });
      const again = f.run("restore");
      assert(again.ok, again.output);
      assertMatch(again.output, /changed=0\s/);
      // The same command moves the Instance again, from a new container.
      f.build();
      assertEquals(f.workspace("rev-parse", "HEAD"), head);
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a move starts with a new container: one that an earlier failed move left goes first",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      for (const phase of ["container", "workspace"]) assert(f.run(phase).ok);
      Deno.writeTextFileSync(`${f.rootfs}/srv/workspace/leftover.txt`, "from the failed move\n");
      f.build();
      assert(!exists(`${f.rootfs}/srv/workspace/leftover.txt`));
      assertEquals(f.read("index.php"), "<?php echo 2;\n");
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a checkout on another branch than the recorded one is refused before anything changes",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      git(f.checkout, "stash", "push", "--quiet", "--include-untracked");
      git(f.checkout, "checkout", "--quiet", "-b", "elsewhere");
      const quiesce = f.run("container");
      assert(!quiesce.ok);
      assertEquals(f.result(), { error_code: "instance.migrate_branch_changed", preflight_refused: true });
      // The Route still serves, and no container was made.
      assert(exists(f.migrate.awake.path));
      assertEquals(f.state().containers ?? {}, {});
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a database server that the container cannot reach refuses the move, and the copy leaves nothing behind",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      const status = git(f.checkout, "status", "--porcelain");
      // Nothing listens on this port.
      f.migrate.databases.push({ host: "127.0.0.1", port: 1 });
      f.write();
      const processes = migrationProcesses(f, "running");
      const processBefore = processes();
      const markerBefore = Deno.statSync(f.migrate.awake.path).mtime?.getTime();
      const files = f.run("container");
      assert(!files.ok);
      assertEquals(f.result(), { error_code: "instance.database_unreachable" });
      assert(exists(f.migrate.awake.path));
      assertEquals(processes(), processBefore);
      assert(!exists(`${f.folder}/run/quiesced`));
      assert(!exists(`${f.rootfs}/srv/workspace/.git`));
      assertEquals([...Deno.readDirSync(`${f.rootfs}/srv`)].map((entry) => entry.name), ["workspace"]);
      assertEquals([...Deno.readDirSync(`${f.folder}/mirror`)].map((entry) => entry.name), ["7.git"]);
      const restore = f.run("restore");
      assert(restore.ok, restore.output);
      assertEquals(f.state().containers, {});
      assertEquals(f.state()["network-acls"].filter((acl: {name: string}) => acl.name !== "orbitinst0-egress"), []);
      assertEquals(f.ufw(), []);
      assert(exists(f.migrate.awake.path));
      assertEquals(git(f.checkout, "status", "--porcelain"), status);
      assertEquals(f.result(), { error_code: "instance.database_unreachable" });
      assertEquals(processes(), processBefore);
      assertEquals(Deno.statSync(f.migrate.awake.path).mtime?.getTime(), markerBefore);
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a database server that listens is reached from the container",
  ignore: !hasAnsible || !has("timeout"),
  async fn() {
    const f = fixture();
    const listener = Deno.listen({ hostname: "127.0.0.1", port: 0 });
    const accepting = (async () => {
      for await (const connection of listener) connection.close();
    })();
    try {
      f.migrate.databases.push({ host: "127.0.0.1", port: (listener.addr as Deno.NetAddr).port });
      f.write();
      const done = await new Promise<void>((resolve, reject) => {
        try {
          f.build();
          resolve();
        } catch (error) {
          reject(error);
        }
      });
      assertEquals(done, undefined);
    } finally {
      listener.close();
      await accepting.catch(() => {});
      f.cleanup();
    }
  },
});

Deno.test({
  name: "preflight names linked and detached worktrees and every tracked change before any Node change",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      const linked = `${f.apps}/linked with spaces`;
      const detached = `${f.apps}/detached`;
      git(f.checkout, "worktree", "add", "--quiet", "-b", "extra", linked);
      git(f.checkout, "worktree", "add", "--quiet", "--detach", detached);
      Deno.writeTextFileSync(`${f.checkout}/index.php`, "changed\n");
      Deno.writeTextFileSync(`${f.checkout}/staged.php`, "staged edit\n");
      git(f.checkout, "add", "staged.php");
      git(f.checkout, "mv", "note.txt", "renamed with spaces.txt");
      Deno.removeSync(`${f.checkout}/local.php`);
      Deno.writeTextFileSync(`${f.checkout}/untracked.txt`, "not ignored\n");
      const before = git(f.checkout, "status", "--porcelain=v1", "--untracked-files=all");
      // Retained resources from a previous failure must survive both preflight and its restore path.
      assert(f.run("container", true).ok);
      const state = f.state();
      const rules = f.ufw();
      const quiesce = f.run("container");
      assert(!quiesce.ok, quiesce.output);
      const receipt = f.result();
      assertEquals(receipt.error_code, "instance.migrate_retirement_blocked");
      for (const path of [f.checkout, linked, detached, "index.php", "staged.php", "note.txt", "renamed with spaces.txt", "local.php"]) {
        assert(receipt.error_message.includes(path), receipt.error_message);
      }
      assert(receipt.error_message.includes("Other worktrees (2 total; first 20)"));
      assert(receipt.error_message.includes("Changed files (4 total; first 20)"));
      assert(!receipt.error_message.includes("untracked.txt"));
      assert(exists(f.migrate.awake.path));
      assertEquals(f.state(), state);
      const restore = f.run("restore");
      assert(restore.ok, restore.output);
      assertEquals(f.state(), state);
      assertEquals(f.ufw(), rules);
      assertEquals(git(f.checkout, "status", "--porcelain=v1", "--untracked-files=all"), before);
      assertEquals(f.result(), receipt);
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "retirement error lists stop at 20 entries with total counts",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      for (let i = 0; i < 22; i++) {
        Deno.writeTextFileSync(`${f.checkout}/file-${String(i).padStart(2, "0")}.txt`, "new\n");
        git(f.checkout, "worktree", "add", "--quiet", "--detach", `${f.apps}/worktree-${String(i).padStart(2, "0")}`);
      }
      git(f.checkout, "add", ".");
      git(f.checkout, "commit", "--quiet", "-m", "tracked files for bounded errors");
      git(f.checkout, "update-ref", "refs/remotes/origin/main", "HEAD");
      for (let i = 0; i < 22; i++) Deno.writeTextFileSync(`${f.checkout}/file-${String(i).padStart(2, "0")}.txt`, "changed\n");
      const quiesce = f.run("container");
      assert(!quiesce.ok, quiesce.output);
      const message = f.result().error_message;
      assert(message.includes("Other worktrees (22 total; first 20)"));
      assert(message.includes("Changed files (22 total; first 20)"));
      assert(message.includes("file-19.txt"));
      assert(message.includes("worktree-19"));
      assert(!message.includes("file-20.txt"));
      assert(!message.includes("worktree-20"));
      assert(exists(f.migrate.awake.path));
      assertEquals(f.state().containers ?? {}, {});
    } finally {
      f.cleanup();
    }
  },
});

for (const blocker of ["changes", "worktree", "commits", "stashes"] as const) {
  Deno.test({
    name: `retirement rechecks ${blocker} created after preflight and leaves the container and source intact`,
    ignore: !hasAnsible,
    fn() {
      const f = fixture();
      try {
        f.buildBatched();
        const path = blocker === "changes" ? `${f.checkout}/index.php` : `${f.apps}/late-worktree`;
        if (blocker === "changes") Deno.writeTextFileSync(path, "late edit\n");
        else if (blocker === "worktree") git(f.checkout, "worktree", "add", "--quiet", "--detach", path);
        else if (blocker === "commits") git(f.checkout, "commit", "--quiet", "--allow-empty", "-m", "late commit");
        else {
          Deno.writeTextFileSync(`${f.checkout}/late-stash.txt`, "late stash\n");
          git(f.checkout, "stash", "push", "--quiet", "--include-untracked");
        }
        const refs = git(f.checkout, "show-ref");
        const state = f.state();
        const retire = f.run("retire");
        assert(!retire.ok, retire.output);
        const code = blocker === "commits" ? "instance.migrate_unpushed_commits" : blocker === "stashes" ? "instance.migrate_stashes_present" : "instance.migrate_retirement_blocked";
        assertEquals(f.result().error_code, code);
        const detail = blocker === "changes" ? "index.php" : blocker === "worktree" ? path : blocker === "commits" ? '"commits": 1' : "Stashes: 1";
        assert(f.result().error_message.includes(detail), f.result().error_message);
        assertEquals(git(f.checkout, "show-ref"), refs);
        assertEquals(f.result().preflight_refused, false);
        assert(exists(f.migrate.awake.path), "The container Route must serve after a retirement refusal.");
        assert(exists(f.checkout));
        if (["changes", "worktree"].includes(blocker)) assert(exists(path));
        assertEquals(f.state(), state);
        assertEquals(f.state().containers["instance-7"].status, "Running");
      } finally {
        f.cleanup();
      }
    },
  });
}

function migrationProcesses(f: ReturnType<typeof fixture>, presetDesired: "running" | "stopped") {
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
      ? `[Service]\nUser=orbit\nWorkingDirectory=/srv/workspace\nEnvironmentFile=-/srv/workspace/.env\nEnvironmentFile=${environment}\nEnvironment=ORBIT_DEV_SERVER_PORT=5195\nExecStart=/opt/orbit-runtime/current/bin/vp dev --host=0.0.0.0 --port=5195 --strictPort --base=/__orbit/vite/\n`
      : "[Service]\nUser=orbit\n";
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
function migrationSchedules(f: ReturnType<typeof fixture>, desired: "enabled" | "disabled", kind: "current" | "agent" | "direct" = "current", command = "php artisan schedule:run", sourceDirectory = f.checkout) {
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
    container_user: "orbit",
    container_home: "/home/orbit",
    container_shell: "/bin/bash",
    path: "/usr/bin:/bin",
    environment_file: "/srv/workspace/.env",
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

for (const [desired, kind] of [["enabled", "current"], ["disabled", "agent"], ["enabled", "direct"]] as const) {
  Deno.test({
    name: `Schedule migration repoints a ${kind} timer with ${desired} intent only after successful cutover`,
    ignore: !hasAnsible,
    fn() {
      const f = fixture();
      try {
        const schedules = migrationSchedules(f, desired, kind);
        f.build();
        assertEquals(schedules.host()[schedules.schedule.timer], { state: "stopped", enabled: false });
        assertEquals(Deno.readTextFileSync(schedules.servicePath), schedules.service);
        assertEquals(Deno.readTextFileSync(schedules.timerPath), schedules.timer);
        assertEquals(Deno.readTextFileSync(schedules.scriptPath), schedules.script);
        assertEquals(Deno.readTextFileSync(schedules.callbackPath), schedules.callback);
        assert(exists(f.checkout));
        const retired = f.run("retire");
        assert(retired.ok, retired.output);
        assert(!exists(schedules.servicePath));
        assert(!exists(schedules.timerPath));
        const service = Deno.readTextFileSync(f.rootfs + "/etc/systemd/system/" + schedules.schedule.service);
        const timer = Deno.readTextFileSync(f.rootfs + "/etc/systemd/system/" + schedules.schedule.timer);
        assert(service.includes("User=orbit\n"));
        assert(service.includes("WorkingDirectory=/srv/workspace\n"));
        assert(timer.includes("Persistent=false\n"));
        assert(timer.includes("OnCalendar=" + schedules.schedule.calendar));
        assert(!exists(schedules.scriptPath));
        assert(!exists(schedules.callbackPath));
        assertEquals(schedules.host()[schedules.schedule.timer], {state: "stopped", enabled: false});
        assert(!exists(f.checkout));
        schedules.assertUnrelated();
      } finally { f.cleanup(); }
    },
  });
}

for (const desired of ["enabled", "disabled"] as const) {
  Deno.test({
    name: `Schedule rollback restores exact observed timer activation when its ${desired} intent differs`,
    ignore: !hasAnsible,
    fn() {
      const f = fixture();
      try {
        const schedules = migrationSchedules(f, desired, "agent");
        // Actual activation can differ from the recorded intent; rollback restores what was running.
        schedules.states[schedules.schedule.timer] = { state: desired === "enabled" ? "started" : "stopped", enabled: desired !== "enabled" };
        schedules.writeStates();
        const before = schedules.host();
        f.build();
        assertEquals(schedules.host()[schedules.schedule.timer], { state: "stopped", enabled: false });
        const restored = f.run("restore");
        assert(restored.ok, restored.output);
        assertEquals(f.state().containers, {});
        assertEquals(schedules.host(), before);
        assertEquals(Deno.readTextFileSync(schedules.servicePath), schedules.service);
        assertEquals(Deno.readTextFileSync(schedules.timerPath), schedules.timer);
        assertEquals(Deno.readTextFileSync(schedules.scriptPath), schedules.script);
        assertEquals(Deno.readTextFileSync(schedules.callbackPath), schedules.callback);
        assert(exists(f.checkout));
        assert(exists(f.migrate.awake.path));
        schedules.assertUnrelated();
      } finally { f.cleanup(); }
    },
  });
}

Deno.test({
  name: "Schedule cutover resumes the timer even when a late Git retirement check refuses",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      const schedules = migrationSchedules(f, "enabled", "direct");
      f.build();
      Deno.writeTextFileSync(`${f.checkout}/index.php`, "late edit kept for recovery\n");
      const retired = f.run("retire");
      assert(!retired.ok, retired.output);
      assertEquals(f.result().error_code, "instance.migrate_retirement_blocked");
      assertEquals(f.result().preflight_refused, false);
      assertEquals(f.state().containers["instance-7"].status, "Running");
      assertEquals(schedules.host()[schedules.schedule.timer], { state: "stopped", enabled: false });
      assert(!exists(schedules.servicePath));
      assert(!exists(schedules.timerPath));
      const service = Deno.readTextFileSync(f.rootfs + "/etc/systemd/system/" + schedules.schedule.service);
      assert(service.includes("User=orbit\n"));
      assert(!service.includes(f.checkout));
      assert(!exists(schedules.scriptPath));
      assert(!exists(schedules.callbackPath));
      assertEquals(Deno.readTextFileSync(`${f.checkout}/index.php`), "late edit kept for recovery\n");
      assert(exists(f.migrate.awake.path));
      schedules.assertUnrelated();
    } finally { f.cleanup(); }
  },
});

for (const damaged of ["missing-service", "missing-timer", "foreign-service", "changed-calendar", "masked-timer", "activating-service", "service-dropin", "timer-dropin", "foreign-script", "foreign-callback", "legacy-script-drift", "legacy-context-drift", "legacy-missing-script", "legacy-callback-drift"] as const) {
  Deno.test({
    name: `Schedule preflight refuses ${damaged} before stopping any timer or Process`,
    ignore: !hasAnsible,
    fn() {
      const f = fixture();
      try {
        const schedules = migrationSchedules(f, "enabled", damaged === "legacy-callback-drift" ? "direct" : damaged.startsWith("legacy-") ? "agent" : "current");
        if (damaged === "missing-service") Deno.removeSync(schedules.servicePath);
        else if (damaged === "missing-timer") Deno.removeSync(schedules.timerPath);
        else if (damaged === "foreign-service") Deno.writeTextFileSync(schedules.servicePath, "[Service]\nExecStart=/bin/true\n");
        else if (damaged === "foreign-script") Deno.writeTextFileSync(schedules.scriptPath, "# foreign script\n");
        else if (damaged === "foreign-callback") Deno.writeTextFileSync(schedules.callbackPath, "# foreign callback\n");
        else if (damaged === "legacy-script-drift") Deno.writeTextFileSync(schedules.scriptPath, schedules.script.replace("php artisan schedule:run", "/outside/custom-command"));
        else if (damaged === "legacy-context-drift") Deno.writeTextFileSync(schedules.scriptPath, schedules.script.replace(f.checkout, "/outside/workspace"));
        else if (damaged === "legacy-missing-script") Deno.removeSync(schedules.scriptPath);
        else if (damaged === "legacy-callback-drift") Deno.writeTextFileSync(schedules.callbackPath, schedules.callback.replace("status=error", "status=success"));
        else if (damaged === "changed-calendar") Deno.writeTextFileSync(schedules.timerPath, schedules.timer.replace(schedules.schedule.calendar, "daily"));
        else if (damaged === "masked-timer") schedules.states[schedules.schedule.timer].status = { UnitFileState: "masked" };
        else if (damaged === "activating-service") schedules.states[schedules.schedule.service].status = { ActiveState: "activating" };
        else schedules.states[damaged === "service-dropin" ? schedules.schedule.service : schedules.schedule.timer].status = { DropInPaths: "/etc/systemd/system/example.d/override.conf" };
        schedules.writeStates();
        const before = schedules.host();
        const quiesce = f.run("quiesce");
        assert(!quiesce.ok, quiesce.output);
        const receipt = f.result();
        assertEquals(receipt.error_code, "schedule.container_artifact_unsupported");
        assert(receipt.error_message.includes(schedules.schedule.id), receipt.error_message);
        assert(receipt.error_message.includes(schedules.schedule.name), receipt.error_message);
        assertEquals(receipt.preflight_refused, false);
        assert(exists(f.migrate.awake.path));
        assertEquals(f.state().containers ?? {}, {});
        assertEquals(schedules.host(), before);
        const restored = f.run("restore");
        assert(restored.ok, restored.output);
        assertEquals(schedules.host(), before);
        schedules.assertUnrelated();
      } finally { f.cleanup(); }
    },
  });
}

Deno.test({
  name: "Schedule preflight checks every Schedule before stopping the first timer",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      const first = migrationSchedules(f, "enabled");
      const id = "33333333-3333-4333-8333-000000000003";
      const second = { ...first.schedule, id, name: "second-scheduler", service: `orbit-schedule-${id}.service`, timer: `orbit-schedule-${id}.timer` };
      const systemd = `${f.folder}/host/systemd`;
      Deno.writeTextFileSync(`${systemd}/${second.service}`, "[Service]\nExecStart=/bin/true\n");
      Deno.writeTextFileSync(`${systemd}/${second.timer}`, first.timer.replaceAll(first.schedule.id, id));
      first.states[second.service] = { state: "stopped", enabled: false };
      first.states[second.timer] = { state: "started", enabled: true };
      first.writeStates();
      f.migrate.schedules.push(second);
      f.write();
      const before = first.host();
      const refused = f.run("quiesce");
      assert(!refused.ok, refused.output);
      assertEquals(f.result().error_code, "schedule.container_artifact_unsupported");
      assert(f.result().error_message.includes(second.id));
      assert(f.result().error_message.includes(second.name));
      assertEquals(first.host(), before);
      assertEquals(Deno.readTextFileSync(first.servicePath), first.service);
      assert(exists(f.migrate.awake.path));
      assert(!exists(`${f.folder}/run/schedule-timers.json`));
    } finally { f.cleanup(); }
  },
});

for (const pathKind of ["absolute-command-symlink", "relative-command-symlink", "directory-symlink", "missing-directory", "internal-command-symlink", "internal-directory-symlink", "extensionless-command-symlink"] as const) {
  Deno.test({
    name: `Schedule preflight refuses a ${pathKind} that cannot move before downtime`,
    ignore: !hasAnsible,
    fn() {
      const f = fixture();
      try {
        // A retained default skips Git retirement, so this exercises the Schedule path preflight itself.
        f.migrate.retire = [];
        const external = `${f.folder}/external`;
        Deno.mkdirSync(external);
        Deno.writeTextFileSync(`${external}/command.php`, "<?php // external workspace\n");
        const commandLink = `${f.checkout}/${pathKind === "extensionless-command-symlink" ? "worker" : "linked.php"}`;
        Deno.writeTextFileSync(`${f.checkout}/command.php`, "<?php // workspace command\n");
        Deno.symlinkSync(pathKind === "internal-command-symlink" ? `${f.checkout}/command.php` : `${external}/command.php`, commandLink);
        const directoryLink = `${f.checkout}/linked-directory`;
        Deno.mkdirSync(`${f.checkout}/jobs`);
        Deno.symlinkSync(pathKind === "internal-directory-symlink" ? `${f.checkout}/jobs` : external, directoryLink);
        const sourceDirectory = pathKind.endsWith("directory-symlink") ? directoryLink : pathKind === "missing-directory" ? `${f.checkout}/missing-directory` : f.checkout;
        const command = (pathKind === "absolute-command-symlink" || pathKind === "internal-command-symlink") ? `php ${commandLink}` : pathKind === "relative-command-symlink" ? "php linked.php" : pathKind === "extensionless-command-symlink" ? "php worker" : "php artisan schedule:run";
        const schedules = migrationSchedules(f, "enabled", "agent", command, sourceDirectory);
        if (pathKind.endsWith("command-symlink")) schedules.schedule.source_command_paths = [commandLink];
        f.write();
        const before = schedules.host();
        const refused = f.run("quiesce");
        assert(!refused.ok, refused.output);
        assertEquals(f.result().error_code, pathKind.endsWith("command-symlink") ? "schedule.container_command_unsupported" : "schedule.container_path_unsupported");
        assert(f.result().error_message.includes(schedules.schedule.id), f.result().error_message);
        assert(f.result().error_message.includes(schedules.schedule.name), f.result().error_message);
        assertEquals(f.result().preflight_refused, false);
        assertEquals(schedules.host(), before);
        assertEquals(Deno.readTextFileSync(schedules.servicePath), schedules.service);
        assertEquals(Deno.readTextFileSync(schedules.scriptPath), schedules.script);
        assert(exists(f.migrate.awake.path));
        assertEquals(f.state().containers ?? {}, {});
        const restored = f.run("restore");
        assert(restored.ok, restored.output);
        assertEquals(schedules.host(), before);
        schedules.assertUnrelated();
      } finally { f.cleanup(); }
    },
  });
}

Deno.test({
  name: "Schedule rollback restores all timers after only some source timers stopped",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      const first = migrationSchedules(f, "enabled");
      const id = "44444444-4444-4444-8444-000000000004";
      const second = { ...first.schedule, id, name: "second-scheduler", service: `orbit-schedule-${id}.service`, timer: `orbit-schedule-${id}.timer` };
      const systemd = `${f.folder}/host/systemd`;
      Deno.writeTextFileSync(`${systemd}/${second.service}`, first.service.replaceAll(first.schedule.id, id));
      Deno.writeTextFileSync(`${systemd}/${second.timer}`, first.timer.replaceAll(first.schedule.id, id));
      first.states[second.service] = { state: "stopped", enabled: false };
      first.states[second.timer] = { state: "started", enabled: true };
      first.writeStates();
      f.migrate.schedules.push(second);
      f.write();
      const before = first.host();
      const failed = f.run("quiesce", false, undefined, { ORBIT_FIXTURE_FAIL_SYSTEMD_STOP: second.timer });
      assert(!failed.ok, failed.output);
      assertEquals(f.result().error_code, "instance.migrate_quiesce_failed");
      assertEquals(first.host()[first.schedule.timer], { state: "stopped", enabled: false });
      assertEquals(first.host()[second.timer], before[second.timer]);
      const restored = f.run("restore");
      assert(restored.ok, restored.output);
      assertEquals(first.host(), before);
      assertEquals(Deno.readTextFileSync(first.servicePath), first.service);
      assertEquals(Deno.readTextFileSync(first.timerPath), first.timer);
      assertEquals(Deno.readTextFileSync(`${systemd}/${second.service}`), first.service.replaceAll(first.schedule.id, id));
      assert(exists(f.checkout));
      assert(exists(f.migrate.awake.path));
      first.assertUnrelated();
    } finally { f.cleanup(); }
  },
});

for (const presetDesired of ["running", "stopped"] as const) {
  Deno.test({
    name: `Process migration carries a ${presetDesired} vp-dev through the shared units and retires only moved host files`,
    ignore: !hasAnsible,
    fn() {
      const f = fixture();
      try {
        const host = migrationProcesses(f, presetDesired);
        const prepared = f.run("container");
        assert(prepared.ok, prepared.output);
        assertEquals(host()[f.migrate.processes[0].name], { state: "started", enabled: true });
        const stopped = f.run("quiesce");
        assert(stopped.ok, stopped.output);
        assertEquals(host()[f.migrate.processes[0].name], { state: "stopped", enabled: false });
        for (const phase of ["workspace", "files"]) {
          const result = f.run(phase);
          assert(result.ok, result.output);
        }
        const installed = f.run("processes");
        assert(installed.ok, installed.output);
        assert(!installed.output.includes("process-secret"));
        for (const unit of f.container.processes) {
          assertEquals(Deno.readTextFileSync(`${f.rootfs}/etc/systemd/system/${unit.name}`), unit.content);
          assertEquals(Deno.readTextFileSync(`${f.rootfs}${unit.environment}`), `QUEUE_TOKEN=process-secret-${unit.id}\n`);
          const enabled = exists(`${f.rootfs}/etc/systemd/system/multi-user.target.wants/${unit.name}`);
          assertEquals(enabled, unit.desired === "running");
          const starts = f.state().systemctl.filter((args: string[]) =>
            ["start", "restart", "enable"].includes(args[0]) && args.includes(unit.name));
          assertEquals(starts.length > 0, unit.desired === "running");
        }
        const retired = f.run("retire");
        assert(retired.ok, retired.output);
        for (const unit of f.migrate.processes) assert(!exists(unit.environment_directory));
        assertEquals(host()[f.migrate.processes[0].name], { state: "stopped", enabled: false });
        assertEquals(host()["orbit-process-999-other.service"], { state: "started", enabled: true });
        assertEquals(Deno.readTextFileSync(`${f.folder}/host/unrelated/orbit-process-999-other.service`), "unrelated unit\n");
        assertEquals(Deno.readTextFileSync(`${f.folder}/host/unrelated/vars.env`), "OTHER_TOKEN=kept\n");
      } finally { f.cleanup(); }
    },
  });

  Deno.test({
    name: `Process migration restores a ${presetDesired} vp-dev only after removing its failed container`,
    ignore: !hasAnsible,
    fn() {
      const f = fixture();
      try {
        const host = migrationProcesses(f, presetDesired);
        f.build();
        const installed = f.run("processes");
        assert(installed.ok, installed.output);
        assertEquals(host()[f.migrate.processes[0].name], { state: "stopped", enabled: false });
        const restored = f.run("restore");
        assert(restored.ok, restored.output);
        assertEquals(host()[f.migrate.processes[0].name], { state: "started", enabled: true });
        assertEquals(host()[f.migrate.processes[1].name], { state: "stopped", enabled: false });
        assertEquals(f.state().containers, {});
        for (const unit of f.migrate.processes) {
          assertEquals(Deno.readTextFileSync(unit.path), "old unit\n");
          assertEquals(Deno.readTextFileSync(`${unit.environment_directory}/old.env`), "OLD_TOKEN=kept\n");
          assertEquals(host()[unit.name], { state: unit.desired === "running" ? "started" : "stopped", enabled: unit.desired === "running" });
        }
        assertEquals(host()["orbit-process-999-other.service"], { state: "started", enabled: true });
        assert(exists(f.checkout));
      } finally { f.cleanup(); }
    },
  });
}

Deno.test({
  name: "preflight names every unpushed local branch with its count against all remote-tracking refs",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      const pushed = git(f.checkout, "rev-parse", "HEAD");
      // None of these branches needs an upstream. Remote-only commits count as pushed on any remote.
      git(f.checkout, "commit", "--quiet", "--allow-empty", "-m", "remote-only");
      git(f.checkout, "update-ref", "refs/remotes/second/shared", "HEAD");
      git(f.checkout, "branch", "remote-only");
      git(f.checkout, "checkout", "--quiet", "-b", "spare", pushed);
      git(f.checkout, "commit", "--quiet", "--allow-empty", "-m", "one unpushed commit");
      git(f.checkout, "branch", "another");
      git(f.checkout, "commit", "--quiet", "--allow-empty", "-m", "two unpushed commits");
      git(f.checkout, "checkout", "--quiet", "main");
      const refs = git(f.checkout, "show-ref");
      const status = git(f.checkout, "status", "--porcelain");
      const quiesce = f.run("container");
      assert(!quiesce.ok, quiesce.output);
      const receipt = f.result();
      assertEquals(receipt.error_code, "instance.migrate_unpushed_commits");
      assert(receipt.error_message.includes(f.checkout));
      assert(receipt.error_message.includes('"branch": "another", "commits": 1'), receipt.error_message);
      assert(receipt.error_message.includes('"branch": "spare", "commits": 2'), receipt.error_message);
      assert(!receipt.error_message.includes('"branch": "main"'));
      assert(!receipt.error_message.includes('"branch": "remote-only"'));
      assertEquals(receipt.preflight_refused, true);
      assert(exists(f.migrate.awake.path));
      assertEquals(f.state().containers ?? {}, {});
      assert(f.run("restore").ok);
      assertEquals(git(f.checkout, "show-ref"), refs);
      assertEquals(git(f.checkout, "status", "--porcelain"), status);
    } finally {
      f.cleanup();
    }
  },
});

for (const detached of [false, true]) {
  Deno.test({
    name: detached ? "preflight refuses and counts an unpushed detached HEAD" : "preflight refuses and counts every commit when no remote-tracking refs exist",
    ignore: !hasAnsible,
    fn() {
      const f = fixture();
      try {
        if (detached) {
          git(f.checkout, "checkout", "--quiet", "--detach");
          git(f.checkout, "commit", "--quiet", "--allow-empty", "-m", "detached local commit");
        } else git(f.checkout, "update-ref", "-d", "refs/remotes/origin/main");
        const head = git(f.checkout, "rev-parse", "HEAD");
        const refs = git(f.checkout, "show-ref");
        const quiesce = f.run("container");
        assert(!quiesce.ok, quiesce.output);
        assertEquals(f.result().error_code, "instance.migrate_unpushed_commits");
        const expected = detached ? '"branch": "detached HEAD", "commits": 1' : '"branch": "main", "commits": 3';
        assert(f.result().error_message.includes(expected), f.result().error_message);
        assert(exists(f.migrate.awake.path));
        assertEquals(f.state().containers ?? {}, {});
        assertEquals(git(f.checkout, "rev-parse", "HEAD"), head);
        assertEquals(git(f.checkout, "show-ref"), refs);
      } finally {
        f.cleanup();
      }
    },
  });
}

Deno.test({
  name: "preflight refuses stashes with the full count and preserves every entry and earlier move resource",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      for (const name of ["first", "second"]) {
        Deno.writeTextFileSync(`${f.checkout}/${name}.txt`, name);
        git(f.checkout, "stash", "push", "--quiet", "--include-untracked");
      }
      const stashes = git(f.checkout, "stash", "list", "--format=%H");
      const refs = git(f.checkout, "show-ref");
      assert(f.run("container", true).ok);
      const state = f.state();
      const quiesce = f.run("container");
      assert(!quiesce.ok, quiesce.output);
      assertEquals(f.result().error_code, "instance.migrate_stashes_present");
      assert(f.result().error_message.includes("Stashes: 2"));
      assert(f.result().error_message.includes(f.checkout));
      assertEquals(f.result().preflight_refused, true);
      assert(exists(f.migrate.awake.path));
      assertEquals(f.state(), state);
      assert(f.run("restore").ok);
      assertEquals(f.state(), state);
      assertEquals(git(f.checkout, "show-ref"), refs);
      assertEquals(git(f.checkout, "stash", "list", "--format=%H"), stashes);
      for (const [index, name] of [[0, "second"], [1, "first"]] as const) {
        assertEquals(git(f.checkout, "show", `stash@{${index}}^3:${name}.txt`), name);
      }
      assertEquals(git(f.checkout, "status", "--porcelain"), "");
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a clean default with pushed commits and no stash retains all Git metadata and repeats without changes",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      git(f.checkout, "branch", "spare");
      const head = git(f.checkout, "rev-parse", "HEAD");
      f.build();
      const retire = f.run("retire");
      assert(retire.ok, retire.output);
      assert(!exists(f.checkout));
      assert(exists(f.migrate.awake.path));
      assertEquals(git(f.retained(), "rev-parse", "refs/heads/main"), head);
      assertEquals(git(f.retained(), "rev-parse", "refs/heads/spare"), head);
      const again = f.run("retire");
      assert(again.ok, again.output);
      assertMatch(again.output, /changed=0\s/);
    } finally {
      f.cleanup();
    }
  },
});

for (const common of [false, true]) {
  Deno.test({
    name: `a ${common ? "common_of" : "direct"} repository refuses a worktree created before its final preflight`,
    ignore: !hasAnsible,
    fn() {
      const f = fixture();
      try {
        f.build();
        if (common) {
          // The linked checkout has gone; its common Git directory identifies this retirement candidate by equality.
          f.migrate.retire[0].common_of = `${f.apps}/already-retired`;
          f.write();
          const playbook = `${f.folder}/instance-migrate.yml`;
          const plays = parse(Deno.readTextFileSync(playbook)) as Task[];
          for (const play of plays) {
            if (play.hosts === "migrate_node") (play.vars as Task).migrate_common = `${f.checkout}/.git`;
          }
          Deno.writeTextFileSync(playbook, stringify(plays));
        }
        const late = `${f.apps}/unmanaged-late`;
        const file = `${f.folder}/tasks/instance-migrate-retire.yml`;
        const tasks = parse(Deno.readTextFileSync(file)) as Task[];
        // The batch check has passed; the shared per-checkout preflight still refuses this blocker.
        tasks.unshift({
          name: "Fixture creates an unmanaged worktree before final preflight",
          "ansible.builtin.command": { argv: ["git", "-C", f.checkout, "worktree", "add", "--quiet", "--detach", late] },
          changed_when: true,
        });
        Deno.writeTextFileSync(file, stringify(tasks));
        const head = git(f.checkout, "rev-parse", "HEAD");
        const state = f.state();
        const retire = f.run("retire");
        assert(!retire.ok, retire.output);
        assertEquals(f.result().error_code, "instance.migrate_retirement_blocked");
        assert(f.result().error_message.includes(late), f.result().error_message);
        assertEquals(f.result().preflight_refused, false);
        assert(exists(`${f.checkout}/.git`));
        assert(exists(late));
        assertEquals(git(late, "rev-parse", "HEAD"), head);
        assertEquals(git(f.checkout, "rev-parse", "HEAD"), head);
        assertEquals(f.state(), state);
        assert(exists(f.migrate.awake.path));
      } finally {
        f.cleanup();
      }
    },
  });
}

Deno.test({
  name: "a worktree created after the final listing keeps all its Git data after retirement",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      f.build();
      const late = `${f.apps}/after-final-listing`;
      const file = `${f.folder}/tasks/instance-migrate-retire.yml`;
      const tasks = parse(Deno.readTextFileSync(file)) as Task[];
      const retain = tasks.findIndex((task) => task.name === "Retain the checkout without deleting any files");
      tasks.splice(retain, 0, {
        name: "Fixture adds a late worktree after the final preflight listing",
        "ansible.builtin.command": { argv: ["git", "-C", f.checkout, "worktree", "add", "--quiet", "--detach", late] },
        changed_when: true,
      });
      Deno.writeTextFileSync(file, stringify(tasks));
      const head = git(f.checkout, "rev-parse", "HEAD");
      const retire = f.run("retire");
      assert(retire.ok, retire.output);
      assert(!exists(f.checkout));
      const retained = f.retained();
      assert(exists(`${retained}/.git/worktrees/after-final-listing/HEAD`));
      assertEquals(git(retained, "cat-file", "-t", head), "commit");
      assertEquals(Deno.readTextFileSync(`${late}/.git`).trim(), `gitdir: ${f.checkout}/.git/worktrees/after-final-listing`);
      assert(exists(`${late}/index.php`));
      const receipt = JSON.parse(Deno.readTextFileSync(`${f.folder}/run/retained-git.json`));
      assertEquals(receipt.retained_git_path, `${f.checkout}.orbit-retired-git`);
      assertMatch(receipt.retained_git_at, /^\d{4}-\d{2}-\d{2}T/);
      assert(exists(f.migrate.awake.path));
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a stash ref with an expired reflog still refuses before downtime",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      Deno.writeTextFileSync(`${f.checkout}/expired-stash.txt`, "kept stash data\n");
      git(f.checkout, "stash", "push", "--quiet", "--include-untracked");
      const stash = git(f.checkout, "rev-parse", "refs/stash");
      git(f.checkout, "reflog", "expire", "--expire=all", "refs/stash");
      assertEquals(git(f.checkout, "stash", "list"), "");
      const quiesce = f.run("container");
      assert(!quiesce.ok, quiesce.output);
      assertEquals(f.result().error_code, "instance.migrate_stashes_present");
      assert(f.result().error_message.includes("Stashes: 1"));
      assertEquals(git(f.checkout, "rev-parse", "refs/stash"), stash);
      assertEquals(git(f.checkout, "show", `${stash}^3:expired-stash.txt`), "kept stash data");
      assert(exists(f.migrate.awake.path));
    } finally {
      f.cleanup();
    }
  },
});

Deno.test("retention keeps real Git edge cases intact", () => {
  const done = new Deno.Command("python3", {
    args: ["-I", new URL("fixtures/instance-migrate-retention.py", import.meta.url).pathname],
  }).outputSync();
  assert(done.success, new TextDecoder().decode(done.stdout) + new TextDecoder().decode(done.stderr));
});

Deno.test({
  name: "a worker receipt failure leaves the source untouched and a retry reuses the prepared retention time",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      f.build();
      const file = `${f.folder}/tasks/instance-migrate-retire.yml`;
      const original = Deno.readTextFileSync(file);
      const tasks = parse(original) as Task[];
      const record = tasks.find((task) => task.name === "Record retained Git data for the terminal result")!;
      (record["ansible.builtin.copy"] as Task).dest = `${f.folder}/missing-parent/retained-git.json`;
      Deno.writeTextFileSync(file, stringify(tasks));
      const head = git(f.checkout, "rev-parse", "HEAD");
      const failed = f.run("retire");
      assert(!failed.ok, failed.output);
      assert(exists(`${f.checkout}/.git`));
      assertEquals(git(f.checkout, "rev-parse", "HEAD"), head);
      assert(!exists(`${f.folder}/run/retained-git.json`));
      const prepared = JSON.parse(Deno.readTextFileSync(`${f.checkout}.orbit-retired-git/retained.json`));
      Deno.writeTextFileSync(file, original);
      const retry = f.run("retire");
      assert(retry.ok, retry.output);
      const recorded = JSON.parse(Deno.readTextFileSync(`${f.folder}/run/retained-git.json`));
      assertEquals(recorded.retained_git_at, prepared.retained_git_at);
      assertEquals(git(f.retained(), "rev-parse", "HEAD"), head);
      assert(!exists(f.checkout));
    } finally {
      f.cleanup();
    }
  },
});

for (const docker of [false, true]) for (const failures of [1, 9]) {
  Deno.test({
    name: `${docker ? "Docker" : "direct"} bridge endpoint add with ${failures === 1 ? "transient" : "persistent"} missing-veth failure stays before the migration probe and downtime`,
    ignore: !hasAnsible,
    fn() {
      const f = fixture();
      try {
        f.migrate.retire = [];
        f.container.bridge_endpoints = [{host: "10.44.0.7", port: 5432, docker}];
        f.migrate.databases = [{host: "10.44.0.7", port: 5432}];
        f.write();
        const state = f.state();
        state.bridgeVethFailures = failures;
        state.bridgeProbe = {host: "10.44.0.7", port: 5432,
          translatedHost: docker ? "172.17.0.2" : "10.44.0.7", translatedPort: docker ? 5433 : 5432};
        Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(state));
        if (docker) Deno.writeTextFileSync(`${f.folder}/nat.txt`, "-A DOCKER -d 10.44.0.7/32 ! -i docker0 -p tcp -m tcp --dport 5432 -j DNAT --to-destination 172.17.0.2:5433\n");
        const before = Deno.readTextFileSync(`${f.checkout}/.env`);
        const result = f.run("container");
        assertEquals(result.ok, failures === 1, result.output);
        assert(exists(f.migrate.awake.path));
        assert(!exists(`${f.folder}/run/quiesced`));
        assertEquals(Deno.readTextFileSync(`${f.checkout}/.env`), before);
        const calls = Deno.readTextFileSync(`${f.folder}/calls`).trim().split("\n").map((line) => JSON.parse(line) as string[]);
        const writes = calls.filter((call) => call[0] === "curl" && call.includes("PUT"));
        assertEquals(writes.length, failures === 1 ? 2 : 5);
        const probe = calls.findIndex((call) => call.some((arg) => arg.includes("/dev/tcp/$1/$2")));
        if (failures === 1) assert(probe > calls.findIndex((call) => call === writes.at(-1)));
        else assertEquals(probe, -1, "a failed bridge admission must not probe or quiesce");
      } finally { f.cleanup(); }
    },
  });
}

for (const mappings of [0, 2]) {
  Deno.test({
    name: `${mappings} local Docker DNAT mappings refuse migration before a probe or downtime with the named error`,
    ignore: !hasAnsible,
    fn() {
      const f = fixture();
      try {
        f.migrate.retire = [];
        f.container.bridge_endpoints = [{host: "10.44.0.7", port: 3306, docker: true}];
        f.migrate.databases = [{host: "10.44.0.7", port: 3306}];
        f.write();
        Deno.writeTextFileSync(`${f.folder}/nat.txt`, Array.from({length: mappings}, (_, i) => `-A DOCKER -d 10.44.0.7/32 ! -i docker0 -p tcp -m tcp --dport 3306 -j DNAT --to-destination 172.17.0.${i + 2}:3306`).join("\n"));
        const result = f.run("container");
        assert(!result.ok, result.output);
        assertEquals(f.result().error_code, "instance.container_endpoint_unresolved", result.output);
        assert(exists(f.migrate.awake.path));
        assert(!exists(`${f.folder}/run/quiesced`));
        const calls = Deno.readTextFileSync(`${f.folder}/calls`);
        assert(!calls.includes('"PUT"'));
        assert(!calls.includes('/dev/tcp/$1/$2'));
        assertEquals(JSON.parse(Deno.readTextFileSync(`${f.folder}/bridge-policy/health.json`)).error_code, "instance.container_endpoint_unresolved");
      } finally {f.cleanup();}
    },
  });
}

// Run the real phase before forcing its exit. Restoration must unwind effects that already happened, not merely a no-op failure.
for (const phase of ["quiesce", "workspace", "files", "processes", "seed"]) {
  Deno.test({
    name: `a forced ${phase} failure after quiesce restores host ownership and removes every container reservation effect`,
    ignore: !hasAnsible,
    fn() {
      const f = fixture();
      try {
        const host = migrationProcesses(f, "running");
        const before = host();
        const head = git(f.checkout, "rev-parse", "HEAD");
        for (const prior of ["container", "quiesce", "workspace", "files", "processes"]) {
          if (prior === phase) break;
          const done = f.run(prior);
          assert(done.ok, done.output);
        }
        f.fault(phase);
        const failed = f.run(phase);
        assert(!failed.ok, failed.output);
        assert(failed.output.includes("forced migration failure"), failed.output);
        const restored = f.run("restore");
        assert(restored.ok, restored.output);
        assertEquals(host(), before);
        assertEquals(f.state().containers, {});
        assertEquals(f.state()["network-acls"], [{ name: "orbitinst0-egress", egress: [], ingress: [] }]);
        assertEquals(f.ufw(), []);
        assert(!exists(f.container.mirror));
        assert(exists(f.migrate.awake.path));
        assertEquals(git(f.checkout, "rev-parse", "HEAD"), head);
      } finally {
        f.cleanup();
      }
    },
  });
}


for (const fault of ["exit127", "restart", "pid", "job", "inactive", "never-active"]) {
  Deno.test({
    name: `migration refuses a moved Process with ${fault} before cutover and restores its host ownership`,
    ignore: !hasAnsible,
    fn() {
      const f = fixture();
      try {
        const host = migrationProcesses(f, "running");
        const before = host();
        const process = f.container.processes.find((process) => process.desired === "running")!;
        if (fault === "exit127") process.content = "[Service]\nType=simple\nExecStart=/bin/sh -c 'exit 127'\nRestart=always\nRestartSec=1\n";
        f.write();
        f.build();
        const state = f.state();
        state.movedReadiness = {unit: process.name, fault, reads: []};
        Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(state));
        const failed = f.run("processes");
        assert(!failed.ok, failed.output);
        const receipt = f.result();
        assertEquals(receipt.error_code, "instance.migrate_processes_failed");
        assert(receipt.error_message.includes(process.name), JSON.stringify(receipt));
        const observed = f.state().movedReadiness;
        if (fault !== "never-active") {
          assertEquals(observed.reads.length, 2);
          assert(observed.reads[1] - observed.reads[0] >= 5000, "the real settling window must elapse");
        }
        if (fault === "exit127") assertEquals(observed.exitStatus, 127);
        assert(!exists(`${f.folder}/run/cutover-complete`));
        assert(!exists(f.migrate.awake.path));
        const restored = f.run("restore");
        assert(restored.ok, restored.output);
        assertEquals(f.result(), receipt, "restoration keeps the original named failure");
        assertEquals(host(), before);
        assertEquals(f.state().containers, {});
        assert(exists(f.migrate.awake.path));
        assert(exists(f.checkout));
      } finally { f.cleanup(); }
    },
  });
}

Deno.test({
  name: "only migration settles desired-running Processes; a stable Process passes with its PID and restart count unchanged",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      migrationProcesses(f, "stopped");
      f.build();
      const state = f.state();
      state.movedReadiness = {unit: f.container.processes.find((process) => process.desired === "running")!.name, fault: "stable", reads: []};
      Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(state));
      const passed = f.run("processes");
      assert(passed.ok, passed.output);
      const reads = f.state().movedReadiness.reads;
      assertEquals(reads.length, 2);
      assert(reads[1] - reads[0] >= 5000);
      const calls = f.state().systemctl as string[][];
      for (const process of f.container.processes.filter((process) => process.desired !== "running")) {
        assert(!calls.some((call) => call[0] === "show" && call.includes(process.name)));
      }
      // The same container phase without a migration intent keeps its existing readiness check.
      const intent = JSON.parse(Deno.readTextFileSync(`${f.folder}/run/intent.json`));
      delete intent.migrate;
      Deno.writeTextFileSync(`${f.folder}/run/intent.json`, JSON.stringify(intent));
      const other = f.run("processes");
      assert(other.ok, other.output);
      assertEquals(f.state().movedReadiness.reads, reads);
    } finally { f.cleanup(); }
  },
});

for (const [removedAfterPrepare, customPath] of [[false, "/opt/custom/tool"], [false, "/opt/custom/vp"], [true, null]] as const) {
  Deno.test({
    name: `an unavailable moved Process executable ${customPath ?? "removed runtime"} refuses ${removedAfterPrepare ? "quiesce" : "container preparation"} before stopping any host writer`,
    ignore: !hasAnsible,
    fn() {
      const f = fixture();
      try {
        const host = migrationProcesses(f, "running");
        const before = host();
        const process = f.migrate.processes[0];
        const path = customPath ?? process.executables[0];
        if (removedAfterPrepare) {
          const prepared = f.run("container");
          assert(prepared.ok, prepared.output);
        } else {
          process.executables = [path];
          f.write();
        }
        const state = f.state();
        state.unavailableExecutables = [path];
        Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(state));
        const refused = f.run(removedAfterPrepare ? "quiesce" : "container");
        assert(!refused.ok, refused.output);
        const receipt = f.result();
        assertEquals(receipt.error_code, "instance.migrate_process_binary_unavailable");
        assert(receipt.error_message.includes(process.name), JSON.stringify(receipt));
        assert(receipt.error_message.includes(path), JSON.stringify(receipt));
        assert(!exists(`${f.folder}/run/quiesced`));
        assert(exists(f.migrate.awake.path));
        assertEquals(host(), before);
        assert(exists(f.checkout));
        const restored = f.run("restore");
        assert(restored.ok, restored.output);
        assertEquals(host(), before);
        assertEquals(f.state().containers, {});
        assert(exists(f.migrate.awake.path));
      } finally { f.cleanup(); }
    },
  });
}

Deno.test({
  name: "the binary preflight names every unavailable Process and executable in one refusal",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      const host = migrationProcesses(f, "running");
      const before = host();
      const first = f.migrate.processes[0];
      const second = f.migrate.processes[1];
      first.executables = ["/opt/custom/tool"];
      second.executables = ["/opt/custom/vp"];
      f.write();
      const state = f.state();
      state.unavailableExecutables = [first.executables[0], second.executables[0]];
      Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(state));
      const refused = f.run("container");
      assert(!refused.ok, refused.output);
      const receipt = f.result();
      assertEquals(receipt.error_code, "instance.migrate_process_binary_unavailable");
      for (const process of [first, second]) {
        assert(receipt.error_message.includes(process.name), JSON.stringify(receipt));
        assert(receipt.error_message.includes(process.executables[0]), JSON.stringify(receipt));
      }
      assertEquals(host(), before);
      assert(exists(f.migrate.awake.path));
      assert(!exists(`${f.folder}/run/quiesced`));
    } finally { f.cleanup(); }
  },
});

Deno.test({
  name: "24 moved workers share one five-second settle window inside the 120-second process phase budget",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      migrationProcesses(f, "stopped");
      const host = JSON.parse(Deno.readTextFileSync(`${f.folder}/host-processes.json`));
      for (let id = 73; id < 96; id++) {
        const name = `orbit-process-${id}-worker.service`;
        const directory = `${f.folder}/host/${id}`;
        Deno.mkdirSync(directory, {recursive: true});
        const path = `${directory}/${name}`;
        Deno.writeTextFileSync(path, "old unit\n");
        f.migrate.processes.push({name, path, environment_directory: directory, desired: "running", executables: ["/bin/sleep"]});
        f.container.processes.push({id, name, content: "[Service]\nUser=orbit\nExecStart=/bin/sleep infinity\n", environment: null, desired: "running"});
        host[name] = {state: "started", enabled: true};
      }
      Deno.writeTextFileSync(`${f.folder}/host-processes.json`, JSON.stringify(host));
      f.write();
      f.build();
      const started = Date.now();
      const passed = f.run("processes");
      assert(passed.ok, passed.output);
      assert(Date.now() - started < 120000, "the production process phase budget is fixed at 120 seconds");
      assertEquals(passed.output.match(/Pausing for 5 seconds/g)?.length, 1, passed.output);
      const samples = f.state().movedReadinessSamples as {unit: string; at: number}[];
      const workers = f.container.processes.filter((process) => process.desired === "running");
      assertEquals(workers.length, 24);
      assertEquals(samples.length, 48);
      assert(samples[24].at - samples[23].at >= 5000, "all initial samples precede the single settling window");
      for (const worker of workers) assertEquals(samples.filter((sample) => sample.unit === worker.name).length, 2);
    } finally { f.cleanup(); }
  },
});

Deno.test("the environment guard shares the pre-quiesce preflight and precedes every probe and downtime step", () => {
  const tasks = parse(Deno.readTextFileSync(new URL("tasks/instance-migrate-phase.yml", root))) as Task[];
  const names = [
    "Check checkout links before downtime",
    "Check copied service endpoints before downtime",
    "Record that the container preflight passed",
    "Prepare the move's container",
    "Check the way to each database server",
    "Record that quiesce began",
    "Stop the old Process units before copying",
    "Remove the awake marker",
  ];
  const positions = names.map((name) => tasks.findIndex((task) => task.name === name));
  assert(positions.every((position) => position >= 0), "all preflight and downtime steps must remain present");
  assertEquals(positions, [...positions].sort((left, right) => left - right));
  const guard = tasks[positions[1]];
  assertEquals(guard.when, "phase in ['container', 'quiesce']");
  assertEquals((guard["ansible.builtin.include_tasks"] as Task).file, "instance-migrate-env-check.yml");
});

Deno.test({
  name: "an unsafe copied app service refuses before downtime and restore changes nothing",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      Deno.mkdirSync(`${f.checkout}/apps/mail`, { recursive: true });
      git(f.checkout, "config", "core.excludesFile", "/dev/null");
      Deno.writeTextFileSync(`${f.checkout}/.env`, "DB_CONNECTION=mysql\nDB_HOST=' 127.0.0.1 '\nDB_PORT=3308\nDB_PASSWORD=private-db-password\n");
      Deno.writeTextFileSync(`${f.checkout}/apps/mail/.env`, "MAIL_MAILER=smtp\nMAIL_HOST=10.6.0.7\nMAIL_PORT=1025\nMAIL_PASSWORD=private-mail-password\n");
      git(f.checkout, "add", "--force", "apps/mail/.env");
      git(f.checkout, "commit", "--quiet", "-m", "nested app");
      git(f.checkout, "update-ref", "refs/remotes/origin/main", "HEAD");
      const sourceUnit = `${f.folder}/source.service`;
      Deno.writeTextFileSync(sourceUnit, `[Service]\nEnvironmentFile=-"${f.checkout}/apps/mail/.env"\nExecStart=/bin/true\n`);
      f.migrate.processes.push({ name: "writer.service", path: sourceUnit, environment_directory: `${f.folder}/source-env`, desired: "running", executables: ["/bin/true"] });
      const schedules = migrationSchedules(f, "enabled");
      Deno.writeTextFileSync(`${f.folder}/host-processes.json`, JSON.stringify({ ...schedules.states, "writer.service": { state: "running", enabled: true } }));
      const before = Deno.readTextFileSync(`${f.folder}/host-processes.json`);
      f.migrate.databases = [{ host: "127.0.0.1", port: 1 }];
      f.write();
      const stateBefore = f.state();

      Deno.mkdirSync(`${f.checkout}/second`);
      const primaryEnvironment = Deno.readTextFileSync(`${f.checkout}/.env`);
      const sourceContent = Deno.readTextFileSync(sourceUnit);
      Deno.writeTextFileSync(`${f.checkout}/.env`, "DB_CONNECTION=sqlite\nMAIL_MAILER=log\n");
      Deno.writeTextFileSync(`${f.checkout}/second/.env`, "DB_CONNECTION=mysql\nDB_HOST=127.0.0.1\n");
      Deno.writeTextFileSync(sourceUnit, `[Service]\nWorkingDirectory=${f.checkout}/second\nEnvironmentFile=-${f.checkout}/.env\nExecStart=/bin/true\n`);
      const secondRefused = f.run("container");
      assert(!secondRefused.ok, secondRefused.output);
      assertEquals(f.result().error_code, "instance.migrate_env_service_unreachable");
      assert(f.result().error_message.includes("second/.env: DB_HOST=127.0.0.1:3306"));
      assert(!exists(`${f.folder}/calls`), "a second application refusal precedes every probe");
      assert(!exists(`${f.folder}/run/quiesced`));
      assertEquals(Deno.readTextFileSync(`${f.folder}/host-processes.json`), before);
      assertEquals(f.state(), stateBefore);
      Deno.removeSync(`${f.checkout}/second`, { recursive: true });
      Deno.writeTextFileSync(`${f.checkout}/.env`, primaryEnvironment);
      Deno.writeTextFileSync(sourceUnit, sourceContent);

      const refused = f.run("container");

      assert(!refused.ok, refused.output);
      assertEquals(f.result().error_code, "instance.migrate_env_service_unreachable");
      assertEquals(f.result().preflight_refused, true);
      assert(f.result().error_message.includes("DB_HOST=127.0.0.1:3308"));
      assert(f.result().error_message.includes("apps/mail/.env: MAIL_HOST=10.6.0.7:1025"));
      const output = refused.output + JSON.stringify(f.result());
      assert(!output.includes("private-db-password"));
      assert(!output.includes("private-mail-password"));
      assert(exists(f.migrate.awake.path));
      assertEquals(Deno.readTextFileSync(`${f.folder}/host-processes.json`), before);
      assertEquals(f.state(), stateBefore);
      assert(!exists(`${f.folder}/calls`), "an environment refusal must precede every container command and reach probe");
      assert(!exists(`${f.folder}/run/quiesced`));
      const restore = f.run("restore");
      assert(restore.ok, restore.output);
      assert(exists(f.migrate.awake.path));
      assertEquals(Deno.readTextFileSync(`${f.folder}/host-processes.json`), before);
      assertEquals(f.state(), stateBefore);
      assert(!exists(`${f.folder}/calls`), "an environment refusal must precede every container command and reach probe");
      assert(!exists(`${f.folder}/run/quiesced`));

      f.migrate.environment.targets.push({ host: "127.0.0.1", port: 3308 }, { host: "10.6.0.7", port: 1025 });
      f.migrate.databases = [];
      f.write();
      const allowed = f.run("container");
      assert(allowed.ok, allowed.output);
      assert(exists(f.migrate.awake.path));
      assert(!exists(`${f.folder}/run/quiesced`));
      const allowedEnvironment = Deno.readTextFileSync(`${f.checkout}/.env`);
      Deno.writeTextFileSync(`${f.checkout}/.env`, allowedEnvironment.replace("127.0.0.1", "127.0.0.2"));
      const refusedQuiesce = f.run("quiesce");
      assert(!refusedQuiesce.ok, refusedQuiesce.output);
      assertEquals(f.result().error_code, "instance.migrate_env_service_unreachable");
      assert(exists(`${f.folder}/run/schedule-timers.json`), "Schedule checks finish before the repeated environment guard");
      assert(exists(f.migrate.awake.path));
      assert(!exists(`${f.folder}/run/quiesced`));
      assertEquals(Deno.readTextFileSync(`${f.folder}/host-processes.json`), before);
      assertEquals(Deno.readTextFileSync(schedules.timerPath), schedules.timer);
      Deno.writeTextFileSync(`${f.checkout}/.env`, allowedEnvironment);
      const quiesce = f.run("quiesce");
      assert(quiesce.ok, quiesce.output);
      assert(!exists(f.migrate.awake.path));
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "an unreadable copied environment refuses before downtime and restore preserves the source",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      // Invalid UTF-8 deterministically exercises a file read failure even when the fixture runs as root.
      Deno.writeFileSync(`${f.checkout}/.env`, new Uint8Array([0xff, 0xfe]));
      const sourceUnit = `${f.folder}/source.service`;
      Deno.writeTextFileSync(sourceUnit, "[Service]\nExecStart=/bin/true\n");
      f.migrate.processes.push({ name: "writer.service", path: sourceUnit, environment_directory: `${f.folder}/source-env`, desired: "running", executables: ["/bin/true"] });
      Deno.writeTextFileSync(`${f.folder}/host-processes.json`, JSON.stringify({ "writer.service": { state: "running", enabled: true } }));
      const before = Deno.readTextFileSync(`${f.folder}/host-processes.json`);
      f.migrate.databases = [{ host: "127.0.0.1", port: 1 }];
      f.write();
      const stateBefore = f.state();

      const refused = f.run("container");

      assert(!refused.ok, refused.output);
      assertEquals(f.result().error_code, "instance.migrate_env_service_unreachable");
      assertEquals(f.result().preflight_refused, true);
      assert(f.result().error_message.includes(".env: cannot read .env safely."));
      assert(exists(f.migrate.awake.path));
      assertEquals(Deno.readTextFileSync(`${f.folder}/host-processes.json`), before);
      assertEquals(f.state(), stateBefore);
      assert(!exists(`${f.folder}/calls`), "an environment refusal must precede every container command and reach probe");
      assert(!exists(`${f.folder}/run/quiesced`));
      const restore = f.run("restore");
      assert(restore.ok, restore.output);
      assert(exists(f.migrate.awake.path));
      assertEquals(Deno.readTextFileSync(`${f.folder}/host-processes.json`), before);
      assertEquals(f.state(), stateBefore);
      assert(!exists(`${f.folder}/calls`), "an environment refusal must precede every container command and reach probe");
      assert(!exists(`${f.folder}/run/quiesced`));
    } finally {
      f.cleanup();
    }
  },
});

const real53Environment = "CACHE_STORE=database\nSESSION_DRIVER=database\nQUEUE_CONNECTION=database\nBROADCAST_CONNECTION=log\nMAIL_MAILER=log\nDB_CONNECTION=sqlite\nREDIS_CLIENT=phpredis\nREDIS_HOST=127.0.0.1\nREDIS_PORT=6379\nREDIS_PASSWORD=\n";
for (const scenario of ["stock Laravel", "real53", "selected Redis", "attached Redis", "wrong Redis port"]) {
  Deno.test({
    name: `stock selector preflight: ${scenario}`,
    ignore: !hasAnsible,
    fn() {
      const f = fixture();
      for (const name of [
        "vendor/nunomaduro/collision/tests/LaravelApp/.env",
        "vendor/vlucas/phpdotenv/tests/fixtures/env/.env",
        "vendor/laravel/framework/tests/Foundation/Fixtures/.env",
        "node_modules/example/tests/.env",
      ]) {
        const path = `${f.checkout}/${name}`;
        Deno.mkdirSync(path.slice(0, path.lastIndexOf("/")), { recursive: true });
        Deno.writeTextFileSync(path, "DB_CONNECTION=mysql\nDB_HOST=127.0.0.1\nMAIL_MAILER=smtp\nMAIL_HOST=127.0.0.1\nMAIL_PORT=1025\n");
      }

      try {
        const selected = scenario.includes("Redis");
        const text = scenario === "stock Laravel"
          ? Deno.readTextFileSync(new URL("fixtures/laravel-stock.env.example", import.meta.url))
          : real53Environment.replace("CACHE_STORE=database", selected ? "CACHE_STORE=redis" : "CACHE_STORE=database");
        Deno.writeTextFileSync(`${f.checkout}/.env`, text);
        if (scenario === "attached Redis" || scenario === "wrong Redis port") {
          f.migrate.environment.targets.push({ host: "127.0.0.1", port: scenario === "attached Redis" ? 6379 : 6380 });
        }
        f.write();
        const before = f.state();
        const result = f.run("container");
        const refused = scenario === "selected Redis" || scenario === "wrong Redis port";
        assertEquals(result.ok, !refused, result.output);
        if (refused) {
          assertEquals(f.result().error_code, "instance.migrate_env_service_unreachable");
          assertEquals(f.result().preflight_refused, true);
          assert(f.result().error_message.includes("REDIS_HOST=127.0.0.1:6379"));
          assertEquals(f.state(), before);
          assert(!exists(`${f.folder}/calls`), "refuse before any container command or probe");
        } else {
          assert(exists(`${f.folder}/calls`), "pass the real preflight and prepare the container");
        }
        assertEquals(Deno.readTextFileSync(`${f.checkout}/.env`), text);
        assert(exists(f.migrate.awake.path));
        assert(!exists(`${f.folder}/run/quiesced`));
      } finally { f.cleanup(); }
    },
  });
}
