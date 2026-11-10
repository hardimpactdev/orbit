/** Run the shipped Instance container host phase against a stateful Incus boundary: folders, image, reruns and updates. */
import { assert, assertEquals, assertStringIncludes } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";

const hasAnsible =
  new Deno.Command("sh", { args: ["-c", "command -v ansible-playbook"] })
    .outputSync().success;
const root = new URL("../ansible/", import.meta.url);
const GiB = 1073741824;

type Task = Record<string, unknown>;

/**
 * The task files as an unprivileged user can run them here, written beside the play so each include finds its fixture copy.
 * Package, kernel-module, sysctl and firewall modules need Linux and root: they become files in the fixture folder.
 */
function transform(task: Task, folder: string): Task {
  for (const part of ["block", "always"]) {
    if (Array.isArray(task[part])) {
      task[part] = (task[part] as Task[]).map((inner) =>
        transform(inner, folder)
      );
    }
  }
  if ("community.general.ufw" in task) {
    task.vars = {
      ...(task.vars as object ?? {}),
      fixture_rule: task["community.general.ufw"],
    };
    const remove = (task["community.general.ufw"] as Task).delete === true;
    delete task["community.general.ufw"];
    const dest =
      `${folder}/firewall-{{ fixture_rule.comment | regex_replace('[^a-z-]', '_') }}.json`;
    if (remove) task["ansible.builtin.file"] = { path: dest, state: "absent" };
    else {
      task["ansible.builtin.copy"] = {
        dest,
        content: "{{ fixture_rule | to_json }}",
        mode: "0600",
      };
    }
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
  for (
    const module of [
      "ansible.builtin.apt",
      "community.general.modprobe",
      "ansible.builtin.systemd_service",
      "ansible.builtin.wait_for",
    ]
  ) {
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
  // Ownership needs root; the modes stay.
  const command = task["ansible.builtin.command"] as Task | undefined;
  if (task.name === "Set the folder owners and modes" && command) {
    command.argv = "{{ item if item[0] != 'chown' else ['true'] }}";
  }
  return task;
}

const replacements = (folder: string): [string, string][] => [
  ["/etc/modprobe.d/orbit-instances-zfs.conf", `${folder}/arc.conf`],
  ["/etc/systemd/system/", `${folder}/systemd/`],
  ["/sys/module/zfs/parameters/zfs_arc_max", `${folder}/arc`],
  ["/etc/subuid", `${folder}/subuid`],
  ["/etc/subgid", `${folder}/subgid`],
  ["/proc/meminfo", `${folder}/meminfo`],
  ["/sys/module/sctp", `${folder}/sys/sctp`],
  ["/var/cache/orbit/debs", `${folder}/debs`],
  ["/var/cache/orbit/instance-folders", `${folder}/cache`],
  ["/etc/modules-load.d/", `${folder}/modules-load/`],
  ["/etc/orbit/instance-dev-ports.nft", `${folder}/dev-ports.nft`],
  ["\"/etc/orbit\"", `"${folder}/etc-orbit"`],
  ["cat /proc/[0-9]*/maps", `cat ${folder}/maps`],
];

function writeTasks(folder: string) {
  Deno.mkdirSync(`${folder}/tasks`, { recursive: true });
  for (
    const name of [
      "instance-host.yml",
      "instance-egress.yml",
      "instance-folder.yml",
      "instance-vite-plus-home.yml",
      "instance-image.yml",
      "task-container-host-system.yml",
      "task-container-host-prepare.yml",
    ]
  ) {
    const tasks = (parse(
      Deno.readTextFileSync(new URL(`tasks/${name}`, root)),
    ) as Task[]).map((task) => transform(structuredClone(task), folder));
    let text = JSON.stringify(tasks);
    for (const [from, to] of replacements(folder)) {
      text = text.replaceAll(from, to);
    }
    Deno.writeTextFileSync(
      `${folder}/tasks/${name}`,
      stringify(JSON.parse(text)),
    );
  }
  Deno.copyFileSync(
    new URL("transport-exit-codes.json", root),
    `${folder}/transport-exit-codes.json`,
  );
}

const sha256 = async (path: string) =>
  Array.from(
    new Uint8Array(
      await crypto.subtle.digest("SHA-256", Deno.readFileSync(path)),
    ),
  ).map((b) => b.toString(16).padStart(2, "0")).join("");

/** A tarball with one program that prints its version, and a single-file artifact. */
async function artifacts(folder: string, version: string) {
  const work = Deno.makeTempDirSync();
  Deno.mkdirSync(`${work}/tool-${version}`);
  Deno.writeTextFileSync(
    `${work}/tool-${version}/tool`,
    `#!/bin/sh\necho tool ${version}\n`,
  );
  Deno.chmodSync(`${work}/tool-${version}/tool`, 0o755);
  new Deno.Command("tar", {
    args: [
      "-czf",
      `${folder}/artifacts/tool-${version}.tar.gz`,
      "-C",
      work,
      `tool-${version}`,
    ],
  }).outputSync();
  Deno.writeTextFileSync(
    `${folder}/artifacts/single-1.0.phar`,
    "#!/bin/sh\necho single\n",
  );
  Deno.mkdirSync(`${work}/package`);
  Deno.writeTextFileSync(`${work}/package/claude`, "#!/bin/sh\necho 2.0\n");
  Deno.chmodSync(`${work}/package/claude`, 0o755);
  new Deno.Command("tar", {
    args: [
      "-czf",
      `${folder}/artifacts/claude-2.0.tgz`,
      "-C",
      work,
      "package",
    ],
  }).outputSync();
  Deno.removeSync(work, { recursive: true });
  return {
    runtime: [{
      name: "tool",
      version,
      url: `https://example.test/tool-${version}.tar.gz`,
      archive: "tar",
      sha256: await sha256(`${folder}/artifacts/tool-${version}.tar.gz`),
      bin: { tool: `tool-${version}/tool` },
    }, {
      name: "single",
      version: "1.0",
      url: "https://example.test/download/1.0/single-1.0.phar",
      archive: "file",
      sha256: await sha256(`${folder}/artifacts/single-1.0.phar`),
      bin: { single: "single-1.0.phar" },
    }],
    agent: [{
      name: "claude-code",
      version: "2.0",
      url: "https://example.test/claude-2.0.tgz",
      archive: "tar",
      sha256: await sha256(`${folder}/artifacts/claude-2.0.tgz`),
      bin: { claude: "package/claude" },
    }],
  };
}

/**
 * The image's setup script and finish commands, read from the Gateway's source, so the build container of the fixture runs the
 * real ones against the accounts and the machine-id of a booted stock image.
 */
function gatewayImage() {
  const source = Deno.readTextFileSync(
    new URL(
      "../../apps/gateway/app/Domain/Instances/InstanceImage.php",
      import.meta.url,
    ),
  );
  const values: Record<string, string> = {
    agent: "2750",
    orbit: "1000",
    work: "2760",
    workspace: "/srv/workspace",
    vite: "/var/lib/orbit/vite-plus-home",
    sharedVite: "/opt/orbit-runtime/current/vite-plus-home",
  };
  const prepare = source.match(/return <<<SH\n([\s\S]*?\n)\s*SH;/)![1]
    .split("\n").map((line) => line.trim()).join("\n")
    .replace(/\{\$(\w+)\}/g, (_, name) => values[name]);
  const finish = source.match(/'finish' => \[\n([\s\S]*?)\n\s*\],\n\s*\];/)![1]
    .split("\n").map((line) => line.trim()).filter((line) =>
      line.startsWith("[")
    ).map((line) =>
      [...line.matchAll(/'((?:[^'\\]|\\.)*)'/g)].map((match) => match[1])
    );
  assert(!prepare.includes("$agent") && finish.length > 0);
  return { prepare, finish };
}

type Rule = {
  action: string;
  state: string;
  protocol?: string;
  source?: string;
  destination_port?: string;
};

/**
 * Whether a new TCP connection that the Node sends or forwards to a container passes the bridge ACL. Incus checks that ACL before
 * the container's own: an enabled allow rule of the ACL's ingress admits it, and anything else gets the bridge's default action.
 */
function bridgeAdmits(
  state: Record<string, any>,
  packet: { source: string; port: number },
) {
  const bridge = state.networks.find((n: { name: string }) =>
    n.name === "orbitinst0"
  );
  const acl = state["network-acls"].find((a: { name: string }) =>
    a.name === bridge.config["security.acls"]
  );
  const rule = (acl.ingress as Rule[]).find((rule) => {
    const [first, last = first] = (rule.destination_port ?? "0-65535").split(
      "-",
    ).map(Number);
    return rule.state === "enabled" && (rule.protocol ?? "tcp") === "tcp" &&
      (rule.source === undefined || rule.source === `${packet.source}/32`) &&
      first <= packet.port && packet.port <= last;
  });
  return (rule?.action ??
    bridge.config["security.acls.default.ingress.action"]) === "allow";
}

/**
 * Whether a container's DNS query to the bridge address gets an answer. Incus admits DNS there ahead of the ACL, and the bridge's
 * dnsmasq answers every name the Node resolves, unless `port=0` turns its DNS off.
 */
const bridgeAnswersDns = (state: Record<string, any>) =>
  !(state.networks.find((n: { name: string }) => n.name === "orbitinst0")
    .config["raw.dnsmasq"] ?? "").split("\n").includes("port=0");

/** The Node's Caddy, which connects from the bridge address, and a WireGuard peer whose connection to a published T3 the Node forwards. */
const caddy = "10.253.0.1";
const peer = "10.44.0.20";

/** `ip -4 -o addr show` of a Node with a CI bridge in the next /24 and the Instance bridge itself. */
const nodeAddresses = [
  "1: lo    inet 127.0.0.1/8 scope host lo\\       valid_lft forever preferred_lft forever",
  "3: enp10s0    inet 192.168.6.20/24 brd 192.168.6.255 scope global enp10s0\\       valid_lft forever preferred_lft forever",
  "6: orbitci0    inet 10.253.1.1/24 scope global orbitci0\\       valid_lft forever preferred_lft forever",
  "8: tun0    inet 10.99.0.2 peer 10.99.0.1/32 scope global tun0\\       valid_lft forever preferred_lft forever",
  "7: orbitinst0    inet 10.253.0.1/24 scope global orbitinst0\\       valid_lft forever preferred_lft forever",
];

async function fixture(
  { pool = "zfs", addresses = nodeAddresses }: {
    pool?: string | null;
    addresses?: string[];
  } = {},
) {
  const folder = Deno.makeTempDirSync();
  for (
    const dir of [
      "bin",
      "sysctl",
      "debs",
      "sys",
      "artifacts",
      "cache",
      "modules-load",
      "systemd",
    ]
  ) {
    Deno.mkdirSync(`${folder}/${dir}`);
  }
  Deno.writeTextFileSync(`${folder}/arc`, "0\n");
  Deno.writeTextFileSync(`${folder}/ip-addr`, addresses.join("\n") + "\n");
  Deno.writeTextFileSync(
    `${folder}/meminfo`,
    `MemTotal:       ${32 * GiB / 1024} kB\n`,
  );
  for (const ids of ["subuid", "subgid"]) {
    Deno.writeTextFileSync(`${folder}/${ids}`, "root:1000000:1000000000\n");
  }
  Deno.writeTextFileSync(
    `${folder}/state.json`,
    JSON.stringify({
      "storage-pools": pool === null
        ? []
        : [{ name: "orbit-instances", driver: pool, config: {} }],
      projects: [],
      networks: [],
      "network-acls": [],
      profiles: {},
      images: [],
      instances: [],
      server_version: "7.0.1",
      zfs_pool_name: "fast/orbit-instances",
    }),
  );
  const scripts: Record<string, string> = {
    incus: `exec '${Deno.execPath()}' run -A '${
      new URL("fixtures/task-vm-host-incus.ts", import.meta.url).pathname
    }' "$@"`,
    zfs: 'printf "%s\\n" 307200',
    ip: `cat '${folder}/ip-addr'`,
    "dpkg-query": 'printf "%s" "ii 1:7.0.1-ubuntu26.04"',
    modprobe: 'printf "install /bin/false \\n"',
    sysctl: 'printf "16384\\n128\\n65536\\n200\\n20000\\n1048576\\n"',
    // No Node group holds gid 2750.
    getent: "exit 2",
    // The egress table and Squid configuration validate; the real-kernel check and the live check prove them.
    // It also records each call, so the live check of the development ports table shows.
    nft: `echo "$*" >> '${folder}/nft-calls'; exit 0`,
    // A staged configuration that holds BAD does not parse.
    squid: '! grep -qs BAD "$4"',
    systemctl: 'for unit; do [ "$unit" = is-active ] || echo active; done',
    // The pinned artifacts come from the fixture folder, by the URL's file name.
    curl:
      `out=; for a; do case "$prev" in --output) out=$a;; esac; prev=$a; done
cp "${folder}/artifacts/$(basename "$prev")" "$out"`,
  };
  for (const [name, body] of Object.entries(scripts)) {
    Deno.writeTextFileSync(`${folder}/bin/${name}`, `#!/bin/sh\n${body}\n`);
    Deno.chmodSync(`${folder}/bin/${name}`, 0o755);
  }
  writeTasks(folder);
  Deno.writeTextFileSync(
    `${folder}/play.yml`,
    stringify([{
      name: "Prepare the Instance container host",
      hosts: "localhost",
      gather_facts: false,
      tasks: [{
        name: "Prepare the Instance container host",
        "ansible.builtin.include_tasks": "tasks/instance-host.yml",
      }],
    }]),
  );
  const parts = await artifacts(folder, "1.0");
  const instances = {
    project: "orbit-instances",
    network: "orbitinst0",
    pool: "orbit-instances",
    cidr: "10.253.0.0/24",
    address: "10.253.0.1/24",
    build_address: "10.253.0.254",
    dhcp_range: "10.253.0.2-10.253.0.253",
    stock: "ubuntu-26.04-instance-stock",
    forwarder_port: 8319,
    ingress: [{
      action: "allow",
      source: "10.253.0.1/32",
      protocol: "tcp",
      destination_port: "1024-65535",
      state: "enabled",
    }, {
      action: "allow",
      protocol: "tcp",
      destination_port: "3773",
      state: "enabled",
    }],
    egress: {
      port: 3128,
      user: "proxy",
      directory: `${folder}/egress`,
      config: `${folder}/egress/orbit-instance-egress.conf`,
      unit: "orbit-instance-egress.service",
      table_unit: "orbit-instance-egress-table.service",
      table: "orbit_instance_egress",
      origin: "http://10.253.0.1:3128",
      squid: "http_port 10.253.0.1:3128\n",
      ruleset: "table inet orbit_instance_egress\n",
      rule: {
        comment: "orbit:instances-egress",
        action: "allow",
        direction: "in",
        source: "any",
        destination: "10.253.0.1",
        port: "3128",
        protocol: "tcp",
        in_interface: "orbitinst0",
        out_interface: null,
        family: "v4",
      },
    },
    dev_ports: {
      table: "orbit_instance_dev_ports",
      unit: "orbit-instance-dev-ports.service",
      ruleset: "table inet orbit_instance_dev_ports\ndelete table inet orbit_instance_dev_ports\ntable inet orbit_instance_dev_ports {}\n",
    },
    ids: 201 * 65536,
    profile_config: {
      "environment.VP_HOME": "/var/lib/orbit/vite-plus-home",
      "environment.VP_NO_UPDATE_CHECK": "1",
      "environment.HTTPS_PROXY": "http://10.253.0.1:3128",
    },
    incus: { line: "7.0", packages: [] },
    image: {
      alias: "orbit-instance-aaaaaaaaaaaa",
      packages: ["acl", "git", "nftables"],
      ...gatewayImage(),
      files: [{
        path: "/etc/nftables.conf",
        content: "flush ruleset\n",
        uid: 0,
        gid: 0,
        mode: "0640",
      }],
    },
    folders: {
      runtime: {
        path: `${folder}/opt/orbit-runtime`,
        version: "111111111111",
        parts: parts.runtime,
      },
      agent: {
        path: `${folder}/opt/orbit-agent`,
        version: "222222222222",
        parts: parts.agent,
        notes: { "web-app": "# This environment\n" },
      },
    },
  };
  const write = (value: unknown) =>
    Deno.writeTextFileSync(
      `${folder}/intent.json`,
      JSON.stringify({ instances: value }),
    );
  write(instances);
  return {
    folder,
    instances,
    write,
    artifacts: (version: string) => artifacts(folder, version),
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
          PATH: `${folder}/bin:${Deno.env.get("PATH")}`,
          ANSIBLE_NOCOLOR: "1",
          ANSIBLE_HOME: `${folder}/home`,
          ANSIBLE_LOCAL_TEMP: `${folder}/tmp`,
          ANSIBLE_LIBRARY: new URL("library", root).pathname,
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
    has: (name: string) => {
      try {
        Deno.lstatSync(`${folder}/${name}`);
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
    "a build-list Node gets the Instance project, bridge, folders and image, then a rerun changes nothing",
  ignore: !hasAnsible,
  async fn() {
    const f = await fixture();
    try {
      let result = f.run();
      assert(result.ok, result.output);
      const state = f.state();
      const project = state.projects.find((p: { name: string }) =>
        p.name === "orbit-instances"
      );
      assertEquals(project.config, {
        "features.images": "true",
        "features.profiles": "true",
        "features.networks": "false",
        restricted: "true",
        "restricted.networks.access": "orbitinst0",
        "restricted.containers.privilege": "isolated",
        "restricted.devices.disk": "allow",
        "restricted.devices.proxy": "allow",
        "restricted.snapshots": "allow",
        "limits.virtual-machines": "0",
      });
      // Every process in a container, `incus exec` sessions too, gets the runtime folder's environment and the egress proxy.
      assertEquals(state.profiles["orbit-instances"].config, {
        "environment.VP_HOME": "/var/lib/orbit/vite-plus-home",
        "environment.VP_NO_UPDATE_CHECK": "1",
        "environment.HTTPS_PROXY": "http://10.253.0.1:3128",
      });
      assertEquals(state.profiles["orbit-instances"].devices, {
        root: { type: "disk", path: "/", pool: "orbit-instances" },
        eth0: {
          type: "nic",
          name: "eth0",
          network: "orbitinst0",
          "security.port_isolation": "true",
        },
        "orbit-runtime": {
          type: "disk",
          source: `${f.folder}/opt/orbit-runtime`,
          path: `${f.folder}/opt/orbit-runtime`,
          readonly: "true",
          shift: "true",
        },
        "orbit-agent": {
          type: "disk",
          source: `${f.folder}/opt/orbit-agent`,
          path: `${f.folder}/opt/orbit-agent`,
          readonly: "true",
          shift: "true",
        },
      });
      const bridge = state.networks.find((n: { name: string }) =>
        n.name === "orbitinst0"
      );
      assertEquals(bridge.config, {
        "ipv4.address": "10.253.0.1/24",
        "ipv4.dhcp.ranges": "10.253.0.2-10.253.0.253",
        "ipv4.nat": "true",
        "ipv6.address": "none",
        "raw.dnsmasq": "port=0",
        "security.acls": "orbitinst0-egress",
        "security.acls.default.ingress.action": "reject",
        "security.acls.default.egress.action": "reject",
      });
      // No DNS: the bridge's dnsmasq serves DHCP only, whatever the Node's input rules admit.
      assert(!bridgeAnswersDns(state));
      const acls = Object.fromEntries(
        state["network-acls"].map((a: { name: string }) => [a.name, a]),
      );
      // The bridge reaches only the model forwarder and the egress proxy on its own address. The build container has no ACL and no
      // route of its own: it goes out through the proxy, as every container does.
      assertEquals(acls["orbitinst0-egress"].egress, [{
        action: "allow",
        destination: "10.253.0.1/32",
        protocol: "tcp",
        destination_port: "8319",
        state: "enabled",
      }, {
        action: "allow",
        destination: "10.253.0.1/32",
        protocol: "tcp",
        destination_port: "3128",
        state: "enabled",
      }]);
      assertEquals(acls["orbitinst0-build"], undefined);
      // Into a container, the bridge admits what a container's own ACL may admit: the Node's Caddy to FrankenPHP and the
      // development ports, and T3's clients. Everything else gets the default reject.
      assert(bridgeAdmits(state, { source: caddy, port: 8080 }));
      assert(bridgeAdmits(state, { source: caddy, port: 5173 }));
      assert(bridgeAdmits(state, { source: peer, port: 3773 }));
      assert(!bridgeAdmits(state, { source: peer, port: 8080 }));
      assert(!bridgeAdmits(state, { source: peer, port: 5173 }));
      assert(!bridgeAdmits(state, { source: caddy, port: 22 }));
      // The input rules have the names of the firewall catalog: DHCP and the proxy, and no DNS.
      for (const name of ["dhcp", "egress"]) {
        assert(f.has(`firewall-orbit_instances-${name}.json`), name);
      }
      for (const name of ["dns-udp", "dns-tcp", "build"]) {
        assert(!f.has(`firewall-orbit_instances-${name}.json`), name);
      }
      assertEquals(
        JSON.parse(
          Deno.readTextFileSync(
            `${f.folder}/firewall-orbit_instances-egress.json`,
          ),
        ),
        // `interface_in` alone: the real module refuses `direction` beside it (tests/ansible_instance_module_args_test.py).
        {
          rule: "allow",
          interface_in: "orbitinst0",
          proto: "tcp",
          to_ip: "10.253.0.1",
          to_port: "3128",
          comment: "orbit:instances-egress",
        },
      );
      // The proxy: the package's own unit masked, the table and Squid's configuration in place, and Squid as `proxy` only while
      // its table is loaded.
      assertEquals(
        Deno.readLinkSync(`${f.folder}/systemd/squid.service`),
        "/dev/null",
      );
      assertEquals(
        Deno.readTextFileSync(`${f.folder}/egress/egress.nft`),
        "table inet orbit_instance_egress\n",
      );
      assertEquals(
        Deno.readTextFileSync(`${f.folder}/egress/orbit-instance-egress.conf`),
        "http_port 10.253.0.1:3128\n",
      );
      const proxyUnit = Deno.readTextFileSync(
        `${f.folder}/systemd/orbit-instance-egress.service`,
      );
      for (
        const line of [
          "User=proxy\n",
          "BindsTo=orbit-instance-egress-table.service\n",
          "ExecStartPre=+/usr/sbin/nft list table inet orbit_instance_egress\n",
          `ExecStart=/usr/sbin/squid -N -d 1 -f ${f.folder}/egress/orbit-instance-egress.conf\n`,
        ]
      ) assertStringIncludes(proxyUnit, line);
      assertStringIncludes(
        Deno.readTextFileSync(
          `${f.folder}/systemd/orbit-instance-egress-table.service`,
        ),
        "ExecStop=/usr/sbin/nft delete table inet orbit_instance_egress\n",
      );
      // No modules to load: an Instance container runs no WireGuard or Docker.
      assertEquals([...Deno.readDirSync(`${f.folder}/modules-load`)], []);
      // The table of agent development ports is written as the Gateway rendered it, and its unit loads it at boot.
      assertEquals(Deno.readTextFileSync(`${f.folder}/dev-ports.nft`),
        "table inet orbit_instance_dev_ports\ndelete table inet orbit_instance_dev_ports\ntable inet orbit_instance_dev_ports {}\n");
      assert(Deno.readTextFileSync(`${f.folder}/systemd/orbit-instance-dev-ports.service`)
        .includes(`ExecStart=/usr/sbin/nft --file ${f.folder}/dev-ports.nft`));
      assert(Deno.readTextFileSync(`${f.folder}/nft-calls`).includes("list table inet orbit_instance_dev_ports"));
      assertEquals(
        Deno.readTextFileSync(`${f.folder}/sysctl/fs.inotify.max_user_watches`),
        "1048576 /etc/sysctl.d/60-orbit-instances.conf",
      );
      assertEquals(
        Deno.readTextFileSync(`${f.folder}/arc`).trim(),
        String(4 * GiB),
      );

      // The folders: each program links into its part, `current` names the version, and the agent's notes are beside it.
      const runtime = `${f.folder}/opt/orbit-runtime`;
      assertEquals(Deno.readLinkSync(`${runtime}/current`), "111111111111");
      assertEquals(
        Deno.readLinkSync(`${runtime}/111111111111/bin/tool`),
        "../tool/tool-1.0/tool",
      );
      assertEquals(
        new TextDecoder().decode(
          new Deno.Command(`${runtime}/current/bin/tool`).outputSync().stdout,
        ),
        "tool 1.0\n",
      );
      assertEquals(
        Deno.readLinkSync(`${runtime}/111111111111/bin/single`),
        "../single/single-1.0.phar",
      );
      assertEquals(
        Deno.statSync(`${runtime}/111111111111/single/single-1.0.phar`).mode! &
          0o777,
        0o755,
      );
      const agent = `${f.folder}/opt/orbit-agent/222222222222`;
      assertEquals(
        Deno.readLinkSync(`${agent}/agent/bin/claude`),
        "../claude-code/package/claude",
      );
      // Others cannot open the agent's programs; the notes are public.
      assertEquals(Deno.statSync(`${agent}/agent`).mode! & 0o007, 0);
      assertEquals(
        Deno.readTextFileSync(`${agent}/notes/web-app.md`),
        "# This environment\n",
      );
      assertEquals(Deno.statSync(`${agent}/notes/web-app.md`).mode! & 0o004, 4);

      // The image: built once from the stock image at the build address, published, and named by the stable alias.
      const project_calls = f.calls().filter((c) =>
        c.includes("orbit-instances") && c.includes("orbit-instance-build")
      );
      assertEquals(project_calls[1], [
        "--project",
        "orbit-instances",
        "init",
        "ubuntu-26.04-instance-stock",
        "orbit-instance-build",
        "--config",
        "security.idmap.isolated=true",
        "--device",
        "eth0,ipv4.address=10.253.0.254",
      ]);
      assert(
        f.calls().some((c) =>
          c.includes("install") && c.includes("nftables") && c.includes("acl")
        ),
      );
      // The build ran the Gateway's real setup script. A started stock container has cloud-init's group `lxd` on gid 1000, and the
      // fixture's `groupadd` refuses a taken gid, as the real one does: the script must delete the group first.
      assert(f.instances.image.prepare.includes("groupadd --gid 1000 orbit"));
      // Cloud-init's first boot ends before anything runs in the build container, so it cannot add a sudo user after the setup.
      const inBuild = f.calls().filter((c) =>
        c[0] === "exec" || c.includes("exec")
      ).map((c) => c.slice(c.indexOf("--") + 1).join(" "));
      assertEquals(inBuild[0], "cloud-init status --wait");
      assert(inBuild.indexOf("apt-get update") > 0);
      assertEquals(state.files["orbit-instance-build/etc/nftables.conf"], {
        content: "flush ruleset\n",
        mode: "0640",
        uid: "0",
        gid: "0",
      });
      assertEquals(
        state.images.map((i: { aliases: unknown }) => i.aliases).at(-1),
        [
          { name: "orbit-instance-aaaaaaaaaaaa" },
          { name: "orbit-instance" },
        ],
      );
      // The build container booted, so it had a machine-id; the image has none, and each container writes its own.
      assertEquals(state.images.at(-1).machine_id, "");
      assertEquals(
        state.instances.map((i: { name: string }) => i.name),
        [],
      );

      const before = f.calls().length;
      result = f.run();
      assert(result.ok, result.output);
      assertStringIncludes(result.output, "changed=0");
      // A rerun builds and publishes nothing; only the clone probe runs again.
      assert(
        !f.calls().slice(before).some((c) =>
          c.includes("publish") || c.includes("orbit-instance-build")
        ),
      );

      // A Node prepared before these rules, as Beast was: the bridge's dnsmasq answers DNS, and the ACL rejects all that goes to
      // a container. The next converge gives the existing bridge and ACL both.
      const old = f.state();
      delete old.networks.find((n: { name: string }) =>
        n.name === "orbitinst0"
      ).config["raw.dnsmasq"];
      old["network-acls"].find((a: { name: string }) =>
        a.name === "orbitinst0-egress"
      ).ingress = [];
      Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(old));
      assert(bridgeAnswersDns(f.state()));
      assert(!bridgeAdmits(f.state(), { source: caddy, port: 8080 }));
      assert(!bridgeAdmits(f.state(), { source: peer, port: 3773 }));
      result = f.run();
      assert(result.ok, result.output);
      assert(!bridgeAnswersDns(f.state()));
      assert(bridgeAdmits(f.state(), { source: caddy, port: 8080 }));
      assert(bridgeAdmits(f.state(), { source: peer, port: 3773 }));
      assert(!bridgeAdmits(f.state(), { source: peer, port: 8080 }));
      assertEquals(f.state().networks, state.networks);
      assertEquals(f.state()["network-acls"], state["network-acls"]);
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name:
    "a new runtime version installs beside the old one, flips current, restarts each running container's Processes and drops the old",
  ignore: !hasAnsible,
  async fn() {
    const f = await fixture();
    try {
      let result = f.run();
      assert(result.ok, result.output);
      const state = f.state();
      state.instances.push(
        { name: "app-default", project: "orbit-instances", status: "Running" },
        { name: "app-asleep", project: "orbit-instances", status: "Stopped" },
      );
      Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(state));
      const parts = await f.artifacts("1.1");
      f.write({
        ...f.instances,
        folders: {
          ...f.instances.folders,
          runtime: {
            ...f.instances.folders.runtime,
            version: "333333333333",
            parts: parts.runtime,
          },
        },
      });
      const before = f.calls().length;
      result = f.run();
      assert(result.ok, result.output);
      const runtime = `${f.folder}/opt/orbit-runtime`;
      assertEquals(Deno.readLinkSync(`${runtime}/current`), "333333333333");
      assert(!f.has("opt/orbit-runtime/111111111111"));
      const restarts = f.calls().slice(before).filter((c) =>
        c.includes("exec")
      );
      assertEquals(restarts, [[
        "exec",
        "--project",
        "orbit-instances",
        "app-default",
        "--",
        "systemctl",
        "try-restart",
        "orbit-*.service",
      ]]);
      // The agent folder did not change, so T3 keeps running.
      assert(!restarts.flat().includes("t3.service"));
      assert(!f.has("opt/orbit-runtime/.restart"));
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name:
    "a new image definition is built, takes the stable alias and replaces the old image",
  ignore: !hasAnsible,
  async fn() {
    const f = await fixture();
    try {
      let result = f.run();
      assert(result.ok, result.output);
      f.write({
        ...f.instances,
        image: { ...f.instances.image, alias: "orbit-instance-bbbbbbbbbbbb" },
      });
      result = f.run();
      assert(result.ok, result.output);
      const images = f.state().images.filter((i: { source?: string }) =>
        !i.source
      );
      assertEquals(images.map((i: { aliases: unknown }) => i.aliases), [[
        { name: "orbit-instance-bbbbbbbbbbbb" },
        { name: "orbit-instance" },
      ]]);
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name:
    "a Squid configuration that does not parse never replaces the live one, and the run fails",
  ignore: !hasAnsible,
  async fn() {
    const f = await fixture();
    try {
      let result = f.run();
      assert(result.ok, result.output);
      f.write({
        ...f.instances,
        egress: { ...f.instances.egress, squid: "BAD\n" },
      });
      result = f.run();
      assert(!result.ok);
      assertStringIncludes(
        result.output,
        "Parse the staged Squid configuration",
      );
      assertEquals(
        Deno.readTextFileSync(`${f.folder}/egress/orbit-instance-egress.conf`),
        "http_port 10.253.0.1:3128\n",
      );
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name:
    "a project that lists disk paths loses the list, so a container with the idmapped folders starts",
  ignore: !hasAnsible,
  async fn() {
    const f = await fixture();
    try {
      // Beast after the first release: every restriction, the idmapped folders, and the path list that refused every start.
      let result = f.run();
      assert(result.ok, result.output);
      const state = f.state();
      state.projects.find((p: { name: string }) => p.name === "orbit-instances")
        .config["restricted.devices.disk.paths"] =
          `${f.folder}/opt/orbit-runtime,${f.folder}/opt/orbit-agent`;
      Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(state));
      result = f.run();
      assert(result.ok, result.output);
      const project = f.state().projects.find((p: { name: string }) =>
        p.name === "orbit-instances"
      );
      assertEquals(project.config["restricted.devices.disk.paths"], undefined);
      assertEquals(project.config["restricted.devices.disk"], "allow");
      assert(
        f.calls().some((c) =>
          c.includes("start") && c.includes("orbit-clone-probe-copy")
        ),
      );
      result = f.run();
      assert(result.ok, result.output);
      assertStringIncludes(result.output, "changed=0");
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a Node without its Instance pool is refused before anything changes",
  ignore: !hasAnsible,
  async fn() {
    const f = await fixture({ pool: null });
    try {
      const result = f.run();
      assert(!result.ok);
      assertStringIncludes(result.output, "instance.host_unavailable");
      assertEquals(f.state().projects, []);
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "a directory pool skips the clone probe: a copy there is a full copy",
  ignore: !hasAnsible,
  async fn() {
    const f = await fixture({ pool: "dir" });
    try {
      const result = f.run();
      assert(result.ok, result.output);
      assert(!f.calls().some((c) => c.includes("orbit-clone-probe-copy")));
      // No ZFS, so no ARC cap.
      assert(!f.has("arc.conf"));
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name:
    "an old folder version that a process still maps stays; a restart that failed runs again on the next converge",
  ignore: !hasAnsible,
  async fn() {
    const f = await fixture();
    try {
      let result = f.run();
      assert(result.ok, result.output);
      const parts = await f.artifacts("1.1");
      f.write({
        ...f.instances,
        folders: {
          ...f.instances.folders,
          runtime: {
            ...f.instances.folders.runtime,
            version: "333333333333",
            parts: parts.runtime,
          },
        },
      });
      // A queue worker of a stopped container's last run still maps the old Node binary.
      Deno.writeTextFileSync(
        `${f.folder}/maps`,
        `7f00-7f01 r-xp 0 00:00 1 ${f.folder}/opt/orbit-runtime/111111111111/tool/tool-1.0/tool\n`,
      );
      // A run that stopped between the flip and the restarts leaves the mark.
      const state = f.state();
      state.instances.push({
        name: "app-default",
        project: "orbit-instances",
        status: "Running",
      });
      Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(state));
      result = f.run();
      assert(result.ok, result.output);
      assert(f.has("opt/orbit-runtime/111111111111"));
      Deno.writeTextFileSync(`${f.folder}/opt/orbit-runtime/.restart`, "");
      const before = f.calls().length;
      result = f.run();
      assert(result.ok, result.output);
      assert(
        f.calls().slice(before).some((c) =>
          c.includes("exec") && c.includes("orbit-*.service")
        ),
      );
      assert(!f.has("opt/orbit-runtime/.restart"));
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "an image that keeps a sudo path is never published",
  ignore: !hasAnsible,
  async fn() {
    const f = await fixture();
    try {
      const state = f.state();
      state.sudo_paths =
        "user:ubuntu\nsudoers:/etc/sudoers.d/90-cloud-init-users\nmember:ubuntu";
      Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(state));
      const result = f.run();
      assert(!result.ok);
      assertStringIncludes(
        result.output,
        "the image keeps a sudo path: user:ubuntu, sudoers:/etc/sudoers.d/90-cloud-init-users, member:ubuntu",
      );
      const after = f.state();
      assert(!f.calls().some((c) => c.includes("publish")));
      assertEquals(after.instances, []);
    } finally {
      f.cleanup();
    }
  },
});

/** A tarball at `artifacts/<name>` of shell programs, each printing what its body prints. */
async function program(
  folder: string,
  name: string,
  files: Record<string, string>,
) {
  const work = Deno.makeTempDirSync();
  for (const [path, body] of Object.entries(files)) {
    Deno.mkdirSync(`${work}/${path}`.replace(/\/[^/]+$/, ""), {
      recursive: true,
    });
    Deno.writeTextFileSync(`${work}/${path}`, `#!/bin/sh\n${body}\n`);
    Deno.chmodSync(`${work}/${path}`, 0o755);
  }
  new Deno.Command("tar", {
    args: ["-czf", `${folder}/artifacts/${name}`, "-C", work, "."],
  }).outputSync();
  Deno.removeSync(work, { recursive: true });
  return await sha256(`${folder}/artifacts/${name}`);
}

/** What PHP prints for the check's probe: its version, then its extensions. */
const probe = (version: string, extensions: string[]) =>
  `printf '%s\\n' ${[version, ...extensions].join(" ")}`;

Deno.test({
  name:
    "a static PHP build is installed as php/<version> with no link in bin, and a build whose extensions differ is refused",
  ignore: !hasAnsible,
  async fn() {
    const f = await fixture();
    try {
      const install = async (frankenphp: string[]) => {
        const name = `static-php-${frankenphp.length}.tar.gz`;
        const sha = await program(f.folder, name, {
          "bin/php": probe("8.5.12", ["Core", "pdo_pgsql"]),
          "bin/frankenphp": probe("8.5.12", frankenphp),
        });
        f.write({
          ...f.instances,
          folders: {
            ...f.instances.folders,
            runtime: {
              ...f.instances.folders.runtime,
              version: `44444444444${frankenphp.length}`,
              parts: [...f.instances.folders.runtime.parts, {
                name: "php/8.5",
                version: "8.5.12",
                url: `https://example.test/download/${name}`,
                archive: "tar",
                sha256: sha,
                // The Gateway encodes an empty program list as a JSON list.
                bin: [],
                static_php: {
                  php_version: "8.5.12",
                  extensions: ["core", "pdo_pgsql"],
                },
              }],
            },
          },
        });
        return f.run();
      };
      let result = await install(["Core", "pdo_pgsql", "frankenphp-cli"]);
      assert(result.ok, result.output);
      const version = `${f.folder}/opt/orbit-runtime/444444444443`;
      assert(f.has("opt/orbit-runtime/444444444443/php/8.5/bin/php"));
      assert(f.has("opt/orbit-runtime/444444444443/php/8.5/bin/frankenphp"));
      assert(!f.has("opt/orbit-runtime/444444444443/bin/php"));
      assertEquals(
        Deno.readLinkSync(`${version}/bin/tool`),
        "../tool/tool-1.0/tool",
      );

      result = await install(["Core", "pdo_pgsql", "frankenphp-cli", "apcu"]);
      assert(!result.ok);
      assertStringIncludes(result.output, "frankenphp also loads apcu.");
      // The refused version never becomes whole, and the folder stays on the checked one.
      assert(!f.has("opt/orbit-runtime/444444444444"));
      assertEquals(
        Deno.readLinkSync(`${f.folder}/opt/orbit-runtime/current`),
        "444444444443",
      );
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name:
    "the runtime folder builds its Vite+ home once, links vp and the home into each version, and drops a home no version links",
  ignore: !hasAnsible,
  async fn() {
    const f = await fixture();
    try {
      const calls = `${f.folder}/vp-calls`;
      // npm installs the locked package under its --prefix; vp records its calls and runs the home's Node.
      const npm = await program(f.folder, "node-1.0.tar.gz", {
        "node/bin/npm": `while [ "$1" != --prefix ]; do shift; done
mkdir -p "$2/node_modules/vite-plus" && cp "$2/package-lock.json" "$2/node_modules/vite-plus/package.json"`,
      });
      const vp = await program(f.folder, "vp-1.4.0.tar.gz", {
        vp: `echo "$VP_HOME $VP_NO_UPDATE_CHECK $*" >> ${calls}
[ "$*" = "env exec node --version" ] && echo v24.22.1; exit 0`,
      });
      const runtime = (version: string, node: string, home: string) => ({
        ...f.instances,
        folders: {
          ...f.instances.folders,
          runtime: {
            ...f.instances.folders.runtime,
            version,
            parts: [{
              name: "node",
              version: "1.0",
              url: "https://example.test/download/node-1.0.tar.gz",
              archive: "tar",
              sha256: npm,
              bin: { npm: "node/bin/npm" },
            }, {
              name: "vite-plus",
              version: "1.4.0",
              url: "https://example.test/download/vp-1.4.0.tar.gz",
              archive: "tar",
              sha256: vp,
              bin: [],
            }],
            vite_plus: {
              part: "vite-plus",
              binary: "vp",
              home: `${f.folder}/opt/orbit-runtime/vite-plus/${home}`,
              version: "1.4.0",
              node,
              lock: JSON.stringify({
                name: "orbit-recipe-vite-plus",
                version: "1.4.0",
              }),
            },
          },
        },
      });
      f.write(runtime("555555555555", "24.22.1", "aaaaaaaaaaaa"));
      let result = f.run();
      assert(result.ok, result.output);
      const home = `${f.folder}/opt/orbit-runtime/vite-plus/aaaaaaaaaaaa`;
      const version = `${f.folder}/opt/orbit-runtime/555555555555`;
      assert(f.has("opt/orbit-runtime/vite-plus/aaaaaaaaaaaa/.complete"));
      assert(
        f.has(
          "opt/orbit-runtime/vite-plus/aaaaaaaaaaaa/recipe/runtime/node_modules/vite-plus/package.json",
        ),
      );
      assertEquals(
        Deno.readLinkSync(`${home}/node_modules/vite-plus`),
        `${home}/recipe/runtime/node_modules/vite-plus`,
      );
      assertEquals(Deno.readLinkSync(`${home}/bin/node`), "vp");
      assertEquals(Deno.readLinkSync(`${version}/vite-plus-home`), home);
      assertEquals(
        Deno.readLinkSync(`${version}/bin/vp`),
        "../vite-plus-home/bin/vp",
      );
      assertEquals(Deno.readTextFileSync(calls).trim().split("\n"), [
        `${home} 1 env default 24.22.1`,
        `${home} 1 env install 24.22.1`,
        `${home} 1 env exec node --version`,
      ]);

      // A complete home is not built again for another version of the folder.
      f.write(runtime("666666666666", "24.22.1", "aaaaaaaaaaaa"));
      result = f.run();
      assert(result.ok, result.output);
      assertEquals(Deno.readTextFileSync(calls).trim().split("\n").length, 3);

      // Another release is another home. The old one stays while a process maps it, even after its version went.
      Deno.writeTextFileSync(
        `${f.folder}/maps`,
        `7f00-7f01 r-xp 0 00:00 1 ${home}/bin/vp\n`,
      );
      f.write(runtime("777777777777", "24.22.1", "bbbbbbbbbbbb"));
      result = f.run();
      assert(result.ok, result.output);
      assert(f.has("opt/orbit-runtime/vite-plus/bbbbbbbbbbbb/.complete"));
      assert(!f.has("opt/orbit-runtime/666666666666"));
      assert(f.has("opt/orbit-runtime/vite-plus/aaaaaaaaaaaa/.complete"));
      // Once no process maps it and no version links it, it goes.
      Deno.writeTextFileSync(`${f.folder}/maps`, "");
      result = f.run();
      assert(result.ok, result.output);
      assert(!f.has("opt/orbit-runtime/vite-plus/aaaaaaaaaaaa"));

      // A home whose Node is not the recipe's is refused and never marked complete.
      f.write(runtime("888888888888", "24.23.0", "cccccccccccc"));
      result = f.run();
      assert(!result.ok);
      assertStringIncludes(
        result.output,
        "the Vite+ home runs Node v24.22.1, not 24.23.0",
      );
      assert(!f.has("opt/orbit-runtime/vite-plus/cccccccccccc/.complete"));
      assertEquals(
        Deno.readLinkSync(`${f.folder}/opt/orbit-runtime/current`),
        "777777777777",
      );
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name:
    "a task VM host keeps the ZFS ARC its task side sets: the Instance side writes no cap",
  ignore: !hasAnsible,
  async fn() {
    const f = await fixture();
    try {
      f.write({ ...f.instances, arc: false });
      const result = f.run();
      assert(result.ok, result.output);
      assertEquals(Deno.readTextFileSync(`${f.folder}/arc`).trim(), "0");
      assert(!f.has("arc.conf"));
      // The rest of the Instance side is there.
      assert(
        f.state().networks.some((n: { name: string }) =>
          n.name === "orbitinst0"
        ),
      );
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name:
    "an Instance bridge range that another interface of the Node holds refuses before any change",
  ignore: !hasAnsible,
  async fn() {
    const f = await fixture({
      addresses: [
        ...nodeAddresses.slice(0, 2),
        "6: orbitci0    inet 10.253.0.1/24 scope global orbitci0\\       valid_lft forever preferred_lft forever",
      ],
    });
    try {
      const result = f.run();
      assert(!result.ok);
      assertStringIncludes(
        result.output,
        "instance.host_unavailable: the Instance bridge range 10.253.0.0/24 overlaps orbitci0 10.253.0.1/24",
      );
      assertEquals(f.state().projects, []);
      assertEquals(f.state().networks, []);
      assertEquals(Deno.readTextFileSync(`${f.folder}/arc`).trim(), "0");
    } finally {
      f.cleanup();
    }
  },
});
