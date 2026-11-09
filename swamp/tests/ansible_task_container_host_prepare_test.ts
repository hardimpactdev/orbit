/** Run the shipped host phase of a task container host against a stateful Incus boundary, including a second unchanged run. */
import { assert, assertEquals, assertStringIncludes } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";

const hasAnsible =
  new Deno.Command("sh", { args: ["-c", "command -v ansible-playbook"] })
    .outputSync().success;
const root = new URL("../ansible/", import.meta.url);
const load = (name: string) =>
  parse(Deno.readTextFileSync(new URL(`tasks/${name}`, root))) as Record<
    string,
    unknown
  >[];
const plays = parse(
  Deno.readTextFileSync(new URL("task-vm-host.yml", root)),
) as Record<string, unknown>[];
const play = plays.find((p) => p.name === "Prepare the task VM host")!;
const GiB = 1073741824;

type Task = Record<string, unknown>;

/**
 * The tasks of `name` as an unprivileged user can run them here: the container includes inline, so the phase runs as one play.
 * Package, kernel-module, sysctl and firewall modules need Linux and root: they become files in the fixture folder that the test reads.
 */
function local(name: string, folder: string): Task[] {
  const out: Task[] = [];
  for (const source of load(name)) {
    const task = structuredClone(source);
    const include = task["ansible.builtin.include_tasks"] as string | undefined;
    if (include?.startsWith("task-container-")) {
      out.push({
        name: task.name,
        when: task.when,
        block: local(include, folder),
      });
      continue;
    }
    for (const part of ["block", "always"]) {
      if (Array.isArray(task[part])) {
        task[part] = (task[part] as Task[]).flatMap((inner) =>
          transform(inner, folder)
        );
      }
    }
    out.push(...transform(task, folder));
  }
  return out;
}

function transform(task: Task, folder: string): Task[] {
  if ("block" in task) return [task];
  if (task["ansible.builtin.include_tasks"] === "artifact-fetch.yml") {
    delete task["ansible.builtin.include_tasks"];
    task["ansible.builtin.copy"] = {
      dest: "{{ artifact.path }}",
      content: "{{ artifact | to_json }}",
      mode: "0600",
    };
    return [task];
  }
  if ("community.general.ufw" in task) {
    task.vars = {
      ...(task.vars as object ?? {}),
      fixture_rule: task["community.general.ufw"],
    };
    delete task["community.general.ufw"];
    task["ansible.builtin.copy"] = {
      dest:
        `${folder}/firewall-{{ fixture_rule.comment | regex_replace('[^a-z-]', '_') }}.json`,
      content: "{{ fixture_rule | to_json }}",
      mode: "0600",
    };
  }
  if ("ansible.posix.sysctl" in task) {
    task.vars = {
      ...(task.vars as object ?? {}),
      fixture_sysctl: task["ansible.posix.sysctl"],
    };
    delete task["ansible.posix.sysctl"];
    task["ansible.builtin.copy"] = {
      dest: `${folder}/sysctl/{{ fixture_sysctl.name }}`,
      content: "{{ fixture_sysctl.value }} {{ fixture_sysctl.sysctl_file }}",
      mode: "0600",
    };
  }
  for (const module of ["ansible.builtin.apt", "community.general.modprobe"]) {
    if (module in task) {
      delete task[module];
      task["ansible.builtin.debug"] = { msg: module };
    }
  }
  for (const module of ["ansible.builtin.copy", "ansible.builtin.file"]) {
    const args = task[module] as Record<string, unknown> | undefined;
    if (args) {
      delete args.owner;
      delete args.group;
    }
  }
  return [task];
}

function fixture(
  {
    memory = 32 * GiB,
    written = "307200",
    installed = "ii 1:7.0.1-ubuntu26.04",
    server = "7.0.1",
    subids = 1000000000,
    sctp = "install /bin/false ",
    sctpLoaded = false,
    arc = "0",
    native = false,
  }: {
    memory?: number;
    // The ARC cap the host runs before the phase; 0 is no cap.
    arc?: string;
    // An existing native pool on a ZFS dataset, as on Shark, instead of a new loop pool.
    native?: boolean;
    written?: string;
    installed?: string;
    server?: string;
    subids?: number;
    sctp?: string;
    sctpLoaded?: boolean;
  } = {},
) {
  const folder = Deno.makeTempDirSync();
  for (const dir of ["bin", "disks", "sysctl", "debs", "sys"]) {
    Deno.mkdirSync(`${folder}/${dir}`);
  }
  if (sctpLoaded) Deno.mkdirSync(`${folder}/sys/sctp`);
  Deno.writeTextFileSync(`${folder}/arc`, `${arc}\n`);
  Deno.writeTextFileSync(
    `${folder}/meminfo`,
    `MemTotal:       ${memory / 1024} kB\nMemFree:          1000 kB\n`,
  );
  for (const ids of ["subuid", "subgid"]) {
    Deno.writeTextFileSync(
      `${folder}/${ids}`,
      `orbit:100000:65536\nroot:1000000:${subids}\n`,
    );
  }
  const state = {
    "storage-pools": native
      ? [{
        name: "orbit-tasks",
        driver: "zfs",
        config: { source: "data/orbit-tasks" },
      }]
      : [],
    projects: [],
    networks: [],
    "network-acls": [],
    profiles: {},
    images: [],
    instances: [],
    server_version: server,
    zfs_pool_name: native ? "data/orbit-tasks" : "orbit-tasks",
  };
  Deno.writeTextFileSync(`${folder}/state.json`, JSON.stringify(state));
  const scripts: Record<string, string> = {
    incus: `exec '${Deno.execPath()}' run -A '${
      new URL("fixtures/task-vm-host-incus.ts", import.meta.url).pathname
    }' "$@"`,
    zfs:
      'case "$*" in *written*) printf "%s\\n" "$WRITTEN" ;; *) printf "%s\\n" "$DATASETS" ;; esac',
    // As dpkg-query prints `${db:Status-Abbrev}${Version}`: `ii ` is installed, `rc ` removed with its config files left.
    "dpkg-query": '[ -n "$INSTALLED" ] || exit 1; printf "%s" "$INSTALLED"',
    modprobe: 'printf "%s\\n" "$SCTP"',
    "apt-get": `printf "%s\\n" "$*" >> '${folder}/apt-get'`,
    // The host's current values: Ubuntu's vm.max_map_count is above the floor and stays.
    sysctl: 'printf "16384\\n128\\n65536\\n200\\n20000\\n1048576\\n"',
  };
  for (const [name, body] of Object.entries(scripts)) {
    Deno.writeTextFileSync(`${folder}/bin/${name}`, `#!/bin/sh\n${body}\n`);
    Deno.chmodSync(`${folder}/bin/${name}`, 0o755);
  }
  const source = JSON.stringify([{
    ...play,
    hosts: "localhost",
    become: false,
    tasks: local("task-vm-host-prepare.yml", folder),
  }])
    .replaceAll("/etc/modprobe.d/orbit-task-vm-zfs.conf", `${folder}/arc.conf`)
    .replaceAll("/sys/module/zfs/parameters/zfs_arc_max", `${folder}/arc`)
    .replaceAll("/var/lib/incus/disks/", `${folder}/disks/`)
    .replaceAll(
      "/etc/modules-load.d/orbit-{{ container_host.kind }}.conf",
      `${folder}/modules.conf`,
    )
    .replaceAll("/etc/subuid", `${folder}/subuid`)
    .replaceAll("/etc/subgid", `${folder}/subgid`)
    .replaceAll("/proc/meminfo", `${folder}/meminfo`)
    .replaceAll("/sys/module/sctp", `${folder}/sys/sctp`)
    .replaceAll("/var/cache/orbit/debs", `${folder}/debs`);
  Deno.writeTextFileSync(`${folder}/play.yml`, stringify(JSON.parse(source)));
  const packages = ["incus", "incus-base", "incus-client"].map((name, i) => ({
    name,
    sha256: String(i + 1).repeat(64),
    urls: [
      `https://mirror.example/${name}_${String(i + 1).repeat(64)}.deb`,
      `https://pkgs.zabbly.com/incus/lts-7.0/pool/main/i/incus/${name}_7.0.2-ubuntu26.04-1_amd64.deb`,
    ],
  }));
  const host = {
    project: "orbit-tasks",
    pool: "orbit-tasks",
    network: "orbittask0",
    cidr: "10.252.10.0/24",
    pool_size: "640GiB",
    zfs_dataset: native ? "data/orbit-tasks" : null,
    image: "ubuntu-26.04-vm",
    containers: {
      project: "orbit-task-containers",
      network: "orbittaskc0",
      cidr: "10.252.11.0/24",
      address: "10.252.11.1/24",
      alias: "ubuntu-26.04-ct",
      places: 3,
      incus: { line: "7.0", packages },
    },
  };
  Deno.writeTextFileSync(`${folder}/intent.json`, JSON.stringify({ host }));
  return {
    folder,
    run() {
      const done = new Deno.Command("ansible-playbook", {
        args: [
          "-i",
          "localhost,",
          "-c",
          "local",
          "-e",
          `@${folder}/intent.json`,
          "-e",
          "ansible_python_interpreter={{ ansible_playbook_python }}",
          `${folder}/play.yml`,
        ],
        env: {
          HOST_FIXTURE: folder,
          DATASETS: "",
          WRITTEN: written,
          INSTALLED: installed,
          SCTP: sctp,
          PATH: `${folder}/bin:${Deno.env.get("PATH")}`,
          ANSIBLE_NOCOLOR: "1",
          ANSIBLE_HOME: `${folder}/home`,
          ANSIBLE_LOCAL_TEMP: `${folder}/tmp`,
        },
      }).outputSync();
      return {
        ok: done.success,
        output: new TextDecoder().decode(done.stdout) +
          new TextDecoder().decode(done.stderr),
      };
    },
    state: () => JSON.parse(Deno.readTextFileSync(`${folder}/state.json`)),
    calls: () =>
      Deno.readTextFileSync(`${folder}/calls`).trim().split("\n").map((
        line,
      ) => JSON.parse(line) as string[]),
    read: (name: string) => Deno.readTextFileSync(`${folder}/${name}`),
    has: (name: string) => {
      try {
        Deno.statSync(`${folder}/${name}`);
        return true;
      } catch {
        return false;
      }
    },
    cleanup: () => Deno.removeSync(folder, { recursive: true }),
  };
}

Deno.test({
  name:
    "a container host gets its kernel side, bridge, project and clone check beside the VM side, then changes nothing",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      let result = f.run();
      assert(result.ok, result.output);
      assertEquals(
        f.read("modules.conf"),
        "wireguard\noverlay\nbr_netfilter\n",
      );
      const sysctl = (key: string) => f.read(`sysctl/${key}`);
      assertEquals(
        sysctl("fs.inotify.max_user_instances"),
        "1048576 /etc/sysctl.d/60-orbit-task-containers.conf",
      );
      assertEquals(
        sysctl("fs.inotify.max_user_watches").split(" ")[0],
        "1048576",
      );
      assertEquals(
        sysctl("fs.inotify.max_queued_events").split(" ")[0],
        "1048576",
      );
      assertEquals(sysctl("kernel.keys.maxkeys").split(" ")[0], "2000");
      assertEquals(sysctl("kernel.keys.maxbytes").split(" ")[0], "2000000");
      assertEquals(sysctl("vm.max_map_count").split(" ")[0], "1048576");
      // One eighth of 32 GiB is 4 GiB, but a loop pool keeps at most 2 GiB: the container rule never raises the cap.
      assertEquals(f.read("arc").trim(), String(2 * GiB));
      assertEquals(
        f.read("arc.conf"),
        `# Orbit task container host: reserve RAM for host services.\noptions zfs zfs_arc_max=${
          2 * GiB
        }\n`,
      );

      const state = f.state();
      const project = state.projects.find((p: { name: string }) =>
        p.name === "orbit-task-containers"
      );
      assertEquals(project.config, {
        "features.images": "true",
        "features.profiles": "true",
        "features.networks": "false",
        restricted: "true",
        "restricted.networks.access": "orbittaskc0",
        "restricted.containers.nesting": "allow",
        "restricted.containers.interception": "allow",
        "restricted.containers.privilege": "isolated",
        "restricted.containers.lowlevel": "allow",
        "restricted.snapshots": "allow",
        "limits.virtual-machines": "0",
      });
      assertEquals(state.profiles["orbit-task-containers"].devices, {
        root: { type: "disk", path: "/", pool: "orbit-tasks" },
        eth0: {
          type: "nic",
          name: "eth0",
          network: "orbittaskc0",
          "security.port_isolation": "true",
        },
      });
      const bridge = state.networks.find((n: { name: string }) =>
        n.name === "orbittaskc0"
      );
      assertEquals(bridge.config, {
        "ipv4.address": "10.252.11.1/24",
        "ipv4.nat": "true",
        "ipv6.address": "none",
        "security.acls": "orbittaskc0-egress",
        "security.acls.default.ingress.action": "reject",
        "security.acls.default.egress.action": "reject",
      });
      const acls = Object.fromEntries(
        state["network-acls"].map((a: { name: string }) => [a.name, a]),
      );
      assertEquals(
        acls["orbittaskc0-egress"].egress,
        acls["orbittask0-egress"].egress,
      );
      assertEquals(acls["orbittaskc0-egress"].ingress, [{
        action: "allow",
        source: "10.252.11.1/32",
        protocol: "tcp",
        destination_port: "22",
        state: "enabled",
      }]);
      // The VM side keeps its own project, bridge and profile.
      assertEquals(
        state.profiles["orbit-tasks"].devices.eth0.network,
        "orbittask0",
      );
      assertEquals(
        state.projects.find((p: { name: string }) => p.name === "orbit-tasks")
          .config["restricted.networks.access"],
        "orbittask0",
      );
      const imageCopy = f.calls().find((c) =>
        c[0] === "image" && c.includes("ubuntu-26.04-ct")
      )!;
      assertEquals(imageCopy, [
        "image",
        "copy",
        "images:ubuntu/26.04/cloud",
        "local:",
        "--alias",
        "ubuntu-26.04-ct",
        "--target-project",
        "orbit-task-containers",
      ]);
      // The clone probe is an isolated container whose copy starts once, and nothing of it stays.
      const probe = f.calls().filter((c) =>
        c.includes("orbit-task-containers") &&
        ["init", "copy", "start", "stop"].includes(c[2])
      ).map((c) => c.slice(2));
      assertEquals(probe, [
        [
          "init",
          "ubuntu-26.04-ct",
          "orbit-clone-probe",
          "--config",
          "security.idmap.isolated=true",
        ],
        ["copy", "orbit-clone-probe", "orbit-clone-probe-copy"],
        ["start", "orbit-clone-probe-copy"],
        ["stop", "--force", "orbit-clone-probe-copy"],
      ]);
      assertEquals(state.instances, []);
      // Incus is present on the recipe's line, so nothing is installed.
      assert(!f.has("apt-get"));

      result = f.run();
      assert(result.ok, result.output);
      assertStringIncludes(result.output, "changed=0");
      assertEquals(f.state().instances, []);
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name:
    "the container bridge gets DHCP and DNS input rules under its own catalog names",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      const result = f.run();
      assert(result.ok, result.output);
      const rule = (comment: string) =>
        JSON.parse(f.read(`firewall-${comment.replace(/[^a-z-]/g, "_")}.json`));
      assertEquals(rule("orbit:task-containers-dhcp"), {
        rule: "allow",
        interface_in: "orbittaskc0",
        proto: "udp",
        from_port: "68",
        to_port: "67",
        comment: "orbit:task-containers-dhcp",
      });
      for (const proto of ["udp", "tcp"]) {
        assertEquals(rule(`orbit:task-containers-dns-${proto}`), {
          rule: "allow",
          interface_in: "orbittaskc0",
          proto,
          to_ip: "10.252.11.1",
          to_port: "53",
          comment: `orbit:task-containers-dns-${proto}`,
        });
      }
      // The VM bridge keeps its own rules.
      assertEquals(rule("orbit:task-vms-dhcp").interface_in, "orbittask0");
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a small host caps the ARC at 1 GiB",
  ignore: !hasAnsible,
  fn() {
    const f = fixture({ memory: 8 * GiB - 512 * 1024 * 1024 });
    try {
      const result = f.run();
      assert(result.ok, result.output);
      assertEquals(f.read("arc").trim(), String(GiB));
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name:
    "a host without Incus installs the recipe's pinned packages, fetched by digest",
  ignore: !hasAnsible,
  fn() {
    const f = fixture({ installed: "" });
    try {
      const result = f.run();
      assert(result.ok, result.output);
      const debs = ["incus", "incus-base", "incus-client"].map((name, i) =>
        `${f.folder}/debs/${name}_${String(i + 1).repeat(64)}.deb`
      );
      assertEquals(
        f.read("apt-get").trim(),
        `install --yes --no-install-recommends --no-remove -- ${
          debs.join(" ")
        }`,
      );
      const fetched = JSON.parse(Deno.readTextFileSync(debs[1]));
      assertEquals(fetched.sha256, "2".repeat(64));
      assertEquals(
        fetched.urls[0],
        `https://mirror.example/incus-base_${"2".repeat(64)}.deb`,
      );
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name:
    "a container host is refused before its container side changes: another Incus line, SCTP, subordinate IDs, a taken bridge",
  ignore: !hasAnsible,
  fn() {
    const cases: [string, Parameters<typeof fixture>[0], string?][] = [
      ["incus line", { server: "6.0.5" }],
      ["sctp rule", {
        sctp: "insmod /lib/modules/7.0.0/kernel/net/sctp/sctp.ko",
      }],
      ["sctp loaded", { sctpLoaded: true }],
      // Three places and the base need 404,000,000 IDs.
      [
        "subordinate ids",
        { subids: 400000000 },
        "root needs 404000000 subordinate IDs",
      ],
      ["taken bridge", {}],
    ];
    for (const [label, options, message] of cases) {
      const f = fixture(options);
      try {
        if (label === "taken bridge") {
          const state = f.state();
          state.networks.push({
            name: "orbittaskc0",
            type: "bridge",
            managed: true,
            config: { "ipv4.address": "10.252.99.1/24" },
          });
          Deno.writeTextFileSync(
            `${f.folder}/state.json`,
            JSON.stringify(state),
          );
        }
        const result = f.run();
        assertEquals(result.ok, false, `${label}: ${result.output}`);
        assertStringIncludes(result.output, message ?? "task_vm.setup_failed");
        assert(
          !f.calls().some((c) =>
            c.includes("orbittaskc0") && c[0] !== "query"
          ) &&
            !f.calls().some((c) => c.includes("orbit-task-containers")),
          `${label}: ${JSON.stringify(f.calls())}`,
        );
      } finally {
        f.cleanup();
      }
    }
  },
});

Deno.test({
  name: "a copy that Incus shifted fails the phase and leaves no probe",
  ignore: !hasAnsible,
  fn() {
    const f = fixture({ written: String(500 * 1024 * 1024) });
    try {
      const result = f.run();
      assertEquals(result.ok, false, result.output);
      assertStringIncludes(result.output, "task_vm.setup_failed");
      assertEquals(f.state().instances, []);
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a clone probe that a failed run left is removed before the next probe",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      const state = f.state();
      state.instances = [
        {
          name: "orbit-clone-probe",
          project: "orbit-task-containers",
          status: "Stopped",
        },
        {
          name: "orbit-clone-probe-copy",
          project: "orbit-task-containers",
          status: "Running",
        },
      ];
      Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(state));
      const result = f.run();
      assert(result.ok, result.output);
      const deletes = f.calls().filter((c) => c[2] === "delete").map((c) =>
        c.at(-1)
      );
      assertEquals(deletes.slice(0, 2), [
        "orbit-clone-probe",
        "orbit-clone-probe-copy",
      ]);
      assertEquals(f.state().instances, []);
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name:
    "a container host on a native pool gets the ARC cap now and after reboot, and keeps it when containers go",
  ignore: !hasAnsible,
  fn() {
    const f = fixture({ native: true });
    try {
      let result = f.run();
      assert(result.ok, result.output);
      // No cap ran before, so one eighth of 32 GiB applies, on a pool where a VM host changes no ARC.
      assertEquals(f.read("arc").trim(), String(4 * GiB));
      const conf =
        `# Orbit task container host: reserve RAM for host services.\noptions zfs zfs_arc_max=${
          4 * GiB
        }\n`;
      assertEquals(f.read("arc.conf"), conf);
      assertStringIncludes(
        f.calls().map((c) => c.join(" ")).join("\n"),
        "storage get orbit-tasks zfs.pool_name",
      );
      result = f.run();
      assert(result.ok, result.output);
      assertStringIncludes(result.output, "changed=0");

      const intent = JSON.parse(f.read("intent.json"));
      delete intent.host.containers;
      Deno.writeTextFileSync(`${f.folder}/intent.json`, JSON.stringify(intent));
      result = f.run();
      assert(result.ok, result.output);
      assertStringIncludes(result.output, "changed=0");
      assertEquals(f.read("arc.conf"), conf);
      assertEquals(f.read("arc").trim(), String(4 * GiB));
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "the container rule never raises a cap the host already runs",
  ignore: !hasAnsible,
  fn() {
    // Beast: a loop pool with its 2 GiB cap and 128 GiB of memory. A native pool with a 3 GiB cap of the operator's.
    for (
      const [label, options, cap] of [
        [
          "loop pool with 2 GiB",
          { memory: 128 * GiB, arc: String(2 * GiB) },
          2 * GiB,
        ],
        [
          "native pool with 3 GiB",
          { native: true, arc: String(3 * GiB) },
          3 * GiB,
        ],
      ] as [string, Parameters<typeof fixture>[0], number][]
    ) {
      const f = fixture(options);
      try {
        const result = f.run();
        assert(result.ok, `${label}: ${result.output}`);
        assertEquals(f.read("arc").trim(), String(cap), label);
        assertStringIncludes(f.read("arc.conf"), `zfs_arc_max=${cap}\n`, label);
      } finally {
        f.cleanup();
      }
    }
  },
});

Deno.test({
  name:
    "an Incus package removed with its config files left counts as missing and is installed",
  ignore: !hasAnsible,
  fn() {
    const f = fixture({ installed: "rc 1:7.0.1-ubuntu26.04" });
    try {
      const result = f.run();
      assert(result.ok, result.output);
      assertStringIncludes(
        f.read("apt-get"),
        "install --yes --no-install-recommends --no-remove --",
      );
    } finally {
      f.cleanup();
    }
  },
});
