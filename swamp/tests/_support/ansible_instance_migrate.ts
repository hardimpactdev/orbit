/**
 * Run the shipped playbook of a move (instance-migrate.yml) with the container phases it builds on (instance-container.yml)
 * against a stateful Incus boundary: a default with work that was never committed or pushed, a non-default worktree with the
 * default's old checkout, the restore after a failure and the refusals. The Node is this machine: the run's connection is
 * local and nothing becomes root.
 */
import { assert, assertEquals, assertMatch } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";

export const has = (program: string) => new Deno.Command("sh", { args: ["-c", `command -v ${program}`] }).outputSync().success;
export const hasAnsible = has("ansible-playbook");
export const root = new URL("../../ansible/", import.meta.url);

export type Task = Record<string, unknown>;

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

export const git = (cwd: string, ...args: string[]) => {
  const done = new Deno.Command("git", {
    args: ["-c", "user.name=Orbit", "-c", "user.email=orbit@example.test", ...args],
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
  Deno.writeTextFileSync(`${folder}/host-processes.json`, "{}");
  Deno.writeTextFileSync(`${folder}/process-environments.json`, "{}");
  Deno.writeTextFileSync(`${folder}/state.json`, JSON.stringify({"network-acls": [{name: "orbitinst0-egress", egress: [], ingress: []}]}));
  Deno.writeTextFileSync(
    `${folder}/bin/incus`,
    `#!/bin/sh\nexec '${Deno.execPath()}' run -A '${new URL("../fixtures/instance-migrate-incus.ts", import.meta.url).pathname}' "$@"\n`,
  );
  Deno.chmodSync(`${folder}/bin/incus`, 0o755);
  Deno.copyFileSync(new URL("instance_migrate_links.py", root), `${folder}/instance_migrate_links.py`);
  for (const name of ["instance-container-phase.yml", "instance-migrate-phase.yml", "instance-migrate-discard.yml", "instance-migrate-retire.yml", "instance-migrate-retirement-check.yml", "instance-migrate-link-result.yml"]) {
    const tasks = (parse(Deno.readTextFileSync(new URL(`tasks/${name}`, root))) as Task[]).map((task) => transform(structuredClone(task)));
    Deno.writeTextFileSync(`${folder}/tasks/${name}`, stringify(tasks));
  }
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
    databases: [] as { host: string; port: number }[],
    processes: [] as { name: string; path: string; environment_directory: string; desired: string }[],
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
    run(phase: string, rawContainer = false) {
      const playbook = !rawContainer && ["container", "quiesce", "files", "retire", "restore"].includes(phase) ? "instance-migrate.yml" : "instance-container.yml";
      const done = new Deno.Command("ansible-playbook", {
        args: ["-i", "localhost,", "--tags", phase, "-e", "ansible_connection=local", "-e", "ansible_become=false",
          "-e", "ansible_python_interpreter={{ ansible_playbook_python }}", `${folder}/${playbook}`],
        env: {
          HOST_FIXTURE: folder,
          ORBIT_PROCESS_ENVIRONMENTS: Deno.readTextFileSync(`${folder}/process-environments.json`),
          PATH: `${folder}/bin:${Deno.env.get("PATH")}`,
          ANSIBLE_NOCOLOR: "1",
          ANSIBLE_HOME: `${folder}/ansible`,
          ANSIBLE_LOCAL_TEMP: `${folder}/tmp`,
          ORBIT_RUN: `${folder}/run`,
          ORBIT_RUN_ID: folder.slice(folder.lastIndexOf("/") + 1),
          // As in the workflow of a move, the container phases get no token and no .env from the Gateway.
          GIT_CONFIG_NOSYSTEM: "1",
          HOME: `${folder}/home`,
        },
      }).outputSync();
      return { ok: done.success, output: new TextDecoder().decode(done.stdout) + new TextDecoder().decode(done.stderr) };
    },
    /** The phases of a move up to the copied files. */
    build() {
      for (const phase of ["container", "quiesce", "workspace", "files"]) {
        const result = this.run(phase);
        assert(result.ok, `${phase}: ${result.output}`);
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
    f.migrate.processes.push({ name, path, environment_directory: directory, desired });
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
