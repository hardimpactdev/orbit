import { assert, assertEquals, assertStringIncludes } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";
import { hasAnsible, root, load, plays, play, GiB, Task, local, transform, fixture } from "./_support/ansible_task_container_host_prepare.ts";

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

