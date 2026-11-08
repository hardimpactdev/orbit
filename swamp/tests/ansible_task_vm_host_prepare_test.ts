/** Run the shipped phase against a stateful Incus boundary, including a second unchanged run. */
import { assert, assertEquals, assertStringIncludes } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";

const hasAnsible =
  new Deno.Command("sh", { args: ["-c", "command -v ansible-playbook"] })
    .outputSync().success;
const root = new URL("../ansible/", import.meta.url);
const nativeTasks = parse(
  Deno.readTextFileSync(new URL("tasks/task-vm-host-prepare.yml", root)),
) as Record<string, unknown>[];
const plays = parse(
  Deno.readTextFileSync(new URL("task-vm-host.yml", root)),
) as Record<string, unknown>[];
const play = plays.find((p) => p.name === "Prepare the task VM host")!;
const privateRanges =
  (play.vars as Record<string, unknown>).prepare_private_ranges;

function fixture(
  native = false,
  existing = false,
  network = "orbittask0",
  cidr = "10.252.10.0/24",
) {
  const folder = Deno.makeTempDirSync();
  Deno.mkdirSync(`${folder}/bin`);
  Deno.mkdirSync(`${folder}/disks`);
  Deno.writeTextFileSync(`${folder}/arc`, "0\n");
  Deno.writeTextFileSync(`${folder}/pool.img`, "");
  const state = {
    "storage-pools": existing
      ? [{
        name: "orbit-tasks",
        driver: "zfs",
        config: { source: native ? "data/orbit-tasks" : `${folder}/pool.img` },
      }]
      : [],
    projects: [],
    networks: [],
    "network-acls": [],
    profile: { config: {}, devices: {} },
    images: [],
  };
  Deno.writeTextFileSync(`${folder}/state.json`, JSON.stringify(state));
  Deno.writeTextFileSync(
    `${folder}/bin/incus`,
    `#!/bin/sh\nexec '${Deno.execPath()}' run -A '${
      new URL("fixtures/task-vm-host-incus.ts", import.meta.url).pathname
    }' "$@"\n`,
  );
  Deno.writeTextFileSync(
    `${folder}/bin/zfs`,
    '#!/bin/sh\nprintf "%s\\n" "$DATASETS"\n',
  );
  Deno.chmodSync(`${folder}/bin/incus`, 0o755);
  Deno.chmodSync(`${folder}/bin/zfs`, 0o755);
  // Package, kernel-module and firewall modules need Linux/root. Exercise their guards here; the real pool, ACL, bridge,
  // profile, restrictions, ARC files and idempotence run through the shipped Ansible tasks against the isolated boundary.
  const tasks = structuredClone(nativeTasks).map((task, index) => {
    // Render the actual module arguments through Ansible. Keep loops/guards and test the resulting input policy below.
    if ("community.general.ufw" in task) {
      task.vars = { fixture_firewall_rule: task["community.general.ufw"] };
      delete task["community.general.ufw"];
      task["ansible.builtin.copy"] = {
        dest: `${folder}/firewall-${index}-{{ item | default('rule') }}.json`,
        content: "{{ fixture_firewall_rule | to_json }}",
        mode: "0600",
      };
    }
    for (
      const module of [
        "ansible.builtin.apt",
        "community.general.modprobe",
      ]
    ) {
      if (module in task) {
        delete task[module];
        task["ansible.builtin.debug"] = { msg: module };
      }
    }
    const copy = task["ansible.builtin.copy"] as
      | Record<string, unknown>
      | undefined;
    if (copy) {
      delete copy.owner;
      delete copy.group;
    }
    return task;
  });
  const source = JSON.stringify([{
    ...play,
    hosts: "localhost",
    become: false,
    tasks,
  }])
    .replaceAll("/etc/modprobe.d/orbit-task-vm-zfs.conf", `${folder}/arc.conf`)
    .replaceAll("/sys/module/zfs/parameters/zfs_arc_max", `${folder}/arc`)
    .replaceAll("/var/lib/incus/disks/", `${folder}/disks/`);
  Deno.writeTextFileSync(`${folder}/play.yml`, stringify(JSON.parse(source)));
  const host = {
    project: "orbit-tasks",
    pool: "orbit-tasks",
    network,
    cidr,
    pool_size: "640GiB",
    zfs_dataset: native ? "data/orbit-tasks" : null,
    image: "ubuntu-26.04-vm",
  };
  Deno.writeTextFileSync(`${folder}/intent.json`, JSON.stringify({ host }));
  return {
    folder,
    run(datasets = "") {
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
          DATASETS: datasets,
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
    calls: () => Deno.readTextFileSync(`${folder}/calls`),
    firewall: () =>
      Array.from(Deno.readDirSync(folder))
        .filter((file) => file.name.startsWith("firewall-"))
        .map((file) =>
          JSON.parse(Deno.readTextFileSync(`${folder}/${file.name}`))
        ),
    cleanup: () => Deno.removeSync(folder, { recursive: true }),
  };
}

Deno.test({
  name:
    "a deny-input host admits bridge DHCP and DNS without exposing other host services",
  ignore: !hasAnsible,
  fn() {
    const f = fixture(false, false, "proofbr0", "10.252.42.0/24");
    try {
      const result = f.run();
      assert(result.ok, result.output);
      // Assert every INPUT rule, including unrestricted rules that a packet matcher could overlook.
      const input = f.firewall().filter((rule) => !rule.route)
        .sort((a, b) => (a.comment ?? "").localeCompare(b.comment ?? ""));
      assertEquals(input, [
        {
          rule: "allow",
          interface_in: "proofbr0",
          proto: "udp",
          from_port: "68",
          to_port: "67",
          comment: "orbit:task-vms-dhcp",
        },
        {
          rule: "allow",
          interface_in: "proofbr0",
          proto: "tcp",
          to_ip: "10.252.42.1",
          to_port: "53",
          comment: "orbit:task-vms-dns-tcp",
        },
        {
          rule: "allow",
          interface_in: "proofbr0",
          proto: "udp",
          to_ip: "10.252.42.1",
          to_port: "53",
          comment: "orbit:task-vms-dns-udp",
        },
      ]);
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name:
    "a new loop host gets a sized ZFS pool, ARC cap and isolated network, then changes nothing",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      let result = f.run();
      assert(result.ok, result.output);
      assertStringIncludes(f.calls(), '"size=640GiB"');
      assertEquals(
        Deno.readTextFileSync(`${f.folder}/arc`).trim(),
        "2147483648",
      );
      assertStringIncludes(
        Deno.readTextFileSync(`${f.folder}/arc.conf`),
        "zfs_arc_max=2147483648",
      );
      const state = f.state();
      assertEquals(state.networks[0].config["ipv4.address"], "10.252.10.1/24");
      assertEquals(state.networks[0].config["ipv4.nat"], "true");
      assertEquals(
        state.profile.devices.eth0["security.port_isolation"],
        "true",
      );
      assertEquals(state.projects[0].config["restricted.snapshots"], "allow");
      assertEquals(
        state["network-acls"][0].egress[0].destination,
        (privateRanges as string[]).join(","),
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
  name:
    "native ZFS uses a new dataset and never changes ARC, including an existing pool without a dataset setting",
  ignore: !hasAnsible,
  fn() {
    const f = fixture(true);
    try {
      const result = f.run();
      assert(result.ok, result.output);
      assertStringIncludes(f.calls(), '"source=data/orbit-tasks"');
      assertEquals(Deno.readTextFileSync(`${f.folder}/arc`).trim(), "0");
      assert(
        !Array.from(Deno.readDirSync(f.folder)).some((entry) =>
          entry.name === "arc.conf"
        ),
      );
      const intent = JSON.parse(
        Deno.readTextFileSync(`${f.folder}/intent.json`),
      );
      intent.host.zfs_dataset = null;
      Deno.writeTextFileSync(`${f.folder}/intent.json`, JSON.stringify(intent));
      const second = f.run();
      assert(second.ok, second.output);
      assertStringIncludes(second.output, "changed=0");
      assertEquals(Deno.readTextFileSync(`${f.folder}/arc`).trim(), "0");
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name:
    "host preparation refuses conflicting storage, project and bridge before resource changes",
  ignore: !hasAnsible,
  fn() {
    for (
      const conflict of [
        "driver",
        "dataset",
        "project",
        "bridge",
        "existing-dataset",
        "backing-file",
        "backing-symlink",
      ]
    ) {
      const f = fixture(
        conflict === "dataset" || conflict === "existing-dataset",
        conflict === "driver" || conflict === "dataset",
      );
      try {
        const state = f.state();
        if (conflict === "driver") state["storage-pools"][0].driver = "dir";
        if (conflict === "dataset") {
          state["storage-pools"][0].config.source = "data/foreign";
        }
        if (conflict === "project") {
          state.projects = [{
            name: "orbit-tasks",
            config: { "features.networks": "true" },
          }];
        }
        if (conflict === "bridge") {
          state.networks = [{
            name: "orbittask0",
            type: "bridge",
            managed: true,
            config: { "ipv4.address": "10.252.20.1/24" },
          }];
        }
        const backing = `${f.folder}/disks/orbit-tasks.img`;
        if (conflict === "backing-file") {
          Deno.writeTextFileSync(backing, "leftover pool bytes");
        }
        if (conflict === "backing-symlink") {
          Deno.writeTextFileSync(`${f.folder}/target`, "foreign bytes");
          Deno.symlinkSync(`${f.folder}/target`, backing);
        }
        Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(state));
        const result = f.run(
          conflict === "existing-dataset" ? "data/orbit-tasks" : "",
        );
        assertEquals(result.ok, false, `${conflict}: ${result.output}`);
        assertStringIncludes(result.output, "task_vm.setup_failed");
        assert(!/"create"|"edit"|"set"|"copy"/.test(f.calls()), f.calls());
        if (conflict.startsWith("backing")) {
          assertEquals(Deno.readTextFileSync(`${f.folder}/arc`).trim(), "0");
          assert(
            !Array.from(Deno.readDirSync(f.folder)).some((entry) =>
              entry.name === "arc.conf"
            ),
          );
        }
        if (conflict === "backing-file") {
          assertEquals(Deno.readTextFileSync(backing), "leftover pool bytes");
        }
        if (conflict === "backing-symlink") {
          assertEquals(
            Deno.readTextFileSync(`${f.folder}/target`),
            "foreign bytes",
          );
        }
      } finally {
        f.cleanup();
      }
    }
  },
});
