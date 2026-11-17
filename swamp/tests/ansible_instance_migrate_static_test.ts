/**
 * The static checks of a move: the shipped task files, without a run.
 * The harness is swamp/tests/_support/instance_migrate.ts.
 */
import { assert, assertEquals } from "jsr:@std/assert@1";
import { parse } from "jsr:@std/yaml@1";
import { root, Task } from "./_support/instance_migrate.ts";

Deno.test("retention keeps real Git edge cases intact", () => {
  const done = new Deno.Command("python3", {
    args: ["-I", new URL("fixtures/instance-migrate-retention.py", import.meta.url).pathname],
  }).outputSync();
  assert(done.success, new TextDecoder().decode(done.stdout) + new TextDecoder().decode(done.stderr));
});
Deno.test("the environment guard shares the pre-quiesce preflight and precedes every probe and downtime step", () => {
  const tasks = parse(Deno.readTextFileSync(new URL("tasks/instance-migrate-phase.yml", root))) as Task[];
  const names = [
    "Check checkout links before downtime",
    "Check copied service endpoints before downtime",
    "Record that the container preflight passed",
    "Prepare the move's container",
    "Check the way to each database server",
    "Record that quiesce began",
    "Stop the old Process units before copying",
    "Remove the awake marker",
  ];
  const positions = names.map((name) => tasks.findIndex((task) => task.name === name));
  assert(positions.every((position) => position >= 0), "all preflight and downtime steps must remain present");
  assertEquals(positions, [...positions].sort((left, right) => left - right));
  const guard = tasks[positions[1]];
  assertEquals(guard.when, "phase in ['container', 'quiesce']");
  assertEquals((guard["ansible.builtin.include_tasks"] as Task).file, "instance-migrate-env-check.yml");
});
Deno.test("directory modes stay in the protected result and restore through the workspace owner", () => {
  const phase = parse(Deno.readTextFileSync(new URL("tasks/instance-migrate-phase.yml", root))) as Task[];
  const copy = phase.find(task => task.name === "Copy the checkout's files into the workspace")!;
  const tasks = copy.block as Task[];
  const archive = tasks.find(task => task.register === "migrate_archive")!;
  assertEquals(archive.no_log, true);
  const restore = tasks.find(task => task.name === "Restore the added directories' exact modes including special bits")!;
  const command = restore["ansible.builtin.command"] as Task;
  assert((command.argv as string).startsWith("{{ as_app +"));
  assert((command.argv as string).includes("--restore-directories"));
  assertEquals(command.stdin, "{{ (migrate_archive.stdout | from_json).directory_modes }}");
  assertEquals(restore.no_log, true);
  const push = tasks.find(task => task.name === "Push the archive and the deleted files into the container")!;
  assertEquals(push.loop, [".tar", ".deleted", ".git.pack", ".git.json"]);
});
