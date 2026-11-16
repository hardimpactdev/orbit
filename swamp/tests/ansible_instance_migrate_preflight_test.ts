/**
 * The container, quiesce, workspace, files, restore and retire phases of a move, without the full move.
 * The harness is swamp/tests/_support/instance_migrate.ts.
 */
import { assert, assertEquals } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";
import { hasAnsible, root, Task, git, exists, fixture, gitFiles, migrationProcesses, migrationSchedules, real53Environment } from "./_support/instance_migrate.ts";

for (const reason of ["outside checkout", "broken"]) {
  Deno.test({
    name: `${reason} symlinks refuse before downtime, name every path and target, and preserve the source and earlier resources`,
    ignore: !hasAnsible,
    fn() {
      const f = fixture();
      try {
        const schedules = migrationSchedules(f, "enabled");
        const timerStates = Deno.readTextFileSync(`${f.folder}/host-processes.json`);
        f.migrate.retire = [];
        f.write();
        Deno.mkdirSync(`${f.checkout}/public`);
        const target = reason === "broken" ? `${f.checkout}/missing/storage` : f.apps;
        for (const path of ["public/storage", "local package"]) Deno.symlinkSync(target, `${f.checkout}/${path}`);
        const before = Deno.readTextFileSync(`${f.folder}/state.json`);

        const result = f.run("container");

        assert(!result.ok, result.output);
        assertEquals(f.result().error_code, "instance.migrate_symlink_unsupported");
        assertEquals(f.result().preflight_refused, true);
        assertEquals(Deno.readTextFileSync(`${f.folder}/host-processes.json`), timerStates);
        assertEquals(Deno.readTextFileSync(schedules.timerPath), schedules.timer);
        for (const path of ["public/storage", "local package"]) {
          assert(f.result().error_message.includes(path), f.result().error_message);
          assertEquals(Deno.readLinkSync(`${f.checkout}/${path}`), target);
        }
        assert(f.result().error_message.includes(target), f.result().error_message);
        assert(f.result().error_message.includes(reason), f.result().error_message);
        assert(f.run("restore").ok);
        assert(exists(f.migrate.awake.path));
        assertEquals(Deno.readTextFileSync(`${f.folder}/state.json`), before);
        assert(!exists(`${f.folder}/calls`), "No container command may run after a preflight refusal.");
      } finally {
        f.cleanup();
      }
    },
  });
}
Deno.test({
  name: "a link introduced after preparation refuses quiesce with zero downtime and removes only the prepared container",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      f.migrate.retire = [];
      f.write();
      const processes = migrationProcesses(f, "running");
      const before = processes();
      const markerBefore = Deno.statSync(f.migrate.awake.path).mtime?.getTime();
      const prepared = f.run("container");
      assert(prepared.ok, prepared.output);
      Deno.symlinkSync(f.apps, `${f.checkout}/late-link`);
      const refused = f.run("quiesce");
      assert(!refused.ok, refused.output);
      assertEquals(f.result().error_code, "instance.migrate_symlink_unsupported");
      assertEquals(f.result().preflight_refused, false);
      assertEquals(processes(), before);
      assert(!exists(`${f.folder}/run/quiesced`));
      const restored = f.run("restore");
      assert(restored.ok, restored.output);
      assertEquals(f.state().containers, {});
      assertEquals(f.state()["network-acls"].filter((acl: {name: string}) => acl.name !== "orbitinst0-egress"), []);
      assertEquals(f.ufw(), []);
      assertEquals(processes(), before);
      assertEquals(Deno.statSync(f.migrate.awake.path).mtime?.getTime(), markerBefore);
      assertEquals(Deno.readLinkSync(`${f.checkout}/late-link`), f.apps);
    } finally {
      f.cleanup();
    }
  },
});
Deno.test({
  name: "file targets with directory suffixes refuse before downtime and in the copied tree without repairing links",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      f.migrate.retire = [];
      f.write();
      Deno.mkdirSync(`${f.checkout}/public`);
      Deno.mkdirSync(`${f.checkout}/storage`);
      const file = `${f.checkout}/storage/file`;
      Deno.writeTextFileSync(file, "data\n");
      const targets = new Map<string, string>();
      for (const [kind, prefix] of [["absolute", f.checkout], ["relative", ".."]]) {
        for (const [label, suffix] of [["slash", "/"], ["dot", "/."]]) {
          const path = `public/${kind}-${label}`;
          const target = `${prefix}/storage/file${suffix}`;
          targets.set(path, target);
          Deno.symlinkSync(target, `${f.checkout}/${path}`);
        }
      }
      const preflight = f.run("container");
      assert(!preflight.ok, preflight.output);
      assertEquals(f.result().error_code, "instance.migrate_symlink_unsupported");
      assertEquals(f.result().preflight_refused, true);
      for (const [path, target] of targets) {
        assert(f.result().error_message.includes(path), f.result().error_message);
        assert(f.result().error_message.includes(target), f.result().error_message);
        assertEquals(Deno.readLinkSync(`${f.checkout}/${path}`), target);
      }
      // Make every link valid for preflight, then copy a file in place of that directory.
      Deno.removeSync(file);
      Deno.mkdirSync(file);
      for (const phase of ["container", "quiesce", "workspace"]) {
        const result = f.run(phase);
        assert(result.ok, result.output);
      }
      Deno.writeTextFileSync(`${f.folder}/bin/tar`, `#!/usr/bin/env python3
import os, sys
if os.environ.get("ORBIT_CHECKOUT") == ${JSON.stringify(f.checkout)}:
    os.rmdir(${JSON.stringify(file)})
    with open(${JSON.stringify(file)}, "w") as copied:
        copied.write("copied file")
os.execv("/usr/bin/tar", ["tar", *sys.argv[1:]])
`);
      Deno.chmodSync(`${f.folder}/bin/tar`, 0o755);
      const copied = f.run("files");
      assert(!copied.ok, copied.output);
      // This race changes porcelain status after the Git export; refuse it before
      // inspecting the copied links, without losing the developer's new file.
      assertEquals(f.result().error_code, "instance.migrate_git_source_changed");
      assert(f.result().preflight_refused !== true);
      for (const [path, target] of targets) {
        assertEquals(Deno.readLinkSync(`${f.rootfs}/srv/workspace/${path}`), target);
        assertEquals(Deno.readLinkSync(`${f.checkout}/${path}`), target);
      }
      const restored = f.run("restore");
      assert(restored.ok, restored.output);
      assert(!exists(f.rootfs));
      assert(exists(f.migrate.awake.path));
      assertEquals(Deno.readTextFileSync(file), "copied file");
    } finally {
      f.cleanup();
    }
  },
});
for (const change of ["absolute", "relative", "outside", "broken"]) {
  Deno.test({
    name: `the exact archive tree handles ${change} link changes after preflight and preserves rollback`,
    ignore: !hasAnsible,
    fn() {
      const f = fixture();
      try {
        f.migrate.retire = [];
        f.write();
        Deno.mkdirSync(`${f.checkout}/public`);
        Deno.mkdirSync(`${f.checkout}/storage/app/public`, { recursive: true });
        Deno.mkdirSync(`${f.checkout}/alternate`);
        Deno.writeTextFileSync(`${f.checkout}/alternate/file.txt`, "copied target\n");
        const link = `${f.checkout}/public/storage`;
        Deno.symlinkSync(`${f.checkout}/storage/app/public`, link);
        const target = change === "absolute" ? `${f.checkout}/alternate` : change === "relative" ? "../alternate"
          : change === "outside" ? f.apps : `${f.checkout}/missing`;
        // A developer changes the source at the last possible point before tar copies it, after any source-side scan.
        Deno.writeTextFileSync(`${f.folder}/bin/tar`, `#!/usr/bin/env python3
import os, sys
if os.environ.get("ORBIT_CHECKOUT") == ${JSON.stringify(f.checkout)}:
    os.unlink(${JSON.stringify(link)})
    os.symlink(${JSON.stringify(target)}, ${JSON.stringify(link)})
os.execv("/usr/bin/tar", ["tar", *sys.argv[1:]])
`);
        Deno.chmodSync(`${f.folder}/bin/tar`, 0o755);
        for (const phase of ["container", "quiesce", "workspace"]) {
          const result = f.run(phase);
          assert(result.ok, result.output);
        }

        const result = f.run("files");

        assertEquals(Deno.readLinkSync(link), target);
        assert(!result.ok, result.output);
        assertEquals(f.result().error_code, "instance.migrate_git_source_changed");
        const restored = f.run("restore");
        assert(restored.ok, restored.output);
        assert(!exists(f.rootfs));
        assert(exists(f.migrate.awake.path));
        assertEquals(Deno.readLinkSync(link), target);
      } finally {
        f.cleanup();
      }
    },
  });
}
Deno.test({
  name: "untracked files inside a tracked submodule retain the named retirement refusal even when Git config ignores it",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      const source = `${f.folder}/module-source`;
      Deno.mkdirSync(source);
      git(source, "init", "--quiet");
      Deno.writeTextFileSync(`${source}/tracked.txt`, "module seed\n");
      git(source, "add", ".");
      git(source, "commit", "--quiet", "-m", "module seed");
      git(f.checkout, "-c", "protocol.file.allow=always", "submodule", "add", "--quiet", source, "sub");
      git(f.checkout, "commit", "--quiet", "-m", "tracked submodule");
      git(f.checkout, "update-ref", "refs/remotes/origin/main", "HEAD");
      git(f.checkout, "config", "submodule.sub.ignore", "all");
      Deno.writeTextFileSync(`${f.checkout}/sub/new-untracked`, "cannot be copied by the parent archive\n");
      assertEquals(git(f.checkout, "status", "--porcelain=v1", "--untracked-files=no"), "");
      const refused = f.run("container");
      assert(!refused.ok, refused.output);
      assertEquals(f.result().error_code, "instance.migrate_retirement_blocked");
      assert(f.result().error_message.includes("sub"));
      assert(f.result().error_message.includes("parent checkout archive"));
      assertEquals(f.state().containers ?? {}, {});
      assert(exists(f.migrate.awake.path));
      assertEquals(Deno.readTextFileSync(`${f.checkout}/sub/new-untracked`), "cannot be copied by the parent archive\n");
    } finally {
      f.cleanup();
    }
  },
});
Deno.test({
  name: "linked worktree quiesce carries tracked changes made after the container preflight",
  ignore: !hasAnsible,
  fn() {
    const f = fixture("feature");
    try {
      git(f.main, "worktree", "add", "--quiet", "-b", "sibling", f.apps + "/sibling");
      const prepared = f.run("container");
      assert(prepared.ok, prepared.output);
      Deno.writeTextFileSync(f.checkout + "/index.php", "late tracked edit");
      const before = gitFiles(f.apps);
      const state = f.state();
      const refused = f.run(["quiesce", "workspace", "files"]);
      assert(refused.ok, refused.output);
      assertEquals(f.read("index.php"), "late tracked edit");
      assertEquals(gitFiles(f.apps), before);
      assertEquals(f.state().containers["instance-8"].status, state.containers["instance-8"].status);
      assert(!exists(f.migrate.awake.path));
    } finally { f.cleanup(); }
  },
});
Deno.test({
  name: "a checkout on another branch than the recorded one is refused before anything changes",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      git(f.checkout, "stash", "push", "--quiet", "--include-untracked");
      git(f.checkout, "checkout", "--quiet", "-b", "elsewhere");
      const quiesce = f.run("container");
      assert(!quiesce.ok);
      assertEquals(f.result().error_code, "instance.migrate_branch_changed");
      assertEquals(f.result().preflight_refused, true);
      assert(f.result().error_message.includes("All data stays"));
      // The Route still serves, and no container was made.
      assert(exists(f.migrate.awake.path));
      assertEquals(f.state().containers ?? {}, {});
    } finally {
      f.cleanup();
    }
  },
});
Deno.test({
  name: "a database server that the container cannot reach refuses the move, and the copy leaves nothing behind",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      const status = git(f.checkout, "status", "--porcelain");
      // Nothing listens on this port.
      f.migrate.databases.push({ host: "127.0.0.1", port: 1 });
      f.write();
      const processes = migrationProcesses(f, "running");
      const processBefore = processes();
      const markerBefore = Deno.statSync(f.migrate.awake.path).mtime?.getTime();
      const files = f.run("container");
      assert(!files.ok);
      assertEquals(f.result(), { error_code: "instance.database_unreachable" });
      assert(exists(f.migrate.awake.path));
      assertEquals(processes(), processBefore);
      assert(!exists(`${f.folder}/run/quiesced`));
      assert(!exists(`${f.rootfs}/srv/workspace/.git`));
      assertEquals([...Deno.readDirSync(`${f.rootfs}/srv`)].map((entry) => entry.name), ["workspace"]);
      assertEquals([...Deno.readDirSync(`${f.folder}/mirror`)].map((entry) => entry.name), ["7.git"]);
      const restore = f.run("restore");
      assert(restore.ok, restore.output);
      assertEquals(f.state().containers, {});
      assertEquals(f.state()["network-acls"].filter((acl: {name: string}) => acl.name !== "orbitinst0-egress"), []);
      assertEquals(f.ufw(), []);
      assert(exists(f.migrate.awake.path));
      assertEquals(git(f.checkout, "status", "--porcelain"), status);
      assertEquals(f.result(), { error_code: "instance.database_unreachable" });
      assertEquals(processes(), processBefore);
      assertEquals(Deno.statSync(f.migrate.awake.path).mtime?.getTime(), markerBefore);
    } finally {
      f.cleanup();
    }
  },
});
Deno.test({
  name: "preflight names linked and detached worktrees and every tracked change before any Node change",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      const linked = `${f.apps}/linked with spaces`;
      const detached = `${f.apps}/detached`;
      git(f.checkout, "worktree", "add", "--quiet", "-b", "extra", linked);
      git(f.checkout, "worktree", "add", "--quiet", "--detach", detached);
      Deno.writeTextFileSync(`${f.checkout}/index.php`, "changed\n");
      Deno.writeTextFileSync(`${f.checkout}/staged.php`, "staged edit\n");
      git(f.checkout, "add", "staged.php");
      git(f.checkout, "mv", "note.txt", "renamed with spaces.txt");
      Deno.removeSync(`${f.checkout}/local.php`);
      Deno.writeTextFileSync(`${f.checkout}/untracked.txt`, "not ignored\n");
      const before = git(f.checkout, "status", "--porcelain=v1", "--untracked-files=all");
      // Retained resources from a previous failure must survive both preflight and its restore path.
      assert(f.run("container", true).ok);
      const state = f.state();
      const rules = f.ufw();
      const quiesce = f.run("container");
      assert(!quiesce.ok, quiesce.output);
      const receipt = f.result();
      assertEquals(receipt.error_code, "instance.migrate_retirement_blocked");
      for (const path of [f.checkout, linked, detached, "index.php", "staged.php", "note.txt", "renamed with spaces.txt", "local.php"]) {
        assert(receipt.error_message.includes(path), receipt.error_message);
      }
      assert(receipt.error_message.includes("Other worktrees (2 total; first 20)"));
      assert(receipt.error_message.includes("Changed files (4 total; first 20)"));
      assert(!receipt.error_message.includes("untracked.txt"));
      assert(exists(f.migrate.awake.path));
      assertEquals(f.state(), state);
      const restore = f.run("restore");
      assert(restore.ok, restore.output);
      assertEquals(f.state(), state);
      assertEquals(f.ufw(), rules);
      assertEquals(git(f.checkout, "status", "--porcelain=v1", "--untracked-files=all"), before);
      assertEquals(f.result(), receipt);
    } finally {
      f.cleanup();
    }
  },
});
Deno.test({
  name: "retirement error lists stop at 20 entries with total counts",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      for (let i = 0; i < 22; i++) {
        Deno.writeTextFileSync(`${f.checkout}/file-${String(i).padStart(2, "0")}.txt`, "new\n");
        git(f.checkout, "worktree", "add", "--quiet", "--detach", `${f.apps}/worktree-${String(i).padStart(2, "0")}`);
      }
      git(f.checkout, "add", ".");
      git(f.checkout, "commit", "--quiet", "-m", "tracked files for bounded errors");
      git(f.checkout, "update-ref", "refs/remotes/origin/main", "HEAD");
      for (let i = 0; i < 22; i++) Deno.writeTextFileSync(`${f.checkout}/file-${String(i).padStart(2, "0")}.txt`, "changed\n");
      const quiesce = f.run("container");
      assert(!quiesce.ok, quiesce.output);
      const message = f.result().error_message;
      assert(message.includes("Other worktrees (22 total; first 20)"));
      assert(message.includes("Changed files (22 total; first 20)"));
      assert(message.includes("file-19.txt"));
      assert(message.includes("worktree-19"));
      assert(!message.includes("file-20.txt"));
      assert(!message.includes("worktree-20"));
      assert(exists(f.migrate.awake.path));
      assertEquals(f.state().containers ?? {}, {});
    } finally {
      f.cleanup();
    }
  },
});
for (const damaged of ["missing-service", "missing-timer", "foreign-service", "changed-calendar", "masked-timer", "activating-service", "service-dropin", "timer-dropin", "foreign-script", "foreign-callback", "legacy-script-drift", "legacy-context-drift", "legacy-missing-script", "legacy-callback-drift"] as const) {
  Deno.test({
    name: `Schedule preflight refuses ${damaged} before stopping any timer or Process`,
    ignore: !hasAnsible,
    fn() {
      const f = fixture();
      try {
        const schedules = migrationSchedules(f, "enabled", damaged === "legacy-callback-drift" ? "direct" : damaged.startsWith("legacy-") ? "agent" : "current");
        if (damaged === "missing-service") Deno.removeSync(schedules.servicePath);
        else if (damaged === "missing-timer") Deno.removeSync(schedules.timerPath);
        else if (damaged === "foreign-service") Deno.writeTextFileSync(schedules.servicePath, "[Service]\nExecStart=/bin/true\n");
        else if (damaged === "foreign-script") Deno.writeTextFileSync(schedules.scriptPath, "# foreign script\n");
        else if (damaged === "foreign-callback") Deno.writeTextFileSync(schedules.callbackPath, "# foreign callback\n");
        else if (damaged === "legacy-script-drift") Deno.writeTextFileSync(schedules.scriptPath, schedules.script.replace("php artisan schedule:run", "/outside/custom-command"));
        else if (damaged === "legacy-context-drift") Deno.writeTextFileSync(schedules.scriptPath, schedules.script.replace(f.checkout, "/outside/workspace"));
        else if (damaged === "legacy-missing-script") Deno.removeSync(schedules.scriptPath);
        else if (damaged === "legacy-callback-drift") Deno.writeTextFileSync(schedules.callbackPath, schedules.callback.replace("status=error", "status=success"));
        else if (damaged === "changed-calendar") Deno.writeTextFileSync(schedules.timerPath, schedules.timer.replace(schedules.schedule.calendar, "daily"));
        else if (damaged === "masked-timer") schedules.states[schedules.schedule.timer].status = { UnitFileState: "masked" };
        else if (damaged === "activating-service") schedules.states[schedules.schedule.service].status = { ActiveState: "activating" };
        else schedules.states[damaged === "service-dropin" ? schedules.schedule.service : schedules.schedule.timer].status = { DropInPaths: "/etc/systemd/system/example.d/override.conf" };
        schedules.writeStates();
        const before = schedules.host();
        const quiesce = f.run("quiesce");
        assert(!quiesce.ok, quiesce.output);
        const receipt = f.result();
        assertEquals(receipt.error_code, "schedule.container_artifact_unsupported");
        assert(receipt.error_message.includes(schedules.schedule.id), receipt.error_message);
        assert(receipt.error_message.includes(schedules.schedule.name), receipt.error_message);
        assertEquals(receipt.preflight_refused, false);
        assert(exists(f.migrate.awake.path));
        assertEquals(f.state().containers ?? {}, {});
        assertEquals(schedules.host(), before);
        const restored = f.run("restore");
        assert(restored.ok, restored.output);
        assertEquals(schedules.host(), before);
        schedules.assertUnrelated();
      } finally { f.cleanup(); }
    },
  });
}
Deno.test({
  name: "Schedule preflight checks every Schedule before stopping the first timer",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      const first = migrationSchedules(f, "enabled");
      const id = "33333333-3333-4333-8333-000000000003";
      const second = { ...first.schedule, id, name: "second-scheduler", service: `orbit-schedule-${id}.service`, timer: `orbit-schedule-${id}.timer` };
      const systemd = `${f.folder}/host/systemd`;
      Deno.writeTextFileSync(`${systemd}/${second.service}`, "[Service]\nExecStart=/bin/true\n");
      Deno.writeTextFileSync(`${systemd}/${second.timer}`, first.timer.replaceAll(first.schedule.id, id));
      first.states[second.service] = { state: "stopped", enabled: false };
      first.states[second.timer] = { state: "started", enabled: true };
      first.writeStates();
      f.migrate.schedules.push(second);
      f.write();
      const before = first.host();
      const refused = f.run("quiesce");
      assert(!refused.ok, refused.output);
      assertEquals(f.result().error_code, "schedule.container_artifact_unsupported");
      assert(f.result().error_message.includes(second.id));
      assert(f.result().error_message.includes(second.name));
      assertEquals(first.host(), before);
      assertEquals(Deno.readTextFileSync(first.servicePath), first.service);
      assert(exists(f.migrate.awake.path));
      assert(!exists(`${f.folder}/run/schedule-timers.json`));
    } finally { f.cleanup(); }
  },
});
for (const pathKind of ["absolute-command-symlink", "relative-command-symlink", "directory-symlink", "missing-directory", "internal-command-symlink", "internal-directory-symlink", "extensionless-command-symlink"] as const) {
  Deno.test({
    name: `Schedule preflight refuses a ${pathKind} that cannot move before downtime`,
    ignore: !hasAnsible,
    fn() {
      const f = fixture();
      try {
        // A retained default skips Git retirement, so this exercises the Schedule path preflight itself.
        f.migrate.retire = [];
        const external = `${f.folder}/external`;
        Deno.mkdirSync(external);
        Deno.writeTextFileSync(`${external}/command.php`, "<?php // external workspace\n");
        const commandLink = `${f.checkout}/${pathKind === "extensionless-command-symlink" ? "worker" : "linked.php"}`;
        Deno.writeTextFileSync(`${f.checkout}/command.php`, "<?php // workspace command\n");
        Deno.symlinkSync(pathKind === "internal-command-symlink" ? `${f.checkout}/command.php` : `${external}/command.php`, commandLink);
        const directoryLink = `${f.checkout}/linked-directory`;
        Deno.mkdirSync(`${f.checkout}/jobs`);
        Deno.symlinkSync(pathKind === "internal-directory-symlink" ? `${f.checkout}/jobs` : external, directoryLink);
        const sourceDirectory = pathKind.endsWith("directory-symlink") ? directoryLink : pathKind === "missing-directory" ? `${f.checkout}/missing-directory` : f.checkout;
        const command = (pathKind === "absolute-command-symlink" || pathKind === "internal-command-symlink") ? `php ${commandLink}` : pathKind === "relative-command-symlink" ? "php linked.php" : pathKind === "extensionless-command-symlink" ? "php worker" : "php artisan schedule:run";
        const schedules = migrationSchedules(f, "enabled", "agent", command, sourceDirectory);
        if (pathKind.endsWith("command-symlink")) schedules.schedule.source_command_paths = [commandLink];
        f.write();
        const before = schedules.host();
        const refused = f.run("quiesce");
        assert(!refused.ok, refused.output);
        assertEquals(f.result().error_code, pathKind.endsWith("command-symlink") ? "schedule.container_command_unsupported" : "schedule.container_path_unsupported");
        assert(f.result().error_message.includes(schedules.schedule.id), f.result().error_message);
        assert(f.result().error_message.includes(schedules.schedule.name), f.result().error_message);
        assertEquals(f.result().preflight_refused, false);
        assertEquals(schedules.host(), before);
        assertEquals(Deno.readTextFileSync(schedules.servicePath), schedules.service);
        assertEquals(Deno.readTextFileSync(schedules.scriptPath), schedules.script);
        assert(exists(f.migrate.awake.path));
        assertEquals(f.state().containers ?? {}, {});
        const restored = f.run("restore");
        assert(restored.ok, restored.output);
        assertEquals(schedules.host(), before);
        schedules.assertUnrelated();
      } finally { f.cleanup(); }
    },
  });
}
Deno.test({
  name: "Schedule rollback restores all timers after only some source timers stopped",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      const first = migrationSchedules(f, "enabled");
      const id = "44444444-4444-4444-8444-000000000004";
      const second = { ...first.schedule, id, name: "second-scheduler", service: `orbit-schedule-${id}.service`, timer: `orbit-schedule-${id}.timer` };
      const systemd = `${f.folder}/host/systemd`;
      Deno.writeTextFileSync(`${systemd}/${second.service}`, first.service.replaceAll(first.schedule.id, id));
      Deno.writeTextFileSync(`${systemd}/${second.timer}`, first.timer.replaceAll(first.schedule.id, id));
      first.states[second.service] = { state: "stopped", enabled: false };
      first.states[second.timer] = { state: "started", enabled: true };
      first.writeStates();
      f.migrate.schedules.push(second);
      f.write();
      const before = first.host();
      const failed = f.run("quiesce", false, undefined, { ORBIT_FIXTURE_FAIL_SYSTEMD_STOP: second.timer });
      assert(!failed.ok, failed.output);
      assertEquals(f.result().error_code, "instance.migrate_quiesce_failed");
      assertEquals(first.host()[first.schedule.timer], { state: "stopped", enabled: false });
      assertEquals(first.host()[second.timer], before[second.timer]);
      const restored = f.run("restore");
      assert(restored.ok, restored.output);
      assertEquals(first.host(), before);
      assertEquals(Deno.readTextFileSync(first.servicePath), first.service);
      assertEquals(Deno.readTextFileSync(first.timerPath), first.timer);
      assertEquals(Deno.readTextFileSync(`${systemd}/${second.service}`), first.service.replaceAll(first.schedule.id, id));
      assert(exists(f.checkout));
      assert(exists(f.migrate.awake.path));
      first.assertUnrelated();
    } finally { f.cleanup(); }
  },
});
Deno.test({
  name: "a copied tracked-file mismatch fails before cutover and rollback preserves the source Git state",
  ignore: !hasAnsible,
  fn() {
    const f = fixture("default", true);
    try {
      const shadow = f.folder + "/untrusted-python-module-ran";
      Deno.writeTextFileSync(f.checkout + "/json.py", `from pathlib import Path
Path(${JSON.stringify(shadow)}).write_text("executed")
raise RuntimeError("untrusted checkout module")
`);
      const prepared = f.run(["container", "quiesce", "workspace"]);
      assert(prepared.ok, prepared.output);
      const before = gitFiles(f.apps);
      const file = f.folder + "/tasks/instance-migrate-phase.yml";
      const tasks = parse(Deno.readTextFileSync(file)) as Task[];
      const copy = tasks.find((task) => task.name === "Copy the checkout's files into the workspace")!;
      const parts = copy.block as Task[];
      const verify = parts.findIndex((task) => task.name === "Import and verify all local Git state before path rewrites");
      parts.splice(verify, 0, {
        name: "Fixture corrupts a copied tracked file",
        "ansible.builtin.command": {argv: ["python3", "-c",
          "from pathlib import Path; Path(" + JSON.stringify(f.rootfs + "/srv/workspace/index.php") + ").write_text('corrupt copy')"]},
        changed_when: true,
      });
      Deno.writeTextFileSync(file, stringify(tasks));
      const copied = f.run("files");
      assert(!copied.ok, copied.output);
      assertEquals(f.result().error_code, "instance.migrate_git_state_mismatch");
      assert(!exists(shadow), "the receiver imported a repository-controlled Python module");
      assertEquals(gitFiles(f.apps), before);
      const restored = f.run("restore");
      assert(restored.ok, restored.output);
      assertEquals(f.state().containers, {});
      assertEquals(gitFiles(f.apps), before);
      assertEquals([...Deno.readDirSync(f.folder + "/mirror")].map((entry) => entry.name), []);
      git(f.checkout, "fsck", "--full", "--strict");
    } finally { f.cleanup(); }
  },
});
for (const docker of [false, true]) for (const failures of [1, 9]) {
  Deno.test({
    name: `${docker ? "Docker" : "direct"} bridge endpoint add with ${failures === 1 ? "transient" : "persistent"} missing-veth failure stays before the migration probe and downtime`,
    ignore: !hasAnsible,
    fn() {
      const f = fixture();
      try {
        f.migrate.retire = [];
        f.container.bridge_endpoints = [{host: "10.44.0.7", port: 5432, docker}];
        f.migrate.databases = [{host: "10.44.0.7", port: 5432}];
        f.write();
        const state = f.state();
        state.bridgeVethFailures = failures;
        state.bridgeProbe = {host: "10.44.0.7", port: 5432,
          translatedHost: docker ? "172.17.0.2" : "10.44.0.7", translatedPort: docker ? 5433 : 5432};
        Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(state));
        if (docker) Deno.writeTextFileSync(`${f.folder}/nat.txt`, "-A DOCKER -d 10.44.0.7/32 ! -i docker0 -p tcp -m tcp --dport 5432 -j DNAT --to-destination 172.17.0.2:5433\n");
        const before = Deno.readTextFileSync(`${f.checkout}/.env`);
        const result = f.run("container");
        assertEquals(result.ok, failures === 1, result.output);
        assert(exists(f.migrate.awake.path));
        assert(!exists(`${f.folder}/run/quiesced`));
        assertEquals(Deno.readTextFileSync(`${f.checkout}/.env`), before);
        const calls = Deno.readTextFileSync(`${f.folder}/calls`).trim().split("\n").map((line) => JSON.parse(line) as string[]);
        const writes = calls.filter((call) => call[0] === "curl" && call.includes("PUT"));
        assertEquals(writes.length, failures === 1 ? 2 : 5);
        const probe = calls.findIndex((call) => call.some((arg) => arg.includes("/dev/tcp/$1/$2")));
        if (failures === 1) assert(probe > calls.findIndex((call) => call === writes.at(-1)));
        else assertEquals(probe, -1, "a failed bridge admission must not probe or quiesce");
      } finally { f.cleanup(); }
    },
  });
}
for (const mappings of [0, 2]) {
  Deno.test({
    name: `${mappings} local Docker DNAT mappings refuse migration before a probe or downtime with the named error`,
    ignore: !hasAnsible,
    fn() {
      const f = fixture();
      try {
        f.migrate.retire = [];
        f.container.bridge_endpoints = [{host: "10.44.0.7", port: 3306, docker: true}];
        f.migrate.databases = [{host: "10.44.0.7", port: 3306}];
        f.write();
        Deno.writeTextFileSync(`${f.folder}/nat.txt`, Array.from({length: mappings}, (_, i) => `-A DOCKER -d 10.44.0.7/32 ! -i docker0 -p tcp -m tcp --dport 3306 -j DNAT --to-destination 172.17.0.${i + 2}:3306`).join("\n"));
        const result = f.run("container");
        assert(!result.ok, result.output);
        assertEquals(f.result().error_code, "instance.container_endpoint_unresolved", result.output);
        assert(exists(f.migrate.awake.path));
        assert(!exists(`${f.folder}/run/quiesced`));
        const calls = Deno.readTextFileSync(`${f.folder}/calls`);
        assert(!calls.includes('"PUT"'));
        assert(!calls.includes('/dev/tcp/$1/$2'));
        assertEquals(JSON.parse(Deno.readTextFileSync(`${f.folder}/bridge-policy/health.json`)).error_code, "instance.container_endpoint_unresolved");
      } finally {f.cleanup();}
    },
  });
}
// Run the real phase before forcing its exit. Restoration must unwind effects that already happened, not merely a no-op failure.
for (const phase of ["quiesce", "workspace", "files", "processes", "seed"]) {
  Deno.test({
    name: `a forced ${phase} failure after quiesce restores host ownership and removes every container reservation effect`,
    ignore: !hasAnsible,
    fn() {
      const f = fixture();
      try {
        const host = migrationProcesses(f, "running");
        const before = host();
        const head = git(f.checkout, "rev-parse", "HEAD");
        for (const prior of ["container", "quiesce", "workspace", "files", "processes"]) {
          if (prior === phase) break;
          const done = f.run(prior);
          assert(done.ok, done.output);
        }
        f.fault(phase);
        const failed = f.run(phase);
        assert(!failed.ok, failed.output);
        assert(failed.output.includes("forced migration failure"), failed.output);
        const restored = f.run("restore");
        assert(restored.ok, restored.output);
        assertEquals(host(), before);
        assertEquals(f.state().containers, {});
        assertEquals(f.state()["network-acls"], [{ name: "orbitinst0-egress", egress: [], ingress: [] }]);
        assertEquals(f.ufw(), []);
        assert(!exists(f.container.mirror));
        assert(exists(f.migrate.awake.path));
        assertEquals(git(f.checkout, "rev-parse", "HEAD"), head);
      } finally {
        f.cleanup();
      }
    },
  });
}
for (const [removedAfterPrepare, customPath] of [[false, "/opt/custom/tool"], [false, "/opt/custom/vp"], [true, null]] as const) {
  Deno.test({
    name: `an unavailable moved Process executable ${customPath ?? "removed runtime"} refuses ${removedAfterPrepare ? "quiesce" : "container preparation"} before stopping any host writer`,
    ignore: !hasAnsible,
    fn() {
      const f = fixture();
      try {
        const host = migrationProcesses(f, "running");
        const before = host();
        const process = f.migrate.processes[0];
        const path = customPath ?? process.executables[0];
        if (removedAfterPrepare) {
          const prepared = f.run("container");
          assert(prepared.ok, prepared.output);
        } else {
          process.executables = [path];
          f.write();
        }
        const state = f.state();
        state.unavailableExecutables = [path];
        Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(state));
        const refused = f.run(removedAfterPrepare ? "quiesce" : "container");
        assert(!refused.ok, refused.output);
        const receipt = f.result();
        assertEquals(receipt.error_code, "instance.migrate_process_binary_unavailable");
        assert(receipt.error_message.includes(process.name), JSON.stringify(receipt));
        assert(receipt.error_message.includes(path), JSON.stringify(receipt));
        assert(!exists(`${f.folder}/run/quiesced`));
        assert(exists(f.migrate.awake.path));
        assertEquals(host(), before);
        assert(exists(f.checkout));
        const restored = f.run("restore");
        assert(restored.ok, restored.output);
        assertEquals(host(), before);
        assertEquals(f.state().containers, {});
        assert(exists(f.migrate.awake.path));
      } finally { f.cleanup(); }
    },
  });
}
Deno.test({
  name: "the binary preflight names every unavailable Process and executable in one refusal",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      const host = migrationProcesses(f, "running");
      const before = host();
      const first = f.migrate.processes[0];
      const second = f.migrate.processes[1];
      first.executables = ["/opt/custom/tool"];
      second.executables = ["/opt/custom/vp"];
      f.write();
      const state = f.state();
      state.unavailableExecutables = [first.executables[0], second.executables[0]];
      Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(state));
      const refused = f.run("container");
      assert(!refused.ok, refused.output);
      const receipt = f.result();
      assertEquals(receipt.error_code, "instance.migrate_process_binary_unavailable");
      for (const process of [first, second]) {
        assert(receipt.error_message.includes(process.name), JSON.stringify(receipt));
        assert(receipt.error_message.includes(process.executables[0]), JSON.stringify(receipt));
      }
      assertEquals(host(), before);
      assert(exists(f.migrate.awake.path));
      assert(!exists(`${f.folder}/run/quiesced`));
    } finally { f.cleanup(); }
  },
});
Deno.test({
  name: "an unsafe copied app service refuses before downtime and restore changes nothing",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    const listener = Deno.listen({hostname:"127.0.0.1",port:0});
    const port = (listener.addr as Deno.NetAddr).port;
    try {
      Deno.mkdirSync(`${f.checkout}/apps/mail`, { recursive: true });
      git(f.checkout, "config", "core.excludesFile", "/dev/null");
      Deno.writeTextFileSync(`${f.checkout}/.env`, "DB_CONNECTION=mysql\nDB_HOST=' 127.0.0.1 '\nDB_PORT=3308\nDB_PASSWORD=private-db-password\n");
      Deno.writeTextFileSync(`${f.checkout}/apps/mail/.env`, `MAIL_MAILER=smtp\nMAIL_HOST=127.0.0.1\nMAIL_PORT=${port}\nMAIL_PASSWORD=private-mail-password\n`);
      git(f.checkout, "add", "--force", "apps/mail/.env");
      git(f.checkout, "commit", "--quiet", "-m", "nested app");
      git(f.checkout, "update-ref", "refs/remotes/origin/main", "HEAD");
      const sourceUnit = `${f.folder}/source.service`;
      Deno.writeTextFileSync(sourceUnit, `[Service]\nEnvironmentFile=-"${f.checkout}/apps/mail/.env"\nExecStart=/bin/true\n`);
      f.migrate.processes.push({ name: "writer.service", path: sourceUnit, environment_directory: `${f.folder}/source-env`, desired: "running", executables: ["/bin/true"] });
      const schedules = migrationSchedules(f, "enabled");
      Deno.writeTextFileSync(`${f.folder}/host-processes.json`, JSON.stringify({ ...schedules.states, "writer.service": { state: "running", enabled: true } }));
      const before = Deno.readTextFileSync(`${f.folder}/host-processes.json`);
      f.migrate.databases = [{ host: "127.0.0.1", port: 1 }];
      f.write();
      const stateBefore = f.state();

      Deno.mkdirSync(`${f.checkout}/second`);
      const primaryEnvironment = Deno.readTextFileSync(`${f.checkout}/.env`);
      const sourceContent = Deno.readTextFileSync(sourceUnit);
      Deno.writeTextFileSync(`${f.checkout}/.env`, "DB_CONNECTION=sqlite\nMAIL_MAILER=log\n");
      Deno.writeTextFileSync(`${f.checkout}/second/.env`, `DB_CONNECTION=mysql\nDB_HOST=127.0.0.1\nDB_PORT=${port}\n`);
      Deno.writeTextFileSync(sourceUnit, `[Service]\nWorkingDirectory=${f.checkout}/second\nEnvironmentFile=-${f.checkout}/.env\nExecStart=/bin/true\n`);
      const secondRefused = f.run("container");
      assert(!secondRefused.ok, secondRefused.output);
      assertEquals(f.result().error_code, "instance.migrate_env_service_unreachable");
      assert(f.result().error_message.includes(`second/.env: DB_HOST=127.0.0.1:${port}`));
      assert(!exists(`${f.folder}/calls`), "a second application refusal precedes every probe");
      assert(!exists(`${f.folder}/run/quiesced`));
      assertEquals(Deno.readTextFileSync(`${f.folder}/host-processes.json`), before);
      assertEquals(f.state(), stateBefore);
      Deno.removeSync(`${f.checkout}/second`, { recursive: true });
      Deno.writeTextFileSync(`${f.checkout}/.env`, primaryEnvironment);
      Deno.writeTextFileSync(sourceUnit, sourceContent);

      const refused = f.run("container");

      assert(!refused.ok, refused.output);
      assertEquals(f.result().error_code, "instance.migrate_env_service_unreachable");
      assertEquals(f.result().preflight_refused, true);
      assert(f.result().error_message.includes("DB_HOST=127.0.0.1:3308"));
      assert(f.result().error_message.includes(`apps/mail/.env: MAIL_HOST=127.0.0.1:${port}`));
      const output = refused.output + JSON.stringify(f.result());
      assert(!output.includes("private-db-password"));
      assert(!output.includes("private-mail-password"));
      assert(exists(f.migrate.awake.path));
      assertEquals(Deno.readTextFileSync(`${f.folder}/host-processes.json`), before);
      assertEquals(f.state(), stateBefore);
      assert(!exists(`${f.folder}/calls`), "an environment refusal must precede every container command and reach probe");
      assert(!exists(`${f.folder}/run/quiesced`));
      const restore = f.run("restore");
      assert(restore.ok, restore.output);
      assert(exists(f.migrate.awake.path));
      assertEquals(Deno.readTextFileSync(`${f.folder}/host-processes.json`), before);
      assertEquals(f.state(), stateBefore);
      assert(!exists(`${f.folder}/calls`), "an environment refusal must precede every container command and reach probe");
      assert(!exists(`${f.folder}/run/quiesced`));

      f.migrate.environment.targets.push({ host: "127.0.0.1", port: 3308 }, { host: "127.0.0.1", port });
      f.migrate.databases = [];
      f.write();
      const allowed = f.run("container");
      assert(allowed.ok, allowed.output);
      assert(exists(f.migrate.awake.path));
      assert(!exists(`${f.folder}/run/quiesced`));
      const allowedEnvironment = Deno.readTextFileSync(`${f.checkout}/.env`);
      Deno.writeTextFileSync(`${f.checkout}/.env`, allowedEnvironment.replace("127.0.0.1", "127.0.0.2"));
      const refusedQuiesce = f.run("quiesce");
      assert(!refusedQuiesce.ok, refusedQuiesce.output);
      assertEquals(f.result().error_code, "instance.migrate_env_service_unreachable");
      assert(exists(`${f.folder}/run/schedule-timers.json`), "Schedule checks finish before the repeated environment guard");
      assert(exists(f.migrate.awake.path));
      assert(!exists(`${f.folder}/run/quiesced`));
      assertEquals(Deno.readTextFileSync(`${f.folder}/host-processes.json`), before);
      assertEquals(Deno.readTextFileSync(schedules.timerPath), schedules.timer);
      Deno.writeTextFileSync(`${f.checkout}/.env`, allowedEnvironment);
      const quiesce = f.run("quiesce");
      assert(quiesce.ok, quiesce.output);
      assert(!exists(f.migrate.awake.path));
    } finally {
      listener.close();
      f.cleanup();
    }
  },
});
Deno.test({
  name: "an unreadable copied environment refuses before downtime and restore preserves the source",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      // Invalid UTF-8 deterministically exercises a file read failure even when the fixture runs as root.
      Deno.writeFileSync(`${f.checkout}/.env`, new Uint8Array([0xff, 0xfe]));
      const sourceUnit = `${f.folder}/source.service`;
      Deno.writeTextFileSync(sourceUnit, "[Service]\nExecStart=/bin/true\n");
      f.migrate.processes.push({ name: "writer.service", path: sourceUnit, environment_directory: `${f.folder}/source-env`, desired: "running", executables: ["/bin/true"] });
      Deno.writeTextFileSync(`${f.folder}/host-processes.json`, JSON.stringify({ "writer.service": { state: "running", enabled: true } }));
      const before = Deno.readTextFileSync(`${f.folder}/host-processes.json`);
      f.migrate.databases = [{ host: "127.0.0.1", port: 1 }];
      f.write();
      const stateBefore = f.state();

      const refused = f.run("container");

      assert(!refused.ok, refused.output);
      assertEquals(f.result().error_code, "instance.migrate_env_service_unreachable");
      assertEquals(f.result().preflight_refused, true);
      assert(f.result().error_message.includes(".env: cannot read .env safely."));
      assert(exists(f.migrate.awake.path));
      assertEquals(Deno.readTextFileSync(`${f.folder}/host-processes.json`), before);
      assertEquals(f.state(), stateBefore);
      assert(!exists(`${f.folder}/calls`), "an environment refusal must precede every container command and reach probe");
      assert(!exists(`${f.folder}/run/quiesced`));
      const restore = f.run("restore");
      assert(restore.ok, restore.output);
      assert(exists(f.migrate.awake.path));
      assertEquals(Deno.readTextFileSync(`${f.folder}/host-processes.json`), before);
      assertEquals(f.state(), stateBefore);
      assert(!exists(`${f.folder}/calls`), "an environment refusal must precede every container command and reach probe");
      assert(!exists(`${f.folder}/run/quiesced`));
    } finally {
      f.cleanup();
    }
  },
});
for (const scenario of ["stock Laravel", "real53", "selected Redis", "attached Redis", "wrong Redis port"]) {
  Deno.test({
    name: `stock selector preflight: ${scenario}`,
    ignore: !hasAnsible,
    fn() {
      const f = fixture();
      const listener = Deno.listen({hostname:"127.0.0.1",port:0});
      const port = (listener.addr as Deno.NetAddr).port;
      for (const name of [
        "vendor/nunomaduro/collision/tests/LaravelApp/.env",
        "vendor/vlucas/phpdotenv/tests/fixtures/env/.env",
        "vendor/laravel/framework/tests/Foundation/Fixtures/.env",
        "node_modules/example/tests/.env",
      ]) {
        const path = `${f.checkout}/${name}`;
        Deno.mkdirSync(path.slice(0, path.lastIndexOf("/")), { recursive: true });
        Deno.writeTextFileSync(path, "DB_CONNECTION=mysql\nDB_HOST=127.0.0.1\nMAIL_MAILER=smtp\nMAIL_HOST=127.0.0.1\nMAIL_PORT=1025\n");
      }

      try {
        const selected = scenario.includes("Redis");
        const text = scenario === "stock Laravel"
          ? Deno.readTextFileSync(new URL("fixtures/laravel-stock.env.example", import.meta.url))
          : real53Environment.replace("CACHE_STORE=database", selected ? "CACHE_STORE=redis" : "CACHE_STORE=database");
        Deno.writeTextFileSync(`${f.checkout}/.env`, text.replace("REDIS_PORT=6379", `REDIS_PORT=${port}`));
        if (scenario === "attached Redis" || scenario === "wrong Redis port") {
          f.migrate.environment.targets.push({ host: "127.0.0.1", port: scenario === "attached Redis" ? port : port + 1 });
        }
        f.write();
        const before = f.state();
        const result = f.run("container");
        const refused = scenario === "selected Redis" || scenario === "wrong Redis port";
        assertEquals(result.ok, !refused, result.output);
        if (refused) {
          assertEquals(f.result().error_code, "instance.migrate_env_service_unreachable");
          assertEquals(f.result().preflight_refused, true);
          assert(f.result().error_message.includes(`REDIS_HOST=127.0.0.1:${port}`));
          assertEquals(f.state(), before);
          assert(!exists(`${f.folder}/calls`), "refuse before any container command or probe");
        } else {
          assert(exists(`${f.folder}/calls`), "pass the real preflight and prepare the container");
        }
        assertEquals(Deno.readTextFileSync(`${f.checkout}/.env`), text.replace("REDIS_PORT=6379", `REDIS_PORT=${port}`));
        assert(exists(f.migrate.awake.path));
        assert(!exists(`${f.folder}/run/quiesced`));
      } finally { listener.close(); f.cleanup(); }
    },
  });
}
