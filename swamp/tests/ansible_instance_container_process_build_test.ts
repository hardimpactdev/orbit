import { assert, assertEquals, assertThrows } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";
import { hasAnsible, root, Task, transform, git, fixture, worker, paused, environments, restarts } from "./_support/ansible_instance_container.ts";

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
      assertEquals(enabled, ["enable", "--now", "--", "orbit-frankenphp.service", worker.name]);
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
