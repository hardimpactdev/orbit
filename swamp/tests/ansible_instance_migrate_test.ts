/**
 * Run the shipped playbook of a move (instance-migrate.yml) with the container phases it builds on (instance-container.yml)
 * against a stateful Incus boundary: a default with work that was never committed or pushed, a non-default worktree with the
 * default's old checkout, the restore after a failure and the refusals. The Node is this machine: the run's connection is
 * local and nothing becomes root.
 */
import { assert, assertEquals, assertMatch } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";

const has = (program: string) => new Deno.Command("sh", { args: ["-c", `command -v ${program}`] }).outputSync().success;
const hasAnsible = has("ansible-playbook");
const root = new URL("../ansible/", import.meta.url);

type Task = Record<string, unknown>;

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
  Deno.mkdirSync(`${folder}/library`);
  Deno.copyFileSync(new URL("fixtures/instance-migrate-systemd.py", import.meta.url), `${folder}/library/orbit_migrate_systemd.py`);
  Deno.writeTextFileSync(`${folder}/host-processes.json`, "{}");
  Deno.writeTextFileSync(`${folder}/process-environments.json`, "{}");
  Deno.writeTextFileSync(`${folder}/state.json`, "{}");
  Deno.writeTextFileSync(
    `${folder}/bin/incus`,
    `#!/bin/sh\nexec '${Deno.execPath()}' run -A '${new URL("fixtures/instance-migrate-incus.ts", import.meta.url).pathname}' "$@"\n`,
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
      assertEquals(f.state()["network-acls"], []);
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
      f.build();
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
      assertEquals(f.state()["network-acls"][0].egress, f.container.acl_rules.egress);
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

Deno.test({
  name: "a linked checkout refuses retirement even when the default is in the same retirement batch",
  ignore: !hasAnsible,
  fn() {
    const f = fixture("feature");
    try {
      f.migrate.retire.push({ path: f.main, mirror: `${f.folder}/mirror/7.git`, common_of: f.checkout });
      f.write();
      const quiesce = f.run("container");
      assert(!quiesce.ok, quiesce.output);
      assertEquals(f.result().error_code, "instance.migrate_retirement_blocked");
      assert(f.result().error_message.includes(f.main));
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
      assertEquals(f.state()["network-acls"], []);
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
      assertEquals(f.state()["network-acls"], []);
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
  name: "preflight names linked and detached worktrees and every tracked or untracked change before any Node change",
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
      for (const path of [f.checkout, linked, detached, "index.php", "staged.php", "note.txt", "renamed with spaces.txt", "local.php", "untracked.txt"]) {
        assert(receipt.error_message.includes(path), receipt.error_message);
      }
      assert(receipt.error_message.includes("Other worktrees (2 total; first 20)"));
      assert(receipt.error_message.includes("Changed files (5 total; first 20)"));
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
        f.build();
        const path = blocker === "changes" ? `${f.checkout}/late.txt` : `${f.apps}/late-worktree`;
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
        const detail = blocker === "changes" ? "late.txt" : blocker === "worktree" ? path : blocker === "commits" ? '"commits": 1' : "Stashes: 1";
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
