/**
 * Run the shipped playbook of a default Instance's container (instance-container.yml) against a stateful Incus boundary: the first
 * build, a rerun that changes nothing, a deployment, the refusals of a deployment and the result file each phase writes. The Node is
 * this machine: the run's connection is local and nothing becomes root.
 */
import { assert, assertEquals, assertThrows } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";
import { fixture as hostFixture } from "./_support/instance_host.ts";

/** The normal host transcript of main; see the case that compares it. */
// The browser change adds exactly one task (the image's shared files in existing containers) and two Incus calls (its instance
// list, and the image's last `rm -f /etc/ld.so.cache`); the Orbit root CA in the image adds one task and two Incus calls (one push, and
// `update-ca-certificates` in the definition's finish); the host files stay as they were. The NIC address filters add two `--device`
// keys to the build container's init (digest 4f193b4c… → b21a521d…). The strict filter check has no report task, so the tasks are
// 174 again and the digest, which covers the task names, is 20b1670d…; the Incus calls stay the same.
// Chromium's trust adds six tasks: the folder gathers its one Debian package part (the trust module), the image checks the module's
// library (one exec) and writes agent's NSS store (one exec and one push), and the container step keeps its list of containers
// without the store for the Operation result; the host files stay as they were.
const MAIN_NORMAL_HOST = {calls: 69, tasks: 181, digest: "484a8ee104ff4a6397498ef96a917169c22dcbdcc44a537cca59c16733463f15"};

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
  const args = (task["ansible.builtin.file"] ?? task["ansible.builtin.copy"]) as Record<string, unknown> | undefined;
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
  Deno.copyFileSync(new URL("instance_bridge.py", root), `${folder}/instance_bridge.py`);
    Deno.copyFileSync(new URL("instance_network.py", root), `${folder}/instance_network.py`);
    Deno.copyFileSync(new URL("instance-nic-filters.json", root), `${folder}/instance-nic-filters.json`);
    Deno.copyFileSync(new URL("instance_nested.py", root), `${folder}/instance_nested.py`);
  Deno.writeTextFileSync(`${folder}/bin/curl`, `#!/bin/sh\nexec '${Deno.execPath()}' run -A '${new URL("fixtures/instance-bridge-curl.ts", import.meta.url).pathname}' "$@"\n`);
  Deno.chmodSync(`${folder}/bin/curl`, 0o755);
  Deno.writeTextFileSync(`${folder}/nat.txt`, "");
  Deno.writeTextFileSync(`${folder}/bin/iptables-save`, `#!/bin/sh\ncat '${folder}/nat.txt'\n`);
  Deno.chmodSync(`${folder}/bin/iptables-save`, 0o755);
  for (const shared of ["instance-network-nic-check.yml", "instance-bridge-rules.yml"]) {
    Deno.writeTextFileSync(`${folder}/tasks/${shared}`, stringify((parse(Deno.readTextFileSync(new URL(`tasks/${shared}`, root))) as Task[]).map(transform)).replaceAll("/usr/local/libexec", `${folder}/libexec`).replaceAll("/var/lib/orbit/instance-bridge", `${folder}/bridge-policy`));
  }
  Deno.mkdirSync(`${folder}/home`);
  Deno.writeTextFileSync(`${folder}/state.json`, JSON.stringify({"network-acls": [{name: "orbitinst0-egress", egress: [], ingress: []}]}));
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
  Deno.writeTextFileSync(`${folder}/tasks/instance-container-phase.yml`,
    stringify(tasks).replaceAll("/var/lib/orbit/instance-nested-image.json", `${folder}/nested-image.json`));
  for (const name of ["instance-container.yml", "tasks/address-node.yml", "tasks/instance-container-process.yml", "tasks/instance-container-handlers.yml",
    "tasks/instance-container-power.yml", "tasks/instance-private-names.yml",
    "tasks/instance-nested-club-key.yml", "instance_nested_club_key.sh"]) {
    Deno.copyFileSync(new URL(name, root), `${folder}/${name}`);
  }
  Deno.copyFileSync(new URL("instance_node_runtime.py", root), `${folder}/instance_node_runtime.py`);
  // The composed proxy environment goes into the container through instance_node_services.py `proxy`; the fake keeps its value.
  Deno.copyFileSync(new URL("instance_node_services.py", root), `${folder}/instance_node_services.py`);
  Deno.copyFileSync(new URL("tasks/instance-node-runtime.yml", root), `${folder}/tasks/instance-node-runtime.yml`);
  Deno.copyFileSync(new URL("tasks/instance-fleet.yml", root), `${folder}/tasks/instance-fleet.yml`);
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
    config: { "limits.cpu": "4", "limits.memory": "8GiB", "limits.processes": "4096" },
    root_disk_pool: "orbit-instances",
    root_disk_size: "40GiB",
    address: "10.253.0.2",
    network: "orbitinst0",
    nic_acl: "orbitinst0-proxy",
    bridge_cidr: "10.253.0.0/24",
    bridge_admitted_at: 1800000000000000,
    bridge_endpoints: [] as {host: string; port: number; docker: boolean}[],
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
    web_config: { path: "/etc/orbit/frankenphp.Caddyfile", content: ":8080 {\n    php_server\n}\n" },
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
  // Top-level intent parts beside the container, such as the `deployment` of an instance:deploy run and a nested-fleet default's `fleet`.
  const intent: Record<string, unknown> = {};
  const write = () =>
    Deno.writeTextFileSync(`${folder}/run/intent.json`, JSON.stringify({ ssh_execution, container, control, ...intent }));
  write();
  return {
    folder,
    source,
    container,
    control,
    intent,
    write,
    run(phase: string, extra: Record<string, string> = {}, options: string[] = []) {
      const done = new Deno.Command("ansible-playbook", {
        args: ["-i", "localhost,", "--tags", phase, "-e", "ansible_connection=local", "-e", "ansible_become=false",
          "-e", "ansible_python_interpreter={{ ansible_playbook_python }}", ...options, `${folder}/instance-container.yml`],
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
      assertEquals(state["network-acls"].find((acl: {name: string}) => acl.name === "instance-7"), { name: "instance-7", ...f.container.acl_rules });
      assertEquals(state.containers["instance-7"].devices, [
        "eth0,ipv4.address=10.253.0.2",
        "eth0,security.acls=orbitinst0-proxy,instance-7",
        "eth0,security.acls.default.egress.action=reject",
        "eth0,security.acls.default.ingress.action=reject",
        // The NIC accepts only its own IPv4 and MAC address (instance_network.py's FILTERS).
        "eth0,security.ipv4_filtering=true",
        "eth0,security.mac_filtering=true",
        "root,type=disk", "root,path=/", "root,pool=orbit-instances", "root,size=40GiB",
      ]);
      assertEquals(state.containers["instance-7"].config, ["security.idmap.isolated=true", "limits.cpu=4", "limits.memory=8GiB", "limits.processes=4096"]);
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
      // Git never takes the .env, although the Project's own files do not ignore it.
      assertEquals(Deno.readTextFileSync(`${f.folder}/containers/instance-7/srv/workspace/.git/info/exclude`).split("\n").filter((line) => line === "/.env").length, 1);
      assertEquals(f.workspace("status", "--porcelain", "--untracked-files=all"), "");
      assertEquals(state.containers["instance-7"].snapshots, [`seed-${head}`]);
      assert(state.systemctl.some((call: string[]) => call.join(" ") === "enable --now --no-block -- orbit-frankenphp.service"));
      assertEquals(Deno.readTextFileSync(`${f.folder}/containers/instance-7/etc/orbit/frankenphp.Caddyfile`), f.container.web_config.content);
      // No bundle, credential or .env stays behind on the Node or in the container.
      assertEquals([...Deno.readDirSync(`${f.folder}/containers/instance-7/tmp`)], []);

      // Deploy must leave a stock legacy store alone, even if a helper file is present.
      const guest = f.folder + "/containers/instance-7";
      const helper = guest + "/usr/local/libexec/orbit-instance-node-runtime.py";
      Deno.mkdirSync(guest + "/usr/local/libexec", {recursive: true});
      Deno.writeTextFileSync(helper, "pre-existing helper: do not install or convert");
      for (const phase of ["container", "workspace", "processes", "seed", "start", "check"]) {
        const again = f.run(phase);
        assert(again.ok, again.output);
        assertEquals(again.changed, 0, `${phase}: ${again.output}`);
      }

      assertEquals(Deno.readTextFileSync(helper), "pre-existing helper: do not install or convert");

      // A container made before the .env line existed: its own exclude lines stay, the .env line comes once.
      const exclude = `${guest}/srv/workspace/.git/info/exclude`;
      Deno.writeTextFileSync(exclude, "# local\n*.log");
      for (const _ of [1, 2]) assert(f.run("workspace").ok);
      assertEquals(Deno.readTextFileSync(exclude), "# local\n*.log\n/.env\n");
      // An application folder names its own .env.
      Object.assign(f.container, { environment_file: "/srv/workspace/apps/web/.env" });
      f.write();
      Deno.mkdirSync(`${guest}/srv/workspace/apps/web`, { recursive: true });
      assert(f.run("workspace").ok);
      assertEquals(Deno.readTextFileSync(exclude), "# local\n*.log\n/.env\n/apps/web/.env\n");
      assertEquals(f.workspace("status", "--porcelain", "--untracked-files=all"), "");
      Object.assign(f.container, { environment_file: "/srv/workspace/.env" });
      f.write();
      assertEquals(Deno.readLinkSync(guest + "/var/lib/orbit/vite-plus-home/js_runtime"), "/opt/orbit-runtime/current/vite-plus-home/js_runtime");

      // Configuration drift alone restarts PHP, even when its unit has not changed.
      Deno.writeTextFileSync(`${f.folder}/containers/instance-7/etc/orbit/frankenphp.Caddyfile`, "stale\n");
      const before = f.state().systemctl.length;
      const repaired = f.run("processes");
      assert(repaired.ok, repaired.output);
      assertEquals(Deno.readTextFileSync(`${f.folder}/containers/instance-7/etc/orbit/frankenphp.Caddyfile`), f.container.web_config.content);
      assert(f.state().systemctl.slice(before).some((call: string[]) => call.join(" ") === "restart --no-block -- orbit-frankenphp.service"));
      const unchanged = f.run("processes");
      assert(unchanged.ok, unchanged.output);
      assertEquals(unchanged.changed, 0, unchanged.output);
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a converted default Node store refreshes its same-commit seed once at the existing snapshot boundary",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      for (const phase of ["container", "workspace", "processes", "seed"]) {
        const done = f.run(phase);
        assert(done.ok, done.output);
      }
      const before = f.state();
      // Simulate interruption after conversion but before snapshot capture.
      // The durable marker, not this invocation's changed flag, must force refresh.
      before.node_seed_pending = true;
      Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(before));
      const converted = f.run("seed");
      assert(converted.ok, converted.output);
      const calls = Deno.readTextFileSync(`${f.folder}/calls`).trim().split("\n").map((line) => JSON.parse(line) as string[]);
      const snapshots = calls.filter((call) => call.includes("snapshot") && call.includes("create"));
      assertEquals(snapshots.length, 2);
      assert(snapshots[1].includes("--reuse"), "the old immutable seed cannot acquire the converted store");
      assertEquals(f.state().containers["instance-7"].snapshots, before.containers["instance-7"].snapshots);
      const stops = f.state().systemctl.filter((call: string[]) => call[0] === "stop").length;
      const again = f.run("seed");
      assert(again.ok, again.output);
      assertEquals(again.changed, 0);
      assertEquals(f.state().systemctl.filter((call: string[]) => call[0] === "stop").length, stops);
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a deployment at the seed's commit retakes the seed; a seed phase outside a deployment keeps it",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      for (const phase of ["container", "workspace", "processes", "seed"]) {
        const done = f.run(phase);
        assert(done.ok, done.output);
      }
      const head = git(f.source, "rev-parse", "HEAD");
      const snapshotCreates = () =>
        Deno.readTextFileSync(`${f.folder}/calls`).trim().split("\n").map((line) => JSON.parse(line) as string[])
          .filter((call) => call.includes("snapshot") && call.includes("create"));
      assertEquals(snapshotCreates().length, 1);

      const again = f.run("seed");
      assert(again.ok, again.output);
      assertEquals(snapshotCreates().length, 1);

      // The default's caches and built files move on between deployments; a clone must copy them as they are now.
      f.intent.deployment = { variant: "container" };
      f.write();
      const redeployed = f.run("seed");
      assert(redeployed.ok, redeployed.output);
      const creates = snapshotCreates();
      assertEquals(creates.length, 2);
      assert(creates[1].includes("--reuse"), "the same-commit seed is replaced in place");
      assertEquals(f.state().containers["instance-7"].snapshots, [`seed-${head}`]);
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
      // The live Project 66 seed inherited root from its profile: no local root device.
      const sourceState = f.state();
      sourceState.containers["instance-7"].devices = sourceState.containers["instance-7"].devices.filter(
        (device: string) => !device.startsWith("root,"),
      );
      Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(sourceState));
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
          t3: { title: "Tool", port: 3800, listen: "10.44.0.7", device: "t3", unit: "t3.service", internal_port: 3773, ready_seconds: 20,
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
      // The copy's NIC carries the same address filters as a create.
      assertEquals(state.containers["instance-8"].devices, ["eth0,ipv4.address=10.253.0.3", "eth0,security.acls=orbitinst0-proxy,instance-8", "eth0,security.acls.default.egress.action=reject", "eth0,security.acls.default.ingress.action=reject", "eth0,security.ipv4_filtering=true", "eth0,security.mac_filtering=true", "root,type=disk", "root,path=/", "root,pool=orbit-instances", "root,size=40GiB"]);
      assertEquals(state.containers["instance-8"].added, {
        t3: { type: "proxy", settings: ["listen=tcp:10.44.0.7:3800", "connect=tcp:10.253.0.3:3773", "nat=true"] },
      });
      assertEquals(state.containers["instance-8"].config, ["limits.cpu=4", "limits.memory=8GiB", "limits.processes=4096"]);
      assert(state.containers["instance-8"].devices.includes("root,size=40GiB"));
      assertEquals(state["network-acls"].find((acl: { name: string }) => acl.name === "instance-8"), { name: "instance-8", ...f.container.acl_rules });
      // The task VM range's deny comes before the allow.
      assertEquals(Deno.readTextFileSync(`${f.folder}/ufw`).trim().split("\n").map((line) => JSON.parse(line).comment),
        ["orbit:instance-8-t3-task-vms", "orbit:instance-8-t3"]);
      // The branch is new, at the seed's commit; the default's workspace and seed stay as they were.
      assertEquals(git(`${clone}/srv/workspace`, "symbolic-ref", "--short", "HEAD"), "feature");
      assertEquals(git(`${clone}/srv/workspace`, "rev-parse", "HEAD"), seed);
      // The seed's exclude line comes with the copy; the clone's own write keeps it once.
      assertEquals(Deno.readTextFileSync(`${clone}/srv/workspace/.git/info/exclude`).split("\n").filter((line) => line === "/.env").length, 1);
      assertEquals(git(`${clone}/srv/workspace`, "status", "--porcelain", "--untracked-files=all"), "");
      assertEquals(f.workspace("symbolic-ref", "--short", "HEAD"), "main");
      assertEquals(state.containers["instance-7"].snapshots, [`seed-${seed}`]);
      assertEquals(Deno.readTextFileSync(`${clone}/etc/orbit/instance`), "ORBIT_INSTANCE_ID=8\n");
      assertEquals(JSON.parse(Deno.readTextFileSync(`${clone}/home/agent/.t3/userdata/settings.json`)), { linkedEnvironmentsEnabled: true });
      assertEquals(Deno.statSync(`${clone}/home/agent/.t3/userdata`).mode! & 0o777, 0o700);
      assertEquals(Deno.readTextFileSync(`${clone}/home/agent/.config/orbit/model.env`), "ORBIT_MODEL_KEY=placeholder\n");
      assertEquals(state.files["/home/agent/.config/orbit/model.env"], { uid: "2750", gid: "2750", mode: "0600" });
      assertEquals(Deno.readLinkSync(`${clone}/home/agent/.claude/CLAUDE.md`), "/opt/orbit-agent/current/notes/web-app.md");
      assert(state.systemctl.some((call: string[]) => call.join(" ") === "restart --no-block -- orbit-frankenphp.service"));
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
        // Tag selection must not dispatch the other lifecycle phases as skipped tasks.
        if (phase === "t3") {
          const skipped = Number(/^node\s*:.*skipped=(\d+)/m.exec(again.output)?.[1] ?? 999);
          assert(skipped < 10, again.output);
        }
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
          t3: { title: "Tool", port: 3800, listen: "10.44.0.7", device: "t3", unit: "other.service", internal_port: 3773, ready_seconds: 1, t3: "/t3",
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
  (state.systemctl ?? []).filter((call) => call[0] === "restart").map((call) => call.slice(call.indexOf("--") + 1));

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
      assertEquals(enabled, ["enable", "--now", "--no-block", "--", "orbit-frankenphp.service", worker.name]);
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
  name: "a deployment's start waits only for enabled Process units; the processes phase enables, starts and waits for a new or resumed one",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      f.container.processes = [worker, paused];
      f.write();
      for (const phase of ["container", "workspace", "processes"]) {
        const result = f.run(phase, { ORBIT_PROCESS_ENVIRONMENTS: environments });
        assert(result.ok, `${phase}: ${result.output}`);
      }
      // The Project gained a definition: its record is in the intent, but no unit file is in the container yet. The scheduler was
      // stopped and is now set to run: its unit file is there, but disabled and inactive.
      const fresh = { id: 43, name: "orbit-process-43-fresh.service", content: "[Service]\nUser=orbit\nExecStart=sleep infinity\n", environment: null, desired: "running" };
      const resumed = { ...paused, desired: "running" };
      assert(Deno.statSync(`${f.folder}/containers/instance-7/etc/systemd/system/${paused.name}`).isFile);
      const stopped = f.state();
      stopped.inactive = [...new Set([...(stopped.inactive ?? []), paused.name])];
      Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(stopped));
      f.container.processes = [worker, resumed, fresh];
      f.write();
      const shows = (unit: string) => f.state().systemctl.filter((call: string[]) => call[0] === "show" && call.at(-1) === unit).length;
      const start = f.run("start", { ORBIT_PROCESS_ENVIRONMENTS: environments });
      assert(start.ok, start.output);
      assertEquals(shows(fresh.name), 0);
      assertEquals(shows(paused.name), 0);
      assert(shows(worker.name) > 0);
      const installed = f.run("processes", { ORBIT_PROCESS_ENVIRONMENTS: environments });
      assert(installed.ok, installed.output);
      assert(Deno.statSync(`${f.folder}/containers/instance-7/etc/systemd/system/${fresh.name}`).isFile);
      for (const unit of [fresh.name, paused.name]) {
        assert(f.state().systemctl.some((call: string[]) => call[0] === "enable" && call.includes("--now") && call.includes(unit)), unit);
        assert(shows(unit) > 0, unit);
      }

    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a new Process that does not start fails the deployment's processes phase, which names its unit",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      f.container.processes = [worker];
      f.write();
      for (const phase of ["container", "workspace", "processes"]) {
        const result = f.run(phase, { ORBIT_PROCESS_ENVIRONMENTS: environments });
        assert(result.ok, `${phase}: ${result.output}`);
      }
      const broken = { id: 44, name: "orbit-process-44-broken.service", content: "[Service]\nUser=orbit\nExecStart=/bin/false\n", environment: null, desired: "running" };
      f.container.processes = [worker, broken];
      f.write();
      const state = f.state();
      state.failing = [broken.name];
      state.inactive = [broken.name];
      Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(state));
      const processes = f.run("processes", { ORBIT_PROCESS_ENVIRONMENTS: environments });
      assert(!processes.ok, processes.output);
      assertEquals(f.result(), { error_code: "instance.container_processes_failed", detail: `Not active: ${broken.name}`, units_inactive: [broken.name] });
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a Process's start, stop, restart and destroy change only its own unit in the container",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      f.container.processes = [worker, paused];
      f.write();
      for (const phase of ["container", "workspace", "processes"]) {
        assert(f.run(phase, { ORBIT_PROCESS_ENVIRONMENTS: environments }).ok);
      }
      const calls = () => f.state().systemctl.map((call: string[]) => call.join(" "));

      const checksBeforeStop = calls().length;
      const stop = f.run("process", { ORBIT_PROCESS_ACTION: "stop" });
      assert(stop.ok, stop.output);
      assert(calls().includes(`disable --now -- ${worker.name}`));
      assertEquals(f.result(), { state: "inactive", output: "" });
      assertEquals(calls().slice(checksBeforeStop).filter((call: string) => call.startsWith("show ")).length, 0);

      const checksBeforeStart = calls().filter((call: string) => call === `show --property=ActiveState --property=Job -- ${worker.name}`).length;
      assert(f.run("process", { ORBIT_PROCESS_ACTION: "start" }).ok);
      assert(calls().includes(`enable --now --no-block -- ${worker.name}`));
      assertEquals(f.result(), { state: "active", output: "" });
      assert(calls().filter((call: string) => call === `show --property=ActiveState --property=Job -- ${worker.name}`).length > checksBeforeStart);

      const checksBeforeRestart = calls().length;
      assert(f.run("process", { ORBIT_PROCESS_ACTION: "restart" }).ok);
      assert(calls().join("\n").includes(`enable -- ${worker.name}\nrestart --no-block -- ${worker.name}`));

      assert(calls().slice(checksBeforeRestart).includes(`show --property=ActiveState --property=Job -- ${worker.name}`));

      // Destroy removes only the admitted Process's unit and folder; the other Process keeps both.
      const rootfs = `${f.folder}/containers/${f.container.name}`;
      const exists = (path: string) => {
        try {
          Deno.lstatSync(`${rootfs}${path}`);
          return true;
        } catch {
          return false;
        }
      };
      Deno.mkdirSync(`${rootfs}/etc/orbit/processes/42`, { recursive: true });
      // A unit name of another Process than the admitted id is refused before anything changes.
      Object.assign(f.control, { process_id: 41, name: paused.name });
      f.write();
      assert(!f.run("process", { ORBIT_PROCESS_ACTION: "destroy" }).ok);
      assertEquals(f.result(), { error_code: "process.target_unsupported" });
      assert(exists(`/etc/systemd/system/${paused.name}`));
      Object.assign(f.control, { process_id: 42, name: paused.name });
      f.write();
      const beforeDestroy = calls().length;
      const destroy = f.run("process", { ORBIT_PROCESS_ACTION: "destroy" });
      assert(destroy.ok, destroy.output);
      assertEquals(f.result(), { state: "removed", output: "" });
      assertEquals([exists(`/etc/systemd/system/${paused.name}`), exists("/etc/orbit/processes/42")], [false, false]);
      assertEquals([exists(`/etc/systemd/system/${worker.name}`), exists("/etc/orbit/processes/41")], [true, true]);
      assert(!exists(`/etc/systemd/system/multi-user.target.wants/${paused.name}`));
      const destroyCalls = calls().slice(beforeDestroy);
      assert(destroyCalls.includes(`disable --now -- ${paused.name}`), destroyCalls.join("\n"));
      assert(destroyCalls.includes("daemon-reload"));
      assertEquals(destroyCalls.filter((call: string) => call.includes(worker.name)), []);
      // A unit already gone, with a boot link left behind, converges: the link goes and the check passes.
      Deno.symlinkSync(`/etc/systemd/system/${paused.name}`, `${rootfs}/etc/systemd/system/multi-user.target.wants/${paused.name}`);
      assert(f.run("process", { ORBIT_PROCESS_ACTION: "destroy" }).ok);
      assertEquals(f.result(), { state: "removed", output: "" });
      assert(!exists(`/etc/systemd/system/multi-user.target.wants/${paused.name}`));
      Object.assign(f.control, { process_id: 41, name: worker.name });
      f.write();

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

/** A sleeping container behind its asleep marker, and the calls of the start that follows. */
function asleep(f: ReturnType<typeof fixture>, container: Partial<{ web: number[]; t3: boolean }>) {
  const marker = `${f.folder}/hibernation/app-instance-7.asleep`;
  Deno.mkdirSync(`${f.folder}/hibernation`, { recursive: true });
  assert(f.run("container").ok);
  assert(f.run("stop").ok);
  Deno.writeTextFileSync(marker, "");
  const state = f.state();
  state.containers["instance-7"] = { ...state.containers["instance-7"], ...container };
  Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(state));
  const before = Deno.readTextFileSync(`${f.folder}/calls`).split("\n").length;
  const webChecks = () => Deno.readTextFileSync(`${f.folder}/calls`).split("\n").slice(before - 1)
    .filter((line) => line.includes("http://127.0.0.1:8080/")).length;
  const present = () => { try { return Deno.statSync(marker).isFile; } catch { return false; } };
  return { marker, webChecks, present };
}

Deno.test({
  name: "a deployment's start and restore-start (no marker in the intent) do no web wait and no marker work",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      const sleeping = asleep(f, { web: [200] });
      // The deploy and the nested-fleet restore run the same `start` part with an intent that names no marker.
      const result = f.run("start");
      assert(result.ok, result.output);
      assert(sleeping.present());
      assertEquals(sleeping.webChecks(), 0);
      assert(/Mark the woken Instance awake.*\n.*skipping/.test(result.output), result.output);
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a wake's start removes the marker once the app answers, waits a short time through 5xx, and keeps it when the web unit never answers",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      // A web unit that listens before the app: two 5xx answers, then the app. The marker stays until the app answers.
      let sleeping = asleep(f, { web: [502, 503, 200] });
      Object.assign(f.intent, { marker: sleeping.marker, web: { port: 8080, ready_seconds: 3, settle_seconds: 4 } });
      f.write();
      const woken = f.run("start");
      assert(woken.ok, woken.output);
      assert(!sleeping.present());
      assertEquals(sleeping.webChecks(), 3);

      // An app that answers 500 by itself is served as it is once the settle time ends.
      sleeping = asleep(f, { web: [500] });
      const broken = f.run("start");
      assert(broken.ok, broken.output);
      assert(!sleeping.present());
      // The first answer, then the settle wait's first try and its 4 retries.
      assertEquals(sleeping.webChecks(), 6);

      // No answer at all keeps the marker.
      sleeping = asleep(f, {});
      const state = f.state();
      delete state.containers["instance-7"].web;
      Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(state));
      const silent = f.run("start");
      assert(!silent.ok, silent.output);
      assertEquals(f.result(), { error_code: "instance.web_unready", wake: "started", marker_found: true });
      assert(sleeping.present());
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a wake's start removes the marker before the T3 wait: a silent T3 fails the wake while the site serves",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      (f.container as Record<string, unknown>).clone = { t3: { internal_port: 3773, ready_seconds: 1, unit: "t3.service" } };
      let sleeping = asleep(f, { web: [200], t3: false });
      Object.assign(f.intent, { marker: sleeping.marker, web: { port: 8080, ready_seconds: 3, settle_seconds: 4 } });
      f.write();
      const silent = f.run("start");
      assert(!silent.ok, silent.output);
      assertEquals(f.result(), { error_code: "instance.t3_unready", wake: "started", marker_found: true });
      assert(!sleeping.present());

      sleeping = asleep(f, { web: [200], t3: true });
      const woken = f.run("start");
      assert(woken.ok, woken.output);
      assert(!sleeping.present());
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a wake's start of a Project without a web unit removes the marker without a web wait",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      f.container.units = [];
      (f.container as Record<string, unknown>).web_config = null;
      f.write();
      const sleeping = asleep(f, {});
      Object.assign(f.intent, { marker: sleeping.marker, web: { port: 8080, ready_seconds: 3, settle_seconds: 4 } });
      f.write();
      const woken = f.run("start");
      assert(woken.ok, woken.output);
      assert(!sleeping.present());
      assertEquals(sleeping.webChecks(), 0);
    } finally {
      f.cleanup();
    }
  },
});

/** The wake of each state a sleeping container can be in; the cgroup files live in the fixture. */
function wakeFrom(f: ReturnType<typeof fixture>, status: "Frozen" | "Stopped" | "Running", options: { marker?: boolean; web?: number[]; silent?: boolean } = {}) {
  const sleeping = asleep(f, { web: options.web ?? [200] });
  if (options.marker === false) Deno.removeSync(sleeping.marker);
  const state = f.state();
  state.containers["instance-7"].status = status;
  if (options.silent) state.containers["instance-7"].silent_until_boot = true;
  Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(state));
  const cgroup = `${f.folder}/cgroup/lxc.payload.orbit-instances_instance-7`;
  Deno.mkdirSync(cgroup, { recursive: true });
  Deno.writeTextFileSync(`${cgroup}/memory.swap.max`, "max");
  Object.assign(f.intent, { marker: sleeping.marker, web: { port: 8080, ready_seconds: 2, settle_seconds: 1, resume_seconds: 2 } });
  f.write();
  const calls = Deno.readTextFileSync(`${f.folder}/calls`).split("\n").length;
  const run = f.run("start", {}, ["-e", `cgroup_root=${f.folder}/cgroup`]);
  const families = Deno.readTextFileSync(`${f.folder}/calls`).split("\n").slice(calls - 1).filter(Boolean)
    .map((line) => (JSON.parse(line) as string[]).filter((arg, i, all) => arg !== "--project" && all[i - 1] !== "--project")[0]);
  return { run, sleeping, families, swap: Deno.readTextFileSync(`${cgroup}/memory.swap.max`), status: f.state().containers["instance-7"].status as string };
}

Deno.test({
  name: "a wake resumes a Frozen container without a boot, closes swap again and removes the marker; its result names the path and seconds",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      const woken = wakeFrom(f, "Frozen");
      assert(woken.run.ok, woken.run.output);
      assertEquals(woken.status, "Running");
      assertEquals(woken.swap, "0");
      assert(!woken.sleeping.present());
      assert(woken.families.includes("resume"));
      assert(!woken.families.includes("start") && !woken.families.includes("stop"), woken.families.join(","));
      // A running container converges nothing: no egress read, php link or ini push, no autostart key.
      assert(!woken.families.includes("file") && !woken.families.includes("config"), woken.families.join(","));
      const result = f.result();
      assertEquals([result.wake, result.marker_found], ["resumed", true]);
      assert(typeof result.resume_seconds === "number" && typeof result.web_probe_seconds === "number", JSON.stringify(result));
      // After the marker went, one exec checks the library cache that a converge could not refresh while the container was frozen.
      assert(Deno.readTextFileSync(`${f.folder}/calls`).includes("ldconfig -p"), "the library check ran");
      assert(Deno.readTextFileSync(`${f.folder}/calls`).includes("update-ca-certificates"), "the CA bundle check ran");
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a resumed container whose web unit stays silent is stopped and booted, then woken as a stopped one",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      const woken = wakeFrom(f, "Frozen", { silent: true });
      assert(woken.run.ok, woken.run.output);
      assertEquals(woken.families.filter((family) => ["resume", "stop", "start"].includes(family)), ["resume", "stop", "start"]);
      assertEquals(woken.status, "Running");
      assert(!woken.sleeping.present());
      assertEquals(f.result().wake, "restarted_after_resume");
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a wake boots a Stopped container behind its marker, verifies a Running one without a start, and reports a Frozen one without a marker",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      const stopped = wakeFrom(f, "Stopped");
      assert(stopped.run.ok, stopped.run.output);
      assert(stopped.families.includes("start") && !stopped.families.includes("resume"));
      assert(!stopped.sleeping.present());
      assertEquals(f.result().wake, "started");

      // After a host reboot the container runs while its marker stays: the web unit answers, the marker goes, nothing starts.
      const running = wakeFrom(f, "Running");
      assert(running.run.ok, running.run.output);
      assert(!running.families.some((family) => ["start", "stop", "resume", "config", "file"].includes(family)), running.families.join(","));
      assert(!running.sleeping.present());
      assertEquals([f.result().wake, f.result().marker_found], ["already_running", true]);

      // Running and no marker: nothing to do.
      const awake = wakeFrom(f, "Running", { marker: false });
      assert(awake.run.ok, awake.run.output);
      assertEquals([f.result().wake, f.result().marker_found], ["already_running", false]);

      // A Frozen container without its marker is a defect: the wake resumes it and its result says the marker was missing.
      const defect = wakeFrom(f, "Frozen", { marker: false });
      assert(defect.run.ok, defect.run.output);
      assertEquals(defect.status, "Running");
      assertEquals([f.result().wake, f.result().marker_found], ["resumed", false]);
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a deployment's start resumes a Frozen container and then converges as usual",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      asleep(f, { web: [200] });
      const state = f.state();
      state.containers["instance-7"].status = "Frozen";
      Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(state));
      Deno.mkdirSync(`${f.folder}/cgroup/lxc.payload.orbit-instances_instance-7`, { recursive: true });
      const result = f.run("start", {}, ["-e", `cgroup_root=${f.folder}/cgroup`]);
      assert(result.ok, result.output);
      assertEquals(f.state().containers["instance-7"].status, "Running");
      assertEquals(f.result().wake, undefined);
      assert(/Configure the container's PHP settings|Read the container's PHP settings/.test(result.output));
      assert(!/Read the container's PHP settings\]\s*\n\s*skipping/.test(result.output), result.output);
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
          t3: { title: "Tool", port: 3800, listen: "10.44.0.7", device: "t3", unit: "t3.service", internal_port: 3773, ready_seconds: 1, t3: "/t3",
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
      assert(calls.includes(`enable --now --no-block -- orbit-frankenphp.service ${own.name}`), calls.join("\n"));
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
          t3: { title: "Tool", port: 3800, listen: "10.44.0.7", device: "t3", unit: "t3.service", internal_port: 3773, ready_seconds: 1, t3: "/t3",
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

Deno.test({
  name: "container PHP reads the shared 1G memory limit and PHP Data Bridge, and start repairs them without rerun changes",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    const rootfs = `${f.folder}/containers/instance-7`;
    const settings = `${rootfs}/etc/orbit/php/conf.d/99-orbit-runtime.ini`;
    try {
      Deno.writeTextFileSync(`${f.folder}/empty.ini`, "");
      const created = f.run("container");
      assert(created.ok, created.output);
      const bridge = "/opt/orbit-runtime/current/php-data-bridge/bridge/prepend.php";
      const setting = (name: string) => {
        const result = new Deno.Command("php", {
          args: ["-r", `echo ini_get("${name}");`], clearEnv: true,
          env: { PATH: Deno.env.get("PATH")!, PHPRC: `${f.folder}/empty.ini`, PHP_INI_SCAN_DIR: `${rootfs}/etc/orbit/php/conf.d` },
        }).outputSync();
        assert(result.success, new TextDecoder().decode(result.stderr));
        return new TextDecoder().decode(result.stdout);
      };
      assertEquals(setting("memory_limit"), "1G");
      assertEquals(setting("auto_prepend_file"), bridge);
      assertEquals(f.state().files["/etc/orbit/php/conf.d/99-orbit-runtime.ini"], { uid: "0", gid: "0", mode: "0644" });

      // A running unit read the old settings at its start, so a repair restarts the running Orbit units; a rerun does not.
      const tryRestarts = () =>
        (f.state().systemctl ?? []).filter((call: string[]) => call[0] === "try-restart").map((call: string[]) => call.join(" "));
      const before = tryRestarts().length;
      Deno.writeTextFileSync(settings, "memory_limit = 128M\n");
      const repaired = f.run("start");
      assert(repaired.ok, repaired.output);
      assertEquals(setting("memory_limit"), "1G");
      assertEquals(setting("auto_prepend_file"), bridge);
      assertEquals(tryRestarts().slice(before), ["try-restart --no-block orbit-*.service"]);
      const again = f.run("start");
      assert(again.ok, again.output);
      assertEquals(again.changed, 0, again.output);
      assertEquals(tryRestarts().length, before + 1);
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "container writes the access plan's private names into /etc/hosts and its composed proxy environment, keeps other lines, and removes the names when the plan has none",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    const hosts = `${f.folder}/containers/instance-7/etc/hosts`;
    const block = "# BEGIN Orbit private names\n10.44.0.3 reverb.orbit\n# END Orbit private names\n";
    try {
      // A normal Instance: the plan has no names, and the step writes nothing, also where the image has a hosts file.
      const plain = f.run("container");
      assert(plain.ok, plain.output);
      assert(!/Write the changed hosts file\]\s*\n\s*changed/.test(plain.output), plain.output);
      Deno.writeTextFileSync(hosts, "127.0.0.1 localhost\n");
      const again = f.run("start");
      assert(again.ok, again.output);
      assertEquals(Deno.readTextFileSync(hosts), "127.0.0.1 localhost\n");
      assertEquals(again.changed, 0, again.output);
      // Without a composed value other than the base, nothing writes the container's proxy bypass.
      assertEquals(f.state().proxyWrites ?? 0, 0);

      // An Instance with a private websocket app: its name at the plan's address, after the image's own lines.
      const proxy = { NO_PROXY: "localhost,127.0.0.1,reverb.orbit", no_proxy: "localhost,127.0.0.1,reverb.orbit" };
      Object.assign(f.container, { private_names: [{ name: "reverb.orbit", host: "10.44.0.3", port: 8443 }], proxy_environment: proxy });
      f.write();
      const named = f.run("start");
      assert(named.ok, named.output);
      assertEquals(Deno.readTextFileSync(hosts), "127.0.0.1 localhost\n" + block);
      assertEquals(f.state().files["/etc/hosts"], { uid: "0", gid: "0", mode: "0644" });
      const rerun = f.run("start");
      assertEquals(rerun.changed, 0, rerun.output);
      // The one writer got the composed value once; the rerun found it in place.
      assertEquals(f.state().containers["instance-7"].proxy, proxy);
      assertEquals(f.state().proxyWrites, 2);

      // The app is removed: the next start takes the block away and keeps every other line.
      Deno.writeTextFileSync(hosts, "127.0.0.1 localhost\n" + block + "10.1.1.1 operator\n");
      delete (f.container as Record<string, unknown>).private_names;
      delete (f.container as Record<string, unknown>).proxy_environment;
      f.write();
      const removed = f.run("start");
      assert(removed.ok, removed.output);
      assertEquals(Deno.readTextFileSync(hosts), "127.0.0.1 localhost\n10.1.1.1 operator\n");
    } finally {
      f.cleanup();
    }
  },
});

for (const damage of ["missing ACL", "permissive default", "bridge union ACL"]) {
  Deno.test({
    name: `start refuses a NIC with ${damage} before starting the container`,
    ignore: !hasAnsible,
    fn() {
      const f = fixture();
      try {
        const made = f.run("container");
        assert(made.ok, made.output);
        assert(f.run("stop").ok);
        const state = f.state();
        const key = damage === "permissive default" ? "security.acls.default.egress.action" : "security.acls";
        const value = damage === "missing ACL" ? "" : damage === "bridge union ACL" ? "orbitinst0-egress,instance-7" : "allow";
        state.containers["instance-7"].devices = state.containers["instance-7"].devices
          .filter((device: string) => !device.startsWith(`eth0,${key}=`));
        state.containers["instance-7"].devices.push(`eth0,${key}=${value}`);
        Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(state));
        const result = f.run("start");
        assert(!result.ok, result.output);
        assertEquals(f.result(), { error_code: "instance.container_network_unsafe" }, result.output);
        assertEquals(f.state().containers["instance-7"].status, "Stopped");
      } finally { f.cleanup(); }
    },
  });
}

Deno.test({
  name: "start refuses a NIC without the address filters with its own code, and starts it once the device set adds them",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      assert(f.run("container").ok);
      assert(f.run("stop").ok);
      // Both keys missing, then only one: each refuses before the start, with the filters' own code.
      for (const missing of [["ipv4", "mac"], ["mac"]]) {
        const state = f.state();
        state.containers["instance-7"].devices = state.containers["instance-7"].devices
          .filter((device: string) => !missing.some((key) => device.startsWith(`eth0,security.${key}_filtering=`)));
        Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(state));
        const refused = f.run("start");
        assert(!refused.ok, refused.output);
        assertEquals(f.result(), { error_code: "instance.nic_filters_missing" }, refused.output);
        assertEquals(f.state().containers["instance-7"].status, "Stopped");
      }
      // The by-hand repair: one device set adds the keys, and the next start passes.
      const set = new Deno.Command(`${f.folder}/bin/incus`, {
        args: ["--project", "orbit-instances", "config", "device", "set", "instance-7", "eth0", "security.ipv4_filtering=true",
          "security.mac_filtering=true"],
        env: { HOST_FIXTURE: f.folder },
      }).outputSync();
      assert(set.success, new TextDecoder().decode(set.stderr));
      assertEquals(f.state().containers["instance-7"].status, "Stopped");
      const filtered = f.run("start");
      assert(filtered.ok, filtered.output);
      assertEquals(f.state().containers["instance-7"].status, "Running");
    } finally { f.cleanup(); }
  },
});

Deno.test({
  name: "start refuses an unguarded extra bridge NIC beside guarded eth0",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      assert(f.run("container").ok);
      const state = f.state();
      state.containers["instance-7"].status = "Stopped";
      state.containers["instance-7"].devices.push("eth1,type=nic", "eth1,network=orbitinst0");
      Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(state));
      const result = f.run("start");
      assert(!result.ok, result.output);
      assertEquals(f.result(), {error_code: "instance.container_network_unsafe"}, result.output);
      assertEquals(f.state().containers["instance-7"].status, "Stopped");
      // Even a guarded extra NIC is unexpected for an Instance and must refuse.
      const guarded = f.state();
      guarded.containers["instance-7"].devices.push("eth1,security.acls=instance-7", "eth1,security.acls.default.egress.action=reject");
      Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(guarded));
      assert(!f.run("start").ok);
      assertEquals(f.state().containers["instance-7"].status, "Stopped");
    } finally { f.cleanup(); }
  },
});

Deno.test({
  name: "first attach adds an exact bridge endpoint, retries ETag conflicts and repeats without changes",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      f.container.bridge_endpoints = [{host: "10.44.0.7", port: 3306, docker: false}];
      f.write();
      const state = f.state();
      state.etagConflicts = 1;
      const concurrent = {action: "allow", source: "10.253.0.0/24", destination: "10.44.0.3/32", protocol: "tcp", destination_port: "5434", state: "enabled"};
      state.concurrentEndpoint = concurrent;
      Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(state));
      let result = f.run("container");
      assert(result.ok, result.output);
      const bridge = f.state()["network-acls"].find((acl: {name: string}) => acl.name === "orbitinst0-egress");
      assertEquals(bridge.egress, [concurrent, {action: "allow", source: "10.253.0.0/24", destination: "10.44.0.7/32", protocol: "tcp", destination_port: "3306", state: "enabled", description: "orbit:endpoint:1800000000000000"}]);
      result = f.run("container");
      assert(result.ok, result.output);
      assertEquals(result.changed, 0, result.output);
      const calls = Deno.readTextFileSync(`${f.folder}/calls`).trim().split("\n").map((line) => JSON.parse(line) as string[]);
      assertEquals(calls.filter((call) => call[0] === "curl" && call.includes("PUT")).length, 2);
    } finally { f.cleanup(); }
  },
});

Deno.test({
  name: "a copied seed with an unguarded extra local NIC refuses before the clone starts",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      for (const phase of ["container", "workspace", "processes", "seed"]) {
        const prepared = f.run(phase);
        assert(prepared.ok, prepared.output);
      }
      const state = f.state();
      const source = state.containers["instance-7"];
      source.devices.push("eth1,type=nic", "eth1,network=orbitinst0");
      Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(state));
      Object.assign(f.container, {instance_id: 8, name: "instance-8", address: "10.253.0.3", acl: "instance-8",
        clone: {source: "instance-7", snapshot: source.snapshots[0], t3: {device: "t3", listen: "10.44.0.7", port: 3800, internal_port: 3773}}});
      f.write();
      const result = f.run("clone");
      assert(!result.ok, result.output);
      assertEquals(f.result(), {error_code: "instance.container_network_unsafe"}, result.output);
      assertEquals(f.state().containers["instance-8"].status, "Stopped");
      assert(f.state().containers["instance-8"].devices.includes("eth1,network=orbitinst0"));
    } finally {f.cleanup();}
  },
});

Deno.test({
  name: "a queued container Process start is nonblocking and every intended running unit has a bounded readiness read",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      f.container.processes = [worker, paused];
      f.write();
      for (const phase of ["container", "workspace"]) assert(f.run(phase).ok);
      const done = f.run("processes", { ORBIT_PROCESS_ENVIRONMENTS: environments });
      assert(done.ok, done.output);
      const calls: string[][] = f.state().systemctl;
      for (const call of calls.filter((call) => call[0] === "restart" || (call[0] === "enable" && call.includes("--now")))) {
        assert(call.includes("--no-block"), JSON.stringify(call));
      }
      assertEquals(calls.filter((call) => call[0] === "show").map((call) => call.at(-1)), ["orbit-frankenphp.service", worker.name]);
      // A unit whose job never settles must fail this phase, even though it remains active.
      const state = f.state();
      state.systemctl = [];
      state.queued = { [worker.name]: 100 };
      Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(state));
      const blocked = f.run("processes", { ORBIT_PROCESS_ENVIRONMENTS: environments });
      assert(!blocked.ok, blocked.output);
      const reads = f.state().systemctl.filter((call: string[]) => call[0] === "show" && call.at(-1) === worker.name);
      assert(reads.length > 1 && reads.length <= 11, JSON.stringify(reads));
      assert(f.state().queued[worker.name] > 0, "Readiness must stop waiting before the queued job finishes.");
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "PHP ini write and try-restart precede nonblocking Process start, and readiness waits for an active queued job to finish",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      f.container.processes = [worker, paused];
      f.write();
      for (const phase of ["container", "workspace", "processes"]) {
        const done = f.run(phase, { ORBIT_PROCESS_ENVIRONMENTS: environments });
        assert(done.ok, done.output);
      }
      const settings = `${f.folder}/containers/instance-7/etc/orbit/php/conf.d/99-orbit-runtime.ini`;
      Deno.writeTextFileSync(settings, "memory_limit = 128M\n");
      const state = f.state();
      state.systemctl = [];
      // A unit remains active while its restart job is queued. Checking is-active alone would finish too soon.
      state.queued = { [worker.name]: 2 };
      Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(state));
      const start = f.run("start");
      assert(start.ok, start.output);
      const writes = start.output.indexOf("Write the changed container PHP settings");
      const restart = start.output.indexOf("Restart the running Orbit units after changed PHP settings");
      const ready = start.output.indexOf("Wait for each running unit");
      assert(writes >= 0 && writes < restart && restart < ready, start.output);
      assert(Deno.readTextFileSync(settings).includes("auto_prepend_file = /opt/orbit-runtime/current/php-data-bridge/bridge/prepend.php"));
      assertEquals(f.state().queued[worker.name], 0);
      assertEquals(f.state().systemctl.filter((call: string[]) => call[0] === "show" && call.at(-1) === worker.name).length, 3);
      const processes = f.run("processes", { ORBIT_PROCESS_ENVIRONMENTS: environments });
      assert(processes.ok, processes.output);
      const calls: string[][] = f.state().systemctl;
      const tryRestart = calls.findIndex((call) => call[0] === "try-restart");
      const enable = calls.findIndex((call) => call[0] === "enable" && call.includes("--now"));
      assert(tryRestart >= 0 && tryRestart < enable, JSON.stringify(calls));
      assert(calls[tryRestart].includes("--no-block"));
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a thirty-second Process stop succeeds",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      f.container.processes = [worker];
      f.write();
      for (const phase of ["container", "workspace", "processes"]) {
        const done = f.run(phase, { ORBIT_PROCESS_ENVIRONMENTS: environments });
        assert(done.ok, done.output);
      }
      const state = f.state();
      state.slowStopSeconds = 30;
      Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(state));
      const stopped = f.run("process", { ORBIT_PROCESS_ACTION: "stop" });
      assert(stopped.ok, stopped.output);
      assertEquals(f.result(), { state: "inactive", output: "" });
      assertEquals(f.state().slowStopCompleted, true);
    } finally {
      f.cleanup();
    }
  },
});

/** InstancePlacement::config of the nested project over the common limits: what the Gateway writes and the guard admits. */
const NESTED_CONFIG: Record<string, string> = {
  "limits.cpu": "4", "limits.memory": "12GiB", "limits.processes": "4096", "security.nesting": "true",
  "security.syscalls.intercept.mknod": "true", "security.syscalls.intercept.setxattr": "true", "security.idmap.isolated": "true",
  "security.idmap.size": "101000000", "boot.autostart": "false",
};
const nestedDamage: Record<string, Record<string, string>> = {
  "raw.lxc": {"raw.lxc": "lxc.apparmor.profile=unconfined"},
  "privileged": {"security.privileged": "true"},
  "kernel-modules": {"linux.kernel_modules": "overlay"},
  "mount-intercept": {"security.syscalls.intercept.mount": "true"},
  "bpf-intercept": {"security.syscalls.intercept.bpf": "true"},
  "shared-idmap": {"security.idmap.isolated": "false"},
  "idmap-size": {"security.idmap.size": "65536"},
  "changed-limit": {"limits.cpu": "64"},
  "user-key": {"user.note": "x"},
};
for (const damage of [...Object.keys(nestedDamage), "extra-device", "changed-host-path", "writable-share", "clean", "made-before-autostart"]) {
  Deno.test({
    name: `nested fleet start gate: ${damage}`,
    ignore: !hasAnsible,
    fn() {
      const f = fixture();
      try {
        assert(f.run("container").ok);
        const state = f.state();
        const row = state.containers["instance-7"];
        row.status = "Stopped";
        row.project = "orbit-instances-nested";
        const disks = {
          "orbit-runtime": {type: "disk", source: "/opt/orbit-runtime", path: "/opt/orbit-runtime", readonly: "true", shift: "true"},
          "orbit-agent": {type: "disk", source: "/opt/orbit-agent", path: "/opt/orbit-agent", readonly: "true", shift: "true"},
        };
        Object.assign(f.container, {project: "orbit-instances-nested", config: NESTED_CONFIG, allowed_devices: {
          root: {type: "disk", path: "/", pool: "orbit-instances", size: "40GiB"},
          eth0: {type: "nic", network: "orbitinst0", "security.port_isolation": "true", "ipv4.address": "10.253.0.2", "security.acls": "orbitinst0-proxy,instance-7", "security.acls.default.egress.action": "reject", "security.acls.default.ingress.action": "reject", "security.ipv4_filtering": "true", "security.mac_filtering": "true"},
          ...structuredClone(disks),
        }});
        // The config create wrote; Incus's own keys and the profile's environment come on top.
        row.config = Object.entries(NESTED_CONFIG).filter(([key]) => damage !== "made-before-autostart" || key !== "boot.autostart")
          .map(([key, value]) => `${key}=${value}`);
        row.inherited_config = {"volatile.uuid": "x", "image.os": "ubuntu", "environment.HTTP_PROXY": "http://10.253.0.1:3128", ...(nestedDamage[damage] ?? {})};
        row.added = disks;
        if (damage === "extra-device") row.added.socket = {type: "disk", source: "/var/lib/incus/unix.socket", path: "/run/host-incus"};
        if (damage === "writable-share") delete row.added["orbit-runtime"].readonly;
        if (damage === "changed-host-path") row.added["orbit-runtime"].source = "/fast";
        Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(state));
        f.write();
        const result = f.run("start");
        const autostart = ["--project", "orbit-instances-nested", "config", "set", "instance-7", "boot.autostart=false"];
        const wrote = incusCalls(f).filter((args) => args.join(" ") === autostart.join(" ")).length;
        if (damage === "clean" || damage === "made-before-autostart") {
          assert(result.ok, result.output);
          assertEquals(f.state().containers["instance-7"].status, "Running");
          // Only a container without the key gets it, before the guard; a rerun writes nothing.
          assertEquals(wrote, damage === "clean" ? 0 : 1);
          assert(f.run("start").ok);
          assertEquals(incusCalls(f).filter((args) => args.join(" ") === autostart.join(" ")).length, wrote);
        } else {
          assert(!result.ok, result.output);
          assertEquals(f.result(), {error_code: "instance.container_network_unsafe"});
          assertEquals(f.state().containers["instance-7"].status, "Stopped");
          if (damage in nestedDamage) assert(result.output.includes(`config ${Object.keys(nestedDamage[damage])[0]}`), result.output);
          assertEquals(wrote, 0);
        }
      } finally { f.cleanup(); }
    },
  });
}

Deno.test({
  name: "a normal container start never writes boot.autostart",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      assert(f.run("container").ok);
      const state = f.state();
      state.containers["instance-7"].status = "Stopped";
      Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(state));
      assert(f.run("start").ok);
      assert(!incusCalls(f).some((args) => args.includes("config") && args.includes("set")), JSON.stringify(incusCalls(f)));
      assert(!f.state().containers["instance-7"].config.some((value: string) => value.startsWith("boot.")));
    } finally { f.cleanup(); }
  },
});

/** The intent of a nested-fleet default: only it has `allowed_devices`, and it starts from the nested-fleet image. */
function nestedCreate(f: ReturnType<typeof fixture>) {
  Object.assign(f.container, {project: "orbit-instances-nested", image: "orbit-instance-nested", config: NESTED_CONFIG, allowed_devices: {
    root: {type: "disk", path: "/", pool: "orbit-instances", size: "40GiB"},
    eth0: {type: "nic", network: "orbitinst0", "security.port_isolation": "true", "ipv4.address": "10.253.0.2", "security.acls": "orbitinst0-proxy,instance-7", "security.acls.default.egress.action": "reject", "security.acls.default.ingress.action": "reject", "security.ipv4_filtering": "true", "security.mac_filtering": "true"},
  }});
  f.write();
}

const incusCalls = (f: ReturnType<typeof fixture>) => {
  try {
    return Deno.readTextFileSync(`${f.folder}/calls`).trim().split("\n").filter(Boolean).map((line) => JSON.parse(line) as string[]);
  } catch (error) {
    if (error instanceof Deno.errors.NotFound) return [];
    throw error;
  }
};

Deno.test({
  name: "a nested-fleet create refuses by name, before any Incus change, while the host converge records a failed nested-fleet image build",
  ignore: !hasAnsible,
  async fn() {
    // The record comes from the shipped host tasks: a switched-on Node whose nested build fails its sudo audit.
    const host = await hostFixture({pool: "dir", nested: true});
    let record: string;
    try {
      const state = host.state();
      state.sudo_paths_by_project = {"orbit-instances-nested": "agent:syntax"};
      Deno.writeTextFileSync(`${host.folder}/state.json`, JSON.stringify(state));
      const converged = await host.run();
      assert(converged.ok, converged.output.slice(-4000));
      record = Deno.readTextFileSync(`${host.folder}/nested-image.json`);
    } finally { host.cleanup(); }
    const f = fixture();
    try {
      nestedCreate(f);
      Deno.writeTextFileSync(`${f.folder}/nested-image.json`, record);
      const result = f.run("container");
      assert(!result.ok, result.output);
      assertEquals(f.result(), {error_code: "instance.nested_fleet_image_unavailable"});
      // Only the read of the container ran: no ACL, no container, no init.
      assertEquals(incusCalls(f).map((call) => call.slice(0, 2)), [["query", "--"]]);
      assertEquals(f.state().containers?.["instance-7"], undefined);
      assertEquals(f.state()["network-acls"].map((acl: {name: string}) => acl.name), ["orbitinst0-egress"]);
    } finally { f.cleanup(); }
  },
});

Deno.test({
  name: "without a recorded failed build a nested-fleet create makes its container from the nested-fleet image, and a normal create never reads the record",
  ignore: !hasAnsible,
  fn() {
    const nested = fixture();
    try {
      nestedCreate(nested);
      const result = nested.run("container");
      assert(result.ok, result.output);
      assert(incusCalls(nested).some((call) => call.includes("init") && call.includes("orbit-instance-nested")), JSON.stringify(incusCalls(nested)));
    } finally { nested.cleanup(); }
    const normal = fixture();
    try {
      // A record left on the Node never touches a normal create: its refusal task is skipped.
      Deno.writeTextFileSync(`${normal.folder}/nested-image.json`, JSON.stringify({error: "nested image build failed"}));
      const result = normal.run("container");
      assert(result.ok, result.output);
      assert(/skipping: \[node\]/.test(result.output.split("TASK [Refuse a nested-fleet create while its image build has failed]")[1] ?? ""), result.output);
      assertEquals(normal.state().containers["instance-7"].status, "Running");
    } finally { normal.cleanup(); }
  },
});

/** NestedFleetInnerVolume::intent of a new nested-fleet default, and the disk the start guard admits for it. */
const INNER_CONFIG: Record<string, string> = {
  "zfs.block_mode": "true", "block.filesystem": "btrfs", "block.mount_options": "user_subvol_rm_allowed,discard", "security.shifted": "true",
};
function innerVolumeCreate(f: ReturnType<typeof fixture>) {
  nestedCreate(f);
  const container = f.container as unknown as Record<string, unknown> & {allowed_devices: Record<string, Record<string, string>>};
  container.inner_volume = {name: "inner-7", pool: "orbit-instances", size: "30GiB", config: INNER_CONFIG, device: "inner-pool",
    path: "/srv/inner-pool", inner_pool: {name: "inner", source: "/srv/inner-pool/pool"}};
  container.allowed_devices["inner-pool"] = {type: "disk", pool: "orbit-instances", source: "inner-7", path: "/srv/inner-pool"};
  f.write();
}
const volumeCalls = (f: ReturnType<typeof fixture>) => incusCalls(f).filter((call) => call[0] === "storage" || call.includes("inner-pool"));

Deno.test({
  name: "a new nested-fleet default gets its inner volume before the container, its disk before the first start and the inner pool after it",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      innerVolumeCreate(f);
      const result = f.run("container");
      assert(result.ok, result.output);
      const state = f.state();
      assertEquals(state.volumes["inner-7"].config, {"volatile.uuid": state.volumes["inner-7"].config["volatile.uuid"], ...INNER_CONFIG, size: "30GiB"});
      assertEquals(state.volumes["inner-7"].project, "orbit-instances-nested");
      const calls = incusCalls(f).map((call) => call.join(" "));
      const create = calls.findIndex((call) => call.startsWith("storage volume create orbit-instances inner-7"));
      const init = calls.findIndex((call) => call.includes(" init "));
      const attach = calls.findIndex((call) => call.includes("config device add instance-7 inner-pool disk"));
      const start = calls.findIndex((call) => call.endsWith("start instance-7"));
      assert(create >= 0 && create < init && init < attach && attach < start, JSON.stringify(calls));
      assertEquals(state.containers["instance-7"].added["inner-pool"],
        {type: "disk", settings: ["pool=orbit-instances", "source=inner-7", "path=/srv/inner-pool"]});
      assertEquals(state.inner_pools["instance-7"], {inner: ["btrfs", "source=/srv/inner-pool/pool"]});
      // A retry reuses the volume, the disk and the pool: it creates none of them again.
      const before = volumeCalls(f).filter((call) => !call.includes("query")).length;
      const stopped = f.state();
      stopped.containers["instance-7"].status = "Stopped";
      Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(stopped));
      assert(f.run("container").ok);
      assertEquals(volumeCalls(f).filter((call) => !call.includes("query")).length, before);
      assertEquals(Object.keys(f.state().inner_pools["instance-7"]), ["inner"]);
    } finally { f.cleanup(); }
  },
});

Deno.test({
  name: "a nested-fleet default without an inner volume (Instance 2454's shape) and a normal default make no volume, disk or inner pool",
  ignore: !hasAnsible,
  fn() {
    for (const shape of ["nested", "normal"]) {
      const f = fixture();
      try {
        if (shape === "nested") nestedCreate(f);
        const result = f.run("container");
        assert(result.ok, result.output);
        assertEquals(volumeCalls(f), [], shape);
        assertEquals(f.state().volumes, undefined, shape);
        assertEquals(f.state().inner_pools, undefined, shape);
        for (const task of ["Create the inner volume", "Add the inner volume's disk", "Create the inner pool on the inner volume"]) {
          assert(!result.output.includes(`TASK [${task}]`) || /skipping: \[node\]/.test(result.output.split(`TASK [${task}]`)[1]), `${shape}: ${task}`);
        }
      } finally { f.cleanup(); }
    }
  },
});

// One key of the inner volume changed after the create: the deploy's start refuses it before the start and names the key.
const volumeDamage: Record<string, (config: Record<string, string>) => void> = {
  "changed-mount-options": (config) => { config["block.mount_options"] = "discard"; },
  "missing-shifted": (config) => { delete config["security.shifted"]; },
  "extra-key": (config) => { config["block.create_options"] = "-O no-holes"; },
  "delegated": (config) => { config["zfs.delegate"] = "true"; },
  "changed-size": (config) => { config.size = "300GiB"; },
};
Deno.test({
  name: "the start gate admits the inner volume only with its exact keys, never a delegated volume, and never its disk without the intent",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      innerVolumeCreate(f);
      assert(f.run("container").ok);
      const created = f.state();
      created.containers["instance-7"].status = "Stopped";
      const intent = structuredClone(f.container);
      const named: Record<string, string> = {"changed-mount-options": "volume inner-pool block.mount_options", "missing-shifted": "volume inner-pool security.shifted",
        "extra-key": "volume inner-pool block.create_options", "delegated": "volume inner-pool zfs.delegate", "changed-size": "volume inner-pool size",
        "delegated-root": "volume root zfs.delegate", "disk-without-volume-intent": "instance.container_network_unsafe: inner-pool"};
      for (const damage of [...Object.keys(named), "clean"]) {
        const state = structuredClone(created);
        volumeDamage[damage]?.(state.volumes["inner-7"].config);
        if (damage === "delegated-root") state.containers["instance-7"].volume_config = {"zfs.delegate": "true"};
        Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(state));
        Object.assign(f.container, structuredClone(intent));
        if (damage === "disk-without-volume-intent") {
          // An Instance whose runtime records no inner volume never starts with such a disk.
          const container = f.container as unknown as Record<string, unknown> & {allowed_devices: Record<string, unknown>};
          delete container.inner_volume;
          delete container.allowed_devices["inner-pool"];
        }
        f.write();
        const result = f.run("start");
        if (damage === "clean") {
          assert(result.ok, result.output);
          assertEquals(f.state().containers["instance-7"].status, "Running");
          continue;
        }
        assert(!result.ok, `${damage}: ${result.output}`);
        assertEquals(f.result(), {error_code: "instance.container_network_unsafe"}, damage);
        assertEquals(f.state().containers["instance-7"].status, "Stopped", damage);
        assert(result.output.includes(named[damage]), `${damage}: ${result.output}`);
      }
    } finally { f.cleanup(); }
  },
});

/**
 * What a host converge did: every Incus command line, every task that ran (ok or changed, never a skipped one or an include line)
 * and every host file it wrote in place of a kernel module, sysctl or firewall rule. Fixture paths read FIXTURE.
 */
async function hostTranscript(f: Awaited<ReturnType<typeof hostFixture>>, output: string) {
  const tasks: string[] = [];
  let task = "";
  for (const line of output.split("\n")) {
    const named = line.match(/^TASK \[(.*)\] \*/);
    if (named) task = named[1];
    else if (/^(ok|changed): \[/.test(line) && task !== "" && tasks.at(-1) !== task) tasks.push(task);
  }
  const files: Record<string, string> = {};
  for (const dir of ["modules-load", "sysctl"]) {
    for (const entry of Deno.readDirSync(`${f.folder}/${dir}`)) files[`${dir}/${entry.name}`] = Deno.readTextFileSync(`${f.folder}/${dir}/${entry.name}`);
  }
  for (const entry of Deno.readDirSync(f.folder)) {
    if (entry.name.startsWith("firewall-")) files[entry.name] = Deno.readTextFileSync(`${f.folder}/${entry.name}`);
  }
  const transcript = JSON.stringify({calls: f.calls(), tasks, files: Object.fromEntries(Object.entries(files).sort())}).replaceAll(f.folder, "FIXTURE");
  const digest = Array.from(new Uint8Array(await crypto.subtle.digest("SHA-256", new TextEncoder().encode(transcript))))
    .map((x) => x.toString(16).padStart(2, "0")).join("");
  return {calls: f.calls().length, tasks: tasks.length, digest, transcript};
}

Deno.test({
  name: "a normal host intent runs the exact tasks, Incus command lines and host files of main",
  ignore: !hasAnsible,
  async fn() {
    // A real Node's shape: a ZFS pool, so the clone probe runs too.
    const f = await hostFixture({pool: "zfs"});
    try {
      const result = await f.run();
      assert(result.ok, result.output);
      const seen = await hostTranscript(f, result.output);
      if (Deno.env.get("ORBIT_PRINT_HOST_TRANSCRIPT")) console.log(JSON.stringify(seen));
      // Captured by this same case from the tasks of origin/main 77f6bf5c22 (2a-local 7b9e25ba02 changes no task), plus the five tasks
      // that install the fast wake of a frozen container (#3639); its Incus calls and host files are unchanged. Plus the one task that
      // admits the containers to their own sites, with its two UFW rule files (443 and the development listener); no Incus call changes.
      assertEquals({calls: seen.calls, tasks: seen.tasks, digest: seen.digest}, MAIN_NORMAL_HOST);
      assertEquals(f.state().projects.map((row: {name: string}) => row.name), ["orbit-instances"]);
      assert(!f.has("modules-load/orbit-instances.conf"));
    } finally { f.cleanup(); }
  },
});

for (const file of ["subuid", "subgid"]) {
  Deno.test({
    name: `a switched-on host with a short ${file} range refuses before any nesting change`,
    ignore: !hasAnsible,
    async fn() {
      const f = await hostFixture({pool: "zfs"});
      try {
        // Room for the normal Instances only: 13,172,736 IDs, short of 13,172,736 + 2 x 101,000,000.
        Deno.writeTextFileSync(`${f.folder}/${file}`, "root:1000000:13172736\n");
        f.write({...f.instances, ids: 201 * 65536 + 2 * 101000000, nested: {project: "orbit-instances-nested"}});
        const result = await f.run();
        assert(!result.ok, result.output);
        assert(result.output.includes(`instance.host_unavailable: root needs 215172736 subordinate IDs in ${f.folder}/${file}, and its largest range has 13172736`), result.output);
        assertEquals([...Deno.readDirSync(`${f.folder}/modules-load`)], []);
        assertEquals([...Deno.readDirSync(`${f.folder}/sysctl`)], []);
        assertEquals(f.state().projects, []);
        // Only the read-only pool query ran.
        assertEquals(f.calls().map((args) => args.slice(0, 2)), [["query", "--"]]);
      } finally { f.cleanup(); }
    },
  });
}

Deno.test({
  name: "only a switched-on host prepares the nested project, its profile and the nesting modules",
  ignore: !hasAnsible,
  async fn() {
    const f = await hostFixture({pool: "zfs"});
    try {
      f.write({...f.instances, ids: 201 * 65536 + 2 * 101000000, nested: {project: "orbit-instances-nested"}});
      const result = await f.run();
      assert(result.ok, result.output);
      const state = f.state();
      assertEquals(state.projects.map((row: {name: string}) => row.name), ["orbit-instances", "orbit-instances-nested"]);
      const config = (name: string) => state.projects.find((row: {name: string}) => row.name === name).config;
      for (const key of ["restricted.containers.nesting", "restricted.containers.interception", "restricted.containers.lowlevel"]) {
        assert(!(key in config("orbit-instances")), key);
        assertEquals(config("orbit-instances-nested")[key], "allow");
      }
      for (const key of ["restricted", "restricted.networks.access", "restricted.containers.privilege", "restricted.devices.disk", "restricted.devices.proxy", "limits.virtual-machines"]) {
        assertEquals(config("orbit-instances-nested")[key], config("orbit-instances")[key], key);
      }
      assertEquals(state.profiles["orbit-instances-nested"].devices, state.profiles["orbit-instances"].devices);
      assertEquals(Object.keys(state.profiles["orbit-instances-nested"].devices).sort(), ["eth0", "orbit-agent", "orbit-runtime", "root"]);
      assertEquals(state.profiles["orbit-instances-nested"].devices["orbit-runtime"].readonly, "true");
      assertEquals(Deno.readTextFileSync(`${f.folder}/modules-load/orbit-instances.conf`), "wireguard\noverlay\n");
      // The modprobe loop got the same two modules: br_netfilter is never loaded on the host.
      assert(result.output.includes("wireguard") && result.output.includes("overlay"), result.output);
      assert(!result.output.includes("br_netfilter"), result.output);
      // The probe ran in both projects and left nothing behind.
      for (const project of ["orbit-instances", "orbit-instances-nested"]) {
        assert(f.calls().some((args) => args.join(" ") === `--project ${project} start orbit-clone-probe-copy`), project);
      }
      assertEquals(state.instances, []);
      const again = await f.run();
      assert(again.ok, again.output);
    } finally { f.cleanup(); }
  },
});

const probeDamage: Record<string, string> = {"raw.lxc": "a raw.lxc key", "privileged": "security.privileged", "host-disk": "a host disk", "extra-share-key": "an extra share key"};
for (const damage of Object.keys(probeDamage)) {
  Deno.test({
    name: `the nested clone probe refuses a profile with ${probeDamage[damage]} before it starts`,
    ignore: !hasAnsible,
    async fn() {
      const f = await hostFixture({pool: "zfs"});
      try {
        f.write({...f.instances, ids: 201 * 65536 + 2 * 101000000, nested: {project: "orbit-instances-nested"}});
        // A nested project left by an earlier converge, whose profile drifted: profile convergence keeps extra keys and devices.
        const state = f.state();
        state.projects.push({name: "orbit-instances-nested", config: {"features.images": "true", "features.profiles": "true", "features.networks": "false"}});
        const runtime = `${f.instances.folders.runtime.path}`;
        state.profiles["orbit-instances-nested"] = {description: "", config: {
          ...(damage === "raw.lxc" ? {"raw.lxc": "lxc.apparmor.profile=unconfined"} : {}),
          ...(damage === "privileged" ? {"security.privileged": "true"} : {}),
        }, devices: {
          ...(damage === "host-disk" ? {socket: {type: "disk", source: "/var/lib/incus/unix.socket", path: "/run/host-incus"}} : {}),
          // Profile convergence rewrites the keys it owns, so a drifted share keeps only keys it does not own.
          ...(damage === "extra-share-key" ? {"orbit-runtime": {type: "disk", source: runtime, path: runtime, propagation: "shared"}} : {}),
        }};
        Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(state));
        const result = await f.run();
        assert(!result.ok, result.output);
        assert(result.output.includes("instance.container_network_unsafe"), result.output);
        assert(f.calls().some((args) => args.join(" ") === "--project orbit-instances start orbit-clone-probe-copy"));
        assert(!f.calls().some((args) => args.join(" ") === "--project orbit-instances-nested start orbit-clone-probe-copy"), result.output);
        assertEquals(f.state().instances, []);
      } finally { f.cleanup(); }
    },
  });
}

for (const scopes of ["recorded nested runtimes", "a normal Node"]) {
Deno.test({
  name: scopes === "a normal Node"
    ? "a changed shared folder on a normal Node runs main's list and restart command lines"
    : "a changed shared folder restarts running normal and nested runtimes before clearing its marker",
  ignore: !hasAnsible,
  async fn() {
    const f = await hostFixture({pool: "dir"});
    try {
      const state = f.state();
      state.instances = [
        {project: "orbit-instances", name: "instance-7", status: "Running"},
        {project: "orbit-instances-nested", name: "instance-8", status: "Running"},
        {project: "orbit-instances-nested", name: "instance-9", status: "Stopped"},
        {project: "unrelated", name: "other", status: "Running"},
      ];
      Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(state));
      // Existing versions let this case exercise only the real refresh and restart path.
      for (const folder of Object.values(f.instances.folders)) {
        Deno.mkdirSync(`${folder.path}/aaaaaaaaaaaa/bin`, {recursive: true});
        // A program the new version lacks: only a version that changes or drops something restarts (instance_folder_same.py).
        Deno.writeTextFileSync(`${folder.path}/aaaaaaaaaaaa/bin/old`, "old");
        Deno.mkdirSync(`${folder.path}/${folder.version}`, {recursive: true});
        Deno.symlinkSync("aaaaaaaaaaaa", `${folder.path}/current`);
      }
      f.write(scopes === "a normal Node" ? f.instances
        : {...f.instances, runtime_projects: ["orbit-instances", "orbit-instances-nested", "not-created-yet"]});
      Deno.writeTextFileSync(`${f.folder}/play.yml`, stringify([{
        hosts: "localhost", gather_facts: false,
        tasks: [{"ansible.builtin.include_tasks": "tasks/instance-folder.yml",
          loop: "{{ instances.folders | dict2items }}", loop_control: {loop_var: "shared"},
          vars: {folder_name: "{{ shared.key }}", folder: "{{ shared.value }}"}}],
      }]));
      const result = await f.run();
      assert(result.ok, result.output);
      if (scopes === "a normal Node") {
        // The exact argv of main's tasks: one project, and each running container restarted in it.
        assertEquals(f.calls().filter((args) => args[0] === "list" || args[0] === "exec"), ["orbit-*.service", "t3.service"].flatMap((unit) => [
          ["list", "--project", "orbit-instances", "--format", "json", "status=running"],
          ["exec", "--project", "orbit-instances", "instance-7", "--", "systemctl", "try-restart", unit],
        ]));
      }
      assertEquals(f.state().folder_restarts, ["orbit-*.service", "t3.service"].flatMap(unit => [
        {project: "orbit-instances", name: "instance-7", unit, marked: true},
        ...(scopes === "a normal Node" ? [] : [{project: "orbit-instances-nested", name: "instance-8", unit, marked: true}]),
      ]));
      for (const folder of Object.values(f.instances.folders)) {
        assert(!f.has(`opt/${folder.path.split("/").at(-1)}/.restart`));
        assertEquals(Deno.readLinkSync(`${folder.path}/current`), folder.version);
      }
      const again = await f.run();
      assert(again.ok, again.output);
      assert(/changed=0/.test(again.output), again.output);
      assertEquals(f.state().folder_restarts.length, scopes === "a normal Node" ? 2 : 4);
    } finally { f.cleanup(); }
  },
});
}

/** A fake Club key: no real key is ever read here. */
const CLUB = "fake-club-key-7f3a9c";

/** A nested-fleet default with the Club key delivery of its intent; `available` says whether the run has the vault copy. */
function nestedClub(f: ReturnType<typeof fixture>, available = true) {
  nestedCreate(f);
  Object.assign(f.container, {club_key: {directory: "/run/orbit-club", file: "swamp-club-api-key", owner: 1000, group: 1000,
    tmpfiles: "/etc/tmpfiles.d/orbit-club.conf", gateway: "nested-gateway", serve_unit: "orbit-swamp-serve", available}});
  f.write();
}

const clubFile = (f: ReturnType<typeof fixture>, name = "instance-7") => `${f.folder}/tmpfs/${name}/run/orbit-club/swamp-club-api-key`;
const innerCalls = (f: ReturnType<typeof fixture>) => {
  try {
    return Deno.readTextFileSync(`${f.folder}/inner-calls`).trim().split("\n").filter(Boolean);
  } catch {
    return [];
  }
};

/** Every file of the fixture that a later reader could open: Incus call lines, state, result, snapshots, copies and root file systems. */
function filesHolding(f: ReturnType<typeof fixture>, value: string): string[] {
  const found: string[] = [];
  const walk = (path: string) => {
    for (const entry of Deno.readDirSync(path)) {
      const full = `${path}/${entry.name}`;
      if (full.startsWith(`${f.folder}/tmpfs`) || full.startsWith(`${f.folder}/source`)) continue;
      if (entry.isDirectory) walk(full);
      else if (entry.isFile) {
        try {
          if (new TextDecoder().decode(Deno.readFileSync(full)).includes(value)) found.push(full.slice(f.folder.length));
        } catch { /* unreadable */ }
      }
    }
  };
  walk(f.folder);
  return found;
}

Deno.test({
  name: "a nested-fleet create and deploy start deliver the Club key into /run by stdin; no call, output, result, snapshot or copy holds it",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      nestedClub(f);
      // -vvv shows every module invocation that no_log does not hide.
      const created = f.run("container", {ORBIT_CLUB_KEY: CLUB, ANSIBLE_VERBOSITY: "3"});
      assert(created.ok, created.output);
      assertEquals(Deno.readTextFileSync(clubFile(f)), CLUB);
      assertEquals(Deno.statSync(clubFile(f)).mode! & 0o777, 0o600);
      assertEquals(Deno.readTextFileSync(`${f.folder}/containers/instance-7/etc/tmpfiles.d/orbit-club.conf`), "d /run/orbit-club 0750 0 1000 -\n");
      assertEquals(f.state().club_key_runs, [["/run/orbit-club", "swamp-club-api-key", "1000", "1000", "/etc/tmpfiles.d/orbit-club.conf", "nested-gateway", "orbit-swamp-serve"]]);
      // A new key restarts the nested Gateway's Swamp serve, which reads the key at its start.
      assertEquals(innerCalls(f), ["systemctl is-active --quiet incus.service", "list --format csv --columns s -- ^nested-gateway$",
        "exec nested-gateway -- systemctl reset-failed -- orbit-swamp-serve", "exec nested-gateway -- systemctl restart --no-block -- orbit-swamp-serve"]);
      assert(!created.output.includes(CLUB), "the run's output holds the key");
      // The result names what the start did; each later phase of the run keeps that word.
      assertEquals(f.result().club_key, "changed");
      for (const phase of ["workspace", "processes", "seed"]) {
        assert(f.run(phase).ok, phase);
        assertEquals(f.result().club_key, "changed", phase);
      }
      // The seed snapshot holds the folder's tmpfiles line, never the key; a clone copies that snapshot.
      assert(f.state().containers["instance-7"].snapshots.length > 0);
      const deployed = f.run("start", {ORBIT_CLUB_KEY: CLUB, ANSIBLE_VERBOSITY: "3"});
      assert(deployed.ok, deployed.output);
      // The same key on a running container changes nothing and restarts nothing.
      assertEquals(innerCalls(f).length, 4);
      assertEquals(f.result().club_key, "unchanged");
      assertEquals(filesHolding(f, CLUB), []);
    } finally { f.cleanup(); }
  },
});

Deno.test({
  name: "a stopped nested-fleet container loses the key with its /run, and the deploy start delivers it again without blocking on its inner Incus",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      nestedClub(f);
      assert(f.run("container", {ORBIT_CLUB_KEY: CLUB}).ok);
      // Right after a boot the inner Incus is not ready: its service is not active, its client hangs, or the restart fails. The start
      // writes the key, leaves serve to start by itself, and says so; the bound for the inner Incus's answer is 5 s.
      for (const inner of ["ready", "inactive", "hang", "restart_fails"]) {
        assert(f.run("stop").ok);
        assertThrows(() => Deno.statSync(clubFile(f)));
        Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify({...f.state(), inner_incus: inner}));
        Deno.writeTextFileSync(`${f.folder}/inner-calls`, "");
        const began = Date.now();
        const started = f.run("start", {ORBIT_CLUB_KEY: CLUB});
        assert(started.ok, `${inner}: ${started.output}`);
        assert(Date.now() - began < 60_000, `${inner} took ${Date.now() - began} ms`);
        assertEquals(Deno.readTextFileSync(clubFile(f)), CLUB);
        assertEquals(f.result().club_key, inner === "ready" ? "changed" : "changed_serve_pending", inner);
        assertEquals(innerCalls(f).some((call) => call.startsWith("exec ")), ["ready", "restart_fails"].includes(inner), inner);
        assertEquals(innerCalls(f).some((call) => call.startsWith("list ")), inner !== "inactive", inner);
      }
    } finally { f.cleanup(); }
  },
});

Deno.test({
  name: "a nested-fleet start without the vault copy starts the container without the key",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      nestedClub(f, false);
      // A run without the copy names another key it always has; the step never delivers it.
      const result = f.run("container", {ORBIT_CLUB_KEY: "git-credential-fallback"});
      assert(result.ok, result.output);
      assertEquals(f.state().containers["instance-7"].status, "Running");
      assertEquals(f.state().club_key_runs, undefined);
      assertThrows(() => Deno.statSync(clubFile(f)));
      assertEquals(filesHolding(f, "git-credential-fallback"), []);
      // Nothing was written, so the result names no outcome and the Operation says `missing`.
      assertEquals(f.result().club_key, undefined);
    } finally { f.cleanup(); }
  },
});

const unsafeTransfers: {name: string; env: Record<string, string>; extra: string[]}[] = [
  {name: "kept remote files", env: {ANSIBLE_KEEP_REMOTE_FILES: "1"}, extra: []},
  {name: "pipelining turned off", env: {}, extra: ["-e", "ansible_pipelining=false"]},
];
for (const unsafe of unsafeTransfers) {
  Deno.test({
    name: `a nested-fleet start refuses the key transfer by name, before it reads the value: ${unsafe.name}`,
    ignore: !hasAnsible,
    fn() {
      const f = fixture();
      try {
        nestedClub(f);
        const result = f.run("container", {ORBIT_CLUB_KEY: CLUB, ...unsafe.env}, unsafe.extra);
        assert(!result.ok, result.output);
        assertEquals(f.result(), {error_code: "instance.nested_club_key_unsafe_transfer"});
        assertEquals(f.state().club_key_runs, undefined);
        assertEquals(filesHolding(f, CLUB), []);
      } finally { f.cleanup(); }
    },
  });
}

Deno.test({
  name: "a nested-fleet start refuses a /run that is not a tmpfs, before it reads the value",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      nestedClub(f);
      Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify({...f.state(), run_fstype: "zfs"}));
      const result = f.run("container", {ORBIT_CLUB_KEY: CLUB});
      assert(!result.ok, result.output);
      assertEquals(f.result(), {error_code: "instance.nested_club_key_not_tmpfs"});
      assertEquals(f.state().club_key_runs, undefined);
    } finally { f.cleanup(); }
  },
});

Deno.test({
  name: "a normal container's create and deploy start run no Club key task and no Club key command",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      for (const phase of ["container", "workspace", "processes", "seed", "start"]) {
        const result = f.run(phase, {ORBIT_CLUB_KEY: CLUB});
        assert(result.ok, `${phase}: ${result.output}`);
        assert(!/TASK \[(Refuse a key transfer|Read the file system of the container's \/run|Write the Club key)/.test(result.output), result.output);
        assert(!("club_key" in f.result()), phase);
      }
      assertEquals(f.state().club_key_runs, undefined);
      assert(!incusCalls(f).some((call) => call.includes("findmnt")), JSON.stringify(incusCalls(f)));
      assertEquals(filesHolding(f, CLUB), []);
    } finally { f.cleanup(); }
  },
});

/** The `fleet` of a nested-fleet default's deployment intent (NestedFleetIntent). */
const fleetIntent = () => ({
  guests: { gateway: "nested-gateway", "app-dev": "nested-app-dev", "app-prod": "nested-app-prod" },
  layout: { gateway: { address: "10.232.2.11" }, "app-dev": { address: "10.232.2.12" }, "app-prod": { address: "10.232.2.13" } },
  operator: { address: "10.45.0.10", hub: "10.45.0.1", network: "10.45.0.0/24" },
  checkout: { path: "/home/orbit/orbit" },
  proxy: { HTTP_PROXY: "http://10.253.0.1:3128", HTTPS_PROXY: "http://10.253.0.1:3128", http_proxy: "http://10.253.0.1:3128",
    https_proxy: "http://10.253.0.1:3128", NO_PROXY: "localhost", no_proxy: "localhost" },
});

Deno.test({
  name: "a nested-fleet default runs its fleet phases by tag, retakes its seed at the same commit and refuses a Club key on its disk",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      for (const phase of ["container", "workspace", "processes", "seed"]) assert(f.run(phase).ok);
      const commit = git(f.source, "rev-parse", "HEAD");
      // The phase files of the fleet have their own fixture (ansible_instance_fleet_test.ts); here each tag reaches its own file.
      for (const name of ["check", "start", "refresh", "resume"]) {
        Deno.writeTextFileSync(`${f.folder}/tasks/instance-fleet-${name}.yml`,
          `- name: Mark the phase\n  ansible.builtin.debug: { msg: "fleet phase ${name} ran" }\n`);
      }
      // Only a deployment has `fleet`, so its intent has `deployment` too.
      f.intent.deployment = { variant: "container" };
      f.intent.fleet = fleetIntent();
      f.write();
      for (const name of ["check", "start", "refresh", "resume"]) {
        const ran = f.run(`fleet_${name}`);
        assert(ran.ok, ran.output);
        assert(ran.output.includes(`fleet phase ${name} ran`), ran.output);
        for (const other of ["check", "start", "refresh", "resume"].filter((n) => n !== name)) assert(!ran.output.includes(`fleet phase ${other} ran`), ran.output);
      }
      // A drift refusal keeps its code and names each difference in the result.
      Deno.writeTextFileSync(`${f.folder}/tasks/instance-fleet-check.yml`, [
        "- ansible.builtin.set_fact: { fleet_drift_failure: { fleet_drift: [nested-gateway config raw.idmap] } }",
        "- ansible.builtin.assert: { that: false, fail_msg: instance.container_fleet_drift, quiet: true }", ""].join("\n"));
      assert(!f.run("fleet_check").ok);
      assertEquals(f.result(), { error_code: "instance.container_fleet_drift", fleet_drift: ["nested-gateway config raw.idmap"] });
      // A failed fleet phase names its tag, and says why: the failed task and its message (a swamp step that exits nonzero keeps no output).
      Deno.writeTextFileSync(`${f.folder}/tasks/instance-fleet-refresh.yml`,
        `- name: Check the base converge\n  ansible.builtin.assert: { that: false, fail_msg: "The base converge of the nested Node app-dev ended failed", quiet: true }\n`);
      assert(!f.run("fleet_refresh").ok);
      assertEquals(f.result(), { error_code: "instance.container_fleet_refresh_failed",
        detail: "Check the base converge: The base converge of the nested Node app-dev ended failed" });
      // The Doctor wait of a start names its check and the trimmed Doctor body.
      Deno.writeTextFileSync(`${f.folder}/tasks/instance-fleet-start.yml`,
        `- name: Check Doctor\n  ansible.builtin.assert: { that: false, fail_msg: "Readiness check 'doctor' failed after 300 s: {\\"data\\":{\\"healthy\\":false}}", quiet: true }\n`);
      assert(!f.run("fleet_start").ok);
      assertEquals(f.result(), { error_code: "instance.container_fleet_start_failed",
        detail: `Check Doctor: Readiness check 'doctor' failed after 300 s: {"data":{"healthy":false}}` });
      // A failed command adds its stderr tail.
      Deno.writeTextFileSync(`${f.folder}/tasks/instance-fleet-refresh.yml`,
        `- name: Migrate the nested Gateway\n  ansible.builtin.command: { argv: [sh, -c, "echo SQLSTATE no such table >&2; exit 1"] }\n`);
      assert(!f.run("fleet_refresh").ok);
      assertEquals(f.result(), { error_code: "instance.container_fleet_refresh_failed",
        detail: "Migrate the nested Gateway: non-zero return code: SQLSTATE no such table" });
      // The start after a passed refresh: its failure keeps the seed's commit, has its own code, and says the new seed stays.
      Deno.writeTextFileSync(`${f.folder}/run/result.json`, JSON.stringify({ commit }));
      Deno.writeTextFileSync(`${f.folder}/tasks/instance-fleet-resume.yml`,
        `- name: Check the agents\n  ansible.builtin.assert: { that: false, fail_msg: "Readiness check 'agents' failed: the agent of app-prod is not online", quiet: true }\n`);
      assert(!f.run("fleet_resume").ok);
      assertEquals(f.result(), { error_code: "instance.container_fleet_resume_failed", commit,
        detail: "The new seed and base stay; the inner fleet on the new commit did not start: Check the agents: Readiness check 'agents' failed: the agent of app-prod is not online" });
      // After a restore it keeps the first failure and adds why the restored fleet did not start.
      const first = { error_code: "instance.container_fleet_refresh_failed", detail: "Check the base converge: ended failed" };
      Deno.writeTextFileSync(`${f.folder}/run/failed.json`, JSON.stringify(first));
      Deno.writeTextFileSync(`${f.folder}/run/result.json`, JSON.stringify(first));
      assert(!f.run("fleet_resume").ok);
      assertEquals(f.result(), { ...first,
        restore_failure: "The inner fleet of the restored seed did not start: Check the agents: Readiness check 'agents' failed: the agent of app-prod is not online" });
      Deno.removeSync(`${f.folder}/run/failed.json`);
      // A clone's create (no `deployment`) took no seed: its failed fleet start says only why, even after an earlier phase's result file.
      const deployment = f.intent.deployment;
      delete f.intent.deployment;
      f.write();
      Deno.writeTextFileSync(`${f.folder}/run/result.json`, JSON.stringify({ commit: null }));
      assert(!f.run("fleet_resume").ok);
      assertEquals(f.result(), { error_code: "instance.container_fleet_resume_failed",
        detail: "Check the agents: Readiness check 'agents' failed: the agent of app-prod is not online" });
      f.intent.deployment = deployment;
      f.write();

      // The refresh names what each part did; every later phase of the run keeps it in the result file, a failed one too.
      const refreshed = { migrate: "nothing", restart: "yes", converge: "ran", changed: 0, base: "kept", record: "present",
        seconds: { gateway: 14, converge: 70, ready: 3, base: 1 } };
      Deno.writeTextFileSync(`${f.folder}/tasks/instance-fleet-refresh.yml`,
        `- ansible.builtin.set_fact: { fleet_refresh_result: ${JSON.stringify(refreshed)} }\n`);
      assert(f.run("fleet_refresh").ok);
      assertEquals(f.result().fleet_refresh, refreshed);

      // The refresh retook the fleet's base, so the seed is taken again at the same commit.
      const stops = () => ((f.state().systemctl ?? []) as string[][]).filter((call) => call[0] === "stop").length;
      const before = stops();
      assert(f.run("seed").ok);
      assertEquals(f.state().containers["instance-7"].snapshots, [`seed-${commit}`]);
      assertEquals(stops(), before + 1, "the Processes stop for a new snapshot");
      assertEquals(f.result(), { commit, fleet_refresh: refreshed });
      const key = `${f.folder}/containers/instance-7/etc/orbit/swamp-club-api-key`;
      Deno.mkdirSync(`${f.folder}/containers/instance-7/etc/orbit`, { recursive: true });
      Deno.writeTextFileSync(key, "placeholder");
      Deno.chmodSync(key, 0o600);
      const prepared = (f.state().node_seed_calls ?? []).length;
      const stopped = stops();
      const refused = f.run("seed");
      assert(!refused.ok);
      assertEquals(f.result(), { error_code: "instance.fleet_key_on_disk", fleet_refresh: refreshed });
      // Refused before the seed changes anything: no Node-store preparation, no Process stop.
      assertEquals((f.state().node_seed_calls ?? []).length, prepared);
      assertEquals(stops(), stopped);
      assert(!refused.output.includes("placeholder"), "the key's content never reaches the output");
      assertEquals(f.state().containers["instance-7"].snapshots, [`seed-${commit}`]);
      // A seed outside a deployment, such as a create's, with the same file seeds as before and takes no new snapshot at the same commit.
      delete f.intent.fleet;
      delete f.intent.deployment;
      f.write();
      const normal = stops();
      assert(f.run("seed").ok);
      assertEquals(stops(), normal);
    } finally {
      f.cleanup();
    }
  },
});

/** The seed snapshots of the container and of its inner volume. */
const seedPair = (f: ReturnType<typeof fixture>) => ({container: f.state().containers["instance-7"].snapshots,
  volume: f.state().volumes?.["inner-7"]?.snapshots ?? []});

Deno.test({
  name: "a default with an inner volume seeds the container and the volume under one name, retakes both, and keeps only the current pair",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      innerVolumeCreate(f);
      for (const phase of ["container", "workspace", "processes", "seed"]) {
        const result = f.run(phase);
        assert(result.ok, `${phase}: ${result.output}`);
      }
      const first = git(f.source, "rev-parse", "HEAD");
      // docs/reference/development-containers.md#the-inner-volume: the seed is a pair of one name, taken with the Processes stopped.
      assertEquals(seedPair(f), {container: [`seed-${first}`], volume: [`seed-${first}`]});
      const calls = incusCalls(f).map((call) => call.join(" "));
      const container = calls.findIndex((call) => call.includes(`snapshot create instance-7 seed-${first}`));
      const volume = calls.findIndex((call) => call.includes(`storage volume snapshot create orbit-instances inner-7 seed-${first}`));
      assert(container >= 0 && container < volume, JSON.stringify(calls));
      // A seed without its volume half, as one taken before the volume seed existed, is taken again; a full pair is kept.
      const half = f.state();
      half.volumes["inner-7"].snapshots = [];
      Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(half));
      assert(f.run("seed").ok);
      assertEquals(seedPair(f), {container: [`seed-${first}`], volume: [`seed-${first}`]});
      const takes = () => incusCalls(f).filter((call) => call.includes("create") && call.includes("snapshot")).length;
      const before = takes();
      assert(f.run("seed").ok);
      assertEquals(takes(), before);
      // A deployment at a new commit takes the new pair, then deletes both halves of the older one.
      git(f.source, "commit", "--quiet", "--allow-empty", "-m", "second");
      const second = git(f.source, "rev-parse", "HEAD");
      f.intent.deployment = { variant: "container" };
      f.write();
      for (const phase of ["start", "fetch", "checkout", "seed"]) {
        const result = f.run(phase);
        assert(result.ok, `${phase}: ${result.output}`);
      }
      assertEquals(seedPair(f), {container: [`seed-${second}`], volume: [`seed-${second}`]});
    } finally { f.cleanup(); }
  },
});

Deno.test({
  name: "a failed volume snapshot deletes the container half of the seed, keeps the older pair and fails the seed by name",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      innerVolumeCreate(f);
      for (const phase of ["container", "workspace", "processes", "seed"]) assert(f.run(phase).ok);
      const first = git(f.source, "rev-parse", "HEAD");
      git(f.source, "commit", "--quiet", "--allow-empty", "-m", "second");
      f.intent.deployment = { variant: "container" };
      f.write();
      for (const phase of ["start", "fetch", "checkout"]) assert(f.run(phase).ok);
      const state = f.state();
      state.volume_snapshot_fails = true;
      Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(state));
      const failed = f.run("seed");
      assert(!failed.ok);
      assertEquals(f.result(), { error_code: "instance.seed_volume_snapshot_failed" });
      // No half pair: the new container snapshot is gone, and the older pair stays for a restore.
      assertEquals(seedPair(f), {container: [`seed-${first}`], volume: [`seed-${first}`]});
      // The Processes run again after the failure.
      assert(failed.output.includes("TASK [Start the Processes again]"), failed.output);
    } finally { f.cleanup(); }
  },
});

Deno.test({
  name: "a failed deployment of a default with an inner volume restores both halves of the newest seed, and refuses a seed without its volume half",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      innerVolumeCreate(f);
      for (const phase of ["container", "workspace", "processes", "seed"]) assert(f.run(phase).ok);
      f.intent.fleet = fleetIntent();
      f.write();
      const seed = f.state().containers["instance-7"].snapshots[0];
      const restored = f.run("fleet_restore");
      assert(restored.ok, restored.output);
      assertEquals(f.state().containers["instance-7"].restored, [seed]);
      assertEquals(f.state().volumes["inner-7"].restored, [seed]);
      assert(f.run("start").ok);

      // The volume half is gone: nothing is restored, and the container stays as it is.
      const half = f.state();
      half.volumes["inner-7"].snapshots = [];
      Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(half));
      assert(!f.run("fleet_restore").ok);
      assertEquals(f.result(), { error_code: "instance.fleet_seed_missing" });
      assertEquals(f.state().containers["instance-7"].restored, [seed]);
      assertEquals(f.state().containers["instance-7"].status, "Running");
    } finally { f.cleanup(); }
  },
});

Deno.test({
  name: "a normal default and a nested-fleet default without an inner volume (Instance 2454's shape) seed, retake and restore with no volume call",
  ignore: !hasAnsible,
  fn() {
    for (const shape of ["nested", "normal"]) {
      const f = fixture();
      try {
        if (shape === "nested") nestedCreate(f);
        for (const phase of ["container", "workspace", "processes", "seed"]) assert(f.run(phase).ok, `${shape} ${phase}`);
        f.intent.deployment = { variant: "container" };
        f.write();
        const seeded = f.run("seed");
        assert(seeded.ok, seeded.output);
        // The seed tasks for the volume run their expressions and skip.
        for (const task of ["Read the inner volume's snapshots", "Snapshot the inner volume", "Delete the older seeds of the inner volume"]) {
          assert(/skipping: \[node\]/.test(seeded.output.split(`TASK [${task}]`)[1] ?? ""), `${shape}: ${task}`);
        }
        f.intent.fleet = fleetIntent();
        f.write();
        assert(f.run("fleet_restore").ok, shape);
        assertEquals(f.state().containers["instance-7"].restored?.length, 1, shape);
        assertEquals(incusCalls(f).filter((call) => call[0] === "storage" || call.join(" ").includes("/volumes/custom/")), [], shape);
      } finally { f.cleanup(); }
    }
  },
});

Deno.test({
  name: "a failed nested-fleet deployment restores the newest seed with the container stopped, and refuses when no seed is left",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      for (const phase of ["container", "workspace", "processes", "seed"]) assert(f.run(phase).ok);
      f.intent.fleet = fleetIntent();
      f.write();
      // The run's first seed is the newest, whatever its name and its place in Incus's list.
      const state = f.state();
      const kept = "seed-" + "a".repeat(40);
      const older = "seed-" + "f".repeat(40);
      state.containers["instance-7"].snapshots = [kept, older];
      state.containers["instance-7"].created_at = { [older]: "2026-11-13T09:53:00Z", [kept]: "2026-11-13T12:40:00Z" };
      Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(state));
      assertEquals(f.state().containers["instance-7"].status, "Running");
      const restored = f.run("fleet_restore");
      assert(restored.ok, restored.output);
      assertEquals(f.state().containers["instance-7"].restored, [kept]);
      assertEquals(f.state().containers["instance-7"].status, "Stopped");
      // The workflow starts it again through `start`.
      assert(f.run("start").ok);
      assertEquals(f.state().containers["instance-7"].status, "Running");

      const none = f.state();
      none.containers["instance-7"].snapshots = ["before-migrate"];
      Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(none));
      assert(!f.run("fleet_restore").ok);
      assertEquals(f.result(), { error_code: "instance.fleet_seed_missing" });
      assertEquals(f.state().containers["instance-7"].restored, [kept]);
      assertEquals(f.state().containers["instance-7"].status, "Running");
    } finally {
      f.cleanup();
    }
  },
});
