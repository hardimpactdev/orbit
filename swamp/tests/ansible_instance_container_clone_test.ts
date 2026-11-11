import { assert, assertEquals, assertThrows } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";
import { hasAnsible, root, Task, transform, git, fixture, worker, paused, environments, restarts } from "./_support/ansible_instance_container.ts";

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
      assertEquals(state.containers["instance-8"].devices, ["eth0,ipv4.address=10.253.0.3", "eth0,security.acls=orbitinst0-proxy,instance-8", "eth0,security.acls.default.egress.action=reject", "eth0,security.acls.default.ingress.action=reject", "root,type=disk", "root,path=/", "root,pool=orbit-instances", "root,size=40GiB"]);
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
      assertEquals(f.workspace("symbolic-ref", "--short", "HEAD"), "main");
      assertEquals(state.containers["instance-7"].snapshots, [`seed-${seed}`]);
      assertEquals(Deno.readTextFileSync(`${clone}/etc/orbit/instance`), "ORBIT_INSTANCE_ID=8\n");
      assertEquals(JSON.parse(Deno.readTextFileSync(`${clone}/home/agent/.t3/userdata/settings.json`)), { linkedEnvironmentsEnabled: true });
      assertEquals(Deno.statSync(`${clone}/home/agent/.t3/userdata`).mode! & 0o777, 0o700);
      assertEquals(Deno.readTextFileSync(`${clone}/home/agent/.config/orbit/model.env`), "ORBIT_MODEL_KEY=placeholder\n");
      assertEquals(state.files["/home/agent/.config/orbit/model.env"], { uid: "2750", gid: "2750", mode: "0600" });
      assertEquals(Deno.readLinkSync(`${clone}/home/agent/.claude/CLAUDE.md`), "/opt/orbit-agent/current/notes/web-app.md");
      assert(state.systemctl.some((call: string[]) => call.join(" ") === "restart -- orbit-frankenphp.service"));
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

