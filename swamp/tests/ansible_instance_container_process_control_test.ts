import { assert, assertEquals, assertThrows } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";
import { hasAnsible, root, Task, transform, git, fixture, worker, paused, environments, restarts } from "./_support/ansible_instance_container.ts";

Deno.test({
  name: "a Process's start, stop and restart change its unit in the container; a read returns its state and journal",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      f.container.processes = [worker];
      f.write();
      for (const phase of ["container", "workspace", "processes"]) {
        assert(f.run(phase, { ORBIT_PROCESS_ENVIRONMENTS: environments }).ok);
      }
      const calls = () => f.state().systemctl.map((call: string[]) => call.join(" "));

      const stop = f.run("process", { ORBIT_PROCESS_ACTION: "stop" });
      assert(stop.ok, stop.output);
      assert(calls().includes(`disable --now -- ${worker.name}`));
      assertEquals(f.result(), { state: "inactive", output: "" });

      assert(f.run("process", { ORBIT_PROCESS_ACTION: "start" }).ok);
      assert(calls().includes(`enable --now -- ${worker.name}`));
      assertEquals(f.result(), { state: "active", output: "" });

      assert(f.run("process", { ORBIT_PROCESS_ACTION: "restart" }).ok);
      assert(calls().join("\n").includes(`enable -- ${worker.name}\nrestart -- ${worker.name}`));

      const state = f.state();
      state.journal = { [worker.name]: ["2026-11-10T08:00:00+00:00 worker[7]: first", "2026-11-10T08:00:01+00:00 worker[7]: second"] };
      Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(state));
      const logs = f.run("process", { ORBIT_PROCESS_ACTION: "logs" });
      assert(logs.ok, logs.output);
      // Newest first, as the Node agent reads a journal; the lines stay out of the run's output.
      assertEquals(f.result(), { state: "active", output: "2026-11-10T08:00:01+00:00 worker[7]: second\n2026-11-10T08:00:00+00:00 worker[7]: first\n" });
      assert(!logs.output.includes("worker[7]"), logs.output);

      state.journal[worker.name] = ["2026-11-10T08:00:00+00:00 worker[7]: only"];
      Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(state));
      const single = f.run("process", { ORBIT_PROCESS_ACTION: "logs" });
      assert(single.ok, single.output);
      assertEquals(f.result(), { state: "active", output: "2026-11-10T08:00:00+00:00 worker[7]: only\n" });
      assert(!single.output.includes("worker[7]"), single.output);

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
      assert(calls.includes(`enable --now -- orbit-frankenphp.service ${own.name}`), calls.join("\n"));
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
