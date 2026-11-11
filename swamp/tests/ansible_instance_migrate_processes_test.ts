import { assert, assertEquals, assertMatch } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";
import { hasAnsible, root, Task, transform, git, exists, fixture, migrationProcesses } from "./_support/ansible_instance_migrate.ts";

for (const presetDesired of ["running", "stopped"] as const) {
  Deno.test({
    name: `Process migration carries a ${presetDesired} vp-dev through the shared units and retires only moved host files`,
    ignore: !hasAnsible,
    fn() {
      const f = fixture();
      try {
        const host = migrationProcesses(f, presetDesired);
        const prepared = f.run("container");
        assert(prepared.ok, prepared.output);
        assertEquals(host()[f.migrate.processes[0].name], { state: "started", enabled: true });
        const stopped = f.run("quiesce");
        assert(stopped.ok, stopped.output);
        assertEquals(host()[f.migrate.processes[0].name], { state: "stopped", enabled: false });
        for (const phase of ["workspace", "files"]) {
          const result = f.run(phase);
          assert(result.ok, result.output);
        }
        const installed = f.run("processes");
        assert(installed.ok, installed.output);
        assert(!installed.output.includes("process-secret"));
        for (const unit of f.container.processes) {
          assertEquals(Deno.readTextFileSync(`${f.rootfs}/etc/systemd/system/${unit.name}`), unit.content);
          assertEquals(Deno.readTextFileSync(`${f.rootfs}${unit.environment}`), `QUEUE_TOKEN=process-secret-${unit.id}\n`);
          const enabled = exists(`${f.rootfs}/etc/systemd/system/multi-user.target.wants/${unit.name}`);
          assertEquals(enabled, unit.desired === "running");
          const starts = f.state().systemctl.filter((args: string[]) =>
            ["start", "restart", "enable"].includes(args[0]) && args.includes(unit.name));
          assertEquals(starts.length > 0, unit.desired === "running");
        }
        const retired = f.run("retire");
        assert(retired.ok, retired.output);
        for (const unit of f.migrate.processes) assert(!exists(unit.environment_directory));
        assertEquals(host()[f.migrate.processes[0].name], { state: "stopped", enabled: false });
        assertEquals(host()["orbit-process-999-other.service"], { state: "started", enabled: true });
        assertEquals(Deno.readTextFileSync(`${f.folder}/host/unrelated/orbit-process-999-other.service`), "unrelated unit\n");
        assertEquals(Deno.readTextFileSync(`${f.folder}/host/unrelated/vars.env`), "OTHER_TOKEN=kept\n");
      } finally { f.cleanup(); }
    },
  });

  Deno.test({
    name: `Process migration restores a ${presetDesired} vp-dev only after removing its failed container`,
    ignore: !hasAnsible,
    fn() {
      const f = fixture();
      try {
        const host = migrationProcesses(f, presetDesired);
        f.build();
        const installed = f.run("processes");
        assert(installed.ok, installed.output);
        assertEquals(host()[f.migrate.processes[0].name], { state: "stopped", enabled: false });
        const restored = f.run("restore");
        assert(restored.ok, restored.output);
        assertEquals(host()[f.migrate.processes[0].name], { state: "started", enabled: true });
        assertEquals(host()[f.migrate.processes[1].name], { state: "stopped", enabled: false });
        assertEquals(f.state().containers, {});
        for (const unit of f.migrate.processes) {
          assertEquals(Deno.readTextFileSync(unit.path), "old unit\n");
          assertEquals(Deno.readTextFileSync(`${unit.environment_directory}/old.env`), "OLD_TOKEN=kept\n");
          assertEquals(host()[unit.name], { state: unit.desired === "running" ? "started" : "stopped", enabled: unit.desired === "running" });
        }
        assertEquals(host()["orbit-process-999-other.service"], { state: "started", enabled: true });
        assert(exists(f.checkout));
      } finally { f.cleanup(); }
    },
  });
}
