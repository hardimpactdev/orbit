/**
 * Run the shipped playbook of a move (instance-migrate.yml) with the container phases it builds on (instance-container.yml)
 * against a stateful Incus boundary: a default with work that was never committed or pushed, a non-default worktree with the
 * default's old checkout, the restore after a failure and the two refusals. The Node is this machine: the run's connection is
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
  Deno.writeTextFileSync(`${folder}/state.json`, "{}");
  Deno.writeTextFileSync(
    `${folder}/bin/incus`,
    `#!/bin/sh\nexec '${Deno.execPath()}' run -A '${new URL("fixtures/instance-migrate-incus.ts", import.meta.url).pathname}' "$@"\n`,
  );
  Deno.chmodSync(`${folder}/bin/incus`, 0o755);
  for (const name of ["instance-container-phase.yml", "instance-migrate-phase.yml", "instance-migrate-discard.yml", "instance-migrate-retire.yml", "instance-migrate-retirement-check.yml"]) {
    const tasks = (parse(Deno.readTextFileSync(new URL(`tasks/${name}`, root))) as Task[]).map((task) => transform(structuredClone(task)));
    Deno.writeTextFileSync(`${folder}/tasks/${name}`, stringify(tasks));
  }
  for (const name of ["instance-container.yml", "instance-migrate.yml", "tasks/address-node.yml"]) {
    const source = Deno.readTextFileSync(new URL(name, root));
    if (name === "instance-migrate.yml") {
      const plays = parse(source) as Task[];
      for (const play of plays) {
        if (Array.isArray(play.tasks)) play.tasks = (play.tasks as Task[]).map(transform);
      }
      Deno.writeTextFileSync(`${folder}/${name}`, stringify(plays));
    } else Deno.writeTextFileSync(`${folder}/${name}`, source);
  }
  // The default's checkout: one pushed commit, then work the origin never saw.
  const main = `${apps}/default`;
  Deno.mkdirSync(main);
  git(main, "init", "--quiet", "--initial-branch", "main");
  Deno.writeTextFileSync(`${main}/.gitignore`, "vendor/\n.env\ndatabase/*.sqlite\n");
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
    clone,
  };
  const migrate = {
    checkout,
    branch,
    default: instance === "default",
    application: checkout,
    awake: { directory: `${folder}/markers`, path: `${folder}/markers/app-instance-${id}.awake` },
    databases: [] as { host: string; port: number }[],
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
    write,
    run(phase: string) {
      const playbook = ["quiesce", "files", "retire", "restore"].includes(phase) ? "instance-migrate.yml" : "instance-container.yml";
      const done = new Deno.Command("ansible-playbook", {
        args: ["-i", "localhost,", "--tags", phase, "-e", "ansible_connection=local", "-e", "ansible_become=false",
          "-e", "ansible_python_interpreter={{ ansible_playbook_python }}", `${folder}/${playbook}`],
        env: {
          HOST_FIXTURE: folder,
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
      for (const phase of ["quiesce", "container", "workspace", "files"]) {
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
  name: "a default moves with its unpushed commit and its changed, staged, untracked, deleted and ignored files; then its checkout goes",
  ignore: !hasAnsible,
  fn() {
    const f = fixture("default", true);
    try {
      // A retained default can copy its local files; its checkout is not scheduled for deletion yet.
      const retirements = f.migrate.retire;
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

      git(f.checkout, "branch", "spare");
      git(f.checkout, "stash", "push", "--quiet", "--include-untracked");
      const olderStash = git(f.checkout, "rev-parse", "refs/stash");
      Deno.writeTextFileSync(`${f.checkout}/new-stash.txt`, "newer stash\n");
      git(f.checkout, "stash", "push", "--quiet", "--include-untracked");
      const newerStash = git(f.checkout, "rev-parse", "refs/stash");
      f.migrate.retire = retirements;
      f.write();
      const retire = f.run("retire");
      assert(retire.ok, retire.output);
      assert(!exists(f.checkout));
      assert(exists(f.migrate.awake.path));
      // No commit went with the repository: its local branches and its stash are in the mirror.
      assertEquals(git(f.container.mirror, "rev-parse", "refs/migrated/heads/main"), head);
      assertEquals(git(f.container.mirror, "rev-parse", "refs/migrated/heads/spare"), head);
      assertEquals(git(f.container.mirror, "rev-parse", `refs/migrated/stashes/${olderStash}`), olderStash);
      assertEquals(git(f.container.mirror, "rev-parse", `refs/migrated/stashes/${newerStash}`), newerStash);
      assertEquals(git(f.container.mirror, "show", `${olderStash}^3:note.txt`), "untracked");
      assertEquals(git(f.container.mirror, "show", `${newerStash}^3:new-stash.txt`), "newer stash");
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
      const quiesce = f.run("quiesce");
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
      const quiesce = f.run("quiesce");
      assert(!quiesce.ok);
      assertEquals(f.result(), { error_code: "instance.migrate_branch_changed" });
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
      for (const phase of ["quiesce", "container", "workspace"]) assert(f.run(phase).ok);
      const files = f.run("files");
      assert(!files.ok);
      assertEquals(f.result(), { error_code: "instance.database_unreachable" });
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
      assert(f.run("container").ok);
      const state = f.state();
      const rules = f.ufw();
      const quiesce = f.run("quiesce");
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
      const quiesce = f.run("quiesce");
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

for (const blocker of ["changes", "worktree"] as const) {
  Deno.test({
    name: `retirement rechecks ${blocker} created after preflight and leaves the container and source intact`,
    ignore: !hasAnsible,
    fn() {
      const f = fixture();
      try {
        f.build();
        const path = blocker === "changes" ? `${f.checkout}/late.txt` : `${f.apps}/late-worktree`;
        if (blocker === "changes") Deno.writeTextFileSync(path, "late edit\n");
        else git(f.checkout, "worktree", "add", "--quiet", "--detach", path);
        const state = f.state();
        const retire = f.run("retire");
        assert(!retire.ok, retire.output);
        assertEquals(f.result().error_code, "instance.migrate_retirement_blocked");
        assert(f.result().error_message.includes(blocker === "changes" ? "late.txt" : path));
        assertEquals(f.result().preflight_refused, false);
        assert(exists(f.migrate.awake.path), "The container Route must serve after a retirement refusal.");
        assert(exists(f.checkout));
        assert(exists(path));
        assertEquals(f.state(), state);
        assertEquals(f.state().containers["instance-7"].status, "Running");
      } finally {
        f.cleanup();
      }
    },
  });
}
