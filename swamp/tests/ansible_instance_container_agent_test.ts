import { assert, assertEquals, assertThrows } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";
import { hasAnsible, root, Task, transform, git, fixture, worker, paused, environments, restarts } from "./_support/ansible_instance_container.ts";

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
