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

/** Ownership needs root; the modes stay. */
function transform(task: Task): Task {
  for (const part of ["block", "always", "rescue"]) {
    if (Array.isArray(task[part])) task[part] = (task[part] as Task[]).map(transform);
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
  for (const name of ["instance-container.yml", "tasks/address-node.yml"]) {
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
  const write = () =>
    Deno.writeTextFileSync(`${folder}/run/intent.json`, JSON.stringify({ ssh_execution, container }));
  write();
  return {
    folder,
    source,
    container,
    write,
    run(phase: string) {
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
          ORBIT_RUN_ID: "run-1",
          ORBIT_GIT_CREDENTIAL: "",
          ORBIT_ENVIRONMENT: "APP_KEY=base64:secret\n",
          GIT_CONFIG_NOSYSTEM: "1",
          HOME: `${folder}/home`,
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
      assert(f.run("fetch").ok);
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
    } finally {
      f.cleanup();
    }
  },
});
